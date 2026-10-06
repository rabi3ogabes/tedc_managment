<?php

namespace App\Gamification;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Settings → Gamification: where it is switched off (programs, roles) and what the leaderboards show. */
class GamificationSettings
{
    public const KEY = 'gamification';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return ['disabled_roles' => [], 'disabled_programs' => [], 'show_names' => true, 'leaderboard_size' => 20];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Cache::remember('site.gamification', 30, fn () => array_replace(self::defaults(), SiteSetting::find(self::KEY)?->value ?? []));
    }

    /** @param  array<string, mixed>  $d @return array<string, mixed> */
    public function save(array $d, ?string $by = null): array
    {
        $cur = $this->all();
        $list = fn ($v) => array_values(array_unique(array_filter(array_map('strval', (array) $v))));
        $new = [
            'disabled_roles' => array_key_exists('disabled_roles', $d) ? $list($d['disabled_roles']) : $cur['disabled_roles'],
            'disabled_programs' => array_key_exists('disabled_programs', $d) ? array_values(array_filter($list($d['disabled_programs']), fn ($x) => Str::isUuid($x))) : $cur['disabled_programs'],
            'show_names' => (bool) ($d['show_names'] ?? $cur['show_names']),
            'leaderboard_size' => max(5, min(100, (int) ($d['leaderboard_size'] ?? $cur['leaderboard_size']))),
        ];
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $new, 'updated_by' => $by]);
        Cache::forget('site.gamification');

        return $new;
    }
}
