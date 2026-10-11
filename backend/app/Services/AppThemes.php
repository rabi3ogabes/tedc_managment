<?php

namespace App\Services;

use App\Models\Role;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\ActiveRole;
use Illuminate\Support\Facades\Cache;

/**
 * Ready-made looks for the mobile app (palette, card shape, header, navigation bar and the way the home cards are
 * laid out). The administrator assigns one to each group of people (trainees, trainers…) and can change its colours.
 * Only the choices are stored: the looks themselves live here.
 */
class AppThemes
{
    public const KEY = 'app_themes';

    /** The groups a look can be assigned to, with the roles each one covers. */
    public const GROUPS = [
        'trainee' => [Role::EMPLOYEE],
        'trainer' => [Role::TRAINER],
        'school' => [Role::SCHOOL_ADMIN, Role::SUPERVISOR, Role::ACADEMIC_DEPUTY],
        'admin' => [Role::SUPER_ADMIN, Role::CENTER_ADMIN, Role::COORDINATOR, Role::TRAINING_HEAD, Role::CENTER_LEADERSHIP, Role::EXECUTIVE],
    ];

    public const COLOR_KEYS = ['primary', 'accent', 'background'];

    /**
     * The looks. `classic` is the app as it is (the brand colours of Brand Studio). Colours are the starting point; the
     * administrator's changes are laid over them.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function presets(): array
    {
        return [
            'classic' => [
                'name' => ['ar' => 'الكلاسيكي', 'en' => 'Classic'], 'description' => ['ar' => 'مظهر التطبيق الحالي بألوان الهوية.', 'en' => 'The app as it is today, in the brand colours.'],
                'colors' => null, 'shape' => ['card_radius' => 20, 'button_radius' => 14, 'elevation' => 0], 'header' => 'curved', 'nav' => null, 'home' => null,
            ],
            'vault' => [
                'name' => ['ar' => 'عنابي زجاجي', 'en' => 'Maroon glass'], 'description' => ['ar' => 'بطاقات ناعمة بحواف مدوّرة وظلال وردية خفيفة وشريط سفلي زجاجي.', 'en' => 'Soft rounded cards, rosy shadows and a frosted bottom bar.'],
                'colors' => ['primary' => '#7A1233', 'accent' => '#E7A9BA', 'background' => '#F6F1F3'], 'shape' => ['card_radius' => 26, 'button_radius' => 18, 'elevation' => 2], 'header' => 'glass', 'nav' => 'glass',
                'home' => ['hours', 'stats', 'todo', 'programs', 'scan', 'needs', 'sessions', 'dashboard', 'recommended'],
            ],
            'care' => [
                'name' => ['ar' => 'أزرق هادئ', 'en' => 'Calm blue'], 'description' => ['ar' => 'واجهة نظيفة بترويسة فاتحة وبطاقات ملوّنة خفيفة وأرقام سريعة في الأعلى.', 'en' => 'A clean light header, softly tinted cards and quick numbers on top.'],
                'colors' => ['primary' => '#1F5FA6', 'accent' => '#F2B33D', 'background' => '#F3F7FC'], 'shape' => ['card_radius' => 18, 'button_radius' => 14, 'elevation' => 1], 'header' => 'flat', 'nav' => 'classic',
                'home' => ['stats', 'programs', 'hours', 'todo', 'scan', 'needs', 'sessions', 'dashboard', 'recommended'],
            ],
            'learn' => [
                'name' => ['ar' => 'بنفسجي تعليمي', 'en' => 'Learning violet'], 'description' => ['ar' => 'ألوان حيوية للتعلّم: البرامج أولاً ثم التقدّم، مع زر مركزي بارز.', 'en' => 'Lively colours for learning: programs first, then progress, with a raised centre button.'],
                'colors' => ['primary' => '#5B3DF5', 'accent' => '#F5A524', 'background' => '#F5F3FF'], 'shape' => ['card_radius' => 24, 'button_radius' => 16, 'elevation' => 2], 'header' => 'curved', 'nav' => 'center_fab',
                'home' => ['programs', 'hours', 'todo', 'stats', 'scan', 'needs', 'sessions', 'dashboard', 'recommended'],
            ],
            'aqua' => [
                'name' => ['ar' => 'تركوازي دافئ', 'en' => 'Warm aqua'], 'description' => ['ar' => 'تركوازي مع لمسة خوخية، بطاقات كبيرة مدوّرة وشريط عائم.', 'en' => 'Aqua with a peach touch, large rounded cards and a floating bar.'],
                'colors' => ['primary' => '#0E8F8A', 'accent' => '#F4A08D', 'background' => '#EEF8F7'], 'shape' => ['card_radius' => 28, 'button_radius' => 18, 'elevation' => 2], 'header' => 'glass', 'nav' => 'floating',
                'home' => ['hours', 'programs', 'todo', 'stats', 'scan', 'needs', 'sessions', 'dashboard', 'recommended'],
            ],
        ];
    }

    /** @return array{assignments: array<string, string|null>, colors: array<string, array<string, string>>} */
    public function settings(): array
    {
        $v = Cache::remember('site.app_themes', 60, fn () => SiteSetting::find(self::KEY)?->value ?? []);

        return ['assignments' => (array) ($v['assignments'] ?? []), 'colors' => (array) ($v['colors'] ?? [])];
    }

    public function save(array $assignments, array $colors, string $userId): array
    {
        $presets = self::presets();
        $clean = ['assignments' => [], 'colors' => []];
        foreach (array_keys(self::GROUPS) as $group) {
            $id = $assignments[$group] ?? null;
            if ($id && isset($presets[$id]) && $id !== 'classic') {
                $clean['assignments'][$group] = $id;
            }
        }
        foreach ($colors as $id => $set) {
            if (! isset($presets[$id]) || ! $presets[$id]['colors'] || ! is_array($set)) {
                continue;
            }
            $own = array_intersect_key(array_filter($set, fn ($c) => is_string($c) && preg_match('/^#[0-9A-Fa-f]{6}$/', $c)), array_flip(self::COLOR_KEYS));
            // Only what differs from the look's own colours is kept.
            $own = array_filter($own, fn ($c, $k) => strcasecmp($c, $presets[$id]['colors'][$k]) !== 0, ARRAY_FILTER_USE_BOTH);
            if ($own) {
                $clean['colors'][$id] = $own;
            }
        }
        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $clean, 'updated_by' => $userId]);
        Cache::forget('site.app_themes');

        return $clean;
    }

    public function reset(): void
    {
        SiteSetting::where('key', self::KEY)->delete();
        Cache::forget('site.app_themes');
    }

    /** A preset with the administrator's colour changes laid over it. @return array<string, mixed> */
    public function effective(string $id): array
    {
        $p = self::presets()[$id];
        if ($p['colors']) {
            $p['colors'] = array_replace($p['colors'], $this->settings()['colors'][$id] ?? []);
        }

        return ['id' => $id] + $p;
    }

    public function groupFor(User $user): string
    {
        $slug = app(ActiveRole::class)->for($user)?->role?->slug ?? $user->roles->sortByDesc('level')->first()?->slug ?? Role::EMPLOYEE;
        foreach (self::GROUPS as $group => $roles) {
            if (in_array($slug, $roles, true)) {
                return $group;
            }
        }

        return 'admin';
    }

    /** The look of this person's group, or null when none is assigned (the app keeps its brand look). @return array<string, mixed>|null */
    public function forUser(User $user, AppHomeLayout $home): ?array
    {
        $id = $this->settings()['assignments'][$this->groupFor($user)] ?? null;
        if (! $id || ! isset(self::presets()[$id]) || $id === 'classic') {
            return null;
        }
        $t = $this->effective($id);
        // The look's own card order applies until the administrator sets one for the whole app.
        $t['home'] = $home->customised(Role::EMPLOYEE) ? null : $t['home'];

        return $t;
    }
}
