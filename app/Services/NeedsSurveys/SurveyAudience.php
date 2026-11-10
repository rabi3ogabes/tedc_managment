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
 * qualification, years of experience and age.
 */
class SurveyAudience
{
    public const LIST_FILTERS = ['school_ids', 'regions', 'school_types', 'stages', 'job_title_ids', 'specializations', 'nationalities', 'genders', 'qualifications', 'grade_levels', 'subjects', 'grades_taught'];

    public const RANGE_FILTERS = ['experience_min', 'experience_max', 'age_min', 'age_max', 'experience_moe_min', 'experience_moe_max', 'experience_outside_min', 'experience_outside_max'];

    public const AGE_BANDS = ['<30' => [0, 29], '30-39' => [30, 39], '40-49' => [40, 49], '50+' => [50, 120]];

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
        $rules[$prefix.'age_min'] = ['nullable', 'integer', 'min:16', 'max:80'];
        $rules[$prefix.'age_max'] = ['nullable', 'integer', 'min:16', 'max:80'];
        foreach (['experience_moe_min', 'experience_moe_max', 'experience_outside_min', 'experience_outside_max'] as $k) {
            $rules[$prefix.$k] = ['nullable', 'numeric', 'min:0', 'max:60'];
        }

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
        foreach (self::RANGE_FILTERS as $key) {
            if (isset($audience[$key]) && $audience[$key] !== '' && is_numeric($audience[$key])) {
                $clean[$key] = str_starts_with($key, 'age') ? (int) $audience[$key] : (float) $audience[$key];
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
            ->when($a['grade_levels'] ?? null, fn ($q, $v) => $q->whereIn('employees.grade_level', $v))
            ->when($a['subjects'] ?? null, fn ($q, $v) => $q->where(fn ($w) => collect($v)->each(fn ($x) => $w->orWhereJsonContains('employees.subjects', $x))))
            ->when($a['grades_taught'] ?? null, fn ($q, $v) => $q->where(fn ($w) => collect($v)->each(fn ($x) => $w->orWhereJsonContains('employees.grades_taught', $x))))
            ->when(isset($a['experience_moe_min']), fn ($q) => $q->where('employees.experience_moe_years', '>=', $a['experience_moe_min']))
            ->when(isset($a['experience_moe_max']), fn ($q) => $q->where('employees.experience_moe_years', '<=', $a['experience_moe_max']))
            ->when(isset($a['experience_outside_min']), fn ($q) => $q->where('employees.experience_outside_years', '>=', $a['experience_outside_min']))
            ->when(isset($a['experience_outside_max']), fn ($q) => $q->where('employees.experience_outside_years', '<=', $a['experience_outside_max']))
            ->when(isset($a['experience_min']), fn ($q) => $q->where('employees.experience_years', '>=', $a['experience_min']))
            ->when(isset($a['experience_max']), fn ($q) => $q->where('employees.experience_years', '<=', $a['experience_max']))
            // Age N means born on or before today-N years; at most N means born after today-(N+1) years.
            ->when(isset($a['age_min']), fn ($q) => $q->where('employees.birth_date', '<=', today()->subYears($a['age_min'])->toDateString()))
            ->when(isset($a['age_max']), fn ($q) => $q->where('employees.birth_date', '>', today()->subYears($a['age_max'] + 1)->toDateString()));
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

        $ages = [];
        foreach (self::AGE_BANDS as $band => [$min, $max]) {
            $ages[] = ['label' => $band, 'value' => (clone $base)->where('employees.birth_date', '<=', today()->subYears($min)->toDateString())->where('employees.birth_date', '>', today()->subYears($max + 1)->toDateString())->count()];
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
            'by_age' => $ages,
            'by_gender' => $label($group('employees.gender')),
        ];
    }

    /** A few matching employees, shown next to the count so the audience can be sanity-checked. */
    public function sample(array $audience, int $limit = 8): array
    {
        return $this->query($audience)->with(['user:id,name,name_ar', 'school:id,name_ar,name_en', 'jobTitle:id,name_ar,name_en'])
            ->orderByDesc('employees.experience_years')->limit($limit)->get()->map(fn (Employee $e) => [
                'id' => $e->id, 'employee_no' => $e->employee_no, 'name' => $e->user?->displayName(), 'school' => $e->school?->translate('name'),
                'job_title' => $e->jobTitle?->translate('name'), 'specialization' => $e->specialization, 'age' => $e->age(), 'experience_years' => $e->experience_years,
            ])->all();
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
            'age_bands' => array_keys(self::AGE_BANDS),
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
        if (isset($a['age_min']) || isset($a['age_max'])) {
            $parts[] = 'العمر '.($a['age_min'] ?? 16).'–'.($a['age_max'] ?? '∞').' سنة';
        }
        if (isset($a['experience_min']) || isset($a['experience_max'])) {
            $parts[] = 'الخبرة '.($a['experience_min'] ?? 0).'–'.($a['experience_max'] ?? '∞').' سنة';
        }

        return $parts ? implode(' · ', $parts) : 'جميع الموظفين';
    }
}
