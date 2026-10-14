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
            $display = (string) preg_replace_callback('/\{\{\s*([^{}]*?)\s*\}\}/', fn ($m) => $this->isSetting($m[1]) ? Template::render($m[0], ['settings' => $this->settings]) : '{'.trim(explode('|', $m[1])[0]).'}', (string) $op['path']);
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

    // ---- internals ------------------------------------------------------------------------------------

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
            'bearer', 'oauth2' => str_starts_with((string) ($headers['authorization'] ?? ''), 'Bearer ') ? trim(substr((string) $headers['authorization'], 7)) : '',
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
            $regex .= $this->isSetting($hit[1][0]) ? preg_quote(Template::render($whole, ['settings' => $this->settings]), '#') : '([^/]+)';
            $offset = $position + strlen($whole);
        }

        return '#^'.$regex.preg_quote(substr($template, $offset), '#').'/?$#';
    }
}
