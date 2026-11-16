<?php

namespace App\Integrations\Contracts;

/**
 * A stand-in for a Ministry system that implements its contract from the recorded fixtures: it finds the operation a request is for,
 * checks the authentication the contract names, and answers with the fixture of the chosen scenario (ok, an error, a slow answer).
 * It is plain code over files, with no framework: `php artisan tedc:mock-ministry` serves it, and the tests replace the network with
 * the same answers.
 */
final class MockMinistry
{
    /** @var array<string, string> operation => scenario, used when the request does not choose one */
    private array $scenarios = [];

    /** @var array<string, mixed> the system's settings that decide an address (an operation whose path is a setting) */
    private array $settings = [];

    /** @var array<string, array{status: int, body: mixed}> operation => an answer to give instead of its recorded one (a sample the Ministry sent) */
    private array $answers = [];

    public function __construct(private readonly Contract $contract, private readonly string $fixtures, private readonly string $scenario = 'ok', private readonly ?string $requireKey = null) {}

    public static function fromDirectory(string $dir, string $system, string $scenario = 'ok', ?string $requireKey = null): self
    {
        $read = fn (string $file) => is_file("{$dir}/{$file}") ? (json_decode((string) file_get_contents("{$dir}/{$file}"), true) ?: []) : [];

        return new self(new Contract($system, ['system' => $system] + $read('contract.json'), $read('mapping.json')), $dir.'/fixtures', $scenario, $requireKey);
    }

    /** @param  array<string, string>  $scenarios  operation => scenario */
    public function withScenarios(array $scenarios): self
    {
        $copy = clone $this;
        $copy->scenarios = $scenarios;

        return $copy;
    }

    /** @param  array<string, array{status: int, body: mixed}>  $answers  operation => the answer to give for it, whatever the scenario */
    public function withAnswers(array $answers): self
    {
        $copy = clone $this;
        $copy->answers = $answers;

        return $copy;
    }

    /** @param  array<string, mixed>  $settings  the settings that decide addresses (such as publish_path), when they are not the contract's defaults */
    public function withSettings(array $settings): self
    {
        $copy = clone $this;
        $copy->settings = $settings;

        return $copy;
    }

    /** What the mock answers: method, address (the system's settings at their defaults) and operation. @return list<array{method: string, path: string, operation: string}> */
    public function routes(): array
    {
        $routes = [];
        foreach ($this->contract->operations() as $name => $op) {
            $display = (string) preg_replace_callback('/\{\{\s*([^{}]*?)\s*\}\}/', fn ($m) => $this->isSetting($m[1]) && Template::render($m[0], ['settings' => $this->settings]) !== '' ? Template::render($m[0], ['settings' => $this->settings]) : '{'.preg_replace('/^settings\./', '', trim(explode('|', $m[1])[0])).'}', (string) $op['path']);
            $routes[] = ['method' => (string) $op['method'], 'path' => $display, 'operation' => (string) $name];
        }

        return $routes;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers  names in lower case
     * @return array{status: int, headers: array<string, string>, body: string, delay_ms: int}
     */
    public function respond(string $method, string $path, array $query, array $headers, string $body): array
    {
        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');
        if ($method === 'POST' && $this->tokenPath() !== null && rtrim($path, '/') === rtrim((string) $this->tokenPath(), '/')) {
            return $this->token($headers, $body);
        }
        $matched = [];
        foreach ($this->contract->operations() as $name => $op) {
            if (preg_match($this->pattern((string) $op['path']), $path)) {
                $matched[(string) $name] = $op;
            }
        }
        if ($matched === []) {
            return $this->json(404, ['message' => 'The mock of "'.$this->contract->system.'" has no such address.', 'known' => array_map(fn ($r) => $r['method'].' '.$r['path'], $this->routes())]);
        }
        $name = null;
        foreach ($matched as $candidate => $op) {
            if (strtoupper((string) $op['method']) === $method) {
                $name = $candidate;
                break;
            }
        }
        if ($name === null) {
            $allowed = array_values(array_unique(array_map(fn ($op) => strtoupper((string) $op['method']), $matched)));
            $res = $this->json(405, ['message' => 'This address answers '.implode(', ', $allowed).'.']);
            $res['headers']['Allow'] = implode(', ', $allowed);

            return $res;
        }
        $scenario = $this->chosenScenario($name, $query, $headers);
        if (! $this->authorised($headers, $query)) {
            return $this->fixture($name, 'unauthorised') ?? $this->json(401, ['message' => 'Authentication is required.']);
        }

        if (isset($this->answers[$name]) && ! isset($headers['x-mock-scenario']) && ! isset($query['_scenario'])) {
            $given = $this->answers[$name];

            return ['status' => (int) $given['status'], 'headers' => ['Content-Type' => 'application/json'], 'body' => is_string($given['body']) ? $given['body'] : (string) json_encode($given['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'delay_ms' => 0];
        }

        return $this->fixture($name, $scenario) ?? $this->json(501, ['message' => 'The mock has no recorded answer for "'.$name.'.'.$scenario.'".']);
    }

    /** Where the token service of a system that signs in with OAuth2 answers, given the system's own address (null for any other system). */
    public function tokenUrl(string $baseUrl): ?string
    {
        $auth = $this->contract->auth();
        if (($auth['type'] ?? '') !== 'oauth2') {
            return null;
        }
        if (! empty($auth['token_url'])) {
            return (string) $auth['token_url'];
        }

        return ! empty($auth['token_path']) ? rtrim($baseUrl, '/').$auth['token_path'] : null;
    }

    // ---- internals ------------------------------------------------------------------------------------

    /** The path the mock answers token requests at: the contract's token path, or the path of its token address. */
    private function tokenPath(): ?string
    {
        $auth = $this->contract->auth();
        if (($auth['type'] ?? '') !== 'oauth2') {
            return null;
        }

        return ! empty($auth['token_path']) ? (string) $auth['token_path'] : (! empty($auth['token_url']) ? (string) parse_url((string) $auth['token_url'], PHP_URL_PATH) : null);
    }

    /**
     * A token for the client credentials the contract asks for: the client in the Authorization header or in the form, as the contract
     * says, with the extra fields it names. The mock does not know the real credentials, so any non-empty client is accepted.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, headers: array<string, string>, body: string, delay_ms: int}
     */
    private function token(array $headers, string $body): array
    {
        $auth = $this->contract->auth();
        parse_str($body, $form);
        if (($auth['client_auth'] ?? 'body') === 'basic') {
            $given = str_starts_with((string) ($headers['authorization'] ?? ''), 'Basic ') && str_contains((string) base64_decode(substr((string) $headers['authorization'], 6), true), ':');
        } else {
            $given = filled($form['client_id'] ?? null) && filled($form['client_secret'] ?? null);
        }
        if (! $given) {
            return $this->json(401, ['error' => 'invalid_client', 'error_description' => 'The client credentials are missing'.(($auth['client_auth'] ?? 'body') === 'basic' ? ' (this contract wants them in the Authorization header)' : ' (this contract wants client_id and client_secret in the form)').'.']);
        }
        if (($form['grant_type'] ?? '') !== 'client_credentials') {
            return $this->json(400, ['error' => 'unsupported_grant_type', 'error_description' => 'Only client_credentials is served.']);
        }
        foreach ((array) ($auth['token_params'] ?? []) as $name => $value) {
            if (($form[$name] ?? null) !== (string) $value) {
                return $this->json(400, ['error' => 'invalid_request', 'error_description' => 'The field "'.$name.'" must be "'.$value.'".']);
            }
        }

        return $this->json(200, ['access_token' => 'mock-token-'.bin2hex(random_bytes(6)), 'token_type' => (string) ($auth['header_scheme'] ?? 'Bearer'), 'expires_in' => 1799]);
    }

    private function chosenScenario(string $operation, array $query, array $headers): string
    {
        $asked = (string) ($headers['x-mock-scenario'] ?? $query['_scenario'] ?? $this->scenarios[$operation] ?? $this->scenario);

        return $asked;
    }

    /** @return array{status: int, headers: array<string, string>, body: string, delay_ms: int}|null */
    private function fixture(string $operation, string $scenario): ?array
    {
        if (! preg_match('/^[a-z0-9_]{1,40}$/', $scenario)) {
            return null;   // a scenario is a file name part: nothing else is ever opened
        }
        $file = "{$this->fixtures}/{$operation}.{$scenario}.json";
        $fixture = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($fixture) || ! isset($fixture['status'])) {
            return null;
        }
        $body = $fixture['body'] ?? null;

        return [
            'status' => (int) $fixture['status'], 'headers' => ['Content-Type' => 'application/json'] + (array) ($fixture['headers'] ?? []),
            'body' => is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'delay_ms' => (int) ($fixture['delay_ms'] ?? 0),
        ];
    }

    /** @return array{status: int, headers: array<string, string>, body: string, delay_ms: int} */
    private function json(int $status, array $body): array
    {
        return ['status' => $status, 'headers' => ['Content-Type' => 'application/json'], 'body' => (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'delay_ms' => 0];
    }

    /** Whether the request carries the credential the contract asks for (and the key this server was told to expect, if any). */
    private function authorised(array $headers, array $query): bool
    {
        $auth = $this->contract->auth();
        $given = match ($auth['type'] ?? 'none') {
            'bearer' => str_starts_with((string) ($headers['authorization'] ?? ''), 'Bearer ') ? trim(substr((string) $headers['authorization'], 7)) : '',
            'oauth2' => str_starts_with((string) ($headers['authorization'] ?? ''), ($scheme = (string) ($auth['header_scheme'] ?? 'Bearer')).' ') ? trim(substr((string) $headers['authorization'], strlen($scheme) + 1)) : '',
            'basic' => str_starts_with((string) ($headers['authorization'] ?? ''), 'Basic ') ? trim(substr((string) $headers['authorization'], 6)) : '',
            'api_key' => ($auth['in'] ?? 'header') === 'query' ? (string) ($query[$this->keyName($auth)] ?? '') : (string) ($headers[strtolower($this->keyName($auth))] ?? ''),
            'hmac' => (string) ($headers[strtolower((string) ($auth['signature_header'] ?? ''))] ?? ''),
            default => null,
        };
        if ($given === null) {
            return true;
        }
        if ($given === '') {
            return false;
        }

        return $this->requireKey === null || ! in_array($auth['type'] ?? '', ['bearer', 'api_key'], true) || hash_equals($this->requireKey, $given);
    }

    /** The header (or parameter) an API key travels in: the system's own setting when the contract leaves it to one, else the contract's name. */
    private function keyName(array $auth): string
    {
        $fromSetting = isset($auth['name_setting']) ? trim((string) ($this->settings[(string) $auth['name_setting']] ?? '')) : '';

        return $fromSetting !== '' ? $fromSetting : (string) ($auth['name'] ?? '');
    }

    private function isSetting(string $expression): bool
    {
        return str_starts_with(trim(explode('|', $expression)[0]), 'settings.');
    }

    /** The regular expression an operation's path template stands for: the system's own settings at their defaults, anything else one segment. */
    private function pattern(string $template): string
    {
        $regex = '';
        $offset = 0;
        preg_match_all('/\{\{\s*([^{}]*?)\s*\}\}/', $template, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($found as $hit) {
            [$whole, $position] = $hit[0];
            $regex .= preg_quote(substr($template, $offset, $position - $offset), '#');
            $given = $this->isSetting($hit[1][0]) ? Template::render($whole, ['settings' => $this->settings]) : '';
            $regex .= $given !== '' ? preg_quote($given, '#') : '([^/]+)';   // a setting that is not given (an organisation id) stands for any value
            $offset = $position + strlen($whole);
        }

        return '#^'.$regex.preg_quote(substr($template, $offset), '#').'/?$#';
    }
}
