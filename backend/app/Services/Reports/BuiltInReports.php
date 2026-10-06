<?php

namespace App\Services\Reports;

use App\Models\ReportDefinition;
use App\Models\Role;

/**
 * The reports the RFP names for each role, as system definitions over the datasets. Administrators can copy and change them; the originals
 * are restored by `ensure()`. Filters marked `adjustable` can be changed at run time; tokens like @me make a report "mine".
 */
class BuiltInReports
{
    private const ADMIN = [Role::SUPER_ADMIN, Role::CENTER_ADMIN, Role::TRAINING_HEAD, Role::CENTER_LEADERSHIP, Role::EXECUTIVE];

    private const STAFF = [Role::SUPER_ADMIN, Role::CENTER_ADMIN, Role::TRAINING_HEAD, Role::COORDINATOR, Role::CENTER_LEADERSHIP, Role::EXECUTIVE];

    private static function col(string $field, ?string $aggregate = null): array
    {
        return ['field' => $field] + ($aggregate ? ['aggregate' => $aggregate] : []);
    }

    private static function flt(string $field, string $op, mixed $value = null, bool $adjustable = false): array
    {
        return ['field' => $field, 'operator' => $op, 'value' => $value, 'adjustable' => $adjustable];
    }

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        $c = fn (string ...$f) => array_map(fn ($x) => ['field' => $x], $f);
        $from = fn (string $field) => self::flt($field, 'gte', null, true);
        $to = fn (string $field) => self::flt($field, 'lte', null, true);

        return [
            // ---- system administration and centre staff ------------------------------------------------
            ['key' => 'employees_data', 'category' => 'admin', 'roles' => self::ADMIN, 'title' => ['تقرير بيانات الموظفين', 'Employee data'], 'dataset' => 'employees',
                'columns' => $c('employee_no', 'name', 'school', 'job_title', 'job_category', 'gender', 'nationality', 'education_stage', 'qualification', 'experience_years', 'hire_date', 'status'),
                'filters' => [self::flt('school', 'contains', null, true), self::flt('job_category', 'eq', null, true), self::flt('gender', 'eq', null, true), self::flt('nationality', 'contains', null, true), self::flt('education_stage', 'eq', null, true), self::flt('qualification', 'contains', null, true)],
                'sort' => [['field' => 'name']]],
            ['key' => 'employee_courses', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['تقرير دورات الموظفين', 'Employee courses'], 'dataset' => 'registrations',
                'columns' => $c('employee_no', 'name', 'school', 'program', 'group', 'status', 'attendance_percent', 'pass_status', 'completed_at'),
                'filters' => [self::flt('status', 'eq', null, true), self::flt('school', 'contains', null, true), $from('created_at'), $to('created_at')], 'sort' => [['field' => 'name']]],
            ['key' => 'programs_paths_groups', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['تقرير البرامج والمسارات والمجموعات', 'Programs, paths and groups'], 'dataset' => 'registrations',
                'columns' => $c('category', 'program', 'group', 'job_category', 'status', 'gender', 'employee_no', 'group_start', 'group_end'),
                'filters' => [self::flt('job_category', 'eq', null, true), self::flt('gender', 'eq', null, true), self::flt('status', 'eq', null, true), $from('group_start'), $to('group_end')], 'sort' => [['field' => 'program']]],
            ['key' => 'employee_courses_lookup', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['استعلام دورات موظف بالرقم الوظيفي', 'Courses of one employee by number'], 'dataset' => 'registrations',
                'columns' => $c('employee_no', 'name', 'program', 'group', 'status', 'program_hours', 'completed_at'), 'filters' => [self::flt('employee_no', 'eq', null, true)]],
            ['key' => 'attendance_detail', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['الحضور والغياب التفصيلي لكل متدرب', 'Detailed attendance and absence per trainee'], 'dataset' => 'attendance',
                'columns' => $c('employee_no', 'name', 'program', 'group', 'session', 'session_date', 'status', 'method', 'minutes_attended', 'leave_minutes'),
                'filters' => [self::flt('program', 'contains', null, true), self::flt('group', 'contains', null, true), self::flt('status', 'eq', null, true), $from('session_date'), $to('session_date')], 'sort' => [['field' => 'session_date', 'dir' => 'desc']]],
            ['key' => 'programs_licences_matrix', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['مصفوفة البرامج والرخص', 'Programs and licences matrix'], 'dataset' => 'employees',
                'columns' => $c('employee_no', 'name'), 'options' => ['kind' => 'matrix']],
            ['key' => 'trainer_followup', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['متابعة المدربين: الدورات المنفذة وحالة الإكمال', 'Trainer follow-up: courses delivered and completion'], 'dataset' => 'trainers',
                'columns' => $c('trainer', 'program', 'group', 'role', 'hours', 'assignment_status', 'group_status', 'start_date', 'end_date'),
                'filters' => [self::flt('trainer', 'contains', null, true), self::flt('group_status', 'eq', null, true), $from('start_date'), $to('end_date')], 'sort' => [['field' => 'trainer']]],
            ['key' => 'achievement_statistics', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['إحصائيات الإنجاز', 'Achievement statistics'], 'dataset' => 'registrations',
                'columns' => [self::col('school'), self::col('registrations', 'count'), self::col('attendance_percent', 'avg')], 'group_by' => ['school'], 'sort' => [['field' => 'registrations__count', 'dir' => 'desc']],
                'chart' => ['type' => 'bar', 'x' => 'school', 'y' => 'registrations__count'],
                'options' => ['parts' => [
                    ['title' => ['حسب المدرسة', 'By school'], 'dataset' => 'registrations', 'columns' => [self::col('school'), self::col('registrations', 'count'), self::col('attendance_percent', 'avg')], 'sort' => [['field' => 'registrations__count', 'dir' => 'desc']]],
                    ['title' => ['حسب الفئة الوظيفية', 'By job category'], 'dataset' => 'registrations', 'columns' => [self::col('job_category'), self::col('registrations', 'count'), self::col('attendance_percent', 'avg')]],
                    ['title' => ['حسب فئة البرنامج', 'By program category'], 'dataset' => 'registrations', 'columns' => [self::col('category'), self::col('registrations', 'count'), self::col('attendance_percent', 'avg')]],
                    ['title' => ['حسب الشهر', 'By month'], 'dataset' => 'registrations', 'columns' => [self::col('year'), self::col('month'), self::col('registrations', 'count')], 'sort' => [['field' => 'year'], ['field' => 'month']]],
                    ['title' => ['الشهادات حسب السنة', 'Certificates by year'], 'dataset' => 'certificates', 'columns' => [self::col('year'), self::col('certificates', 'count'), self::col('hours', 'sum')], 'sort' => [['field' => 'year']]],
                ]]],
            ['key' => 'process_tracking', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['تتبع مراحل الإجراءات', 'Process tracking'], 'dataset' => 'registrations',
                'columns' => [self::col('program'), self::col('status'), self::col('registrations', 'count')], 'group_by' => ['program', 'status'], 'sort' => [['field' => 'program']]],
            ['key' => 'trainee_results', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['نتائج المتدربين: الحضور والأنشطة والاختبارات', 'Trainee results: attendance, activities, tests'], 'dataset' => 'registrations',
                'columns' => $c('employee_no', 'name', 'program', 'group', 'attendance_percent', 'tasks_completed', 'weighted_score', 'pass_status'),
                'filters' => [self::flt('program', 'contains', null, true), self::flt('pass_status', 'eq', null, true)], 'sort' => [['field' => 'program']]],
            ['key' => 'programs_by_job_category', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['البرامج المستهدفة لكل فئة وظيفية', 'Programs targeted per job category'], 'dataset' => 'registrations',
                'columns' => [self::col('job_category'), self::col('program'), self::col('registrations', 'count')], 'group_by' => ['job_category', 'program'], 'sort' => [['field' => 'job_category']]],
            ['key' => 'satisfaction_courses', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['الرضا عن الدورات', 'Satisfaction with courses'], 'dataset' => 'evaluations',
                'columns' => [self::col('program'), self::col('responses', 'count'), self::col('satisfaction_score', 'avg'), self::col('knowledge_gain', 'avg')], 'group_by' => ['program'], 'sort' => [['field' => 'satisfaction_score__avg', 'dir' => 'desc']],
                'chart' => ['type' => 'bar', 'x' => 'program', 'y' => 'satisfaction_score__avg']],
            ['key' => 'satisfaction_trainers', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['الرضا عن المدربين', 'Satisfaction with trainers'], 'dataset' => 'trainers',
                'columns' => [self::col('trainer'), self::col('assignments', 'count'), self::col('hours', 'sum'), self::col('rating', 'avg')], 'group_by' => ['trainer'], 'sort' => [['field' => 'rating__avg', 'dir' => 'desc']]],
            ['key' => 'hours_approved_vs_actual', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['الساعات المعتمدة مقابل الفعلية لكل متدرب', 'Approved vs actual hours per trainee'], 'dataset' => 'certificates',
                'columns' => [self::col('employee_no'), self::col('name'), self::col('hours_total', 'sum'), self::col('hours_actual', 'sum')], 'group_by' => ['employee_no', 'name'], 'filters' => [$from('issued_at'), $to('issued_at')]],
            ['key' => 'statistics_periodic', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['إحصائيات سنوية وفصلية وشهرية', 'Yearly, quarterly and monthly statistics'], 'dataset' => 'registrations',
                'columns' => [self::col('year'), self::col('month'), self::col('registrations', 'count'), self::col('attendance_percent', 'avg')], 'group_by' => ['year', 'month'], 'sort' => [['field' => 'year'], ['field' => 'month']],
                'chart' => ['type' => 'line', 'x' => 'month', 'y' => 'registrations__count']],
            ['key' => 'pd_hours', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['ساعات التطوير المهني', 'Professional development hours'], 'dataset' => 'pd',
                'columns' => [self::col('school'), self::col('activities', 'count'), self::col('approved_hours', 'sum')], 'group_by' => ['school'], 'sort' => [['field' => 'approved_hours__sum', 'dir' => 'desc']]],
            ['key' => 'withdrawals_overview', 'category' => 'admin', 'roles' => self::STAFF, 'title' => ['طلبات الانسحاب', 'Withdrawal requests'], 'dataset' => 'withdrawals',
                'columns' => [self::col('reason_code'), self::col('status'), self::col('requests', 'count')], 'group_by' => ['reason_code', 'status']],
            ['key' => 'notification_deliveries', 'category' => 'admin', 'roles' => self::ADMIN, 'title' => ['تسليم الإشعارات', 'Notification delivery'], 'dataset' => 'deliveries',
                'columns' => [self::col('channel'), self::col('status'), self::col('deliveries', 'count')], 'group_by' => ['channel', 'status']],
            // ---- supervisors ---------------------------------------------------------------------------
            ['key' => 'attendance_sheet', 'category' => 'supervisor', 'roles' => self::STAFF, 'title' => ['كشف حضور للطباعة لكل مجموعة', 'Printable attendance sheet per group'], 'dataset' => 'registrations',
                'columns' => $c('employee_no', 'name', 'school', 'job_title'), 'filters' => [self::flt('group', 'eq', null, true), self::flt('status', 'in', ['approved', 'completed'])], 'sort' => [['field' => 'name']], 'options' => ['kind' => 'sheet', 'blank_columns' => 6]],
            ['key' => 'workshop_calendar', 'category' => 'supervisor', 'roles' => self::STAFF, 'title' => ['تقويم الورش الأسبوعي والشهري', 'Weekly and monthly workshop calendar'], 'dataset' => 'sessions',
                'columns' => $c('date', 'session', 'program', 'group', 'trainer', 'room', 'mode', 'status'), 'filters' => [$from('starts_at'), $to('starts_at')], 'sort' => [['field' => 'date']]],
            ['key' => 'groups_supervised', 'category' => 'supervisor', 'roles' => self::STAFF, 'title' => ['البرامج والمجموعات المشرف عليها في فترة', 'Programs and groups supervised in a period'], 'dataset' => 'groups',
                'columns' => $c('group', 'program', 'status', 'start_date', 'end_date', 'seats_taken', 'completed_count'), 'filters' => [self::flt('supervisor_user_id', 'eq', '@me'), $from('start_date'), $to('end_date')]],
            ['key' => 'attendance_leave_group', 'category' => 'supervisor', 'roles' => self::STAFF, 'title' => ['الحضور والغياب والاستئذان لكل مجموعة', 'Attendance, absence and leave per group'], 'dataset' => 'attendance',
                'columns' => [self::col('group'), self::col('employee_no'), self::col('name'), self::col('records', 'count'), self::col('minutes_attended', 'sum'), self::col('leave_minutes', 'sum')], 'group_by' => ['group', 'employee_no', 'name'],
                'filters' => [self::flt('group', 'contains', null, true), $from('session_date'), $to('session_date')]],
            // ---- trainers ------------------------------------------------------------------------------
            ['key' => 'trainer_calendar', 'category' => 'trainer', 'roles' => [Role::TRAINER], 'title' => ['تقويم ورشي', 'My workshop calendar'], 'dataset' => 'sessions',
                'columns' => $c('date', 'session', 'program', 'group', 'room', 'mode', 'status'), 'filters' => [self::flt('trainer_id', 'eq', '@my_trainer'), $from('starts_at'), $to('starts_at')], 'sort' => [['field' => 'date']]],
            ['key' => 'trainer_statistics', 'category' => 'trainer', 'roles' => [Role::TRAINER], 'title' => ['إحصائيات البرامج والمجموعات التي نفذتها', 'Statistics of the programs and groups I delivered'], 'dataset' => 'trainers',
                'columns' => [self::col('program'), self::col('assignments', 'count'), self::col('hours', 'sum')], 'group_by' => ['program'], 'filters' => [self::flt('trainer_id', 'eq', '@my_trainer')]],
            ['key' => 'trainer_process_tracking', 'category' => 'trainer', 'roles' => [Role::TRAINER], 'title' => ['تتبع مراحل مجموعاتي', 'Process tracking for my groups'], 'dataset' => 'sessions',
                'columns' => [self::col('program'), self::col('status'), self::col('sessions', 'count')], 'group_by' => ['program', 'status'], 'filters' => [self::flt('trainer_id', 'eq', '@my_trainer')]],
            // ---- direct managers -----------------------------------------------------------------------
            ['key' => 'manager_staff_courses', 'category' => 'manager', 'roles' => [Role::SUPERVISOR, Role::SCHOOL_ADMIN, Role::ACADEMIC_DEPUTY], 'title' => ['الدورات التي حصل عليها موظفوني في فترة', 'Courses my staff obtained in a period'], 'dataset' => 'registrations',
                'columns' => $c('employee_no', 'name', 'program', 'status', 'completed_at'), 'filters' => [self::flt('status', 'eq', 'completed'), $from('completed_at'), $to('completed_at')], 'options' => ['scope' => 'staff']],
            ['key' => 'manager_nominations', 'category' => 'manager', 'roles' => [Role::SUPERVISOR, Role::SCHOOL_ADMIN, Role::ACADEMIC_DEPUTY], 'title' => ['الترشيحات ومسار الاعتماد', 'Nominations and approval flow'], 'dataset' => 'registrations',
                'columns' => $c('employee_no', 'name', 'program', 'source', 'status', 'created_at', 'approved_at'), 'filters' => [self::flt('source', 'in', ['school_nomination', 'center_nomination'])], 'options' => ['scope' => 'staff']],
            ['key' => 'manager_staff_attendance', 'category' => 'manager', 'roles' => [Role::SUPERVISOR, Role::SCHOOL_ADMIN, Role::ACADEMIC_DEPUTY], 'title' => ['حضور وغياب موظفيّ', 'Attendance and absence of my staff'], 'dataset' => 'attendance',
                'columns' => [self::col('employee_no'), self::col('name'), self::col('records', 'count'), self::col('minutes_attended', 'sum')], 'group_by' => ['employee_no', 'name'], 'filters' => [$from('session_date'), $to('session_date')], 'options' => ['scope' => 'staff']],
            // ---- trainees ------------------------------------------------------------------------------
            ['key' => 'my_calendar', 'category' => 'trainee', 'roles' => [], 'title' => ['تقويم ورشي المعتمدة', 'My approved workshop calendar'], 'dataset' => 'sessions',
                'columns' => $c('date', 'session', 'program', 'trainer', 'room', 'mode'), 'filters' => [$from('starts_at'), $to('starts_at')], 'options' => ['scope' => 'my_registrations'], 'sort' => [['field' => 'date']]],
            ['key' => 'my_hours_by_year', 'category' => 'trainee', 'roles' => [], 'title' => ['دوراتي وساعاتي حسب السنة أو العام الدراسي', 'My courses and hours by year or academic year'], 'dataset' => 'certificates',
                'columns' => [self::col('academic_year'), self::col('certificates', 'count'), self::col('hours', 'sum')], 'group_by' => ['academic_year'], 'filters' => [self::flt('employee_id', 'eq', '@my_employee')], 'sort' => [['field' => 'academic_year', 'dir' => 'desc']],
                'chart' => ['type' => 'bar', 'x' => 'academic_year', 'y' => 'hours__sum']],
            ['key' => 'programs_i_can_apply', 'category' => 'trainee', 'roles' => [], 'title' => ['البرامج التي يمكنني التقدم لها', 'Programs I can apply for'], 'dataset' => 'groups',
                'columns' => $c('program', 'group', 'start_date', 'end_date', 'delivery_mode', 'seats_taken', 'capacity'), 'filters' => [self::flt('status', 'eq', 'registration_open')], 'options' => ['scope' => 'open']],
            ['key' => 'my_completed_courses', 'category' => 'trainee', 'roles' => [], 'title' => ['دوراتي المكتملة', 'My completed courses'], 'dataset' => 'registrations',
                'columns' => $c('program', 'group', 'completed_at', 'program_hours', 'pass_status'), 'filters' => [self::flt('employee_id', 'eq', '@my_employee'), self::flt('status', 'eq', 'completed')]],
            ['key' => 'my_attendance', 'category' => 'trainee', 'roles' => [], 'title' => ['حضوري', 'My attendance'], 'dataset' => 'attendance',
                'columns' => $c('program', 'session', 'session_date', 'status', 'minutes_attended'), 'filters' => [self::flt('employee_id', 'eq', '@my_employee'), $from('session_date'), $to('session_date')], 'sort' => [['field' => 'session_date', 'dir' => 'desc']]],
            ['key' => 'my_courses_statement', 'category' => 'trainee', 'roles' => [], 'title' => ['كشف دوراتي في فترة', 'Statement of my courses in a period'], 'dataset' => 'registrations',
                'columns' => $c('program', 'group', 'status', 'attendance_percent', 'completed_at', 'program_hours'), 'filters' => [self::flt('employee_id', 'eq', '@my_employee'), $from('created_at'), $to('created_at')], 'sort' => [['field' => 'created_at', 'dir' => 'desc']]],
            // ---- quality and kit developers -------------------------------------------------------------
            ['key' => 'kits_programs_supervisors', 'category' => 'qa', 'roles' => [Role::QA_REVIEWER, ...self::STAFF], 'title' => ['الحقائب المعتمدة وارتباطها بالبرامج والمشرفين', 'Approved kits, their programs and supervisors'], 'dataset' => 'kits',
                'columns' => $c('code', 'title', 'status', 'program', 'supervisor', 'version', 'approved_at'), 'filters' => [self::flt('status', 'in', ['approved', 'published'], true)]],
            ['key' => 'my_kits', 'category' => 'kit', 'roles' => [Role::KIT_DEVELOPER, ...self::STAFF], 'title' => ['حقائبي وحالة اعتمادها', 'My kits and their approval status'], 'dataset' => 'kits',
                'columns' => $c('code', 'title', 'status', 'review_round', 'due_at', 'approved_at'), 'filters' => [self::flt('owner_id', 'eq', '@me')]],
            ['key' => 'evaluation_satisfaction', 'category' => 'general', 'roles' => self::STAFF, 'title' => ['نتائج التقييم ومكتسب المعرفة', 'Evaluation results and knowledge gain'], 'dataset' => 'evaluations',
                'columns' => [self::col('school'), self::col('responses', 'count'), self::col('satisfaction_score', 'avg'), self::col('knowledge_gain', 'avg')], 'group_by' => ['school']],
        ];
    }

    /** Makes sure every built-in report exists (new installations, new versions). Copies made by administrators are left alone. */
    public function ensure(): void
    {
        $existing = ReportDefinition::where('is_system', true)->pluck('id', 'key');
        foreach (self::all() as $d) {
            $attrs = [
                'category' => $d['category'], 'title_ar' => $d['title'][0], 'title_en' => $d['title'][1], 'dataset' => $d['dataset'], 'columns' => $d['columns'], 'filters' => $d['filters'] ?? null,
                'group_by' => $d['group_by'] ?? null, 'sort' => $d['sort'] ?? null, 'chart' => $d['chart'] ?? null, 'options' => $d['options'] ?? null,
                'visibility' => 'role', 'roles' => $d['roles'], 'is_system' => true,
            ];
            if ($existing->has($d['key'])) {
                ReportDefinition::whereKey($existing[$d['key']])->update(array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $attrs));
            } else {
                ReportDefinition::create(['key' => $d['key']] + $attrs);
            }
        }
    }
}
