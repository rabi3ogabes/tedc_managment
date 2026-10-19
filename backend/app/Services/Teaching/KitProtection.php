<?php

namespace App\Services\Teaching;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * How the training kit is protected while a trainer reads or presents it. The kit belongs to the training centre: it is shown,
 * never handed over — no download, no copy, no print — every view carries the trainer's name as a watermark, and an attempt to
 * capture the screen blacks the content out and is reported to the centre. Browsers cannot forbid the operating system from
 * taking a picture, so the protection is a deterrent with a trail, not a lock; the switches below tune how strict it is.
 */
class KitProtection
{
    public const KEY = 'kit_protection';

    private const CACHE = 'site.kit_protection';

    public const DEFAULTS = [
        'watermark' => true,                 // the trainer's name, number and time tiled across every page and slide
        'blank_on_capture_keys' => true,     // PrintScreen, Win+Shift+S, Cmd+Shift+3/4/5: the content goes black at once
        'blank_on_blur_reader' => true,      // the reader hides itself when the window loses focus or the tab is hidden
        'blank_on_blur_presenter' => false,  // the presenter keeps showing (the audience screen is another window)
        'block_print_copy' => true,          // print, save, copy, drag and the context menu are refused
        'require_acknowledgement' => true,   // first opening of a kit asks the trainer to accept that it belongs to the centre
        'log_events' => true,
        'notify_admin' => true,              // capture attempts reach the centre's staff
        'notify_window_minutes' => 10,       // one alert per trainer and kit in this window
    ];

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, fn () => array_replace(self::DEFAULTS, Arr::only((array) (SiteSetting::find(self::KEY)?->value ?? []), array_keys(self::DEFAULTS))));
    }

    public function update(array $changes, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($changes, array_keys(self::DEFAULTS)));
        foreach (self::DEFAULTS as $k => $default) {
            $next[$k] = is_bool($default) ? (bool) $next[$k] : max(1, min(1440, (int) $next[$k]));
        }
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next]);
        Cache::forget(self::CACHE);

        return $next;
    }
}
