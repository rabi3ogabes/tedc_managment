<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * The template of the classroom screens (Settings → Room screen): which brand elements and details they show and how
 * they look. The logo, center name and colours come from Brand Studio; this only chooses how the screen uses them.
 */
class RoomScreenSettings
{
    public const KEY = 'room_screen';

    public const LAYOUTS = ['classic', 'spotlight', 'minimal'];

    public const THEMES = ['brand', 'midnight', 'custom'];

    public static function defaults(): array
    {
        return [
            'layout' => 'classic', 'theme' => 'brand', 'background' => '', 'accent' => '',
            'show_logo' => true, 'show_center_name' => true, 'show_clock' => true, 'show_trainer' => true, 'show_trainees' => true,
            'show_school' => true, 'show_progress' => true, 'show_attendance_ring' => true,
            'footer_ar' => '', 'footer_en' => '',
            // The screen's own background picture (independent of the brand pattern): repeated as a pattern or stretched over the screen.
            // Shown when nothing is running in the room: the logo and an invitation to book it.
            'idle_enabled' => true, 'idle_title_ar' => 'القاعة شاغرة الآن', 'idle_title_en' => 'This room is empty', 'idle_text_ar' => '', 'idle_text_en' => '', 'idle_show_next' => true,
            // Free-form layout made in the designer (null = use the template above).
            'design' => null,
            'bg_image' => '', 'bg_mode' => 'tile', 'bg_opacity' => 25, 'bg_size' => 120, 'bg_tint' => '',
        ];
    }

    public function all(): array
    {
        return Cache::remember('site.room_screen', 60, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        foreach (['idle_enabled', 'idle_show_next'] as $k) {
            $next[$k] = (bool) $next[$k];
        }
        foreach (['idle_title_ar', 'idle_title_en', 'idle_text_ar', 'idle_text_en'] as $k) {
            $next[$k] = mb_substr(trim((string) $next[$k]), 0, 160);
        }
        foreach (['show_logo', 'show_center_name', 'show_clock', 'show_trainer', 'show_trainees', 'show_school', 'show_progress', 'show_attendance_ring'] as $k) {
            $next[$k] = (bool) $next[$k];
        }
        $next['bg_mode'] = in_array($next['bg_mode'], ['tile', 'cover'], true) ? $next['bg_mode'] : 'tile';
        $next['bg_opacity'] = max(0, min(100, (int) $next['bg_opacity']));
        $next['bg_size'] = max(16, min(600, (int) $next['bg_size']));
        $next['bg_image'] = is_string($next['bg_image']) && preg_match('#^(https?://|/)#', $next['bg_image']) ? mb_substr($next['bg_image'], 0, 500) : '';
        $next['design'] = isset($input['design']) || array_key_exists('design', $input) ? $this->cleanDesign($input['design'] ?? null) : ($next['design'] ?? null);
        foreach (['background', 'accent', 'bg_tint'] as $k) {
            $next[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $next[$k]) ? strtolower($next[$k]) : '';
        }
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget('site.room_screen');

        return $this->all();
    }

    public const ELEMENT_TYPES = ['text', 'logo', 'clock', 'date', 'status', 'progress', 'ring', 'trainees', 'image', 'shape'];

    /** Keeps only well-formed elements with bounded numbers, safe colours and plain-text content. */
    public function cleanDesign(mixed $design): ?array
    {
        if (! is_array($design)) {
            return null;
        }
        $num = fn ($v, $min, $max, $def) => is_numeric($v) ? max($min, min($max, round((float) $v, 2))) : $def;
        $color = fn ($v, $def = '') => is_string($v) && (preg_match('/^#[0-9a-fA-F]{6,8}$/', $v) || $v === 'transparent') ? strtolower($v) : $def;
        $url = fn ($v) => is_string($v) && preg_match('#^(https?://|/)#', $v) ? mb_substr($v, 0, 500) : '';
        $elements = function ($list) use ($num, $color, $url) {
            $out = [];
            foreach (array_slice((array) $list, 0, 60) as $el) {
                if (! is_array($el) || ! in_array($el['type'] ?? '', self::ELEMENT_TYPES, true)) {
                    continue;
                }
                $out[] = [
                    'id' => preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($el['id'] ?? uniqid())) ?: uniqid(), 'type' => $el['type'],
                    'x' => $num($el['x'] ?? 0, -20, 120, 0), 'y' => $num($el['y'] ?? 0, -20, 120, 0), 'w' => $num($el['w'] ?? 20, 0.5, 140, 20), 'h' => $num($el['h'] ?? 10, 0.5, 140, 10),
                    'text' => mb_substr((string) ($el['text'] ?? ''), 0, 600), 'color' => $color($el['color'] ?? '', '#ffffff'), 'size' => $num($el['size'] ?? 40, 8, 400, 40),
                    'bold' => (bool) ($el['bold'] ?? false), 'align' => in_array($el['align'] ?? '', ['start', 'center', 'end'], true) ? $el['align'] : 'start',
                    'valign' => in_array($el['valign'] ?? '', ['start', 'center', 'end'], true) ? $el['valign'] : 'center',
                    'fill' => $color($el['fill'] ?? '', 'transparent'), 'fill2' => $color($el['fill2'] ?? '', ''), 'angle' => $num($el['angle'] ?? 135, 0, 360, 135), 'radius' => $num($el['radius'] ?? 0, 0, 200, 0),
                    'opacity' => $num($el['opacity'] ?? 100, 0, 100, 100), 'border' => $color($el['border'] ?? '', ''), 'border_width' => $num($el['border_width'] ?? 0, 0, 20, 0),
                    'src' => $url($el['src'] ?? ''), 'columns' => (int) $num($el['columns'] ?? 3, 1, 8, 3), 'show_school' => (bool) ($el['show_school'] ?? true),
                    'logo_dark' => (bool) ($el['logo_dark'] ?? true), 'accent' => $color($el['accent'] ?? '', ''),
                ];
            }

            return $out;
        };
        $bg = (array) ($design['background'] ?? []);

        return [
            'enabled' => (bool) ($design['enabled'] ?? false),
            'background' => [
                'type' => in_array($bg['type'] ?? '', ['brand', 'solid', 'gradient', 'image'], true) ? $bg['type'] : 'brand',
                'color' => $color($bg['color'] ?? '', '#8a1538'), 'color2' => $color($bg['color2'] ?? '', '#3a0918'), 'angle' => $num($bg['angle'] ?? 135, 0, 360, 135),
                'image' => $url($bg['image'] ?? ''), 'image_fit' => in_array($bg['image_fit'] ?? '', ['cover', 'tile'], true) ? $bg['image_fit'] : 'cover', 'image_size' => $num($bg['image_size'] ?? 160, 16, 800, 160),
                'image_opacity' => $num($bg['image_opacity'] ?? 100, 0, 100, 100), 'overlay' => $color($bg['overlay'] ?? '', '#000000'), 'overlay_opacity' => $num($bg['overlay_opacity'] ?? 0, 0, 95, 0),
            ],
            'live' => $elements($design['live'] ?? []),
            'idle' => $elements($design['idle'] ?? []),
        ];
    }
}
