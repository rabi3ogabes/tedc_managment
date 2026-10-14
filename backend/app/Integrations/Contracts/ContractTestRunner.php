<?php

namespace App\Integrations\Contracts;

/**
 * Runs the test cases of a contract: "request" only builds the request and checks it; "contract" also feeds the case's recorded answer
 * through the response map, the error model and the mapping; "live" sends a read-only operation to the real system and reports counts
 * and gaps, never the people in the answer. Nothing here prints a credential: a request is shown masked, and a dry run never holds a
 * real secret.
 */
final class ContractTestRunner
{
    public const MODES = ['request', 'contract', 'live'];

    private const PREVIEW_VALUE = 'preview-value';

    /** @param  list<string>  $secretSettings */
    public function __construct(private readonly Contract $contract, private readonly string $system, private readonly array $secretSettings) {}

    /**
     * @param  array<string, mixed>  $settings  the system's settings (real ones; secrets are only used by a live run)
     * @param  bool  $onlySafe  leave out the operations that change something in the system (what a live run of everything does)
     * @return array{mode: string, total: int, passed: int, failed: int, ok: bool, cases: list<array<string, mixed>>}
     */
    public function runAll(string $mode, array $settings, bool $onlySafe = false): array
    {
        $chosen = array_filter($this->contract->tests(), fn ($case) => ! $onlySafe || ! empty($this->contract->operations()[(string) ($case['operation'] ?? '')]['safe']));
        $cases = array_map(fn ($case) => $this->run((array) $case, $mode, $settings), array_values($chosen));
        $passed = count(array_filter($cases, fn ($c) => $c['ok']));

        return ['mode' => $mode, 'total' => count($cases), 'passed' => $passed, 'failed' => count($cases) - $passed, 'ok' => $passed === count($cases), 'cases' => $cases];
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  array<string, mixed>  $settings
     * @return array{name: string, operation: string, mode: string, ok: bool, checks: list<array{check: string, ok: bool, detail: string}>, request: ?array<string, mixed>, response: ?array<string, mixed>}
     *
     * @throws ContractException a mode that does not exist
     */
    public function run(array $case, string $mode, array $settings): array
    {
        if (! in_array($mode, self::MODES, true)) {
            throw new ContractException('bad_mode', 'The mode of a test run is request, contract or live.');
        }
        $operation = (string) ($case['operation'] ?? '');
        $result = ['name' => (string) ($case['name'] ?? $operation), 'operation' => $operation, 'mode' => $mode, 'ok' => false, 'checks' => [], 'request' => null, 'response' => null];
        $op = $this->contract->operations()[$operation] ?? null;
        if ($op === null) {
            return $this->finish($result, 'case.operation', false, 'This contract has no operation "'.mb_substr($operation, 0, 40).'".');
        }
        if ($mode === 'live' && empty($op['safe'])) {
            return $this->finish($result, 'live.safe', false, 'A live run only reaches read-only operations. This one changes something in the system: run it against the recorded answers, or with the Ministry present.');
        }

        $expect = (array) ($case['expect'] ?? []);
        $params = (array) ($case['params'] ?? []);
        $live = $mode === 'live';
        $used = $live ? $settings : $this->drySettings($settings);
        $driver = new ContractDriver($this->contract, $used, $this->secretSettings, $this->system);

        try {
            $prepared = $driver->request($operation, $params, ['preview' => true]);
            $result['request'] = $prepared->masked();
            $this->check($result, 'request.prepared', true, $prepared->method.' '.$result['request']['url']);
        } catch (ContractException $e) {
            return $this->finish($result, 'request.prepared', false, $e->getMessage());
        }

        if (isset($expect['request'])) {
            $this->requestChecks($result, (array) $expect['request'], $prepared, $used);
        }
        if (isset($op['request_schema']) && $prepared->body !== null && $prepared->body !== '') {
            $problems = ContractResponse::validate(json_decode($prepared->body, true), (array) $op['request_schema']);
            $this->check($result, 'request.schema', $problems === [], $problems === [] ? 'The request fits the contract\'s request schema.' : implode('; ', array_slice($problems, 0, 5)));
        }
        if ($mode === 'request') {
            return $this->finish($result);
        }
        if ($live && isset($expect['error'])) {
            return $this->finish($result, 'live.skipped', true, 'This case is about a recorded error answer; it is run against the recorded answers, not the live system.');
        }

        try {
            $response = $live ? $driver->call($operation, $params) : $this->recorded($driver, $case, $operation);
        } catch (ContractException $e) {
            return $this->errorChecks($result, $e, $expect, $live);
        }
        if (isset($expect['error'])) {
            return $this->finish($result, 'response.error', false, 'The case expects an error ('.json_encode($expect['error']).'), but the answer was accepted (HTTP '.$response->status.').');
        }

        $this->responseChecks($result, $response, $op, $expect, $live);

        return $this->finish($result);
    }

    // ---- checks ---------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $result */
    private function check(array &$result, string $check, bool $ok, string $detail): void
    {
        $result['checks'][] = ['check' => $check, 'ok' => $ok, 'detail' => $detail];
    }

    /** @param  array<string, mixed>  $result @return array<string, mixed> */
    private function finish(array $result, ?string $check = null, bool $ok = true, string $detail = ''): array
    {
        if ($check !== null) {
            $this->check($result, $check, $ok, $detail);
        }
        $result['ok'] = $result['checks'] !== [] && ! in_array(false, array_column($result['checks'], 'ok'), true);

        return $result;
    }

    /** @param  array<string, mixed>  $result @param  array<string, mixed>  $expect */
    private function errorChecks(array $result, ContractException $e, array $expect, bool $live): array
    {
        $wanted = (array) ($expect['error'] ?? []);
        if ($live || $wanted === []) {
            return $this->finish($result, $live ? 'response.live' : 'response.error', false, ($wanted === [] || $live ? 'The system answered with an error: ' : '').$e->errorCode.' — '.$e->getMessage());
        }
        $problems = [];
        foreach (['code' => $e->errorCode, 'retryable' => $e->retryable, 'status' => $e->status] as $key => $actual) {
            if (array_key_exists($key, $wanted) && $wanted[$key] !== $actual) {
                $problems[] = $key.' is '.json_encode($actual).', expected '.json_encode($wanted[$key]);
            }
        }

        return $this->finish($result, 'response.error', $problems === [], $problems === [] ? 'The error comes out as '.$e->errorCode.': '.$e->getMessage() : implode('; ', $problems));
    }

    /** @param  array<string, mixed>  $result @param  array<string, mixed>  $expect @param  array<string, mixed>  $op */
    private function responseChecks(array &$result, ContractResponse $response, array $op, array $expect, bool $live): void
    {
        $records = $response->records();
        $result['response'] = ['status' => $response->status, 'records' => count($records)];
        $this->check($result, $live ? 'response.live' : 'response.accepted', true, 'HTTP '.$response->status.', '.count($records).' record(s) found by the contract.');

        if (array_key_exists('records', $expect) && ! $live) {
            $this->check($result, 'response.records', count($records) === (int) $expect['records'], count($records).' record(s), expected '.(int) $expect['records'].'.');
        }
        if (! empty($expect['schema']) || ($live && isset($op['response_schema']))) {
            $problems = $response->schemaProblems();
            $this->check($result, 'response.schema', $problems === [], $problems === [] ? 'The answer fits the contract\'s response schema.' : implode('; ', array_slice($problems, 0, 5)));
        }
        $entity = isset($op['entity']) ? (string) $op['entity'] : null;
        $mapped = $entity ? array_map(fn ($r) => $this->contract->map($entity, $r), $records) : [];
        if ($entity && ! $live) {
            $result['response']['preview'] = array_slice($mapped, 0, 3);
        }
        if (isset($expect['mapped']) && ! $live) {
            $problems = [];
            foreach ((array) $expect['mapped'] as $index => $fields) {
                foreach ((array) $fields as $field => $wanted) {
                    $actual = $mapped[(int) $index][$field] ?? null;
                    if (! $this->same($wanted, $actual)) {
                        $problems[] = 'record '.$index.' · '.$field.' is '.json_encode($actual, JSON_UNESCAPED_UNICODE).', expected '.json_encode($wanted, JSON_UNESCAPED_UNICODE);
                    }
                }
            }
            $this->check($result, 'response.mapped', $entity !== null && $problems === [], $entity === null ? 'The operation has no "entity" to map the records to.' : ($problems === [] ? 'The records map to the expected TEDC fields.' : implode('; ', $problems)));
        }
        if (isset($expect['values']) && ! $live) {
            $problems = [];
            foreach ((array) $expect['values'] as $name => $wanted) {
                $actual = $response->value((string) $name);
                if (! $this->same($wanted, $actual)) {
                    $problems[] = $name.' is '.json_encode($actual, JSON_UNESCAPED_UNICODE).', expected '.json_encode($wanted, JSON_UNESCAPED_UNICODE);
                }
            }
            $this->check($result, 'response.values', $problems === [], $problems === [] ? 'The named values are found where the contract says.' : implode('; ', $problems));
        }
        if ($live && $entity && $records !== []) {
            $gaps = [];
            foreach (array_keys((array) ($this->contract->entity($entity)['fields'] ?? [])) as $field) {
                $missing = count(array_filter($mapped, fn ($row) => ! array_key_exists($field, $row)));
                if ($missing > 0) {
                    $gaps[] = $field.': empty in '.$missing.' of '.count($mapped);
                }
            }
            $this->check($result, 'response.gaps', true, $gaps === [] ? 'Every mapped field has a value in every record.' : 'Fields without a value — '.implode('; ', array_slice($gaps, 0, 30)));
        }
    }

    /** @param  array<string, mixed>  $result @param  array<string, mixed>  $expect */
    private function requestChecks(array &$result, array $expect, PreparedRequest $prepared, array $settings): void
    {
        $problems = [];
        if (isset($expect['method']) && strtoupper((string) $expect['method']) !== $prepared->method) {
            $problems[] = 'method is '.$prepared->method.', expected '.strtoupper((string) $expect['method']);
        }
        $basePath = rtrim((string) parse_url(rtrim((string) $settings['base_url'], '/'), PHP_URL_PATH), '/');
        $path = substr((string) parse_url($prepared->base, PHP_URL_PATH), strlen($basePath)) ?: '/';
        if (isset($expect['path']) && $path !== $expect['path']) {
            $problems[] = 'path is '.$path.', expected '.$expect['path'];
        }
        foreach ((array) ($expect['query'] ?? []) as $name => $wanted) {
            if (! $this->same($wanted, $prepared->query[$name] ?? null)) {
                $problems[] = 'query "'.$name.'" is '.json_encode($prepared->query[$name] ?? null).', expected '.json_encode($wanted);
            }
        }
        $headers = array_change_key_case($prepared->headers);
        foreach ((array) ($expect['headers'] ?? []) as $name => $wanted) {
            if (! $this->same($wanted, $headers[strtolower((string) $name)] ?? null)) {
                $problems[] = 'header "'.$name.'" is not '.json_encode($wanted);
            }
        }
        if (isset($expect['body'])) {
            $body = json_decode((string) $prepared->body, true);
            $missing = $this->subsetProblems((array) $expect['body'], $body, 'body');
            $problems = array_merge($problems, $missing);
        }
        $this->check($result, 'request.expected', $problems === [], $problems === [] ? 'The request is what the case expects.' : implode('; ', $problems));
    }

    // ---- helpers --------------------------------------------------------------------------------------

    /** The case's recorded answer, read the way a live one is. @param  array<string, mixed>  $case */
    private function recorded(ContractDriver $driver, array $case, string $operation): ContractResponse
    {
        $fixture = is_array($case['response'] ?? null) && isset($case['response']['status'])
            ? $case['response']   // a sample the Ministry sent, pasted into the case
            : (ContractFixtures::load($this->system, (string) ($case['fixture'] ?? ''))
                ?? throw new ContractException('fixture_missing', 'The recorded answer "'.mb_substr((string) ($case['fixture'] ?? ''), 0, 60).'" is not in this system\'s fixtures.'));
        $body = $fixture['body'] ?? null;

        return $driver->interpret($operation, (int) $fixture['status'], is_string($body) ? $body : (string) json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The settings a dry run uses: the real ones that are not secrets, a placeholder where the contract needs a secret. @param  array<string, mixed>  $settings @return array<string, mixed> */
    private function drySettings(array $settings): array
    {
        $dry = array_diff_key($settings, array_flip($this->secretSettings));
        $base = trim((string) ($settings['base_url'] ?? ''));
        $parts = parse_url($base);
        $dry['base_url'] = in_array($parts['scheme'] ?? '', ['http', 'https'], true) && ! empty($parts['host']) ? $base : 'https://system.example';
        foreach ($this->contract->auth() as $key => $name) {
            if (is_string($name) && ($key === 'setting' || str_ends_with((string) $key, '_setting')) && $key !== 'name_setting' && ! filled($dry[$name] ?? null)) {
                $dry[$name] = self::PREVIEW_VALUE;
            }
        }

        return $dry;
    }

    private function same(mixed $wanted, mixed $actual): bool
    {
        $text = fn ($v) => is_scalar($v) || $v === null ? (is_bool($v) ? json_encode($v) : (string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE);

        return is_array($wanted) ? $this->subsetProblems($wanted, $actual, '') === [] : $text($wanted) === $text($actual);
    }

    /** What is missing from or different in $actual, compared with what $wanted names (extra keys are fine). @return list<string> */
    private function subsetProblems(array $wanted, mixed $actual, string $at): array
    {
        if (! is_array($actual)) {
            return [($at !== '' ? $at : 'value').' is missing'];
        }
        $problems = [];
        foreach ($wanted as $key => $value) {
            $where = $at === '' ? (string) $key : $at.'.'.$key;
            if (! array_key_exists($key, $actual)) {
                $problems[] = $where.' is missing';
            } elseif (is_array($value)) {
                $problems = array_merge($problems, $this->subsetProblems($value, $actual[$key], $where));
            } elseif (! $this->same($value, $actual[$key])) {
                $problems[] = $where.' is '.json_encode($actual[$key], JSON_UNESCAPED_UNICODE).', expected '.json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        return $problems;
    }
}
