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
        $context = $params + ['settings' => $this->visibleSettings(), 'now' => Carbon::now()->toIso8601String()];

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
        try {
            $request = $this->request($operation, $params, $options);
            $pending = Http::withOptions($this->clientOptions())->timeout((int) ($op['timeout'] ?? 20))->withHeaders($request->headers);
            if ($request->body !== null) {
                $pending = $pending->withBody($request->body, $request->contentType);
            }
            $response = $pending->send($request->method, $request->url);
        } catch (ConnectionException $e) {
            $timedOut = preg_match('/timed? ?out|error 28/i', $e->getMessage()) === 1;

            throw new ContractException($timedOut ? 'timeout' : 'unreachable', ($timedOut ? 'The system did not answer in time' : 'The system could not be reached').': '.$this->scrub(mb_substr($e->getMessage(), 0, 160)), 0, true);
        } finally {
            $this->cleanup();
        }

        return $this->read($op, $response);
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
        // A system that relies on the network (an allow-list) can be reached without a key: "optional" leaves the credential out when it is not set.
        if (in_array($auth['type'] ?? '', ['bearer', 'api_key'], true) && ! empty($auth['optional']) && $this->secret((string) ($auth['setting'] ?? '')) === '') {
            return;
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
                $headers['Authorization'] = 'Bearer '.($preview ? '•••' : $this->token($auth));
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

    /** A client-credentials token, kept until a minute before it expires. */
    private function token(array $auth): string
    {
        $url = $this->secret((string) $auth['token_url_setting']);
        $id = $this->secret((string) $auth['client_id_setting']);
        $secret = $this->secret((string) $auth['client_secret_setting']);
        if ($url === '' || $id === '' || $secret === '') {
            throw new ContractException('not_configured', 'The token address, client id and client secret are not all set.');
        }

        return Cache::remember('contract.token.'.md5($this->system.'|'.$url.'|'.$id), 3000, function () use ($auth, $url, $id, $secret) {
            try {
                $r = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(15)->asForm()->post($url, array_filter([
                    'grant_type' => 'client_credentials', 'client_id' => $id, 'client_secret' => $secret, 'scope' => $auth['scope'] ?? null, 'audience' => $auth['audience'] ?? null,
                ]));
            } catch (Throwable $e) {
                throw new ContractException('unreachable', 'The token service could not be reached: '.$this->scrub(mb_substr($e->getMessage(), 0, 120)), 0, true);
            }
            if (! $r->successful() || ! $r->json('access_token')) {
                throw new ContractException('unauthorised', 'The token service refused the client credentials (HTTP '.$r->status().').', $r->status());
            }

            return (string) $r->json('access_token');
        });
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
            return $result;
        }
        $table = $this->contract->errorFor($op, $status);
        $code = (string) ($table['code'] ?? self::builtInCode($status));
        $theirs = isset($op['response']['error']) ? JsonPath::get($result->json, $op['response']['error']) : null;
        $text = $table['message']['en'] ?? self::builtInText($status);
        $detail = is_scalar($theirs) ? trim(strip_tags((string) $theirs)) : '';

        throw new ContractException($code, $this->scrub($text.' (HTTP '.$status.')'.($detail !== '' ? ': '.mb_substr($detail, 0, 160) : '')), $status, (bool) ($table['retryable'] ?? ($status === 429 || $status >= 500)));
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

    /** Takes every secret value out of a text, so an error can be shown or logged safely. */
    private function scrub(string $text): string
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
