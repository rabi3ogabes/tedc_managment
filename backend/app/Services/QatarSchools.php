<?php

namespace App\Services;

use App\Models\School;
use App\Support\Supabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The national school list: government, private and specialised schools with their map position, read from the
 * Ministry of Education "Where is my school" service (an ArcGIS map layer) and kept in our own table, so every part of
 * the platform can use real schools. A copy of the list ships with the code, so a fresh install never depends on the
 * ministry being reachable; "Update" on the Schools page pulls the latest.
 */
class QatarSchools
{
    private const BASE = 'https://myschools.edu.gov.qa/server/rest/services/V2/SchoolsAR/MapServer';

    /** Layer id => source label. */
    private const LAYERS = [0 => 'moe_gov', 1 => 'moe_private', 2 => 'moe_special'];

    public static function bundledPath(): string
    {
        return database_path('data/qatar-schools.json');
    }

    /** Reads the three layers live and normalises them. @return list<array<string, mixed>> */
    public function fetchLive(): array
    {
        $layers = [];
        foreach (array_keys(self::LAYERS) as $layer) {
            $response = Http::withOptions(['verify' => Supabase::caBundle()])->timeout(60)->retry(2, 500)
                ->get(self::BASE."/{$layer}/query", ['where' => '1=1', 'outFields' => '*', 'outSR' => 4326, 'f' => 'json']);
            $layers[$layer] = $response->ok() ? $response->json('features') : null;
        }

        return $this->normalize($layers);
    }

    /** @param  array<int, ?array<int, array<string, mixed>>>  $layers  ArcGIS features per layer id  @return list<array<string, mixed>> */
    public function normalize(array $layers): array
    {
        $rows = [];
        foreach (self::LAYERS as $layer => $source) {
            $features = $layers[$layer] ?? null;
            if (! is_array($features) || $features === []) {
                throw new RuntimeException("The ministry service returned no schools for layer {$layer}.");
            }
            foreach ($features as $f) {
                $row = $source === 'moe_private' ? $this->private($f) : $this->government($f, $source);
                // The ministry layer repeats some schools as identical records; one row per school.
                $row && $rows[$row['code']] ??= $row;
            }
        }

        return array_values($rows);
    }

    /** @return list<array<string, mixed>> */
    public function bundled(): array
    {
        return json_decode((string) file_get_contents(self::bundledPath()), true) ?: [];
    }

    /** Creates or updates schools by code. @return array{created: int, updated: int, total: int} */
    public function import(array $rows): array
    {
        $created = $updated = 0;
        $now = now();
        foreach ($rows as $row) {
            $school = School::firstOrNew(['code' => $row['code']]);
            $school->exists ? $updated++ : $created++;
            // Keep what the center added by hand (partner flag, logo, status); the official facts are refreshed.
            $school->fill($row + ['synced_at' => $now])->save();
        }

        return ['created' => $created, 'updated' => $updated, 'total' => count($rows)];
    }

    // Mapping ---------------------------------------------------------------------------------------------------

    /** Government and specialised layers share one shape. */
    private function government(array $feature, string $source): ?array
    {
        $a = $this->plain($feature['attributes'] ?? []);
        [$lat, $lng] = $this->point($feature, $a['Y'] ?? null, $a['X'] ?? null);
        $name = trim((string) ($a['ARABICNAME'] ?? $a['SCHOOL_NAME_ARA'] ?? ''));
        if ($name === '' || $lat === null) {
            return null;
        }
        $level = trim((string) ($a['LEVEL_NAME'] ?? ''));

        return [
            // The ministry's own key (school number, gender, level) — a school number alone can repeat for separate boys / girls campuses.
            'code' => ($source === 'moe_special' ? 'MOES-' : 'MOE-').($a['IK'] ?? ($a['SCHOOLNO'] ?? '').'-'.$a['OBJECTID']),
            'moe_no' => isset($a['SCHOOLNO']) ? (string) $a['SCHOOLNO'] : null, 'source' => $source,
            'name_ar' => $name, 'name_en' => trim((string) ($a['ENGLISHNAME'] ?? $a['SCHOOL_NAME_END'] ?? '')) ?: $name,
            'type' => 'government', 'gender' => $this->gender($a['GENDER_NAME'] ?? null),
            'stage' => $source === 'moe_special' ? 'multi' : $this->stage($level),
            'region' => $this->region($a['MNCP_NAME'] ?? null), 'district' => $a['DISTRICT_NAME'] ?? null,
            'latitude' => $lat, 'longitude' => $lng,
            'phone' => $this->text($a['PHONE'] ?? null, 32), 'email' => $this->email($a['EMAIL_ADDR'] ?? null),
            'address' => $this->text($a['ADRESS'] ?? null, 255), 'website' => $this->text($a['URL'] ?? null, 255), 'curriculum' => null, 'status' => 'active',
        ];
    }

    private function private(array $feature): ?array
    {
        $a = $this->plain($feature['attributes'] ?? []);
        [$lat, $lng] = $this->point($feature, $a['LAT'] ?? null, $a['LONG_'] ?? null);
        $ar = trim((string) ($a['ANAME'] ?? ''));
        $en = trim((string) ($a['NAME'] ?? ''));
        if (($ar === '' && $en === '') || $lat === null) {
            return null;
        }

        return [
            'code' => 'MOEP-'.$a['OBJECTID'], 'moe_no' => isset($a['SCHOOLNO']) ? (string) $a['SCHOOLNO'] : null, 'source' => 'moe_private',
            'name_ar' => $ar ?: $en, 'name_en' => $en ?: $ar, 'type' => 'private', 'gender' => 'mixed',
            'stage' => $this->privateStage((string) ($a['CATEGORY'] ?? '')), 'region' => $this->region($a['MNCP_NAME'] ?? null), 'district' => $a['DISTRICT_NAME'] ?? null,
            'latitude' => $lat, 'longitude' => $lng, 'phone' => $this->text($a['PHONE'] ?? null, 32), 'email' => $this->email($a['EMAIL'] ?? null),
            'address' => $this->text(trim(($a['DISTRICT_NAME'] ?? '').' — '.($a['MNCP_NAME'] ?? ''), ' —'), 255), 'website' => $this->text($a['WEBSITE'] ?? null, 255),
            'curriculum' => $this->text($a['ADOPTEDCURRICULUM'] ?? null, 80), 'status' => 'active',
        ];
    }

    /** ArcGIS joins prefix field names with the table ("IndependentSchools.X"); drops the prefix. */
    private function plain(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $key => $value) {
            $out[Str::contains($key, '.') ? Str::after($key, '.') : $key] = $value;
        }

        return $out;
    }

    /** @return array{0: ?float, 1: ?float} */
    private function point(array $feature, mixed $lat, mixed $lng): array
    {
        $lat = $feature['geometry']['y'] ?? $lat;
        $lng = $feature['geometry']['x'] ?? $lng;
        $ok = is_numeric($lat) && is_numeric($lng) && $lat > 24 && $lat < 27 && $lng > 50 && $lng < 52.5;   // inside Qatar

        return $ok ? [round((float) $lat, 7), round((float) $lng, 7)] : [null, null];
    }

    private function region(?string $municipality): string
    {
        $m = (string) $municipality;

        return match (true) {
            str_contains($m, 'الريان') => 'al_rayyan', str_contains($m, 'الوكرة') => 'al_wakrah', str_contains($m, 'الخور') => 'al_khor',
            str_contains($m, 'الشمال') => 'al_shamal', str_contains($m, 'صلال') => 'umm_salal', str_contains($m, 'الظعاين') => 'al_daayen',
            str_contains($m, 'الشيحانية') => 'al_shahaniya', default => 'doha',
        };
    }

    private function gender(?string $name): ?string
    {
        return match (trim((string) $name)) {
            'بنين' => 'boys', 'بنات' => 'girls', default => null,
        };
    }

    private function stage(string $level): string
    {
        return match (true) {
            str_contains($level, 'رياض') => 'kindergarten', str_contains($level, 'اعداد') || str_contains($level, 'إعداد') => 'preparatory',
            str_contains($level, 'ثانو') => 'secondary', str_contains($level, 'ابتدائ') || str_contains($level, 'خامس') => 'primary', default => 'multi',
        };
    }

    /** One stage stays itself; several become "multi". */
    private function privateStage(string $category): string
    {
        $c = Str::lower(trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $category))));
        if (str_contains($c, '+') || str_contains($c, ',')) {
            return 'multi';
        }

        return match (true) {
            str_contains($c, 'kinder') => 'kindergarten', str_contains($c, 'prepar') => 'preparatory', str_contains($c, 'second') => 'secondary', str_contains($c, 'primary') => 'primary', default => 'multi',
        };
    }

    private function text(mixed $value, int $max): ?string
    {
        $v = trim((string) $value);

        return $v === '' || $v === '0' ? null : mb_substr($v, 0, $max);
    }

    private function email(mixed $value): ?string
    {
        $v = trim((string) $value);

        return filter_var($v, FILTER_VALIDATE_EMAIL) ? mb_substr($v, 0, 255) : null;
    }
}
