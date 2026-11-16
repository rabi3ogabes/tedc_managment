<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/** Administrator-managed security rules (Settings → Security): the idle lock of the administration team. */
class SecuritySettings
{
    public const KEY = 'security';

    private const CACHE = 'site.security';

    public static function defaults(): array
    {
        return ['idle_lock_enabled' => true, 'idle_lock_minutes' => 10];
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        $next['idle_lock_enabled'] = (bool) $next['idle_lock_enabled'];
        $next['idle_lock_minutes'] = max(1, min(240, (int) $next['idle_lock_minutes']));

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }

    public function lockEnabled(): bool
    {
        return $this->all()['idle_lock_enabled'];
    }

    public function lockSeconds(): int
    {
        return $this->all()['idle_lock_minutes'] * 60;
    }
}
