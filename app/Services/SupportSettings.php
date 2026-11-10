<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The centre's support contacts and service levels (UTR-03): the phone, e-mail, Saaed link, working hours in Arabic and English and the
 * response and resolution times by priority. They are entered in Settings → Help & support and shown in the help centre, the app and the
 * e-mail footers. The environment variables (TEDC_SUPPORT_*) are only the defaults: once the centre has saved a value, even an emptied one,
 * the saved value is what counts.
 */
class SupportSettings
{
    public const KEY = 'support';

    private const CACHE = 'site.support';

    public const PRIORITIES = ['P1', 'P2', 'P3', 'P4'];

    /** @return list<array{priority: string, response_ar: string, response_en: string, resolution_ar: string, resolution_en: string}> */
    public static function standardLevels(): array
    {
        return [
            ['priority' => 'P1', 'response_ar' => '15 دقيقة', 'response_en' => '15 minutes', 'resolution_ar' => 'ساعتان', 'resolution_en' => '2 hours'],
            ['priority' => 'P2', 'response_ar' => '30 دقيقة', 'response_en' => '30 minutes', 'resolution_ar' => '4 ساعات', 'resolution_en' => '4 hours'],
            ['priority' => 'P3', 'response_ar' => 'ساعتان', 'response_en' => '2 hours', 'resolution_ar' => 'يوم عمل', 'resolution_en' => '1 business day'],
            ['priority' => 'P4', 'response_ar' => '4 ساعات', 'response_en' => '4 hours', 'resolution_ar' => 'يوما عمل', 'resolution_en' => '2 business days'],
        ];
    }

    /** What applies until the centre saves its own: the environment, and the standard service levels. @return array<string, mixed> */
    public function defaults(): array
    {
        $env = (array) config('tedc.support', []);

        return [
            'phone' => (string) ($env['phone'] ?? ''), 'email' => (string) ($env['email'] ?? ''), 'saaed_url' => (string) ($env['saaed_url'] ?? ''),
            'hours_ar' => (string) ($env['hours_ar'] ?? 'الأحد – الخميس، 7:00 ص – 3:00 م'), 'hours_en' => (string) ($env['hours_en'] ?? 'Sunday – Thursday, 7:00 – 15:00'),
            'sla' => self::standardLevels(),
        ];
    }

    /** @return array<string, mixed> the values in force (the saved ones over the defaults) */
    public function all(): array
    {
        $saved = Cache::remember(self::CACHE, 60, fn () => SiteSetting::find(self::KEY)?->value ?? []);

        return array_replace($this->defaults(), array_intersect_key((array) $saved, $this->defaults()));
    }

    public function saved(): bool
    {
        return SiteSetting::find(self::KEY) !== null;
    }

    /**
     * Saves validated values (the keys given replace the ones in force) and records who changed what.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(array $input, User $by, ?string $ip = null): array
    {
        $before = $this->all();
        $next = array_replace($before, array_intersect_key($input, $this->defaults()));
        if (isset($input['sla'])) {
            $byPriority = collect($input['sla'])->keyBy('priority');
            $next['sla'] = array_map(fn ($p) => array_intersect_key($byPriority[$p], array_flip(['priority', 'response_ar', 'response_en', 'resolution_ar', 'resolution_en'])), self::PRIORITIES);
        }
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by->id]);
        Cache::forget(self::CACHE);
        AuditLog::create(['user_id' => $by->id, 'action' => 'settings.support.updated', 'auditable_type' => SiteSetting::class, 'auditable_id' => null, 'old_values' => $before, 'new_values' => $next, 'ip_address' => $ip]);

        return $next;
    }
}
