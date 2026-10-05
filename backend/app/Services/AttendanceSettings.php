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
            // Phase 05: windows (null = no limit), absence alerts and the excuse / leave policy.
            'checkin_window_minutes' => null,
            'checkout_window_minutes' => null,
            'manual_window_minutes' => null,
            'absence_warning_percent' => 10,
            'absence_breach_percent' => null,
            'excuse_counts_as_attended' => false,
            'leave_notifies_trainee' => true,
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
        foreach (['checkin_window_minutes', 'checkout_window_minutes', 'manual_window_minutes', 'absence_breach_percent'] as $k) {
            $next[$k] = isset($next[$k]) && $next[$k] !== '' && (int) $next[$k] > 0 ? (int) $next[$k] : null;
        }
        $next['absence_warning_percent'] = max(1, min(100, (int) $next['absence_warning_percent']));
        $next['excuse_counts_as_attended'] = (bool) $next['excuse_counts_as_attended'];
        $next['leave_notifies_trainee'] = (bool) $next['leave_notifies_trainee'];

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }
}
