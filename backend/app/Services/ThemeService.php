<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Brand Studio — the visual identity of the web platform (colors, buttons, links, banners,
 * backgrounds and background pattern), editable by administrators and served publicly so the
 * website, dashboard and portal apply it at runtime through CSS variables.
 */
class ThemeService
{
    public const KEY = 'theme';

    private const CACHE = 'site.theme';

    public const PATTERNS = ['none', 'dots', 'grid', 'islamic_star', 'arabesque', 'diagonal', 'custom'];

    public static function defaults(): array
    {
        return [
            'preset' => 'royal_navy',
            'colors' => [
                'primary' => '#0B1F3A',
                'accent' => '#C8A24A',
                'background' => '#F8F6F1',
                'surface' => '#FFFFFF',
                'text' => '#0F172A',
                'link' => '#8F6F22',
            ],
            'buttons' => [
                'style' => 'gradient',     // gradient | solid | outline
                'radius' => 12,
                'accent_text' => '#06122A',
                'uppercase' => false,
            ],
            'banners' => [
                'overlay_color' => '#06122A',
                'overlay_opacity' => 70,
                'hero_images' => [null, null, null, null],
                'page_banner_image' => null,
                'cta_style' => 'gradient', // gradient | accent | image
            ],
            'pattern' => [
                'type' => 'dots',
                'color' => '#C8A24A',
                'opacity' => 18,
                'size' => 22,
                'image' => null,
            ],
            'shape' => [
                'card_radius' => 16,
                'glass_blur' => 20,
            ],
        ];
    }

    public function get(): array
    {
        return Cache::rememberForever(self::CACHE, function () {
            $stored = SiteSetting::find(self::KEY)?->value ?? [];

            return array_replace_recursive(self::defaults(), $stored);
        });
    }

    public function update(array $theme, User $user): array
    {
        $merged = array_replace_recursive(self::defaults(), Arr::only($theme, array_keys(self::defaults())));
        // Hero images is a positional list; replace rather than merge.
        $merged['banners']['hero_images'] = array_values(array_pad(array_slice($theme['banners']['hero_images'] ?? [], 0, 4), 4, null));

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $merged, 'updated_by' => $user->id]);
        $this->flush();

        return $this->get();
    }

    public function reset(User $user): array
    {
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => self::defaults(), 'updated_by' => $user->id]);
        $this->flush();

        return $this->get();
    }

    private function flush(): void
    {
        Cache::forget(self::CACHE);
        Cache::forget('public.home.ar');
        Cache::forget('public.home.en');
    }
}
