<?php

namespace App\Services;

use App\Models\SiteSetting;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The portrait lobby screen (1080 × 1920): its secret address, the timing and transition of the slideshow, and the
 * images the administrator adds after the automatic "today's programs" slides. Only the settings live here; the content
 * of the program slides is worked out by LobbyScreenService.
 */
class LobbyScreenSettings
{
    public const KEY = 'lobby_screen';

    public const TRANSITIONS = ['fade', 'slide', 'zoom', 'none'];

    /** The stage the screen is designed on (portrait). */
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public static function defaults(): array
    {
        return [
            'token' => null,
            'enabled' => true,
            'show_programs' => true,        // the automatic first slide(s)
            'programs_seconds' => 20,       // each page of programs stays this long
            'programs_per_slide' => 4,
            'slide_seconds' => 12,          // default time of an image slide
            'transition' => 'fade',
            'transition_ms' => 900,
            'show_clock' => true,
            'show_progress' => true,        // the thin timer line at the foot of the screen
            'language' => 'ar',             // ar | en
            'slides' => [],                 // [{id, path, title, seconds, transition, enabled, from, to}]
        ];
    }

    public function all(): array
    {
        return Cache::remember('site.lobby_screen', 30, fn () => array_replace(self::defaults(), Arr::only(SiteSetting::find(self::KEY)?->value ?? [], array_keys(self::defaults()))));
    }

    public function token(bool $regenerate = false): string
    {
        $all = $this->all();
        if ($regenerate || empty($all['token'])) {
            $this->write(['token' => Str::random(40)] + $all);
        }

        return (string) $this->all()['token'];
    }

    public function validToken(string $token): bool
    {
        $current = $this->all()['token'] ?? null;

        return is_string($current) && $current !== '' && hash_equals($current, $token);
    }

    /** @param  array<string, mixed>  $input */
    public function update(array $input, ?User $by = null): array
    {
        $next = array_replace($this->all(), Arr::only($input, ['enabled', 'show_programs', 'programs_seconds', 'programs_per_slide', 'slide_seconds', 'transition', 'transition_ms', 'show_clock', 'show_progress', 'language']));
        foreach (['enabled', 'show_programs', 'show_clock', 'show_progress'] as $k) {
            $next[$k] = (bool) $next[$k];
        }
        $next['programs_seconds'] = max(5, min(300, (int) $next['programs_seconds']));
        $next['programs_per_slide'] = max(1, min(8, (int) $next['programs_per_slide']));
        $next['slide_seconds'] = max(3, min(600, (int) $next['slide_seconds']));
        $next['transition'] = in_array($next['transition'], self::TRANSITIONS, true) ? $next['transition'] : 'fade';
        $next['transition_ms'] = max(0, min(3000, (int) $next['transition_ms']));
        $next['language'] = $next['language'] === 'en' ? 'en' : 'ar';

        return $this->write($next, $by);
    }

    // Slides ---------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the slide that was added */
    public function addSlide(string $path, string $title = ''): array
    {
        $all = $this->all();
        $slide = ['id' => Str::lower(Str::random(10)), 'path' => $path, 'title' => mb_substr($title, 0, 120), 'seconds' => null, 'transition' => null, 'enabled' => true, 'from' => null, 'to' => null];
        $all['slides'] = [...$all['slides'], $slide];
        $this->write($all);

        return $slide;
    }

    /** @param  array<string, mixed>  $patch */
    public function updateSlide(string $id, array $patch): ?array
    {
        $all = $this->all();
        $found = null;
        $all['slides'] = array_map(function (array $s) use ($id, $patch, &$found) {
            if ($s['id'] !== $id) {
                return $s;
            }
            if (array_key_exists('title', $patch)) {
                $s['title'] = mb_substr((string) $patch['title'], 0, 120);
            }
            if (array_key_exists('seconds', $patch)) {
                $s['seconds'] = $patch['seconds'] === null ? null : max(3, min(600, (int) $patch['seconds']));
            }
            if (array_key_exists('transition', $patch)) {
                $s['transition'] = in_array($patch['transition'], self::TRANSITIONS, true) ? $patch['transition'] : null;
            }
            if (array_key_exists('enabled', $patch)) {
                $s['enabled'] = (bool) $patch['enabled'];
            }
            foreach (['from', 'to'] as $k) {
                if (array_key_exists($k, $patch)) {
                    $s[$k] = $patch[$k] ?: null;
                }
            }

            return $found = $s;
        }, $all['slides']);
        $found && $this->write($all);

        return $found;
    }

    /** Removes a slide and returns its stored file so the caller can delete it. */
    public function removeSlide(string $id): ?array
    {
        $all = $this->all();
        $gone = collect($all['slides'])->firstWhere('id', $id);
        if ($gone) {
            $all['slides'] = array_values(array_filter($all['slides'], fn ($s) => $s['id'] !== $id));
            $this->write($all);
        }

        return $gone;
    }

    /** @param  list<string>  $ids */
    public function reorder(array $ids): void
    {
        $all = $this->all();
        $by = collect($all['slides'])->keyBy('id');
        $ordered = collect($ids)->filter(fn ($id) => $by->has($id))->unique()->map(fn ($id) => $by[$id])->all();
        $rest = $by->except($ids)->values()->all();   // a slide missing from the list keeps its place at the end
        $all['slides'] = [...$ordered, ...$rest];
        $this->write($all);
    }

    /** @param  array<string, mixed>  $value */
    private function write(array $value, ?User $by = null): array
    {
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $value, 'updated_by' => $by?->id]);
        Cache::forget('site.lobby_screen');

        return $this->all();
    }
}
