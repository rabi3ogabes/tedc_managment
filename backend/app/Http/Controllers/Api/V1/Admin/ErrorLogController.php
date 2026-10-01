<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErrorLog;
use App\Services\ErrorAutoFixer;
use App\Services\ErrorLogService;
use App\Services\ErrorLogSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The error log, for the system administrator: review, fix (by hand or automatically) and delete. */
class ErrorLogController extends Controller
{
    public function __construct(private readonly ErrorLogService $logs, private readonly ErrorAutoFixer $fixer, private readonly ErrorLogSettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'source' => ['nullable', Rule::in(['server', 'web', 'app'])], 'level' => ['nullable', Rule::in(['warning', 'error', 'critical'])], 'status' => ['nullable', Rule::in(['open', 'fixed', 'ignored'])],
            'q' => ['nullable', 'string', 'max:120'], 'days' => ['nullable', 'integer', 'between:1,365'], 'sort' => ['nullable', Rule::in(['recent', 'frequent', 'users'])], 'per_page' => ['nullable', 'integer', 'between:5,100'],
        ]);

        $query = ErrorLog::query()
            ->when($f['source'] ?? null, fn ($q, $v) => $q->where('source', $v))->when($f['level'] ?? null, fn ($q, $v) => $q->where('level', $v))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['days'] ?? null, fn ($q, $v) => $q->where('last_seen_at', '>=', now()->subDays($v)))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('message', 'like', "%{$v}%")->orWhere('location', 'like', "%{$v}%")->orWhere('user_email', 'like', "%{$v}%")->orWhere('url', 'like', "%{$v}%")));
        match ($f['sort'] ?? 'recent') {
            'frequent' => $query->orderByDesc('occurrences'), 'users' => $query->orderByDesc('users_count'), default => $query->orderByDesc('last_seen_at'),
        };

        $page = $query->paginate($f['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (ErrorLog $l) => $this->present($l, false))->values(),
            'total' => $page->total(), 'last_page' => $page->lastPage(), 'current_page' => $page->currentPage(),
            'stats' => $this->stats(),
        ]);
    }

    public function show(ErrorLog $log): JsonResponse
    {
        return response()->json(['data' => $this->present($log, true)]);
    }

    public function update(Request $request, ErrorLog $log): JsonResponse
    {
        $data = $request->validate(['status' => ['sometimes', Rule::in(['open', 'fixed', 'ignored'])], 'note' => ['nullable', 'string', 'max:2000']]);
        if (isset($data['status'])) {
            $data += ['resolved_at' => $data['status'] === 'open' ? null : now(), 'resolved_by' => $data['status'] === 'open' ? null : $request->user()->id, 'auto_fixed' => false];
        }
        $log->update($data);

        return response()->json(['data' => $this->present($log->refresh(), true)]);
    }

    /** Tries the automatic remedy now. */
    public function fix(ErrorLog $log): JsonResponse
    {
        $result = $this->logs->tryFix($log);

        return response()->json(['data' => $this->present($log->refresh(), true), 'result' => $result]);
    }

    public function destroy(ErrorLog $log): JsonResponse
    {
        $log->delete();

        return response()->json(['message' => 'ok']);
    }

    /** resolve | reopen | ignore | delete for the selected entries, or every entry of a status. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['fix', 'reopen', 'ignore', 'delete'])],
            'ids' => ['nullable', 'array', 'max:500'], 'ids.*' => ['uuid'], 'status' => ['nullable', Rule::in(['open', 'fixed', 'ignored'])],
        ]);
        abort_if(empty($data['ids']) && empty($data['status']), 422);

        $query = ErrorLog::query()->when($data['ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))->when(empty($data['ids']) ? ($data['status'] ?? null) : null, fn ($q, $s) => $q->where('status', $s));
        $count = (clone $query)->count();

        match ($data['action']) {
            'delete' => $query->delete(),
            'fix' => $query->update(['status' => 'fixed', 'resolved_at' => now(), 'resolved_by' => $request->user()->id]),
            'ignore' => $query->update(['status' => 'ignored', 'resolved_at' => now(), 'resolved_by' => $request->user()->id]),
            'reopen' => $query->update(['status' => 'open', 'resolved_at' => null, 'auto_fixed' => false]),
        };

        return response()->json(['data' => ['affected' => $count], 'stats' => $this->stats()]);
    }

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['sometimes', 'boolean'], 'auto_fix' => ['sometimes', 'boolean'], 'capture_clients' => ['sometimes', 'boolean'], 'retention_days' => ['sometimes', 'integer', 'between:7,365']]);

        return response()->json(['data' => $this->settings->update($data, $request->user())]);
    }

    /** Open errors for the sidebar badge. */
    public function badge(): JsonResponse
    {
        return response()->json(['data' => ['open' => ErrorLog::where('status', 'open')->count(), 'critical' => ErrorLog::where('status', 'open')->where('level', 'critical')->count()]]);
    }

    // -----------------------------------------------------------------------------------------------------------

    private function stats(): array
    {
        $open = ErrorLog::where('status', 'open');

        return [
            'open' => (clone $open)->count(), 'critical' => (clone $open)->where('level', 'critical')->count(), 'today' => ErrorLog::where('last_seen_at', '>=', now()->startOfDay())->count(),
            'auto_fixed' => ErrorLog::where('auto_fixed', true)->count(), 'fixed' => ErrorLog::where('status', 'fixed')->count(), 'total' => ErrorLog::count(),
            'by_source' => ErrorLog::where('status', 'open')->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source'),
            // Errors seen per day for the last two weeks.
            'trend' => collect(range(13, 0))->map(fn ($d) => ['date' => now()->subDays($d)->toDateString(), 'count' => (int) ErrorLog::whereBetween('last_seen_at', [now()->subDays($d)->startOfDay(), now()->subDays($d)->endOfDay()])->count()])->values(),
        ];
    }

    private function present(ErrorLog $l, bool $full): array
    {
        $rule = $this->fixer->ruleFor($l);

        return [
            'id' => $l->id, 'source' => $l->source, 'level' => $l->level, 'message' => $l->message, 'exception' => $l->exception, 'location' => $l->location,
            'status_code' => $l->status_code, 'url' => $l->url, 'method' => $l->method, 'occurrences' => $l->occurrences, 'users_count' => $l->users_count,
            'user_email' => $l->user_email, 'app_version' => $l->app_version, 'status' => $l->status, 'auto_fixed' => $l->auto_fixed, 'note' => $l->note,
            'first_seen_at' => $l->first_seen_at?->toIso8601String(), 'last_seen_at' => $l->last_seen_at?->toIso8601String(), 'resolved_at' => $l->resolved_at?->toIso8601String(),
            'fixable' => $rule !== null && $rule !== 'transient', 'fix_label' => $rule ? $this->fixer->label($rule) : null, 'fix_attempts' => $l->fix_attempts,
        ] + ($full ? ['stack' => $l->stack, 'device' => $l->device, 'context' => $l->context, 'user_agent' => $l->user_agent, 'ip' => $l->ip, 'user_id' => $l->user_id] : []);
    }
}
