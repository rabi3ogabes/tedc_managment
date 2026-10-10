<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Brand Studio — the visual identity of the web platform (colors, buttons, links, banners,
 * backgrounds and background pattern), editable by administrators and served publicly so the
 * website, dashboard and portal apply it at runtime through CSS variables.
 */
class ThemeService
{
    public const KEY = 'theme';

    private const CACHE = 'site.theme';

    /** How the app's navigation bar looks; `classic` is the standard bar. */
    public const NAV_STYLES = ['classic', 'floating', 'center_fab', 'neumorphism', 'glass', 'outline'];

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
                // An uploaded pattern can be recoloured with one colour (null keeps its own) and tiled or stretched over the background.
                'tint' => null,
                'repeat' => 'tile',
            ],
            'shape' => [
                'card_radius' => 14,
                'glass_blur' => 18,
            ],
            // Official logos (e.g. the Ministry of Education and Higher Education). Empty values fall back
            // to the files in web/public/brand/ and then to the built-in mark.
            'identity' => [
                // Center name shown across the platform, on certificates and in e-mails.
                'name_ar' => config('tedc.name.ar'),
                'name_en' => config('tedc.name.en'),
                'logo_ar' => null,
                'logo_en' => null,
                'logo_ar_light' => null,
                'logo_en_light' => null,
                'show_center_name' => true,
            ],
            // The page shown while the application loads. Empty colours follow the theme.
            'loading' => [
                'style' => 'emblem',       // emblem | bar | dots | pulse | crescent
                'message_ar' => 'جارٍ التحميل…',
                'message_en' => 'Loading…',
                'background' => null,
                'accent' => null,
                'show_name' => true,
            ],
            // The look of the mobile app's navigation bar (one of NAV_STYLES), shown on every screen of the app.
            'navigation' => ['style' => 'classic'],
            // Occasions are a list of scheduled looks: stored as given, never merged item by item (see get()).
            'occasions' => [],
            'typography' => [
                'arabic_family' => 'Qatar Sans',
                'latin_family' => 'Qatar Sans',
                'arabic_font_url' => null,
                'latin_font_url' => null,
                'heading_weight' => 700,
            ],
        ];
    }

    /** What the platform shows today: the saved theme, with the occasion that is on (if any) laid over it. */
    public function get(?Carbon $on = null): array
    {
        $base = $this->base();
        $active = $this->activeOccasion($base, $on ?? now());
        if (! $active) {
            return $base + ['active_occasion' => null];
        }
        $theme = $base;
        foreach (['colors', 'buttons', 'banners', 'pattern', 'shape', 'loading', 'typography'] as $section) {
            if (isset($active['patch'][$section]) && is_array($active['patch'][$section])) {
                $theme[$section] = array_replace($theme[$section] ?? [], $active['patch'][$section]);
            }
        }
        $theme['preset'] = 'occasion:'.$active['id'];

        return $theme + ['active_occasion' => ['id' => $active['id'], 'name_ar' => $active['name_ar'], 'name_en' => $active['name_en']]];
    }

    /** The saved theme itself, which the Brand Studio edits (an occasion never writes into it). */
    public function base(): array
    {
        // A short TTL keeps per-instance caches (APCu on serverless) in sync after an administrator's change.
        return Cache::remember(self::CACHE, 60, function () {
            $stored = SiteSetting::find(self::KEY)?->value ?? [];
            $merged = array_replace_recursive(self::defaults(), $stored);
            $merged['occasions'] = array_values($stored['occasions'] ?? []);   // a list: merging by position would mix two occasions

            return $merged;
        });
    }

    /** The occasion in force on a day: the shortest period wins when several overlap. @return array<string, mixed>|null */
    public function activeOccasion(array $base, Carbon $day): ?array
    {
        $hits = array_values(array_filter($base['occasions'] ?? [], fn ($o) => ThemeOccasions::covers($o, $day)));
        usort($hits, fn ($a, $b) => strcmp((string) $b['starts_on'], (string) $a['starts_on']));

        return $hits[0] ?? null;
    }

    /** Adds the standard occasions of a year that are not in the list yet; the administrator's own entries and edits stay. */
    public function addStandardOccasions(int $year, User $user): array
    {
        $base = $this->base();
        $have = collect($base['occasions'])->pluck('id')->all();
        $new = array_values(array_filter(ThemeOccasions::standard($year), fn ($o) => ! in_array($o['id'], $have, true)));

        return $this->update(['occasions' => array_merge($base['occasions'], $new)] + $base, $user);
    }

    /** The center name administrators set in the settings page. @return array{ar: string, en: string} */
    public function centerName(): array
    {
        $identity = $this->get()['identity'];

        return [
            'ar' => trim((string) ($identity['name_ar'] ?? '')) ?: config('tedc.name.ar'),
            'en' => trim((string) ($identity['name_en'] ?? '')) ?: config('tedc.name.en'),
        ];
    }

    public function update(array $theme, User $user): array
    {
        $merged = array_replace_recursive(self::defaults(), Arr::only($theme, array_keys(self::defaults())));
        // A cleared name falls back to the default rather than leaving the platform without one.
        foreach (['name_ar', 'name_en'] as $key) {
            $merged['identity'][$key] = trim((string) ($merged['identity'][$key] ?? '')) ?: self::defaults()['identity'][$key];
        }
        $merged['occasions'] = $this->cleanOccasions($theme['occasions'] ?? $this->base()['occasions']);
        // Hero images is a positional list; replace rather than merge.
        $merged['banners']['hero_images'] = array_values(array_pad(array_slice($theme['banners']['hero_images'] ?? [], 0, 4), 4, null));

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $merged, 'updated_by' => $user->id]);
        $this->flush();

        return $this->get();
    }

    /** @param  array<int, mixed>  $list @return list<array<string, mixed>> */
    private function cleanOccasions(array $list): array
    {
        $sections = ['colors', 'buttons', 'banners', 'pattern', 'shape', 'loading', 'typography'];
        $out = [];
        foreach (array_slice($list, 0, 60) as $o) {
            if (! is_array($o) || empty($o['id'])) {
                continue;
            }
            $out[] = [
                'id' => Str::limit(preg_replace('/[^a-z0-9_-]/i', '', (string) $o['id']) ?: Str::random(6), 60, ''), 'occasion' => $o['occasion'] ?? null,
                'name_ar' => Str::limit(strip_tags((string) ($o['name_ar'] ?? '')), 120, ''), 'name_en' => Str::limit(strip_tags((string) ($o['name_en'] ?? '')), 120, ''),
                'enabled' => (bool) ($o['enabled'] ?? false), 'recurring' => (bool) ($o['recurring'] ?? false),
                'starts_on' => $o['starts_on'] ?? null, 'ends_on' => $o['ends_on'] ?? null,
                'patch' => Arr::only((array) ($o['patch'] ?? []), $sections),
            ];
        }

        return $out;
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
