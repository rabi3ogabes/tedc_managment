<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/** Settings of the error log: what is captured, whether known problems are fixed automatically, how long entries are kept. */
class ErrorLogSettings
{
    public const KEY = 'error_log';

    public static function defaults(): array
    {
        return ['enabled' => true, 'auto_fix' => true, 'capture_clients' => true, 'retention_days' => 90];
    }

    public function all(): array
    {
        return Cache::remember('site.error_log', 60, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        foreach (['enabled', 'auto_fix', 'capture_clients'] as $k) {
            $next[$k] = (bool) $next[$k];
        }
        $next['retention_days'] = max(7, min(365, (int) $next['retention_days']));
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget('site.error_log');

        return $this->all();
    }
}
