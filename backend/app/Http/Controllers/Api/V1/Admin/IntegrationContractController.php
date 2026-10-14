<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Integrations\Contracts\ContractFixtures;
use App\Integrations\Contracts\ContractGateway;
use App\Integrations\Contracts\ContractStore;
use App\Integrations\Contracts\ContractTestRunner;
use App\Integrations\Contracts\ContractValidator;
use App\Integrations\Contracts\InvalidContract;
use App\Integrations\Contracts\JsonPath;
use App\Integrations\Contracts\Mapper;
use App\Integrations\IntegrationAudit;
use App\Integrations\IntegrationManager;
use App\Integrations\IntegrationRegistry;
use App\Integrations\IntegrationUnavailable;
use App\Integrations\Ministry\HrReconciliation;
use App\Models\IntegrationChecklistItem;
use App\Services\Communication\MinistrySiteSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Contract, Test cases and Go-live checklist tabs of an integration card: the contract and mapping in force (the shipped ones or the
 * administrator's versions: saved, compared, rolled back), a preview of a mapping on a recorded answer, the test cases, and who
 * ticked which step of the go-live checklist. Nothing here returns a secret: a contract can never read one.
 */
class IntegrationContractController extends Controller
{
    public function __construct(private readonly ContractStore $store, private readonly ContractGateway $gateway, private readonly IntegrationManager $hub) {}

    public function show(string $key): JsonResponse
    {
        $this->known($key);

        return response()->json(['data' => $this->card($key)]);
    }

    public function save(Request $request, string $key): JsonResponse
    {
        $this->known($key);
        $d = $request->validate(['kind' => ['required', Rule::in(ContractStore::KINDS)], 'document' => ['required', 'array'], 'note' => ['nullable', 'string', 'max:200']]);
        try {
            $row = $this->store->save($key, $d['kind'], $d['document'], $request->user()->id, $d['note'] ?? null, $request->ip());
        } catch (InvalidContract $e) {
            return response()->json(['message' => $e->getMessage(), 'problems' => $e->problems], 422);
        }

        return response()->json(['data' => $this->card($key), 'saved' => ['kind' => $d['kind'], 'version' => $row->version]]);
    }

    public function diff(Request $request, string $key): JsonResponse
    {
        $this->known($key);
        $d = $request->validate(['kind' => ['required', Rule::in(ContractStore::KINDS)], 'from' => ['required', 'integer', 'min:0'], 'to' => ['nullable', 'integer', 'min:0']]);
        try {
            return response()->json(['data' => $this->store->diff($key, $d['kind'], (int) $d['from'], (int) ($d['to'] ?? $this->store->activeVersion($key, $d['kind'])))]);
        } catch (InvalidContract $e) {
            return response()->json(['message' => $e->getMessage(), 'problems' => $e->problems], 422);
        }
    }

    public function rollback(Request $request, string $key): JsonResponse
    {
        $this->known($key);
        $d = $request->validate(['kind' => ['required', Rule::in(ContractStore::KINDS)], 'version' => ['required', 'integer', 'min:0']]);
        try {
            $row = $this->store->rollback($key, $d['kind'], (int) $d['version'], $request->user()->id, $request->ip());
        } catch (InvalidContract $e) {
            return response()->json(['message' => $e->getMessage(), 'problems' => $e->problems], 422);
        }

        return response()->json(['data' => $this->card($key), 'saved' => ['kind' => $d['kind'], 'version' => $row->version]]);
    }

    public function reset(Request $request, string $key): JsonResponse
    {
        $this->known($key);
        $d = $request->validate(['kind' => ['required', Rule::in(ContractStore::KINDS)]]);
        $row = $this->store->reset($key, $d['kind'], $request->user()->id, $request->ip());

        return response()->json(['data' => $this->card($key), 'saved' => ['kind' => $d['kind'], 'version' => $row->version]]);
    }

    /** Tries a mapping (saved or not) on a recorded answer: the records as the system sent them and as TEDC would take them. */
    public function preview(Request $request, string $key): JsonResponse
    {
        $this->known($key);
        $d = $request->validate(['entity' => ['required', 'string', 'max:40'], 'fixture' => ['required', 'string', 'max:80'], 'mapping' => ['sometimes', 'array']]);
        $contract = $this->store->active($key);
        $mapping = $d['mapping'] ?? $contract->mappingDocument();
        $entity = $mapping['entities'][$d['entity']] ?? null;
        abort_unless(is_array($entity), 422, 'The mapping has no such entity.');
        $fixture = ContractFixtures::load($key, $d['fixture']);
        abort_if($fixture === null, 422, 'There is no such recorded answer.');

        $problems = ContractValidator::problems($key, 'mapping', $mapping, $this->store->secretSettings($key), $this->store->knownSettings($key));
        $operation = collect($contract->operations())->first(fn ($op) => ($op['entity'] ?? null) === $d['entity']);
        $body = is_array($fixture['body'] ?? null) ? $fixture['body'] : json_decode((string) ($fixture['body'] ?? ''), true);
        $found = isset($operation['response']['records']) ? JsonPath::get($body, $operation['response']['records']) : $body;
        $records = is_array($found) ? array_values(array_filter($found, 'is_array')) : [];

        return response()->json([
            'problems' => $problems, 'total' => count($records),
            'records' => array_map(fn ($r) => ['source' => $r, 'mapped' => $problems === [] ? Mapper::apply($entity, $r) : null], array_slice($records, 0, 5)),
        ]);
    }

    /** Runs the test cases against the recorded answers, or only builds their requests. */
    public function testCase(Request $request, string $key): JsonResponse
    {
        $d = $request->validate(['mode' => ['required', Rule::in(['request', 'contract'])], 'case' => ['nullable', 'integer', 'min:0']]);

        return $this->run($request, $key, $d['mode'], isset($d['case']) ? (int) $d['case'] : null);
    }

    /** Runs read-only test cases against the real system (switched on, a real driver); counts and field gaps come back, never the people. */
    public function testCaseLive(Request $request, string $key): JsonResponse
    {
        $this->known($key);
        $d = $request->validate(['case' => ['nullable', 'integer', 'min:0']]);
        abort_unless($this->liveReady($key), 422, 'Switch the system on with a real driver first: a live run reaches the system itself.');

        return $this->run($request, $key, 'live', isset($d['case']) ? (int) $d['case'] : null);
    }

    public function checklist(string $key): JsonResponse
    {
        $this->known($key);

        return response()->json(['data' => $this->checklistFor($key)]);
    }

    public function tick(Request $request, string $key, string $item): JsonResponse
    {
        $this->known($key);
        $d = $request->validate(['done' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:300']]);
        abort_unless(in_array($item, array_column($this->store->active($key)->checklist(), 'id'), true), 404);

        $done = (bool) $d['done'];
        IntegrationChecklistItem::updateOrCreate(['integration_key' => $key, 'item' => $item], ['done_by' => $done ? $request->user()->id : null, 'done_at' => $done ? now() : null, 'note' => $done ? ($d['note'] ?? null) : null]);
        IntegrationAudit::record($key, 'integration.checklist.updated', $request->user()->id, ['item' => $item, 'done' => $done], null, $request->ip());

        return response()->json(['data' => $this->checklistFor($key)]);
    }

    /** "TEDC vs HR differences" (HR and Mawared): where the two disagree, as a report or as a file Excel opens with Arabic intact. */
    public function reconciliation(Request $request, string $key, HrReconciliation $reconciliation): JsonResponse|StreamedResponse
    {
        abort_unless(in_array($key, ['hr', 'mawared'], true), 404);
        $d = $request->validate(['format' => ['nullable', Rule::in(['json', 'csv'])]]);
        try {
            if (($d['format'] ?? 'json') === 'csv') {
                $lines = iterator_to_array($reconciliation->lines($key), false);   // computed (and HR asked) before anything is sent
                IntegrationAudit::record($key, 'integration.reconciliation.run', $request->user()->id, ['format' => 'csv', 'lines' => max(0, count($lines) - 1)], null, $request->ip());

                return response()->streamDownload(function () use ($lines) {
                    $out = fopen('php://output', 'w');
                    fwrite($out, "\xEF\xBB\xBF");   // the byte-order mark: without it Excel reads Arabic as mojibake
                    foreach ($lines as $line) {
                        fputcsv($out, $line);
                    }
                    fclose($out);
                }, "tedc-vs-{$key}-differences.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
            }
            $report = $reconciliation->report($key);
        } catch (IntegrationUnavailable $e) {
            return response()->json(['message' => 'Switch the HR system on first: '.$e->getMessage()], 422);
        }
        IntegrationAudit::record($key, 'integration.reconciliation.run', $request->user()->id, ['format' => 'json'] + $report['summary'], null, $request->ip());

        return response()->json(['data' => $report]);
    }

    // ---- internals ------------------------------------------------------------------------------------

    private function known(string $key): void
    {
        abort_unless(isset(IntegrationRegistry::all()[$key]) && $this->store->has($key), 404);
    }

    private function liveReady(string $key): bool
    {
        if ($key === 'ministry_site') {
            return app(MinistrySiteSettings::class)->ready();
        }
        $integration = $this->hub->get($key);

        return $integration->enabled && $integration->driver !== 'fake';
    }

    private function run(Request $request, string $key, string $mode, ?int $case): JsonResponse
    {
        $this->known($key);
        $contract = $this->store->active($key);
        $runner = new ContractTestRunner($contract, $key, $this->store->secretSettings($key));
        $settings = $this->gateway->settings($key);
        if ($case !== null) {
            $cases = $contract->tests();
            abort_unless(isset($cases[$case]), 422, 'There is no such test case.');
            $one = $runner->run($cases[$case], $mode, $settings);
            $summary = ['mode' => $mode, 'total' => 1, 'passed' => $one['ok'] ? 1 : 0, 'failed' => $one['ok'] ? 0 : 1, 'ok' => $one['ok'], 'cases' => [$one]];
        } else {
            $summary = $runner->runAll($mode, $settings, onlySafe: $mode === 'live');
        }
        IntegrationAudit::record($key, 'integration.test_case.run', $request->user()->id, ['mode' => $mode, 'case' => $case, 'total' => $summary['total'], 'passed' => $summary['passed']], null, $request->ip());

        return response()->json(['data' => $summary]);
    }

    /** @return array<string, mixed> */
    private function card(string $key): array
    {
        $contract = $this->store->active($key);
        $operations = $contract->operations();
        $fixtures = collect(glob(ContractFixtures::dir($key).'/fixtures/*.json') ?: [])->map(fn ($f) => basename($f, '.json'))->sort()->values()->all();

        return [
            'key' => $key, 'contract' => $contract->document(), 'mapping' => $contract->mappingDocument(), 'auth_type' => $contract->auth()['type'] ?? 'none',
            'versions' => ['contract' => $this->store->versions($key, 'contract'), 'mapping' => $this->store->versions($key, 'mapping')],
            'operations' => collect($operations)->map(fn ($op, $name) => ['name' => $name, 'title' => $op['title'] ?? null, 'method' => $op['method'] ?? null, 'path' => $op['path'] ?? null, 'safe' => (bool) ($op['safe'] ?? false), 'entity' => $op['entity'] ?? null])->values()->all(),
            'fixtures' => $fixtures,
            'tests' => collect($contract->tests())->map(fn ($t, $i) => ['index' => $i, 'name' => (string) ($t['name'] ?? ''), 'operation' => (string) ($t['operation'] ?? ''), 'fixture' => $t['fixture'] ?? null, 'safe' => (bool) ($operations[$t['operation'] ?? '']['safe'] ?? false)])->values()->all(),
            'checklist' => $this->checklistFor($key),
            'secret_settings' => $this->store->secretSettings($key), 'known_settings' => $this->store->knownSettings($key),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function checklistFor(string $key): array
    {
        $ticks = IntegrationChecklistItem::where('integration_key', $key)->with('doneBy')->get()->keyBy('item');

        return collect($this->store->active($key)->checklist())->map(function ($item) use ($ticks) {
            $tick = $ticks->get($item['id']);

            return [
                'id' => $item['id'], 'group' => $item['group'], 'ar' => $item['ar'], 'en' => $item['en'], 'done' => (bool) $tick?->done_at,
                'done_at' => $tick?->done_at?->toIso8601String(), 'done_by' => $tick?->doneBy?->displayName(), 'note' => $tick?->note,
            ];
        })->values()->all();
    }
}
