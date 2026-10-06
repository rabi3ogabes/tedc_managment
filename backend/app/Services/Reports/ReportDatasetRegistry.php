<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The datasets a report can be built on. Nothing a user types ever reaches SQL: fields, joins and operators are listed here, values are
 * always bound, and each dataset limits itself to what the person's scope allows (a school's report never contains another school).
 *
 * A field: label ar/en, type (string|number|date|bool|enum), SQL expression, optional `personal` (needs reports.export_personal),
 * `aggregates` (what may be computed over it) and, for enums, the allowed values.
 */
class ReportDatasetRegistry
{
    public const OPERATORS = [
        'string' => ['eq', 'ne', 'contains', 'starts', 'in', 'empty', 'not_empty'],
        'enum' => ['eq', 'ne', 'in'],
        'number' => ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'],
        'date' => ['eq', 'gte', 'lte', 'between'],
        'bool' => ['is_true', 'is_false'],
    ];

    public const AGGREGATES = ['count', 'count_distinct', 'sum', 'avg', 'min', 'max'];

    /** Year, month and day of a date column, written for the database in use. */
    public static function year(string $col): string
    {
        return DB::getDriverName() === 'sqlite' ? "cast(strftime('%Y', {$col}) as integer)" : "cast(extract(year from {$col}) as integer)";
    }

    public static function month(string $col): string
    {
        return DB::getDriverName() === 'sqlite' ? "cast(strftime('%m', {$col}) as integer)" : "cast(extract(month from {$col}) as integer)";
    }

    public static function day(string $col): string
    {
        return DB::getDriverName() === 'sqlite' ? "date({$col})" : "to_char({$col}, 'YYYY-MM-DD')";
    }

    /** The academic year a date belongs to; it starts in September. */
    public static function academicYear(string $col): string
    {
        return 'case when '.self::month($col).' >= 9 then '.self::year($col).' else '.self::year($col).' - 1 end';
    }

    private static function f(string $type, string $ar, string $en, string $expr, array $extra = []): array
    {
        return ['type' => $type, 'label' => ['ar' => $ar, 'en' => $en], 'expr' => $expr] + $extra;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        static $cache = null;

        return $cache ??= $this->build();
    }

    public function get(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /** Scope helper for datasets that hang off an employee (`e` is the employees alias). */
    private static function employeeScope(Builder $q, AccessScope $s, string $e = 'e'): void
    {
        if ($s->type() === 'none') {
            $q->whereRaw('1 = 0');

            return;
        }
        if ($s->departmentIds() !== null) {
            $q->whereIn("{$e}.department_id", $s->departmentIds());
        }
        if ($s->schoolIds() !== null) {
            $q->whereIn("{$e}.school_id", $s->schoolIds());
        }
    }

    /** Datasets that are about the centre itself are for people whose scope is the whole Ministry. */
    private static function centreOnly(Builder $q, AccessScope $s): void
    {
        if (! $s->isMinistryWide()) {
            $q->whereRaw('1 = 0');
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function build(): array
    {
        $emp = fn () => [
            'employee_no' => self::f('string', 'الرقم الوظيفي', 'Employee no.', 'e.employee_no', ['aggregates' => ['count', 'count_distinct']]),
            'name' => self::f('string', 'الاسم', 'Name', 'coalesce(u.name_ar, u.name)'),
            'school' => self::f('string', 'المدرسة', 'School', 's.name_ar'),
            'school_code' => self::f('string', 'رمز المدرسة', 'School code', 's.code'),
            'region' => self::f('enum', 'المنطقة', 'Region', 's.region'),
            'job_title' => self::f('string', 'المسمى الوظيفي', 'Job title', 'j.name_ar'),
            'job_category' => self::f('enum', 'الفئة الوظيفية', 'Job category', 'j.category'),
            'gender' => self::f('enum', 'الجنس', 'Gender', 'e.gender', ['values' => ['male', 'female']]),
        ];

        return [
            'employees' => [
                'label' => ['ar' => 'الموظفون', 'en' => 'Employees'], 'table' => 'employees as e',
                'joins' => [['users as u', 'u.id', 'e.user_id', 'inner'], ['schools as s', 's.id', 'e.school_id', 'left'], ['job_titles as j', 'j.id', 'e.job_title_id', 'left'], ['departments as d', 'd.id', 'e.department_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::employeeScope($q, $s),
                'date_field' => 'hire_date', 'default' => ['employee_no', 'name', 'school', 'job_title', 'gender'],
                'fields' => $emp() + [
                    'email' => self::f('string', 'البريد الإلكتروني', 'E-mail', 'u.email', ['personal' => true]),
                    'phone' => self::f('string', 'الجوال', 'Phone', 'u.phone', ['personal' => true]),
                    'national_id' => self::f('string', 'رقم الهوية', 'National ID', 'e.national_id', ['personal' => true]),
                    'nationality' => self::f('string', 'الجنسية', 'Nationality', 'e.nationality'),
                    'department' => self::f('string', 'القسم', 'Department', 'd.name_ar'),
                    'education_stage' => self::f('enum', 'المرحلة', 'Stage', 'e.education_stage'),
                    'qualification' => self::f('string', 'المؤهل', 'Qualification', 'e.qualification'),
                    'specialization' => self::f('string', 'التخصص', 'Specialisation', 'e.specialization'),
                    'experience_years' => self::f('number', 'سنوات الخبرة', 'Experience (years)', 'e.experience_years', ['aggregates' => ['avg', 'min', 'max']]),
                    'hire_date' => self::f('date', 'تاريخ التعيين', 'Hire date', 'e.hire_date'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'e.status'),
                    'supervisor_employee_id' => self::f('string', 'المدير المباشر (رقم داخلي)', 'Direct manager (internal id)', 'e.supervisor_id', ['hidden' => true]),
                    'employee_id' => self::f('string', 'المعرّف', 'Id', 'e.id', ['hidden' => true]),
                    'headcount' => self::f('number', 'العدد', 'Headcount', 'e.id', ['aggregates' => ['count', 'count_distinct'], 'computed_only' => true]),
                ],
            ],
            'registrations' => [
                'label' => ['ar' => 'التسجيلات', 'en' => 'Registrations'], 'table' => 'registrations as r',
                'joins' => [['employees as e', 'e.id', 'r.employee_id', 'inner'], ['users as u', 'u.id', 'e.user_id', 'inner'], ['programs as p', 'p.id', 'r.program_id', 'inner'], ['schools as s', 's.id', 'e.school_id', 'left'],
                    ['job_titles as j', 'j.id', 'e.job_title_id', 'left'], ['training_groups as g', 'g.id', 'r.training_group_id', 'left'], ['program_categories as c', 'c.id', 'p.category_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::employeeScope($q, $s),
                'date_field' => 'created_at', 'default' => ['employee_no', 'name', 'program', 'group', 'status'],
                'fields' => $emp() + [
                    'program_code' => self::f('string', 'رمز البرنامج', 'Program code', 'p.code'),
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'program_id' => self::f('string', 'معرّف البرنامج', 'Program id', 'p.id', ['hidden' => true]),
                    'employee_id' => self::f('string', 'المعرّف', 'Id', 'e.id', ['hidden' => true]),
                    'category' => self::f('string', 'فئة البرنامج', 'Program category', 'c.name_ar'),
                    'group' => self::f('string', 'المجموعة', 'Group', 'g.code'),
                    'group_id' => self::f('string', 'معرّف المجموعة', 'Group id', 'r.training_group_id', ['hidden' => true]),
                    'group_start' => self::f('date', 'بداية المجموعة', 'Group start', 'g.start_date'),
                    'group_end' => self::f('date', 'نهاية المجموعة', 'Group end', 'g.end_date'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'r.status'),
                    'source' => self::f('enum', 'مصدر التسجيل', 'Source', 'r.source'),
                    'attendance_percent' => self::f('number', 'نسبة الحضور %', 'Attendance %', 'r.attendance_percent', ['aggregates' => ['avg', 'min', 'max']]),
                    'course_percent' => self::f('number', 'إنجاز المقرر %', 'Course progress %', 'r.course_percent', ['aggregates' => ['avg']]),
                    'weighted_score' => self::f('number', 'الدرجة المرجحة', 'Weighted score', 'r.weighted_score', ['aggregates' => ['avg', 'min', 'max']]),
                    'pass_status' => self::f('enum', 'النتيجة', 'Result', 'r.pass_status'),
                    'tasks_completed' => self::f('bool', 'أنجز المهام', 'Tasks done', 'r.tasks_completed'),
                    'evaluation_completed' => self::f('bool', 'أكمل التقييم', 'Evaluation done', 'r.evaluation_completed'),
                    'certificate_status' => self::f('enum', 'حالة الشهادة', 'Certificate', 'r.certificate_status'),
                    'approved_at' => self::f('date', 'تاريخ الاعتماد', 'Approved on', 'r.approved_at'),
                    'completed_at' => self::f('date', 'تاريخ الإكمال', 'Completed on', 'r.completed_at'),
                    'created_at' => self::f('date', 'تاريخ التسجيل', 'Registered on', 'r.created_at'),
                    'year' => self::f('number', 'السنة', 'Year', self::year('r.created_at')),
                    'academic_year' => self::f('number', 'العام الدراسي', 'Academic year', self::academicYear('r.created_at')),
                    'month' => self::f('number', 'الشهر', 'Month', self::month('r.created_at')),
                    'program_hours' => self::f('number', 'ساعات البرنامج', 'Program hours', 'p.total_hours', ['aggregates' => ['sum', 'avg']]),
                    'registrations' => self::f('number', 'عدد التسجيلات', 'Registrations', 'r.id', ['aggregates' => ['count', 'count_distinct'], 'computed_only' => true]),
                ],
            ],
            'attendance' => [
                'label' => ['ar' => 'الحضور والغياب', 'en' => 'Attendance and absence'], 'table' => 'attendance as a',
                'joins' => [['registrations as r', 'r.id', 'a.registration_id', 'inner'], ['employees as e', 'e.id', 'a.employee_id', 'inner'], ['users as u', 'u.id', 'e.user_id', 'inner'], ['program_sessions as ps', 'ps.id', 'a.program_session_id', 'inner'],
                    ['programs as p', 'p.id', 'ps.program_id', 'inner'], ['training_groups as g', 'g.id', 'a.training_group_id', 'left'], ['schools as s', 's.id', 'e.school_id', 'left'], ['job_titles as j', 'j.id', 'e.job_title_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::employeeScope($q, $s),
                'date_field' => 'session_date', 'default' => ['employee_no', 'name', 'program', 'session_date', 'status'],
                'fields' => $emp() + [
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'group' => self::f('string', 'المجموعة', 'Group', 'g.code'),
                    'group_id' => self::f('string', 'معرّف المجموعة', 'Group id', 'a.training_group_id', ['hidden' => true]),
                    'employee_id' => self::f('string', 'المعرّف', 'Id', 'e.id', ['hidden' => true]),
                    'session' => self::f('string', 'الجلسة', 'Session', 'ps.title_ar'),
                    'session_date' => self::f('date', 'تاريخ الجلسة', 'Session date', 'ps.starts_at'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'a.status'),
                    'method' => self::f('enum', 'طريقة التسجيل', 'Method', 'a.method'),
                    'check_in_at' => self::f('date', 'الدخول', 'Check-in', 'a.check_in_at'),
                    'check_out_at' => self::f('date', 'الخروج', 'Check-out', 'a.check_out_at'),
                    'minutes_attended' => self::f('number', 'دقائق الحضور', 'Minutes attended', 'a.minutes_attended', ['aggregates' => ['sum', 'avg']]),
                    'leave_minutes' => self::f('number', 'دقائق الاستئذان', 'Leave minutes', 'a.leave_minutes', ['aggregates' => ['sum']]),
                    'participated' => self::f('bool', 'شارك', 'Participated', 'a.participated'),
                    'records' => self::f('number', 'عدد السجلات', 'Records', 'a.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'groups' => [
                'label' => ['ar' => 'البرامج والمجموعات', 'en' => 'Programs and groups'], 'table' => 'training_groups as g',
                'joins' => [['programs as p', 'p.id', 'g.program_id', 'inner'], ['program_categories as c', 'c.id', 'p.category_id', 'left'], ['users as sup', 'sup.id', 'g.supervisor_id', 'left']],
                'scope' => function (Builder $q, AccessScope $s) {
                    if (! $s->isMinistryWide()) {
                        // A school's view of groups: those where its own staff are enrolled.
                        $ids = $s->schoolIds() ?? [];
                        $q->whereExists(fn ($w) => $w->select(DB::raw(1))->from('registrations as rr')->join('employees as ee', 'ee.id', '=', 'rr.employee_id')->whereColumn('rr.training_group_id', 'g.id')->whereIn('ee.school_id', $ids));
                    }
                },
                'date_field' => 'start_date', 'default' => ['group', 'program', 'status', 'start_date', 'supervisor'],
                'fields' => [
                    'group' => self::f('string', 'رمز المجموعة', 'Group', 'g.code'),
                    'group_title' => self::f('string', 'عنوان المجموعة', 'Group title', 'coalesce(g.title_ar, g.code)'),
                    'program_code' => self::f('string', 'رمز البرنامج', 'Program code', 'p.code'),
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'category' => self::f('string', 'الفئة', 'Category', 'c.name_ar'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'g.status'),
                    'delivery_mode' => self::f('enum', 'نمط التقديم', 'Delivery mode', 'g.delivery_mode'),
                    'start_date' => self::f('date', 'البداية', 'Start', 'g.start_date'),
                    'end_date' => self::f('date', 'النهاية', 'End', 'g.end_date'),
                    'capacity' => self::f('number', 'السعة', 'Capacity', 'g.capacity', ['aggregates' => ['sum', 'avg']]),
                    'supervisor' => self::f('string', 'المشرف', 'Supervisor', 'coalesce(sup.name_ar, sup.name)'),
                    'supervisor_user_id' => self::f('string', 'معرّف المشرف', 'Supervisor id', 'g.supervisor_id', ['hidden' => true]),
                    'seats_taken' => self::f('number', 'المقاعد المشغولة', 'Seats taken', "(select count(*) from registrations x where x.training_group_id = g.id and x.status in ('pending','approved','completed'))", ['aggregates' => ['sum', 'avg']]),
                    'completed_count' => self::f('number', 'المكمِلون', 'Completed', "(select count(*) from registrations x where x.training_group_id = g.id and x.status = 'completed')", ['aggregates' => ['sum']]),
                    'year' => self::f('number', 'السنة', 'Year', self::year('g.start_date')),
                    'groups' => self::f('number', 'عدد المجموعات', 'Groups', 'g.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'sessions' => [
                'label' => ['ar' => 'الجلسات والتقويم', 'en' => 'Sessions and calendar'], 'table' => 'program_sessions as ps',
                'joins' => [['programs as p', 'p.id', 'ps.program_id', 'inner'], ['trainers as t', 't.id', 'ps.trainer_id', 'left'], ['training_rooms as rm', 'rm.id', 'ps.training_room_id', 'left'], ['training_groups as g', 'g.id', 'ps.training_group_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => $s->type() === 'none' ? $q->whereRaw('1 = 0') : null,
                'date_field' => 'starts_at', 'default' => ['date', 'session', 'program', 'trainer', 'room'],
                'fields' => [
                    'date' => self::f('date', 'التاريخ', 'Date', 'ps.starts_at'),
                    'starts_at' => self::f('date', 'يبدأ', 'Starts', 'ps.starts_at'),
                    'ends_at' => self::f('date', 'ينتهي', 'Ends', 'ps.ends_at'),
                    'session' => self::f('string', 'الجلسة', 'Session', 'ps.title_ar'),
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'group' => self::f('string', 'المجموعة', 'Group', 'g.code'),
                    'trainer' => self::f('string', 'المدرب', 'Trainer', 't.name_ar'),
                    'trainer_id' => self::f('string', 'معرّف المدرب', 'Trainer id', 'ps.trainer_id', ['hidden' => true]),
                    'room' => self::f('string', 'القاعة', 'Room', 'rm.name_ar'),
                    'mode' => self::f('enum', 'النمط', 'Mode', 'ps.mode'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'ps.status'),
                    'program_id' => self::f('string', 'معرّف البرنامج', 'Program id', 'ps.program_id', ['hidden' => true]),
                    'sessions' => self::f('number', 'عدد الجلسات', 'Sessions', 'ps.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'certificates' => [
                'label' => ['ar' => 'الشهادات', 'en' => 'Certificates'], 'table' => 'certificates as ce',
                'joins' => [['employees as e', 'e.id', 'ce.employee_id', 'inner'], ['users as u', 'u.id', 'e.user_id', 'inner'], ['programs as p', 'p.id', 'ce.program_id', 'inner'], ['schools as s', 's.id', 'e.school_id', 'left'], ['job_titles as j', 'j.id', 'e.job_title_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::employeeScope($q, $s),
                'date_field' => 'issued_at', 'default' => ['employee_no', 'name', 'program', 'certificate_no', 'issued_at'],
                'fields' => $emp() + [
                    'certificate_no' => self::f('string', 'رقم الشهادة', 'Certificate no.', 'ce.certificate_no'),
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'issued_at' => self::f('date', 'تاريخ الإصدار', 'Issued on', 'ce.issued_at'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'ce.status'),
                    'type' => self::f('enum', 'النوع', 'Type', 'ce.type'),
                    'hours' => self::f('number', 'الساعات', 'Hours', 'ce.hours', ['aggregates' => ['sum', 'avg']]),
                    'hours_total' => self::f('number', 'الساعات المعتمدة', 'Approved hours', 'ce.hours_total', ['aggregates' => ['sum']]),
                    'hours_actual' => self::f('number', 'الساعات الفعلية', 'Actual hours', 'ce.hours_actual', ['aggregates' => ['sum']]),
                    'year' => self::f('number', 'السنة', 'Year', self::year('ce.issued_at')),
                    'academic_year' => self::f('number', 'العام الدراسي', 'Academic year', self::academicYear('ce.issued_at')),
                    'certificates' => self::f('number', 'عدد الشهادات', 'Certificates', 'ce.id', ['aggregates' => ['count'], 'computed_only' => true]),
                    'employee_id' => self::f('string', 'المعرّف', 'Id', 'e.id', ['hidden' => true]),
                ],
            ],
            'assessments' => [
                'label' => ['ar' => 'الاختبارات والمحاولات', 'en' => 'Assessments and attempts'], 'table' => 'assessment_attempts as at',
                'joins' => [['registrations as r', 'r.id', 'at.registration_id', 'inner'], ['employees as e', 'e.id', 'r.employee_id', 'inner'], ['users as u', 'u.id', 'e.user_id', 'inner'], ['assessments as a', 'a.id', 'at.assessment_id', 'inner'],
                    ['programs as p', 'p.id', 'a.program_id', 'inner'], ['schools as s', 's.id', 'e.school_id', 'left'], ['job_titles as j', 'j.id', 'e.job_title_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::employeeScope($q, $s),
                'date_field' => 'submitted_at', 'default' => ['employee_no', 'name', 'assessment', 'score_percent', 'passed'],
                'fields' => $emp() + [
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'assessment' => self::f('string', 'الاختبار', 'Assessment', 'a.title_ar'),
                    'kind' => self::f('enum', 'النوع', 'Kind', 'a.kind'),
                    'attempt_no' => self::f('number', 'رقم المحاولة', 'Attempt', 'at.attempt_no', ['aggregates' => ['max']]),
                    'score_percent' => self::f('number', 'النسبة %', 'Score %', 'at.score_percent', ['aggregates' => ['avg', 'min', 'max']]),
                    'passed' => self::f('bool', 'ناجح', 'Passed', 'at.passed'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'at.status'),
                    'submitted_at' => self::f('date', 'تاريخ التسليم', 'Submitted', 'at.submitted_at'),
                    'attempts' => self::f('number', 'عدد المحاولات', 'Attempts', 'at.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'evaluations' => [
                'label' => ['ar' => 'التقييم والرضا ومكتسبات التعلم', 'en' => 'Evaluation, satisfaction and knowledge gain'], 'table' => 'evaluations as ev',
                'joins' => [['employees as e', 'e.id', 'ev.employee_id', 'inner'], ['users as u', 'u.id', 'e.user_id', 'inner'], ['programs as p', 'p.id', 'ev.program_id', 'inner'], ['schools as s', 's.id', 'e.school_id', 'left'], ['job_titles as j', 'j.id', 'e.job_title_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::employeeScope($q, $s),
                'date_field' => 'submitted_at', 'default' => ['program', 'satisfaction_score', 'submitted_at'],
                'fields' => $emp() + [
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'program_code' => self::f('string', 'رمز البرنامج', 'Program code', 'p.code'),
                    'satisfaction_score' => self::f('number', 'الرضا (من 100)', 'Satisfaction (of 100)', 'ev.satisfaction_score', ['aggregates' => ['avg', 'min', 'max']]),
                    'pre_test_score' => self::f('number', 'الاختبار القبلي', 'Pre-test', 'ev.pre_test_score', ['aggregates' => ['avg']]),
                    'post_test_score' => self::f('number', 'الاختبار البعدي', 'Post-test', 'ev.post_test_score', ['aggregates' => ['avg']]),
                    'knowledge_gain' => self::f('number', 'مكتسب المعرفة %', 'Knowledge gain %', 'case when ev.pre_test_score > 0 and ev.post_test_score is not null then (ev.post_test_score - ev.pre_test_score) * 100.0 / ev.pre_test_score end', ['aggregates' => ['avg']]),
                    'submitted_at' => self::f('date', 'تاريخ التقديم', 'Submitted', 'ev.submitted_at'),
                    'year' => self::f('number', 'السنة', 'Year', self::year('ev.submitted_at')),
                    'responses' => self::f('number', 'عدد الردود', 'Responses', 'ev.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'pd' => [
                'label' => ['ar' => 'التطوير المهني', 'en' => 'Professional development'], 'table' => 'pd_activities as d',
                'joins' => [['employees as e', 'e.id', 'd.employee_id', 'inner'], ['users as u', 'u.id', 'e.user_id', 'inner'], ['pd_activity_types as t', 't.id', 'd.type_id', 'left'], ['schools as s', 's.id', 'e.school_id', 'left'], ['job_titles as j', 'j.id', 'e.job_title_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::employeeScope($q, $s),
                'date_field' => 'starts_on', 'default' => ['employee_no', 'name', 'title', 'approved_hours', 'status'],
                'fields' => $emp() + [
                    'title' => self::f('string', 'النشاط', 'Activity', 'd.title'),
                    'type' => self::f('string', 'نوع النشاط', 'Activity type', 't.name_ar'),
                    'provider' => self::f('string', 'الجهة', 'Provider', 'd.provider'),
                    'starts_on' => self::f('date', 'بداية', 'Start', 'd.starts_on'),
                    'duration_hours' => self::f('number', 'الساعات المدخلة', 'Hours entered', 'd.duration_hours', ['aggregates' => ['sum']]),
                    'approved_hours' => self::f('number', 'الساعات المعتمدة', 'Approved hours', 'd.approved_hours', ['aggregates' => ['sum', 'avg']]),
                    'status' => self::f('enum', 'الحالة', 'Status', 'd.status'),
                    'year' => self::f('number', 'السنة', 'Year', self::year('d.starts_on')),
                    'activities' => self::f('number', 'عدد الأنشطة', 'Activities', 'd.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'trainers' => [
                'label' => ['ar' => 'المدربون والساعات المنفذة', 'en' => 'Trainers and delivered hours'], 'table' => 'group_trainers as gt',
                'joins' => [['trainers as t', 't.id', 'gt.trainer_id', 'inner'], ['training_groups as g', 'g.id', 'gt.group_id', 'inner'], ['programs as p', 'p.id', 'g.program_id', 'inner']],
                'scope' => fn (Builder $q, AccessScope $s) => self::centreOnly($q, $s),
                'date_field' => 'start_date', 'default' => ['trainer', 'program', 'group', 'hours', 'group_status'],
                'fields' => [
                    'trainer' => self::f('string', 'المدرب', 'Trainer', 't.name_ar'),
                    'trainer_id' => self::f('string', 'معرّف المدرب', 'Trainer id', 'gt.trainer_id', ['hidden' => true]),
                    'trainer_user_id' => self::f('string', 'معرّف حساب المدرب', 'Trainer account', 't.user_id', ['hidden' => true]),
                    'organization' => self::f('string', 'الجهة', 'Organisation', 't.organization'),
                    'rating' => self::f('number', 'التقييم', 'Rating', 't.rating', ['aggregates' => ['avg']]),
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'group' => self::f('string', 'المجموعة', 'Group', 'g.code'),
                    'role' => self::f('enum', 'الدور', 'Role', 'gt.role'),
                    'hours' => self::f('number', 'الساعات', 'Hours', 'gt.hours', ['aggregates' => ['sum', 'avg']]),
                    'assignment_status' => self::f('enum', 'حالة الإسناد', 'Assignment', 'gt.status'),
                    'group_status' => self::f('enum', 'حالة المجموعة', 'Group status', 'g.status'),
                    'start_date' => self::f('date', 'بداية المجموعة', 'Group start', 'g.start_date'),
                    'end_date' => self::f('date', 'نهاية المجموعة', 'Group end', 'g.end_date'),
                    'assignments' => self::f('number', 'عدد الإسنادات', 'Assignments', 'gt.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'kits' => [
                'label' => ['ar' => 'الحقائب التدريبية', 'en' => 'Training kits'], 'table' => 'training_kits as k',
                'joins' => [['programs as p', 'p.id', 'k.program_id', 'left'], ['users as o', 'o.id', 'k.owner_id', 'left'], ['users as co', 'co.id', 'p.coordinator_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::centreOnly($q, $s),
                'date_field' => 'due_at', 'default' => ['code', 'title', 'status', 'program', 'owner'],
                'fields' => [
                    'code' => self::f('string', 'الرمز', 'Code', 'k.code'),
                    'title' => self::f('string', 'العنوان', 'Title', 'k.title_ar'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'k.status'),
                    'version' => self::f('number', 'الإصدار', 'Version', 'k.version', ['aggregates' => ['max']]),
                    'review_round' => self::f('number', 'جولة المراجعة', 'Review round', 'k.review_round', ['aggregates' => ['max', 'avg']]),
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'supervisor' => self::f('string', 'مشرف البرنامج', 'Program supervisor', 'coalesce(co.name_ar, co.name)'),
                    'owner' => self::f('string', 'معد الحقيبة', 'Developer', 'coalesce(o.name_ar, o.name)'),
                    'owner_id' => self::f('string', 'معرّف المعد', 'Developer id', 'k.owner_id', ['hidden' => true]),
                    'due_at' => self::f('date', 'الموعد', 'Due', 'k.due_at'),
                    'approved_at' => self::f('date', 'تاريخ الاعتماد', 'Approved on', 'k.approved_at'),
                    'published_at' => self::f('date', 'تاريخ النشر', 'Published on', 'k.published_at'),
                    'kits' => self::f('number', 'عدد الحقائب', 'Kits', 'k.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'deliveries' => [
                'label' => ['ar' => 'الإشعارات والتسليم', 'en' => 'Notifications and deliveries'], 'table' => 'notification_deliveries as nd',
                'joins' => [['users as u', 'u.id', 'nd.user_id', 'left'], ['employees as e', 'e.user_id', 'u.id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => $s->isMinistryWide() ? null : $q->whereIn('e.school_id', $s->schoolIds() ?? []),
                'date_field' => 'created_at', 'default' => ['created_at', 'channel', 'type', 'status'],
                'fields' => [
                    'created_at' => self::f('date', 'التاريخ', 'Date', 'nd.created_at'),
                    'channel' => self::f('enum', 'القناة', 'Channel', 'nd.channel'),
                    'type' => self::f('string', 'النوع', 'Type', 'nd.type'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'nd.status'),
                    'reason' => self::f('string', 'السبب', 'Reason', 'nd.reason'),
                    'deliveries' => self::f('number', 'العدد', 'Count', 'nd.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'rooms' => [
                'label' => ['ar' => 'القاعات والحجوزات', 'en' => 'Rooms and bookings'], 'table' => 'room_bookings as rb',
                'joins' => [['training_rooms as rm', 'rm.id', 'rb.room_id', 'inner']],
                'scope' => fn (Builder $q, AccessScope $s) => self::centreOnly($q, $s),
                'date_field' => 'starts_at', 'default' => ['room', 'title', 'starts_at', 'status'],
                'fields' => [
                    'room' => self::f('string', 'القاعة', 'Room', 'rm.name_ar'),
                    'building' => self::f('string', 'المبنى', 'Building', 'rm.building'),
                    'title' => self::f('string', 'الغرض', 'Purpose', 'coalesce(rb.title, rb.purpose)'),
                    'starts_at' => self::f('date', 'يبدأ', 'Starts', 'rb.starts_at'),
                    'ends_at' => self::f('date', 'ينتهي', 'Ends', 'rb.ends_at'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'rb.status'),
                    'attendees' => self::f('number', 'الحضور', 'Attendees', 'rb.attendees', ['aggregates' => ['sum', 'avg']]),
                    'bookings' => self::f('number', 'عدد الحجوزات', 'Bookings', 'rb.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'plan_items' => [
                'label' => ['ar' => 'بنود الخطة السنوية وتنفيذها', 'en' => 'Annual plan items and execution'], 'table' => 'training_plan_items as i',
                'joins' => [['training_plans as pl', 'pl.id', 'i.plan_id', 'inner'], ['programs as p', 'p.id', 'i.program_id', 'left'], ['program_categories as c', 'c.id', 'i.category_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::centreOnly($q, $s),
                'date_field' => 'window_start', 'default' => ['year', 'title', 'planned_groups', 'status'],
                'fields' => [
                    'year' => self::f('number', 'سنة الخطة', 'Plan year', 'pl.year'),
                    'plan_status' => self::f('enum', 'حالة الخطة', 'Plan status', 'pl.status'),
                    'title' => self::f('string', 'البند', 'Item', 'i.title_ar'),
                    'category' => self::f('string', 'الفئة', 'Category', 'c.name_ar'),
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'planned_groups' => self::f('number', 'المجموعات المخططة', 'Planned groups', 'i.planned_groups', ['aggregates' => ['sum']]),
                    'planned_seats' => self::f('number', 'المقاعد المخططة', 'Planned seats', 'i.planned_seats', ['aggregates' => ['sum']]),
                    'planned_hours' => self::f('number', 'الساعات المخططة', 'Planned hours', 'i.planned_hours', ['aggregates' => ['sum']]),
                    'window_start' => self::f('date', 'بداية النافذة', 'Window start', 'i.window_start'),
                    'status' => self::f('enum', 'حالة البند', 'Item status', 'i.status'),
                    'is_emergency' => self::f('bool', 'طارئ', 'Emergency', 'i.is_emergency'),
                    'items' => self::f('number', 'عدد البنود', 'Items', 'i.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'withdrawals' => [
                'label' => ['ar' => 'طلبات الانسحاب', 'en' => 'Withdrawals'], 'table' => 'withdrawal_requests as w',
                'joins' => [['registrations as r', 'r.id', 'w.registration_id', 'inner'], ['employees as e', 'e.id', 'r.employee_id', 'inner'], ['users as u', 'u.id', 'e.user_id', 'inner'], ['programs as p', 'p.id', 'r.program_id', 'inner'], ['schools as s', 's.id', 'e.school_id', 'left'], ['job_titles as j', 'j.id', 'e.job_title_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => self::employeeScope($q, $s),
                'date_field' => 'created_at', 'default' => ['employee_no', 'name', 'program', 'reason_code', 'status'],
                'fields' => $emp() + [
                    'program' => self::f('string', 'البرنامج', 'Program', 'p.title_ar'),
                    'reason_code' => self::f('enum', 'السبب', 'Reason', 'w.reason_code'),
                    'timing' => self::f('enum', 'التوقيت', 'Timing', 'w.timing'),
                    'stage' => self::f('enum', 'المرحلة', 'Stage', 'w.stage'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'w.status'),
                    'is_late' => self::f('bool', 'متأخر', 'Late', 'w.is_late'),
                    'created_at' => self::f('date', 'تاريخ الطلب', 'Requested', 'w.created_at'),
                    'requests' => self::f('number', 'عدد الطلبات', 'Requests', 'w.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
            'needs' => [
                'label' => ['ar' => 'الاحتياجات التدريبية', 'en' => 'Training needs'], 'table' => 'training_needs as n',
                'joins' => [['schools as s', 's.id', 'n.school_id', 'left']],
                'scope' => fn (Builder $q, AccessScope $s) => $s->isMinistryWide() ? null : $q->whereIn('n.school_id', $s->schoolIds() ?? []),
                'date_field' => 'created_at', 'default' => ['school', 'skill_name', 'employees_count', 'priority', 'status'],
                'fields' => [
                    'school' => self::f('string', 'المدرسة', 'School', 's.name_ar'),
                    'region' => self::f('enum', 'المنطقة', 'Region', 's.region'),
                    'skill_name' => self::f('string', 'المهارة', 'Skill', 'n.skill_name'),
                    'employees_count' => self::f('number', 'عدد المعلمين', 'Employees', 'n.employees_count', ['aggregates' => ['sum', 'avg']]),
                    'priority' => self::f('enum', 'الأولوية', 'Priority', 'n.priority'),
                    'status' => self::f('enum', 'الحالة', 'Status', 'n.status'),
                    'created_at' => self::f('date', 'التاريخ', 'Date', 'n.created_at'),
                    'needs' => self::f('number', 'عدد الاحتياجات', 'Needs', 'n.id', ['aggregates' => ['count'], 'computed_only' => true]),
                ],
            ],
        ];
    }

    /** Dataset description for the builder screen (hidden and personal fields only when allowed). @return array<string, mixed> */
    public function describe(User $user): array
    {
        $personal = $user->hasPermission('reports.export_personal');
        $out = [];
        foreach ($this->all() as $key => $d) {
            $fields = [];
            foreach ($d['fields'] as $fk => $fd) {
                if (! empty($fd['hidden']) || (! empty($fd['personal']) && ! $personal)) {
                    continue;
                }
                $fields[] = ['key' => $fk, 'label' => $fd['label'], 'type' => $fd['type'], 'aggregates' => $fd['aggregates'] ?? [], 'operators' => self::OPERATORS[$fd['type']], 'personal' => ! empty($fd['personal']), 'computed_only' => ! empty($fd['computed_only']), 'values' => $fd['values'] ?? null];
            }
            $out[] = ['key' => $key, 'label' => $d['label'], 'fields' => $fields, 'default' => $d['default'], 'date_field' => $d['date_field'] ?? null];
        }

        return $out;
    }
}
