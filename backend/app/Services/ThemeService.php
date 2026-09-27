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

    public const PATTERNS = ['none', 'serrated', 'dots', 'grid', 'islamic_star', 'arabesque', 'diagonal', 'custom'];

    /**
     * Default identity: the Qatar Government brand (Government Communications Office guidelines) —
     * Al Adaam maroon (flag, Pantone 1955 C), Dune (Qatari architecture), black and white, with the
     * Qatar Sans typeface — co-branded with the Ministry of Education and Higher Education logo.
     */
    public static function defaults(): array
    {
        return [
            'preset' => 'qatar_gov',
            'colors' => [
                'primary' => '#8A1538',    // Al Adaam
                'accent' => '#A29475',     // Dune
                'background' => '#F8F6F2',
                'surface' => '#FFFFFF',
                'text' => '#1A1A1A',       // Black
                'link' => '#8A1538',
            ],
            'buttons' => [
                'style' => 'solid',        // gradient | solid | outline
                'radius' => 10,
                'accent_text' => '#1A1A1A',
                'uppercase' => false,
            ],
            'banners' => [
                'overlay_color' => '#3A0918',
                'overlay_opacity' => 72,
                'hero_images' => [null, null, null, null],
                'page_banner_image' => null,
                'cta_style' => 'gradient', // gradient | accent | image
            ],
            'pattern' => [
                'type' => 'serrated',
                'color' => '#A29475',
                'opacity' => 16,
                'size' => 40,
                'image' => null,
            ],
            'shape' => [
                'card_radius' => 14,
                'glass_blur' => 18,
            ],
            // Official logos (e.g. the Ministry of Education and Higher Education). Empty values fall back
            // to the files in web/public/brand/ and then to the built-in mark.
            'identity' => [
                'logo_ar' => null,
                'logo_en' => null,
                'logo_ar_light' => null,
                'logo_en_light' => null,
                'show_center_name' => true,
            ],
            'typography' => [
                'arabic_family' => 'Qatar Sans',
                'latin_family' => 'Qatar Sans',
                'arabic_font_url' => null,
                'latin_font_url' => null,
                'heading_weight' => 700,
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
        Cache::forget('certificate.theme');
    }
}
