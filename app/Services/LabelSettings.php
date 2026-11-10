<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Names of menus, buttons and other interface texts, editable by administrators (Settings → Labels).
 * Stored as overrides of the built-in translations: {ar: {"admin.menu.programs": "برامج"}, en: {...}}.
 */
class LabelSettings
{
    public const KEY = 'labels';

    private const CACHE = 'site.labels';

    public const MAX_ENTRIES = 4000;

    /** @return array{ar: array<string, string>, en: array<string, string>} */
    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, function () {
            $stored = SiteSetting::find(self::KEY)?->value ?? [];

            return ['ar' => $this->clean($stored['ar'] ?? []), 'en' => $this->clean($stored['en'] ?? [])];
        });
    }

    /**
     * @param  array{ar?: array<string, ?string>, en?: array<string, ?string>}  $patch  null / empty value removes the override
     * @return array{ar: array<string, string>, en: array<string, string>}
     */
    public function update(array $patch, ?User $by = null, bool $replace = false): array
    {
        $next = $replace ? ['ar' => [], 'en' => []] : $this->all();
        foreach (['ar', 'en'] as $lang) {
            foreach ((array) ($patch[$lang] ?? []) as $key => $value) {
                $value = is_string($value) ? trim(strip_tags($value)) : '';
                if ($value === '') {
                    unset($next[$lang][$key]);
                } elseif (preg_match('/^[A-Za-z0-9_.\-]{1,120}$/', (string) $key)) {
                    $next[$lang][$key] = mb_substr($value, 0, 200);
                }
            }
            $next[$lang] = array_slice($next[$lang], 0, self::MAX_ENTRIES, true);
        }

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);

        return $this->all();
    }

    public function version(): string
    {
        return substr(md5(json_encode($this->all())), 0, 10);
    }

    private function clean(array $labels): array
    {
        return collect($labels)->filter(fn ($v, $k) => is_string($v) && $v !== '' && preg_match('/^[A-Za-z0-9_.\-]{1,120}$/', (string) $k))->all();
    }
}
