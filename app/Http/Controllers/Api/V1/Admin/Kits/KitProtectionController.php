<?php

namespace App\Http\Controllers\Api\V1\Admin\Kits;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KitAccessEvent;
use App\Models\TrainingKit;
use App\Services\Kits\KitAccess;
use App\Services\Teaching\KitProtection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Protection of the training kits in the trainer's reader and presenter: the switches, and who opened, presented or tried to capture what. */
class KitProtectionController extends Controller
{
    public function __construct(private readonly KitProtection $protection) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless(KitAccess::isStaff($this->user()), 403, __('auth.forbidden'));
        $data = $request->validate(['kind' => ['nullable', Rule::in(KitAccessEvent::KINDS)], 'kit' => ['nullable', 'uuid'], 'trainer' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'min:5', 'max:100']]);
        $q = KitAccessEvent::with(['user:id,name,name_ar', 'kit:id,code,title_ar,title_en', 'file:id,name'])->latest('created_at')
            ->when($data['kind'] ?? null, fn ($b, $k) => $b->where('kind', $k))->when($data['kit'] ?? null, fn ($b, $k) => $b->where('kit_id', $k))->when($data['trainer'] ?? null, fn ($b, $k) => $b->where('user_id', $k));
        $page = $q->paginate((int) ($data['per_page'] ?? 25));
        $since = now()->subDays(30);

        return response()->json([
            'settings' => $this->protection->all(),
            'stats' => ['opened' => KitAccessEvent::where('kind', 'opened')->where('created_at', '>=', $since)->count(), 'presented' => KitAccessEvent::where('kind', 'presented')->where('created_at', '>=', $since)->count(),
                'alerts' => KitAccessEvent::whereIn('kind', KitAccessEvent::ALERTS)->where('created_at', '>=', $since)->count(), 'trainers' => KitAccessEvent::where('created_at', '>=', $since)->distinct('user_id')->count('user_id')],
            'data' => collect($page->items())->map(fn (KitAccessEvent $e) => $this->row($e))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(KitAccess::isStaff($this->user()) && $this->user()->hasPermission('kits.manage'), 403, __('auth.forbidden'));
        $data = $request->validate(array_fill_keys(array_keys(KitProtection::DEFAULTS), ['sometimes']) + ['notify_window_minutes' => ['sometimes', 'integer', 'min:1', 'max:1440']]);
        $before = $this->protection->all();
        $next = $this->protection->update($data, $this->user());
        AuditLog::create(['user_id' => $this->user()->id, 'action' => 'kit_protection_updated', 'auditable_type' => TrainingKit::class, 'auditable_id' => null, 'old_values' => $before, 'new_values' => $next]);

        return response()->json(['data' => $next]);
    }

    public function events(TrainingKit $kit): JsonResponse
    {
        abort_unless(KitAccess::canView($this->user(), $kit), 403, __('auth.forbidden'));
        $rows = KitAccessEvent::with(['user:id,name,name_ar', 'kit:id,code,title_ar,title_en', 'file:id,name'])->where('kit_id', $kit->id)->latest('created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (KitAccessEvent $e) => $this->row($e))->values()]);
    }

    /** @return array<string, mixed> */
    private function row(KitAccessEvent $e): array
    {
        return ['id' => $e->id, 'kind' => $e->kind, 'at' => $e->created_at?->toIso8601String(), 'trainer' => $e->user ? ['id' => $e->user->id, 'name' => $e->user->displayName(app()->getLocale())] : null,
            'kit' => $e->kit ? ['id' => $e->kit->id, 'code' => $e->kit->code, 'title' => app()->getLocale() === 'ar' ? $e->kit->title_ar : ($e->kit->title_en ?: $e->kit->title_ar)] : null, 'file' => $e->file?->name, 'meta' => $e->meta, 'ip' => $e->ip, 'agent' => $e->user_agent];
    }
}
