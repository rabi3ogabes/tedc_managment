<?php

namespace App\Integrations\Contracts;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** The recorded answers shipped with a system's contract, and the stand-in for its network in tests. */
final class ContractFixtures
{
    public static function dir(string $system): string
    {
        return resource_path('contracts/'.(ContractStore::ALIASES[$system] ?? $system));
    }

    /** @return array{status: int, headers?: array<string, string>, body: mixed, delay_ms?: int}|null */
    public static function load(string $system, string $name): ?array
    {
        if (! preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)?$/', $name)) {
            return null;
        }
        $file = self::dir($system).'/fixtures/'.$name.'.json';
        $fixture = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($fixture) && isset($fixture['status']) ? $fixture : null;
    }

    /**
     * Makes the HTTP client answer for a system's address from its fixtures (what `tedc:mock-ministry` serves, without a server), following the
     * contract in force.
     *
     * @param  array<string, string>  $scenarios  operation => scenario (ok, unauthorised, server_error…)
     * @param  array<string, mixed>  $settings  the settings that decide addresses, when they are not the contract's defaults
     * @param  array<string, array{status: int, body: mixed}>  $answers  operation => an answer to give instead of the recorded one
     */
    public static function fake(string $system, string $baseUrl = 'https://sys.test/api', array $scenarios = [], string $scenario = 'ok', array $settings = [], array $answers = []): void
    {
        // The contract in force answers (the shipped one, or the administrator's override), so a test of an override talks to a system that follows it.
        $mock = (new MockMinistry(app(ContractStore::class)->active($system), self::dir($system).'/fixtures', $scenario))->withScenarios($scenarios)->withSettings($settings)->withAnswers($answers);
        $parts = parse_url($baseUrl);
        $host = ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        $basePath = rtrim((string) ($parts['path'] ?? ''), '/');

        Http::fake([$host.'/*' => function (Request $request) use ($mock, $basePath) {
            $url = parse_url($request->url());
            $path = substr((string) ($url['path'] ?? '/'), strlen($basePath)) ?: '/';
            parse_str((string) ($url['query'] ?? ''), $query);
            $headers = [];
            foreach ($request->headers() as $name => $values) {
                $headers[strtolower((string) $name)] = implode(', ', (array) $values);
            }
            $answer = $mock->respond($request->method(), $path, $query, $headers, $request->body());

            return Http::response($answer['body'], $answer['status'], $answer['headers']);
        }]);
    }
}
