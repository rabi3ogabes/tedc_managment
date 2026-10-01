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
        ];
    }

    public function all(): array
    {
        return Cache::remember('site.room_screen', 60, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, array_keys(self::defaults())));
        foreach (['show_logo', 'show_center_name', 'show_clock', 'show_trainer', 'show_trainees', 'show_school', 'show_progress', 'show_attendance_ring'] as $k) {
            $next[$k] = (bool) $next[$k];
        }
        foreach (['background', 'accent'] as $k) {
            $next[$k] = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $next[$k]) ? strtolower($next[$k]) : '';
        }
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget('site.room_screen');

        return $this->all();
    }
}
