<?php

namespace App\Services\Assessment;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/** How long exam-integrity data is kept, and the version of the consent wording learners agree to (EXM-10). */
class ProctoringSettings
{
    public const KEY = 'proctoring';

    private const CACHE = 'site.proctoring';

    public static function defaults(): array
    {
        // Snapshots, the event log and the timeline go after this many days; the attempt keeps its final flags.
        return ['integrity_retention_days' => 90, 'consent_version' => '2026-10'];
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function retentionDays(): int
    {
        return (int) $this->all()['integrity_retention_days'];
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        $next['integrity_retention_days'] = max(7, min(3650, (int) $next['integrity_retention_days']));
        $next['consent_version'] = mb_substr((string) $next['consent_version'], 0, 20);

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }
}
