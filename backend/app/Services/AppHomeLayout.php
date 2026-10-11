<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;

/**
 * The order of the cards on the mobile app's home screen, set by the administrator (Settings → App home).
 * Only the arrangement is stored; every card still decides for itself whether it has anything to show.
 */
class AppHomeLayout
{
    public const KEY = 'app_home';

    /** The cards, in their default order. */
    public const SECTIONS = ['hours', 'todo', 'stats', 'scan', 'needs', 'programs', 'dashboard', 'sessions', 'recommended'];

    /** @return list<array{key: string, enabled: bool}> every card once, in the saved order */
    public function get(): array
    {
        $stored = Cache::remember('site.app_home', 60, fn () => SiteSetting::find(self::KEY)?->value['sections'] ?? []);

        return $this->normalise((array) $stored);
    }

    /** @param  list<array{key: string, enabled?: bool}>  $sections */
    public function save(array $sections, string $userId): array
    {
        $clean = $this->normalise($sections);
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => ['sections' => $clean], 'updated_by' => $userId]);
        Cache::forget('site.app_home');

        return $clean;
    }

    public function reset(): array
    {
        SiteSetting::where('key', self::KEY)->delete();
        Cache::forget('site.app_home');

        return $this->normalise([]);
    }

    /** Unknown keys are dropped, repeats ignored, and any card the list leaves out is added at the end (a card added later still shows). */
    private function normalise(array $sections): array
    {
        $out = [];
        foreach ($sections as $s) {
            $key = is_array($s) ? ($s['key'] ?? null) : null;
            if (in_array($key, self::SECTIONS, true) && ! isset($out[$key])) {
                $out[$key] = ['key' => $key, 'enabled' => (bool) ($s['enabled'] ?? true)];
            }
        }
        foreach (self::SECTIONS as $key) {
            $out[$key] ??= ['key' => $key, 'enabled' => true];
        }

        return array_values($out);
    }
}
