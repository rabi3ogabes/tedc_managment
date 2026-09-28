<?php

namespace App\Services\NeedsSurveys;

use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the target audience (الفئة المستهدفة) of a survey from profile filters:
 * school, region, school type, stage, job title, specialization, nationality, gender,
 * qualification and years of experience.
 */
class SurveyAudience
{
    public const LIST_FILTERS = ['school_ids', 'regions', 'school_types', 'stages', 'job_title_ids', 'specializations', 'nationalities', 'genders', 'qualifications'];

    public const EXPERIENCE_BANDS = ['0-2' => [0, 2], '3-5' => [2.01, 5], '6-10' => [5.01, 10], '11-15' => [10.01, 15], '16+' => [15.01, 99]];

    /** Validation rules for an audience payload (prefix e.g. "audience."). */
    public static function rules(string $prefix = ''): array
    {
        $rules = [$prefix === '' ? 'audience' : rtrim($prefix, '.') => ['nullable', 'array']];
        foreach (self::LIST_FILTERS as $key) {
            $rules[$prefix.$key] = ['nullable', 'array', 'max:500'];
            $rules[$prefix.$key.'.*'] = in_array($key, ['school_ids', 'job_title_ids'], true) ? ['uuid'] : ['string', 'max:255'];
        }
        $rules[$prefix.'experience_min'] = ['nullable', 'numeric', 'min:0', 'max:60'];
        $rules[$prefix.'experience_max'] = ['nullable', 'numeric', 'min:0', 'max:60'];

        return $rules;
    }

    /** Keeps only known, non-empty filters. */
    public static function clean(?array $audience): array
    {
        $audience ??= [];
        $clean = [];
        foreach (self::LIST_FILTERS as $key) {
            $values = array_values(array_unique(array_filter(array_map('strval', (array) ($audience[$key] ?? [])), fn ($v) => $v !== '')));
            if ($values) {
                $clean[$key] = $values;
            }
        }
        foreach (['experience_min', 'experience_max'] as $key) {
            if (isset($audience[$key]) && $audience[$key] !== '' && is_numeric($audience[$key])) {
                $clean[$key] = (float) $audience[$key];
            }
        }

        return $clean;
    }

    /** Active employees (with an account) matching the audience. */
    public function query(array $audience): Builder
    {
        $a = self::clean($audience);

        return Employee::query()
            ->where('employees.status', 'active')
            ->whereHas('user', fn ($q) => $q->where('status', 'active'))
            ->when($a['school_ids'] ?? null, fn ($q, $v) => $q->whereIn('employees.school_id', $v))
            ->when(isset($a['regions']) || isset($a['school_types']), fn ($q) => $q->whereHas('school', function ($s) use ($a) {
                $s->when($a['regions'] ?? null, fn ($s, $v) => $s->whereIn('region', $v))
                    ->when($a['school_types'] ?? null, fn ($s, $v) => $s->whereIn('type', $v));
            }))
            ->when($a['stages'] ?? null, fn ($q, $v) => $q->whereIn('employees.education_stage', $v))
            ->when($a['job_title_ids'] ?? null, fn ($q, $v) => $q->whereIn('employees.job_title_id', $v))
            ->when($a['specializations'] ?? null, fn ($q, $v) => $q->whereIn('employees.specialization', $v))
            ->when($a['nationalities'] ?? null, fn ($q, $v) => $q->whereIn('employees.nationality', $v))
            ->when($a['genders'] ?? null, fn ($q, $v) => $q->whereIn('employees.gender', $v))
            ->when($a['qualifications'] ?? null, fn ($q, $v) => $q->whereIn('employees.qualification', $v))
            ->when(isset($a['experience_min']), fn ($q) => $q->where('employees.experience_years', '>=', $a['experience_min']))
            ->when(isset($a['experience_max']), fn ($q) => $q->where('employees.experience_years', '<=', $a['experience_max']));
    }

    /** Live size and composition of the audience, shown before sending. */
    public function preview(array $audience, string $locale = 'ar'): array
    {
        $base = $this->query($audience);
        $count = (clone $base)->count();
        $name = $locale === 'en' ? 'name_en' : 'name_ar';

        $group = fn (string $column) => (clone $base)->toBase()->select($column.' as k', DB::raw('count(*) as n'))
            ->groupBy($column)->orderByDesc('n')->limit(8)->get();

        $schools = $group('employees.school_id');
        $schoolNames = School::whereIn('id', $schools->pluck('k')->filter())->pluck($name, 'id');
        $titles = $group('employees.job_title_id');
        $titleNames = JobTitle::whereIn('id', $titles->pluck('k')->filter())->pluck($name, 'id');

        $bands = [];
        foreach (self::EXPERIENCE_BANDS as $label => [$min, $max]) {
            $bands[] = ['label' => $label, 'value' => (clone $base)->whereBetween('employees.experience_years', [$min, $max])->count()];
        }

        $label = fn ($rows, $names = null) => $rows->map(fn ($r) => ['label' => $names ? ($names[$r->k] ?? '—') : ($r->k ?? '—'), 'value' => (int) $r->n])->values();

        return [
            'count' => $count,
            'schools' => (clone $base)->distinct()->count('employees.school_id'),
            'by_school' => $label($schools, $schoolNames),
            'by_job_title' => $label($titles, $titleNames),
            'by_nationality' => $label($group('employees.nationality')),
            'by_specialization' => $label($group('employees.specialization')),
            'by_experience' => $bands,
        ];
    }

    /** Distinct values available for the free-text profile filters. */
    public function options(): array
    {
        $distinct = fn (string $column) => Employee::query()->whereNotNull($column)->where($column, '!=', '')
            ->distinct()->orderBy($column)->limit(300)->pluck($column)->values();

        return [
            'specializations' => $distinct('specialization'),
            'nationalities' => $distinct('nationality'),
            'qualifications' => $distinct('qualification'),
            'stages' => $distinct('education_stage'),
            'genders' => ['male', 'female'],
            'experience_bands' => array_keys(self::EXPERIENCE_BANDS),
        ];
    }

    /** One-line human summary of an audience, used on generated training needs. */
    public function describe(array $audience): string
    {
        $a = self::clean($audience);
        $parts = [];
        if (isset($a['job_title_ids'])) {
            $parts[] = JobTitle::whereIn('id', $a['job_title_ids'])->pluck('name_ar')->implode('، ');
        }
        if (isset($a['specializations'])) {
            $parts[] = implode('، ', $a['specializations']);
        }
        if (isset($a['school_ids'])) {
            $parts[] = count($a['school_ids']).' مدرسة';
        }
        if (isset($a['experience_min']) || isset($a['experience_max'])) {
            $parts[] = 'الخبرة '.($a['experience_min'] ?? 0).'–'.($a['experience_max'] ?? '∞').' سنة';
        }

        return $parts ? implode(' · ', $parts) : 'جميع الموظفين';
    }
}
