<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/** Administrator-managed attendance rules (Settings → Attendance): the location check around the venue. */
class AttendanceSettings
{
    public const KEY = 'attendance';

    private const CACHE = 'site.attendance';

    public static function defaults(): array
    {
        return [
            'geofence_enabled' => (bool) config('tedc.attendance.geofence.enabled'),
            'radius_m' => (int) config('tedc.attendance.geofence.radius_m'),
            'max_accuracy_m' => (int) config('tedc.attendance.geofence.max_accuracy_m'),
        ];
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        $next['geofence_enabled'] = (bool) $next['geofence_enabled'];
        $next['radius_m'] = (int) $next['radius_m'];
        $next['max_accuracy_m'] = (int) $next['max_accuracy_m'];

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }
}
