<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Integrations\IntegrationAudit;
use App\Integrations\IntegrationManager;
use App\Integrations\IntegrationRegistry;
use App\Models\ExternalCompletionIssue;
use App\Models\ExternalCourse;
use App\Models\ExternalSyncRun;
use App\Services\Library\ExternalLearningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * The learning providers marketplace: for each provider its connection (status, last sync), the settings to connect it, the catalogue to
 * search and turn into programmes, and the completions that could not be used.
 */
class LearningProvidersController extends Controller
{
    public function __construct(private readonly ExternalLearningService $learning, private readonly IntegrationManager $hub) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => collect(ExternalLearningService::PROVIDERS)->map(fn (string $p) => $this->card($p))->values()]);
    }

    /** Connect, switch on or off, or change the settings of one provider (a blank secret keeps the stored one). */
    public function update(Request $request, string $provider): JsonResponse
    {
        $this->known($provider);
        $d = $request->validate(['driver' => ['sometimes', Rule::in(IntegrationRegistry::all()[$provider]['drivers'])], 'enabled' => ['sometimes', 'boolean'], 'settings' => ['sometimes', 'array'], 'clear' => ['sometimes', 'array']]);
        $before = $this->hub->get($provider);
        $secrets = IntegrationRegistry::secretFields($provider);
        $changed = collect(Arr::only((array) ($d['settings'] ?? []), array_column(IntegrationRegistry::all()[$provider]['fields'], 'k')))->reject(fn ($v, $k) => in_array($k, $secrets, true) && ($v === null || $v === ''))->keys()->all();
        $this->hub->update($provider, $d);
        // What changed, never the values: a secret must not reach the audit trail.
        IntegrationAudit::record($provider, 'integration.settings_changed', $this->user()->id, array_filter([
            'driver' => $d['driver'] ?? null, 'enabled' => array_key_exists('enabled', $d) ? (bool) $d['enabled'] : null, 'settings_changed' => $changed ?: null, 'cleared' => $d['clear'] ?? null,
        ], fn ($v) => $v !== null), ['driver' => $before->driver, 'enabled' => $before->enabled], $request->ip());

        return response()->json(['data' => $this->card($provider)]);
    }

    public function check(string $provider): JsonResponse
    {
        $this->known($provider);
        $this->hub->check($provider);

        return response()->json(['data' => $this->card($provider)]);
    }

    /** Reads the provider now (the catalogue and the completions); "full" reads the whole catalogue instead of the changes. */
    public function sync(Request $request, string $provider): JsonResponse
    {
        $this->known($provider);
        $d = $request->validate(['full' => ['sometimes', 'boolean']]);
        if (! $this->hub->get($provider)->enabled) {
            throw new BusinessRuleException(__('messages.providers.off'), 'provider_off');
        }
        $result = $this->learning->sync($provider, (bool) ($d['full'] ?? false), 'manual', $this->user());

        return response()->json(['data' => $result['providers'][$provider] ?? [], 'provider' => $this->card($provider)]);
    }

    /** The catalogue TEDC holds, searched. */
    public function courses(Request $request): JsonResponse
    {
        $f = $request->validate(['provider' => ['nullable', Rule::in(ExternalLearningService::PROVIDERS)], 'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['available', 'retired', 'removed'])], 'imported' => ['nullable', 'boolean']]);
        $like = isset($f['q']) && trim($f['q']) !== '' ? '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($f['q']))).'%' : null;
        $rows = ExternalCourse::query()
            ->when($f['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v), fn ($q) => $q->where('status', '!=', 'removed'))
            ->when(array_key_exists('imported', $f) && $f['imported'] !== null, fn ($q) => $f['imported'] ? $q->whereNotNull('program_id') : $q->whereNull('program_id'))
            ->when($like, fn ($q, $v) => $q->where(fn ($w) => $w->whereRaw("lower(title) like ? escape '!'", [$v])->orWhereRaw("lower(coalesce(description, '')) like ? escape '!'", [$v])))
            ->orderBy('title')->paginate($this->perPage($request));

        return response()->json($rows->through(fn (ExternalCourse $c) => $c->only(['id', 'provider', 'external_id', 'title', 'url', 'hours', 'level', 'language', 'image_url', 'status', 'program_id', 'synced_at']) + ['summary' => $c->description ? mb_substr($c->description, 0, 240) : null]));
    }

    public function runs(Request $request): JsonResponse
    {
        $f = $request->validate(['provider' => ['nullable', Rule::in(ExternalLearningService::PROVIDERS)]]);

        return response()->json(['data' => ExternalSyncRun::when($f['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))->latest('started_at')->limit(30)->get()]);
    }

    /** Completions a provider reported that TEDC could not use, with the reason. */
    public function issues(Request $request): JsonResponse
    {
        $f = $request->validate(['provider' => ['nullable', Rule::in(ExternalLearningService::PROVIDERS)], 'state' => ['nullable', Rule::in(['open', 'resolved', 'all'])]]);
        $state = $f['state'] ?? 'open';
        $rows = ExternalCompletionIssue::query()
            ->when($f['provider'] ?? null, fn ($q, $v) => $q->where('provider', $v))
            ->when($state === 'open', fn ($q) => $q->whereNull('resolved_at'))->when($state === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->orderByDesc('last_seen_at')->paginate($this->perPage($request));

        return response()->json($rows);
    }

    /** An administrator sets a problem aside (a person who is not meant to be in TEDC, say). */
    public function dismiss(ExternalCompletionIssue $issue): JsonResponse
    {
        if ($issue->resolved_at === null) {
            $issue->update(['resolved_at' => now(), 'resolution' => 'dismissed', 'resolved_by' => $this->user()->id]);
        }

        return response()->json(['data' => $issue->fresh()]);
    }

    // ---- internals ------------------------------------------------------------------------------------

    private function known(string $provider): void
    {
        abort_unless(in_array($provider, ExternalLearningService::PROVIDERS, true), 404);
    }

    /** @return array<string, mixed> */
    private function card(string $provider): array
    {
        $hub = $this->hub->present($provider);
        $missing = $hub['driver'] === 'fake' ? [] : $this->learning->missing($provider);
        $last = ExternalSyncRun::where('provider', $provider)->latest('started_at')->first();
        $issues = ExternalCompletionIssue::where('provider', $provider)->whereNull('resolved_at')->count();
        $courses = ExternalCourse::where('provider', $provider);

        $connection = match (true) {
            ! $hub['enabled'] => 'off',
            $hub['driver'] === 'fake' => 'training',
            $missing !== [] => 'not_set_up',
            $hub['circuit_open'] || $hub['health'] === 'down' => 'down',
            $hub['health'] === 'degraded' || in_array($last?->status, ['failed', 'partial'], true) => 'attention',
            default => 'connected',
        };

        return [
            'key' => $provider, 'name' => $hub['name'], 'driver' => $hub['driver'], 'drivers' => $hub['drivers'], 'enabled' => $hub['enabled'], 'connection' => $connection, 'missing' => $missing,
            'health' => $hub['health'], 'last_check_at' => $hub['last_check_at'], 'last_error' => $hub['last_error'], 'latency_ms' => $hub['latency_ms'],
            'fields' => collect($hub['fields'])->reject(fn ($f) => str_starts_with((string) $f['k'], 'fake_'))->values(), 'settings' => $hub['settings'], 'secrets_set' => $hub['secrets_set'],
            'last_run' => $last?->only(['status', 'trigger', 'started_at', 'finished_at', 'courses_seen', 'courses_added', 'completions_seen', 'completions_applied', 'issues_found', 'error']),
            'catalogue' => ['total' => (clone $courses)->where('status', '!=', 'removed')->count(), 'imported' => (clone $courses)->whereNotNull('program_id')->count(), 'retired' => (clone $courses)->where('status', 'retired')->count()],
            'open_issues' => $issues,
        ];
    }
}
