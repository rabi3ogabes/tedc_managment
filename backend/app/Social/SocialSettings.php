<?php

namespace App\Social;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

/** Settings → Collaboration: service-level target for trainer questions, digest, and who may create communities. */
class SocialSettings
{
    public const KEY = 'social';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['trainer_sla_hours' => 48, 'daily_digest' => true, 'digest_hour' => 7, 'rating_reviews' => true];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember('site.social', 30, fn () => array_replace(self::defaults(), SiteSetting::find(self::KEY)?->value ?? []));
    }

    /** @param  array<string, mixed>  $d @return array<string, mixed> */
    public function save(array $d, ?string $by = null): array
    {
        $cur = $this->all();
        $new = [
            'trainer_sla_hours' => max(1, min(720, (int) ($d['trainer_sla_hours'] ?? $cur['trainer_sla_hours']))),
            'daily_digest' => (bool) ($d['daily_digest'] ?? $cur['daily_digest']),
            'digest_hour' => max(0, min(23, (int) ($d['digest_hour'] ?? $cur['digest_hour']))),
            'rating_reviews' => (bool) ($d['rating_reviews'] ?? $cur['rating_reviews']),
        ];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $new, 'updated_by' => $by]);
        Cache::forget('site.social');

        return $new;
    }
}
