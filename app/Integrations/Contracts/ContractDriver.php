<?php

namespace App\Integrations\Contracts;

use App\Support\Supabase;
use Generator;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * The generic HTTP driver of the contract kit: it carries out one operation of a system's contract. URL, query, headers and body come
 * from templates, the contract's authentication is applied, the system's own address (a setting) is the only address it reaches, and
 * what comes back is read through the operation's response map and turned into a stable error when it is not good. A system whose
 * real specification differs from the bundled contract is adapted by editing the contract and the mapping, not this class.
 */
final class ContractDriver
{
    /** @var list<string> temporary key files to remove */
    private array $tempFiles = [];

    /** Whether the token the current call used was asked for during it (a token that was just issued and is refused is not asked for again). */
    private bool $tokenWasFetched = false;

    /** The next token is asked for even if one is kept (the system refused the kept one). */
    private bool $renewToken = false;

    /**
     * @param  array<string, mixed>  $settings  the integration's settings (secrets included: they only ever go to the auth scheme)
     * @param  list<string>  $secretSettings  the names of the settings that are secrets
     */
    public function __construct(private readonly Contract $contract, private readonly array $settings, private readonly array $secretSettings = [], private readonly ?string $system = null) {}

    public function contract(): Contract
    {
        return $this->contract;
    }

    /**
     * Fills the templates and applies the authentication, without sending: what a request preview and the sandbox show.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string, query?: array<string, mixed>, preview?: bool}  $options  preview: a token service is not asked and a signature that cannot be made is shown as "•••"
     */
    public function request(string $operation, array $params = [], array $options = []): PreparedRequest
    {
        $op = $this->contract->operation($operation);
        $context = $this->contract->prepare($params) + ['settings' => $this->visibleSettings(), 'now' => Carbon::now()->toIso8601String()];

        $base = $this->baseUrl().$this->path((string) $op['path'], $context);
        $query = Template::renderValue((array) ($op['query'] ?? []), $context);
        $query = array_filter(array_merge((array) $query, (array) ($options['query'] ?? [])), fn ($v) => $v !== null && $v !== '');
        $headers = ['Accept' => 'application/json'];
        foreach ((array) Template::renderValue((array) ($op['headers'] ?? []), $context) as $name => $value) {
            if ($value !== null && $value !== '') {
                $headers[(string) $name] = str_replace(["\r", "\n"], '', (string) (is_scalar($value) ? $value : json_encode($value)));
            }
        }
        if (isset($op['idempotency']['header'])) {
            $headers[(string) $op['idempotency']['header']] = (string) ($options['idempotency_key'] ?? Str::uuid());
        }

        [$body, $contentType] = $this->body($op, $context, $headers);
        $maskedHeaders = [];
        $maskedQuery = [];
        $this->applyAuth($this->contract->auth(), (string) $op['method'], (string) $op['path'], $headers, $query, $body, $maskedHeaders, $maskedQuery, (bool) ($options['preview'] ?? false));

        return new PreparedRequest((string) $op['method'], $base, $query, $headers, $body, $contentType, $maskedHeaders, $maskedQuery,
            $base.($query ? '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''));
    }

    /**
     * Carries out an operation once.
     *
     * @param  array<string, mixed>  $params
     * @param  array{idempotency_key?: string, query?: array<string, mixed>}  $options
     *
     * @throws ContractException
     */
    public function call(string $operation, array $params = [], array $options = []): ContractResponse
    {
        $op = $this->contract->operation($operation);
        $this->throttle($operation, $op);
        if (isset($op['idempotency']['header'])) {
            $options['idempotency_key'] ??= (string) Str::uuid();   // one key for the call and for the send that follows a renewed token
        }
        try {
            $this->tokenWasFetched = false;
            $response = $this->send($operation, $op, $params, $options);
            if ($response->status() === 401 && ($this->contract->auth()['type'] ?? '') === 'oauth2' && ! $this->tokenWasFetched) {
                $this->renewToken = true;   // the token that was kept is refused (revoked, or expired early): one new token, the same request
                $response = $this->send($operation, $op, $params, $options);
            }
        } catch (ConnectionException $e) {
            $timedOut = preg_match('/timed? ?out|error 28/i', $e->getMessage()) === 1;

            throw new ContractException($timedOut ? 'timeout' : 'unreachable', ($timedOut ? 'The system did not answer in time' : 'The system could not be reached').': '.$this->scrub(mb_substr($e->getMessage(), 0, 160)), 0, true);
        } finally {
            $this->cleanup();
        }

        return $this->read($op, $response);
    }

    /** @param  array<string, mixed>  $op @param  array<string, mixed>  $params @param  array<string, mixed>  $options */
    private function send(string $operation, array $op, array $params, array $options): Response
    {
        $request = $this->request($operation, $params, $options);
        $pending = Http::withOptions($this->clientOptions())->timeout((int) ($op['timeout'] ?? 20))->withHeaders($request->headers);
        if ($request->body !== null) {
            $pending = $pending->withBody($request->body, $request->contentType);
        }

        return $pending->send($request->method, $request->url);
    }

    /**
     * Every record of an operation, page after page (by page number, offset or cursor, as the contract says), never more than max_pages.
     *
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<string, mixed>>
     */
    public function each(string $operation, array $params = []): Generator
    {
        $op = $this->contract->operation($operation);
        $pg = (array) ($op['pagination'] ?? ['type' => 'none']);
        $type = (string) ($pg['type'] ?? 'none');
        $size = isset($pg['size']) ? (int) $pg['size'] : null;
        $page = (int) ($pg['start'] ?? 1);
        $offset = 0;
        $cursor = null;
        for ($i = 0, $max = (int) ($pg['max_pages'] ?? 100); $i < $max; $i++) {
            $extra = [];
            if ($size && ! empty($pg['size_param'])) {
                $extra[$pg['size_param']] = $size;
            }
            if ($type === 'page') {
                $extra = [($pg['param'] ?? 'page') => $page] + $extra;
            } elseif ($type === 'offset') {
                $extra = [($pg['param'] ?? 'offset') => $offset] + $extra;
            } elseif ($type === 'cursor' && $cursor !== null) {
                $extra = [($pg['param'] ?? 'cursor') => $cursor] + $extra;
            }
            $response = $this->call($operation, $params, ['query' => $extra]);
            $records = $response->records();
            foreach ($records as $record) {
                yield $record;
            }
            if ($type === 'none' || $records === []) {
                return;
            }
            if ($type === 'page') {
                $next = $response->marker('next_page');
                if (isset($op['response']['next_page'])) {
                    if ($next === null || $next === '') {
                        return;
                    }
                    $page = is_numeric($next) ? (int) $next : $page + 1;
                } elseif ($size !== null && count($records) < $size) {
                    return;
                } else {
                    $page++;
                }
            } elseif ($type === 'offset') {
                $offset += count($records);
                $total = $response->marker('total');
                if (($total !== null && $offset >= (int) $total) || ($size !== null && count($records) < $size)) {
                    return;
                }
            } else {
                $cursor = $response->marker('next_cursor');
                if ($cursor === null || $cursor === '') {
                    return;
                }
            }
        }
    }

    /**
     * Reads an answer that was not fetched now (a recorded fixture) the way a live one is read: through the operation's response map and
     * error model.
     *
     * @throws ContractException
     */
    public function interpret(string $operation, int $status, string $body): ContractResponse
    {
        return $this->read($this->contract->operation($operation), new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], $body)));
    }

    /** The HTTP client's options: certificate verification, and a client certificate for mutual TLS. @return array<string, mixed> */
    public function clientOptions(): array
    {
        $options = ['verify' => Supabase::caBundle()];
        $auth = $this->contract->auth();
        if (($auth['type'] ?? '') === 'mtls') {
            $cert = $this->secret((string) ($auth['cert_setting'] ?? ''));
            $key = $this->secret((string) ($auth['key_setting'] ?? ''));
            if ($cert === '' || $key === '') {
                throw new ContractException('not_configured', 'The client certificate and its key are not set.');
            }
            $options['cert'] = $this->tempFile($cert);
            $options['ssl_key'] = $this->tempFile($key);
        }

        return $options;
    }

    /** Removes the temporary key files (called after every call). */
    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    // ---- building ---------------------------------------------------------------------------------------

    private function baseUrl(): string
    {
        $base = rtrim(trim((string) ($this->settings['base_url'] ?? '')), '/');
        if ($base === '') {
            $base = $this->addressFromName();   // an address the administrator sets wins; otherwise the contract's own, made from a name
        }
        if ($base === '') {
            throw new ContractException('not_configured', 'The system address is not set.');
        }
        $parts = parse_url($base);
        if (! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new ContractException('not_configured', 'The system address is not a valid http(s) address.');
        }
        if (($parts['scheme'] ?? '') !== 'https' && app()->isProduction()) {
            throw new ContractException('insecure_address', 'The system address must use https in production.');
        }

        return $base;
    }

    /**
     * The address a contract makes from a name (a tenant's subdomain): the scheme and the domain are the contract's, the name is one plain
     * DNS label that also has to match the contract's pattern, so it cannot add a dot, a path, a port or a user name.
     */
    private function addressFromName(): string
    {
        $spec = $this->contract->document()['base_url'] ?? null;
        if (! is_array($spec) || ! is_string($spec['pattern'] ?? null)) {
            return '';
        }
        $url = $spec['pattern'];
        foreach ((array) ($spec['variables'] ?? []) as $name => $regex) {
            $value = is_scalar($this->settings[$name] ?? null) ? (string) $this->settings[$name] : '';
            if ($value === '') {
                throw new ContractException('not_configured', 'The system address is not set: "'.$name.'" is missing.');
            }
            if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value) || ! preg_match('/'.str_replace('/', '\\/', (string) $regex).'/D', $value)) {   // D: "$" is the very end, not "before a final newline"
                throw new ContractException('not_configured', 'The '.$name.' "'.mb_substr($value, 0, 40).'" cannot make a system address.');
            }
            $url = str_replace('{'.$name.'}', $value, $url);
        }

        return $url;
    }

    /**
     * The finished path, checked once more whatever the template did: it must stay a plain path under the system's own address (a value
     * or a setting that adds a host, a query, a fragment or a "." / ".." segment is refused).
     *
     * @param  array<string, mixed>  $context
     */
    private function path(string $template, array $context): string
    {
        $path = Template::render($template, $context, 'url');
        if (! preg_match('#^/[A-Za-z0-9_\-.~/%:,]*$#', $path) || str_starts_with($path, '//') || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            throw new ContractException('bad_path', 'The path of this operation does not stay on the system\'s own address: '.mb_substr($path, 0, 60));
        }

        if (str_contains($path, '//')) {
            throw new ContractException('not_configured', 'The address of this operation is incomplete: a setting it needs is empty ('.mb_substr($path, 0, 80).').');
        }

        return $path;
    }

    /** @return array<string, mixed> the settings a template may read: never a secret */
    private function visibleSettings(): array
    {
        return array_diff_key($this->settings, array_flip($this->secretSettings));
    }

    private function secret(string $name): string
    {
        $v = $this->settings[$name] ?? '';

        return is_string($v) ? $v : '';
    }

    /** @return array{0: ?string, 1: string} the body text and its content type */
    private function body(array $op, array $context, array &$headers): array
    {
        $format = (string) ($op['body']['format'] ?? (isset($op['body']) ? 'json' : 'none'));
        $template = $op['body']['template'] ?? null;
        $headerType = null;
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $headerType = $value;
                unset($headers[$name]);
            }
        }

        return match ($format) {
            'none' => [null, 'application/json'],
            'form' => [http_build_query((array) Template::renderValue((array) $template, $context)), $headerType ?? 'application/x-www-form-urlencoded'],
            'raw' => [Template::render((string) $template, $context), $headerType ?? 'text/plain'],
            default => [is_string($template)
                ? Template::render($template, $context, 'json')
                : (string) json_encode(Template::renderValue($template ?? [], $context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), $headerType ?? 'application/json'],
        };
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     * @param  list<string>  $maskedHeaders
     * @param  list<string>  $maskedQuery
     */
    private function applyAuth(array $auth, string $method, string $path, array &$headers, array &$query, ?string $body, array &$maskedHeaders, array &$maskedQuery, bool $preview = false): void
    {
        $need = function (string $setting, string $what): string {
            $v = $this->secret($setting);
            if ($v === '') {
                throw new ContractException('not_configured', "The {$what} is not set.");
            }

            return $v;
        };
        if (in_array($auth['type'] ?? '', ['bearer', 'api_key'], true) && $this->secret((string) ($auth['setting'] ?? '')) === '') {
            if (isset($auth['fallback']) && is_array($auth['fallback'])) {
                // The first credential is not set: the one behind it is used (a token if there is one, else a user name and password).
                $this->applyAuth(array_diff_key($auth['fallback'], ['fallback' => 1]), $method, $path, $headers, $query, $body, $maskedHeaders, $maskedQuery, $preview);

                return;
            }
            // A system that relies on the network (an allow-list) can be reached without a key: "optional" leaves the credential out when it is not set.
            if (! empty($auth['optional'])) {
                return;
            }
        }
        switch ($auth['type'] ?? 'none') {
            case 'bearer':
                $headers['Authorization'] = 'Bearer '.$need((string) $auth['setting'], 'API key');
                $maskedHeaders[] = 'Authorization';
                break;
            case 'api_key':
                $value = $need((string) $auth['setting'], 'API key');
                $name = $this->keyName($auth);
                if (($auth['in'] ?? 'header') === 'query') {
                    $query[$name] = $value;
                    $maskedQuery[] = $name;
                } else {
                    $headers[$name] = $value;
                    $maskedHeaders[] = $name;
                }
                break;
            case 'basic':
                $headers['Authorization'] = 'Basic '.base64_encode($need((string) $auth['user_setting'], 'user name').':'.$need((string) $auth['password_setting'], 'password'));
                $maskedHeaders[] = 'Authorization';
                break;
            case 'oauth2':
                $headers['Authorization'] = (string) ($auth['header_scheme'] ?? 'Bearer').' '.($preview ? '•••' : $this->token($auth));
                $maskedHeaders[] = 'Authorization';
                break;
            case 'hmac':
                $timestamp = (string) Carbon::now()->timestamp;
                try {
                    $headers[(string) $auth['signature_header']] = Signature::make($auth, $need((string) $auth['secret_setting'], 'signing secret'), $timestamp, (string) $body, ['method' => $method, 'path' => $path]);
                } catch (ContractException $e) {
                    if (! $preview) {
                        throw $e;
                    }
                    $headers[(string) $auth['signature_header']] = '•••';   // the real key is not in a preview
                }
                $maskedHeaders[] = (string) $auth['signature_header'];
                if (! empty($auth['timestamp_header'])) {
                    $headers[(string) $auth['timestamp_header']] = $timestamp;
                }
                break;
            default:
                break;   // none, and mtls (a certificate, not a header)
        }
    }

    /** The name the API key travels under: the system's own setting when the contract names one, else the contract's fixed name. */
    private function keyName(array $auth): string
    {
        $fromSetting = isset($auth['name_setting']) ? trim((string) ($this->settings[(string) $auth['name_setting']] ?? '')) : '';
        $name = $fromSetting !== '' ? $fromSetting : (string) ($auth['name'] ?? '');
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9\-_]{0,60}$/', $name)) {
            throw new ContractException('not_configured', $name === '' ? 'The name the API key travels under is not set.' : 'The name the API key travels under is not a plain header or parameter name.');
        }

        return $name;
    }

    /** A client-credentials token, kept until a minute before the system says it expires. */
    private function token(array $auth): string
    {
        $renew = $this->renewToken;
        $this->renewToken = false;
        $id = $this->secret((string) ($auth['client_id_setting'] ?? ''));
        $secret = $this->secret((string) ($auth['client_secret_setting'] ?? ''));
        $url = $this->tokenUrl($auth);
        if ($url === '' || $id === '' || $secret === '') {
            throw new ContractException('not_configured', 'The token address, client id and client secret are not all set.');
        }
        if (! str_starts_with($url, 'https://') && app()->isProduction()) {
            throw new ContractException('insecure_address', 'The token address must use https in production.');
        }
        $key = 'contract.token.'.md5($this->system.'|'.$url.'|'.$id);
        if ($renew) {
            Cache::forget($key);
        }
        $kept = Cache::get($key);
        if (is_string($kept) && $kept !== '') {
            $this->tokenWasFetched = false;

            return $kept;
        }

        $form = array_filter(['grant_type' => 'client_credentials', 'scope' => $auth['scope'] ?? null, 'audience' => $auth['audience'] ?? null] + (array) ($auth['token_params'] ?? []), fn ($v) => $v !== null && $v !== '');
        $client = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(15)->asForm();
        if (($auth['client_auth'] ?? 'body') === 'basic') {
            $client = $client->withBasicAuth($id, $secret);   // the client signs in with the Authorization header, not the form
        } else {
            $form += ['client_id' => $id, 'client_secret' => $secret];
        }
        try {
            $r = $client->post($url, $form);
        } catch (Throwable $e) {
            throw new ContractException('unreachable', 'The token service could not be reached: '.$this->scrub(mb_substr($e->getMessage(), 0, 120)), 0, true);
        }
        if (! $r->successful() || ! is_string($r->json('access_token')) || $r->json('access_token') === '') {
            throw new ContractException('unauthorised', 'The token service refused the client credentials (HTTP '.$r->status().').', $r->status());
        }
        $token = (string) $r->json('access_token');
        $seconds = is_numeric($r->json('expires_in')) && (int) $r->json('expires_in') > 0 ? (int) $r->json('expires_in') : 3000;
        Cache::put($key, $token, max(30, min(3600, $seconds - 60)));   // a minute early, and never longer than an hour
        $this->tokenWasFetched = true;

        return $token;
    }

    /** Where the token is asked for: a setting that holds the address (it wins, so a mock or a proxy can stand in), the contract's own address, or a path on the system's own address. */
    private function tokenUrl(array $auth): string
    {
        $fromSetting = isset($auth['token_url_setting']) ? trim($this->secret((string) $auth['token_url_setting'])) : '';
        if ($fromSetting !== '') {
            return $fromSetting;
        }
        if (! empty($auth['token_url'])) {
            return (string) $auth['token_url'];
        }

        return ! empty($auth['token_path']) ? $this->baseUrl().$auth['token_path'] : '';
    }

    private function tempFile(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'tedc-contract-');   // created readable by this user only
        file_put_contents($file, $content);
        $this->tempFiles[] = $file;

        return $file;
    }

    private function throttle(string $operation, array $op): void
    {
        $max = (int) ($op['rate_limit']['per_minute'] ?? 0);
        if ($max < 1) {
            return;
        }
        $key = 'contract:'.$this->system.':'.$operation;
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ContractException('rate_limited', 'Too many calls to this operation in a minute (the contract allows '.$max.').', 0, true);
        }
        RateLimiter::hit($key, 60);
    }

    // ---- reading ----------------------------------------------------------------------------------------

    private function read(array $op, Response $response): ContractResponse
    {
        $status = $response->status();
        $accepted = (array) ($op['success'] ?? []);
        $ok = $accepted !== [] ? in_array($status, array_map('intval', $accepted), true) : $response->successful();
        $json = $response->json();
        $result = new ContractResponse($status, is_array($json) ? $json : null, (string) $response->body(), $op);
        if ($ok) {
            if (! $this->accepted($op, $result)) {
                $detail = $this->theirWords($op, $result);

                throw new ContractException('rejected', $this->scrub('The system answered but did not accept the request (HTTP '.$status.')'.($detail !== '' ? ': '.mb_substr($detail, 0, 160) : '')), $status, false);
            }

            return $result;
        }
        $table = $this->contract->errorFor($op, $status);
        $code = (string) ($table['code'] ?? self::builtInCode($status));
        $text = $table['message']['en'] ?? self::builtInText($status);
        $detail = $this->theirWords($op, $result);

        throw new ContractException($code, $this->scrub($text.' (HTTP '.$status.')'.($detail !== '' ? ': '.mb_substr($detail, 0, 160) : '')), $status, (bool) ($table['retryable'] ?? ($status === 429 || $status >= 500)));
    }

    /** What the system itself says about a failure: the first of the paths the contract names ("error" is one path or a few to try in order) that holds a text, shortened and without markup. */
    private function theirWords(array $op, ContractResponse $result): string
    {
        $paths = (array) ($op['response']['error'] ?? []);
        foreach ($paths as $path) {
            $found = is_string($path) ? JsonPath::get($result->json, $path) : null;
            if (is_scalar($found) && trim(strip_tags((string) $found)) !== '') {
                return trim(strip_tags((string) $found));
            }
        }

        return '';
    }

    /** Whether the answer meets the operation's success rule(s): some systems answer 200 and say in the body that they did not take it. */
    private function accepted(array $op, ContractResponse $result): bool
    {
        $rules = (array) ($op['success_when'] ?? []);
        if ($rules === []) {
            return true;
        }
        foreach (array_is_list($rules) ? $rules : [$rules] as $rule) {
            $rule = (array) $rule;
            if (isset($rule['body_contains']) && ! str_contains($result->body, (string) $rule['body_contains'])) {
                return false;
            }
            if (isset($rule['body_not_contains']) && str_contains($result->body, (string) $rule['body_not_contains'])) {
                return false;
            }
            if (! isset($rule['path'])) {
                continue;
            }
            $value = JsonPath::get($result->json, (string) $rule['path']);
            $text = fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (is_scalar($v) ? (string) $v : json_encode($v));
            $present = $value !== null && $value !== '';
            $holds = match (true) {
                array_key_exists('equals', $rule) => $present && $text($value) === $text($rule['equals']),
                array_key_exists('not_equals', $rule) => ! $present || $text($value) !== $text($rule['not_equals']),
                isset($rule['in']) => $present && in_array($text($value), array_map($text, (array) $rule['in']), true),
                isset($rule['not_in']) => ! $present || ! in_array($text($value), array_map($text, (array) $rule['not_in']), true),
                ! empty($rule['exists']) => $present,
                ! empty($rule['absent']) => ! $present,
                default => true,
            };
            if (! $holds) {
                return false;
            }
        }

        return true;
    }

    private static function builtInCode(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'unauthorised',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'upstream_error',
            default => 'rejected',
        };
    }

    private static function builtInText(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'The system refused the credentials',
            $status === 404 => 'The system has no such record',
            $status === 409 => 'The system reports a conflict',
            $status === 429 => 'The system asks to slow down',
            $status >= 500 => 'The system had an error',
            default => 'The system rejected the request',
        };
    }

    /** Takes every secret value out of a text, so an error or an answer can be shown or logged safely. */
    public function scrub(string $text): string
    {
        foreach ($this->secretSettings as $name) {
            $v = $this->settings[$name] ?? null;
            if (is_string($v) && strlen($v) >= 4) {
                $text = str_replace([$v, rawurlencode($v), urlencode($v)], '•••', $text);
            }
        }

        return $text;
    }
}
