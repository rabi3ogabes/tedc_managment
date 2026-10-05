<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Feature flags: defaults from config/features.php (safer in production), overridden from Settings → Features.
 * Every change is audited with who, why and the old and new value.
 */
class FeatureSettings
{
    public const KEY = 'features';

    private const CACHE = 'site.features';

    public function production(): bool
    {
        return app()->environment('production');
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return (array) config('features.flags', []);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    public function defaultFor(string $key): bool
    {
        $d = $this->definitions()[$key] ?? null;
        if (! $d) {
            return false;
        }

        return (bool) ($this->production() ? ($d['production'] ?? $d['default'] ?? false) : ($d['default'] ?? false));
    }

    /** @return array<string, array{enabled: bool, reason: ?string, by: ?string, at: ?string}> */
    public function overrides(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => (array) (SiteSetting::find(self::KEY)?->value ?? []));
    }

    public function enabled(string $key): bool
    {
        if (! $this->exists($key)) {
            return false;
        }
        $override = $this->overrides()[$key] ?? null;

        return $override !== null ? (bool) $override['enabled'] : $this->defaultFor($key);
    }

    /** Tools that are risky on a live system and are switched on in production (the banner lists them). @return list<string> */
    public function unsafeActive(): array
    {
        if (! $this->production()) {
            return [];
        }

        return array_values(array_filter(array_keys($this->definitions()), fn ($k) => ($this->definitions()[$k]['unsafe'] ?? false) && $this->enabled($k)));
    }

    /** @return array<string, bool> */
    public function map(): array
    {
        return collect($this->definitions())->keys()->mapWithKeys(fn ($k) => [$k => $this->enabled($k)])->all();
    }

    /** What the administrator screen shows. @return list<array<string, mixed>> */
    public function describe(): array
    {
        $history = AuditLog::where('action', 'feature_toggled')->latest('created_at')->limit(300)->with('user:id,name,name_ar')->get()->groupBy(fn ($l) => $l->new_values['key'] ?? '');

        return collect($this->definitions())->map(function (array $d, string $key) use ($history) {
            $override = $this->overrides()[$key] ?? null;

            return [
                'key' => $key, 'phase' => $d['phase'], 'unsafe' => (bool) ($d['unsafe'] ?? false), 'title' => $d['title'], 'description' => $d['description'],
                'enabled' => $this->enabled($key), 'default' => $this->defaultFor($key), 'source' => $override !== null ? 'override' : 'default',
                'reason' => $override['reason'] ?? null,
                'history' => collect($history[$key] ?? [])->take(5)->map(fn ($l) => ['enabled' => (bool) $l->new_values['enabled'], 'reason' => $l->new_values['reason'] ?? null, 'by' => $l->user?->displayName(), 'at' => $l->created_at->toIso8601String()])->values()->all(),
            ];
        })->values()->all();
    }

    /** @throws BusinessRuleException when a production-unsafe tool is switched on without a reason */
    public function set(string $key, bool $enabled, ?string $reason, User $by, ?Request $request = null): void
    {
        abort_unless($this->exists($key), 404);
        $unsafe = (bool) ($this->definitions()[$key]['unsafe'] ?? false);
        $reason = $reason !== null ? trim($reason) : null;

        if ($enabled && $unsafe && $this->production() && mb_strlen((string) $reason) < 5) {
            throw new BusinessRuleException(__('messages.features.reason_required'), 'feature_reason_required');
        }
        if ($unsafe && $this->production() && ! $by->hasPermission('users.manage')) {
            abort(403, __('auth.forbidden'));
        }

        $before = $this->enabled($key);
        $all = $this->overrides();
        $all[$key] = ['enabled' => $enabled, 'reason' => $reason, 'by' => $by->id, 'at' => now()->toIso8601String()];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $all, 'updated_by' => $by->id]);
        $this->flush();

        AuditLog::create([
            'user_id' => $by->id, 'action' => 'feature_toggled', 'auditable_type' => SiteSetting::class, 'auditable_id' => null,
            'old_values' => ['key' => $key, 'enabled' => $before], 'new_values' => ['key' => $key, 'enabled' => $enabled, 'reason' => $reason, 'unsafe' => $unsafe, 'environment' => app()->environment()],
            'ip_address' => $request?->ip(), 'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null, 'url' => $request?->fullUrl(),
        ]);
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE);
    }
}
