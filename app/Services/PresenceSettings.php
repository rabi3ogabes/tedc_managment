<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/** Live presence switches: the whole feature and whether locations are collected. */
class PresenceSettings
{
    public const KEY = 'presence';

    public static function defaults(): array
    {
        return ['enabled' => true, 'locations' => true];
    }

    public function all(): array
    {
        return Cache::remember('site.presence', 30, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function enabled(): bool
    {
        return (bool) $this->all()['enabled'];
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        $next = array_map(fn ($v) => (bool) $v, $next);
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget('site.presence');

        return $this->all();
    }
}
