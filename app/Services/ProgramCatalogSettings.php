<?php

namespace App\Services;

use App\Models\Program;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\ProgramTypes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Settings → Programme catalogue: the names of the five programme types and the code prefix each one stamps on
 * a new programme (LIC-00001 for licensing, PRO-00001 for professionalisation …), the code format, and the names
 * of the three delivery modes (in person, online, hybrid). The type ids and the mode ids are fixed — the annual
 * plan, the eligibility rules and the mobile app key on them — only what people read and the prefixes change.
 */
class ProgramCatalogSettings
{
    public const KEY = 'program_catalog';

    private const CACHE = 'site.program_catalog';

    public const MODES = ['in_person', 'online', 'hybrid'];

    public static function defaults(): array
    {
        $prefixes = ['licensing' => 'LIC', 'empowerment' => 'EMP', 'professionalisation' => 'PRO', 'specialisation' => 'SPE', 'electives' => 'ELE'];
        $types = [];
        foreach (ProgramTypes::baseCatalogue() as $t) {
            $types[$t['id']] = ['name_ar' => $t['name_ar'], 'name_en' => $t['name_en'], 'hint_ar' => $t['hint_ar'], 'hint_en' => $t['hint_en'], 'prefix' => $prefixes[$t['id']] ?? strtoupper(substr($t['id'], 0, 3)), 'enabled' => true];
        }

        return [
            'types' => $types,
            'modes' => [
                'in_person' => ['name_ar' => 'حضوري', 'name_en' => 'In person'],
                'online' => ['name_ar' => 'عن بُعد', 'name_en' => 'Online'],
                'hybrid' => ['name_ar' => 'مدمج', 'name_en' => 'Hybrid'],
            ],
            'code' => ['default_prefix' => 'PRG', 'digits' => 5, 'separator' => '-'],
        ];
    }

    public function all(): array
    {
        return Cache::remember(self::CACHE, 60, function () {
            $d = self::defaults();
            $saved = SiteSetting::find(self::KEY)?->value ?? [];
            $out = $d;
            foreach ($d['types'] as $id => $t) {
                $out['types'][$id] = array_replace($t, Arr::only((array) ($saved['types'][$id] ?? []), array_keys($t)));
            }
            foreach ($d['modes'] as $id => $m) {
                $out['modes'][$id] = array_replace($m, Arr::only((array) ($saved['modes'][$id] ?? []), ['name_ar', 'name_en']));
            }
            $out['code'] = array_replace($d['code'], Arr::only((array) ($saved['code'] ?? []), array_keys($d['code'])));

            return $out;
        });
    }

    /** @return list<array{id: string, name_ar: string, name_en: string, hint_ar: string, hint_en: string, prefix: string, enabled: bool}> in the RFP order */
    public function types(bool $enabledOnly = false): array
    {
        $out = [];
        foreach ($this->all()['types'] as $id => $t) {
            if (! $enabledOnly || $t['enabled']) {
                $out[] = ['id' => $id] + $t;
            }
        }

        return $out;
    }

    public function type(?string $id): ?array
    {
        $id = ProgramTypes::normalize($id);

        return $id && isset($this->all()['types'][$id]) ? ['id' => $id] + $this->all()['types'][$id] : null;
    }

    /** @return array<string, array{name_ar: string, name_en: string}> */
    public function modes(): array
    {
        return $this->all()['modes'];
    }

    public function prefixFor(?string $type): string
    {
        return $this->type($type)['prefix'] ?? $this->all()['code']['default_prefix'];
    }

    /** The next free code for a programme of this type: the prefix, the separator, and the highest number used so far plus one (deleted programmes keep their numbers). */
    public function nextCode(?string $type): string
    {
        $c = $this->all()['code'];
        $prefix = $this->prefixFor($type);
        $sep = $c['separator'];
        $start = $prefix.$sep;
        $max = 0;
        foreach (Program::withTrashed()->where('code', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $start).'%')->pluck('code') as $code) {
            $tail = substr((string) $code, strlen($start));
            if (preg_match('/^\d+$/', $tail)) {
                $max = max($max, (int) $tail);
            }
        }

        return $start.str_pad((string) ($max + 1), (int) $c['digits'], '0', STR_PAD_LEFT);
    }

    public function update(array $input, ?User $by = null): array
    {
        $cur = $this->all();
        $next = $cur;
        $used = [];
        foreach ($cur['types'] as $id => $t) {
            $in = (array) ($input['types'][$id] ?? []);
            foreach (['name_ar', 'name_en', 'hint_ar', 'hint_en'] as $k) {
                if (array_key_exists($k, $in)) {
                    $v = trim(strip_tags((string) $in[$k]));
                    $next['types'][$id][$k] = $v !== '' || str_starts_with($k, 'hint') ? mb_substr($v, 0, $k === 'name_ar' || $k === 'name_en' ? 60 : 200) : $t[$k];
                }
            }
            if (array_key_exists('prefix', $in)) {
                $p = strtoupper(trim((string) $in['prefix']));
                if (preg_match('/^[A-Z]{2,5}$/', $p)) {
                    $next['types'][$id]['prefix'] = $p;
                }
            }
            if (array_key_exists('enabled', $in)) {
                $next['types'][$id]['enabled'] = (bool) $in['enabled'];
            }
            $used[] = $next['types'][$id]['prefix'];
        }
        if (count($used) !== count(array_unique($used))) {
            abort(422, __('messages.program_catalog.prefix_taken'));
        }
        foreach ($cur['modes'] as $id => $m) {
            foreach (['name_ar', 'name_en'] as $k) {
                $v = trim(strip_tags((string) ($input['modes'][$id][$k] ?? $m[$k])));
                $next['modes'][$id][$k] = $v !== '' ? mb_substr($v, 0, 60) : $m[$k];
            }
        }
        $code = (array) ($input['code'] ?? []);
        if (isset($code['default_prefix']) && preg_match('/^[A-Z]{2,5}$/', strtoupper(trim((string) $code['default_prefix'])))) {
            $next['code']['default_prefix'] = strtoupper(trim((string) $code['default_prefix']));
        }
        if (isset($code['digits'])) {
            $next['code']['digits'] = max(3, min(6, (int) $code['digits']));
        }
        if (array_key_exists('separator', $code)) {
            $next['code']['separator'] = in_array($code['separator'], ['-', '', '/', '.'], true) ? $code['separator'] : '-';
        }
        if (in_array($next['code']['default_prefix'], $used, true)) {
            abort(422, __('messages.program_catalog.prefix_taken'));
        }

        SiteSetting::updateOrCreate(['key' => self::KEY], ['value' => $next, 'updated_by' => $by?->id]);
        Cache::forget(self::CACHE);
        // The mode names reach every screen through the label overrides the web app already applies on top of its texts.
        $labels = ['ar' => [], 'en' => []];
        foreach ($next['modes'] as $id => $m) {
            $labels['ar']["modes.$id"] = $m['name_ar'] === self::defaults()['modes'][$id]['name_ar'] ? '' : $m['name_ar'];
            $labels['en']["modes.$id"] = $m['name_en'] === self::defaults()['modes'][$id]['name_en'] ? '' : $m['name_en'];
        }
        app(LabelSettings::class)->update($labels, $by);

        return $this->all();
    }

    /** What the settings page shows: the catalogue, the next code per prefix, and how many programmes carry each type. */
    public function overview(): array
    {
        $counts = Program::toBase()->selectRaw('program_type, count(*) as c')->groupBy('program_type')->pluck('c', 'program_type');

        return [
            'settings' => $this->all(),
            'types' => array_map(fn ($t) => $t + ['next_code' => $this->nextCode($t['id']), 'programs' => (int) ($counts[$t['id']] ?? 0)], $this->types()),
            'default_next_code' => $this->nextCode(null),
            'untyped_programs' => (int) ($counts[''] ?? 0) + (int) ($counts[null] ?? 0),
        ];
    }
}
