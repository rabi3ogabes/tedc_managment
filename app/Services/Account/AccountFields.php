<?php

namespace App\Services\Account;

use App\Models\Employee;
use App\Models\User;

/**
 * Everything "My account" shows, grouped, with how each piece can be changed: the user never edits it directly,
 * they send a change request. `auto` says whether an approval can be applied to the profile in one click (simple
 * personal / contact data) or must be corrected by HR (employment data, relations, identity numbers).
 */
class AccountFields
{
    public const GROUPS = [
        'personal' => ['ar' => 'البيانات الشخصية', 'en' => 'Personal details'],
        'contact' => ['ar' => 'التواصل', 'en' => 'Contact'],
        'work' => ['ar' => 'العمل', 'en' => 'Work'],
        'education' => ['ar' => 'المؤهل والخبرة', 'en' => 'Education & experience'],
    ];

    private const OPTIONS = [
        'gender' => ['male' => ['ar' => 'ذكر', 'en' => 'Male'], 'female' => ['ar' => 'أنثى', 'en' => 'Female']],
        'qualification' => ['diploma' => ['ar' => 'دبلوم', 'en' => 'Diploma'], 'bachelor' => ['ar' => 'بكالوريوس', 'en' => 'Bachelor'], 'master' => ['ar' => 'ماجستير', 'en' => 'Master'], 'phd' => ['ar' => 'دكتوراه', 'en' => 'PhD']],
        'education_stage' => ['kindergarten' => ['ar' => 'رياض الأطفال', 'en' => 'Kindergarten'], 'primary' => ['ar' => 'الابتدائية', 'en' => 'Primary'], 'preparatory' => ['ar' => 'الإعدادية', 'en' => 'Preparatory'], 'secondary' => ['ar' => 'الثانوية', 'en' => 'Secondary']],
    ];

    /** key => [group, type, label_ar, label_en, source, column, auto, employee_only] */
    public static function all(): array
    {
        return [
            'name_ar' => ['personal', 'text', 'الاسم بالعربية', 'Name (Arabic)', 'user', 'name_ar', true, false],
            'name_en' => ['personal', 'text', 'الاسم بالإنجليزية', 'Name (English)', 'user', 'name', true, false],
            'national_id' => ['personal', 'text', 'الرقم الشخصي', 'National ID', 'employee', 'national_id', false, true],
            'gender' => ['personal', 'select', 'الجنس', 'Gender', 'employee', 'gender', true, true],
            'birth_date' => ['personal', 'date', 'تاريخ الميلاد', 'Date of birth', 'employee', 'birth_date', true, true],
            'nationality' => ['personal', 'text', 'الجنسية', 'Nationality', 'employee', 'nationality', true, true],
            'email' => ['contact', 'email', 'البريد الإلكتروني', 'E-mail', 'user', 'email', false, false],
            'phone' => ['contact', 'phone', 'رقم الهاتف', 'Phone', 'user', 'phone', true, false],
            'employee_no' => ['work', 'text', 'الرقم الوظيفي', 'Employee number', 'employee', 'employee_no', false, true],
            'school' => ['work', 'relation', 'المدرسة', 'School', 'relation', null, false, true],
            'job_title' => ['work', 'relation', 'المسمى الوظيفي', 'Job title', 'relation', null, false, true],
            'department' => ['work', 'relation', 'القسم', 'Department', 'relation', null, false, true],
            'hire_date' => ['work', 'date', 'تاريخ التعيين', 'Hire date', 'employee', 'hire_date', false, true],
            'specialization' => ['education', 'text', 'التخصص', 'Specialization', 'employee', 'specialization', true, true],
            'qualification' => ['education', 'select', 'المؤهل العلمي', 'Qualification', 'employee', 'qualification', true, true],
            'education_stage' => ['education', 'select', 'المرحلة التي يدرّسها', 'Teaching stage', 'employee', 'education_stage', true, true],
            'experience_years' => ['education', 'number', 'سنوات الخبرة', 'Years of experience', 'employee', 'experience_years', false, true],
        ];
    }

    /** @return array{group: string, type: string, label: array{ar: string, en: string}, source: string, column: ?string, auto: bool, employee_only: bool, options: ?array}|null */
    public static function field(string $key): ?array
    {
        $f = self::all()[$key] ?? null;

        return $f ? ['key' => $key, 'group' => $f[0], 'type' => $f[1], 'label' => ['ar' => $f[2], 'en' => $f[3]], 'source' => $f[4], 'column' => $f[5], 'auto' => $f[6], 'employee_only' => $f[7], 'options' => self::OPTIONS[$key] ?? null] : null;
    }

    /** Raw value and a readable one for the current locale. @return array{value: ?string, display: ?string} */
    public static function read(string $key, User $user, ?Employee $employee, string $locale): array
    {
        $f = self::field($key);
        $raw = match (true) {
            $f['source'] === 'user' => $user->{$f['column']},
            $f['source'] === 'employee' => $employee?->{$f['column']},
            $key === 'school' => $employee?->school?->translate('name'),
            $key === 'job_title' => $employee?->jobTitle?->translate('name'),
            $key === 'department' => $employee?->department?->translate('name'),
            default => null,
        };
        if ($raw instanceof \DateTimeInterface) {
            $raw = $raw->format('Y-m-d');
        }
        $value = $raw === null || $raw === '' ? null : (string) $raw;
        $display = $value !== null && $f['options'] ? ($f['options'][$value][$locale] ?? $value) : $value;

        return ['value' => $value, 'display' => $display];
    }
}
