<?php

namespace App\Services\Dashboards;

use App\Models\DashboardPreset;
use App\Models\Evaluation;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingPlan;
use App\Models\User;
use App\Models\UserDashboardLayout;
use App\Services\AnalyticsService;
use App\Services\AnnualHoursService;
use App\Services\AnnualPlanService;
use App\Services\Kpi\KpiService;
use App\Services\Reports\ReportDatasetRegistry;
use App\Support\AccessScope;
use App\Support\ActiveRole;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Role dashboards built from a registry of widgets. Each widget is scope-aware, takes a date range, returns a typed payload the web and the app draw
 * (kpis, bar, donut, gauge, table, list, heatmap) and names the report it drills down to. Administrators edit the preset of each role; people
 * hide and reorder widgets within their preset.
 */
class DashboardService
{
    /** role slug => widget keys, in order. */
    public const PRESETS = [
        Role::EMPLOYEE => ['my_upcoming', 'my_progress', 'my_hours', 'my_certificates', 'my_tasks', 'recommendations'],
        Role::SCHOOL_ADMIN => ['courses_overview', 'participation', 'attendance_rate', 'pass_rates', 'absence_alerts', 'pending_approvals'],
        Role::ACADEMIC_DEPUTY => ['courses_overview', 'participation', 'attendance_rate', 'pass_rates', 'pending_approvals', 'absence_alerts'],
        Role::SUPERVISOR => ['participation', 'attendance_rate', 'pass_rates', 'pending_approvals'],
        Role::TRAINING_HEAD => ['courses_overview', 'participation', 'attendance_rate', 'pass_rates', 'workload_by_supervisor', 'kits_by_status', 'kits_overdue', 'pending_approvals', 'satisfaction_ranking'],
        Role::COORDINATOR => ['my_groups', 'participation', 'attendance_rate', 'pass_rates', 'tasks_to_review', 'logistics_status'],
        Role::CENTER_LEADERSHIP => ['plan_execution', 'achievement_by_school', 'achievement_by_job', 'courses_overview', 'participation', 'satisfaction_ranking', 'low_satisfaction_alerts'],
        Role::EXECUTIVE => ['executive_kpis', 'plan_execution', 'achievement_by_school', 'satisfaction_ranking'],
        Role::TRAINER => ['my_trainer_courses', 'participation', 'attendance_rate', 'pass_rates'],
        Role::CENTER_ADMIN => ['courses_overview', 'participation', 'attendance_rate', 'pass_rates', 'plan_execution', 'integration_health', 'pending_approvals'],
        Role::SUPER_ADMIN => ['courses_overview', 'participation', 'attendance_rate', 'pass_rates', 'plan_execution', 'integration_health', 'pending_approvals'],
        Role::KIT_DEVELOPER => ['kits_by_status', 'kits_overdue'],
        Role::QA_REVIEWER => ['kits_by_status', 'kits_overdue'],
        Role::PLANNING_HEAD => ['needs_progress', 'gap_heatmap', 'plan_execution', 'pending_approvals'],
        Role::PLANNING_SPECIALIST => ['needs_progress', 'gap_heatmap', 'plan_execution'],
        Role::LOGISTICS_OFFICER => ['rooms_utilisation', 'logistics_status'],
    ];

    /** key => [title ar, title en, type, report key to drill into] */
    public const WIDGETS = [
        'courses_overview' => ['الدورات: الجارية والمتاحة والمستهدفة', 'Courses: ongoing, open and planned', 'kpis', 'programs_paths_groups'],
        'participation' => ['المشاركة: الترشيح والاعتماد والحضور والنجاح', 'Participation: nominated, approved, attended, passed', 'kpis', 'employee_courses'],
        'attendance_rate' => ['نسبة الحضور', 'Attendance rate', 'gauge', 'attendance_detail'],
        'pass_rates' => ['النجاح والرسوب', 'Passed and failed', 'donut', 'trainee_results'],
        'plan_execution' => ['تنفيذ الخطة السنوية والتغييرات بعد الاعتماد', 'Annual plan execution and changes after approval', 'kpis', null],
        'satisfaction_ranking' => ['الرضا: الأعلى والأدنى', 'Satisfaction: highest and lowest', 'table', 'satisfaction_courses'],
        'low_satisfaction_alerts' => ['تنبيهات الرضا المنخفض', 'Low satisfaction alerts', 'list', 'satisfaction_courses'],
        'achievement_by_school' => ['الإنجاز حسب المدرسة', 'Achievement by school', 'bar', 'achievement_statistics'],
        'achievement_by_job' => ['الإنجاز حسب الفئة الوظيفية', 'Achievement by job category', 'bar', 'achievement_statistics'],
        'pending_approvals' => ['بانتظار الاعتماد', 'Waiting for approval', 'kpis', null],
        'absence_alerts' => ['تنبيهات الغياب والتعثر', 'Absence and failure alerts', 'list', 'attendance_detail'],
        'my_upcoming' => ['ورشي القادمة', 'My upcoming workshops', 'list', 'my_calendar'],
        'my_progress' => ['تقدمي في دوراتي', 'My course progress', 'list', 'my_courses_statement'],
        'my_hours' => ['ساعاتي هذا العام', 'My hours this year', 'gauge', 'my_hours_by_year'],
        'my_certificates' => ['شهاداتي', 'My certificates', 'kpis', 'my_completed_courses'],
        'my_tasks' => ['مهامي واستبياناتي', 'My tasks and surveys', 'kpis', null],
        'recommendations' => ['مقترحة لك', 'Recommended for you', 'list', 'programs_i_can_apply'],
        'my_groups' => ['مجموعاتي', 'My groups', 'table', 'groups_supervised'],
        'tasks_to_review' => ['مهام بانتظار المراجعة', 'Tasks waiting for review', 'kpis', null],
        'logistics_status' => ['طلبات الدعم اللوجستي', 'Logistics requests', 'bar', null],
        'workload_by_supervisor' => ['عبء المشرفين', 'Supervisors workload', 'bar', 'groups_supervised'],
        'kits_by_status' => ['الحقائب حسب الحالة', 'Kits by status', 'bar', 'kits_programs_supervisors'],
        'kits_overdue' => ['حقائب متأخرة', 'Overdue kits', 'list', 'my_kits'],
        'needs_progress' => ['تقدم حصر الاحتياجات', 'Needs collection progress', 'bar', null],
        'gap_heatmap' => ['خريطة حرارية للفجوات', 'Gap heat map', 'heatmap', null],
        'rooms_utilisation' => ['استخدام القاعات', 'Room utilisation', 'bar', null],
        'integration_health' => ['صحة النظام والتكاملات', 'System and integration health', 'kpis', null],
        'executive_kpis' => ['المؤشرات الاستراتيجية', 'Strategic indicators', 'kpis', null],
        'my_trainer_courses' => ['دوراتي كمدرب', 'My courses as a trainer', 'table', 'trainer_statistics'],
    ];

    public function __construct(private readonly AnalyticsService $analytics, private readonly AnnualPlanService $plans, private readonly AnnualHoursService $hours, private readonly KpiService $kpi) {}

    public function roleSlug(User $user): string
    {
        return app(ActiveRole::class)->for($user)?->role?->slug ?? $user->roles->sortByDesc('level')->first()?->slug ?? Role::EMPLOYEE;
    }

    /** Widget keys of a role: the administrator's preset, else the built-in one. @return list<string> */
    public function presetFor(string $role): array
    {
        $stored = DashboardPreset::where('role_slug', $role)->value('widgets');
        $keys = $stored ?? (self::PRESETS[$role] ?? self::PRESETS[Role::EMPLOYEE]);

        return array_values(array_filter($keys, fn ($k) => isset(self::WIDGETS[$k])));
    }

    public function savePreset(string $role, array $widgets): array
    {
        $widgets = array_values(array_unique(array_filter($widgets, fn ($k) => isset(self::WIDGETS[$k]))));
        DashboardPreset::updateOrCreate(['role_slug' => $role], ['widgets' => $widgets]);

        return $widgets;
    }

    /** The person's dashboard: their role's preset with their own order and hidden widgets applied. @return array<string, mixed> */
    public function layout(User $user): array
    {
        $role = $this->roleSlug($user);
        $preset = $this->presetFor($role);
        $mine = UserDashboardLayout::where(['user_id' => $user->id, 'role_slug' => $role])->value('layout') ?? ['order' => [], 'hidden' => []];
        $order = array_values(array_unique(array_merge(array_values(array_intersect($mine['order'] ?? [], $preset)), $preset)));
        $hidden = array_values(array_intersect($mine['hidden'] ?? [], $preset));

        return [
            'role' => $role,
            'widgets' => array_map(fn ($k) => ['key' => $k, 'title' => ['ar' => self::WIDGETS[$k][0], 'en' => self::WIDGETS[$k][1]], 'type' => self::WIDGETS[$k][2], 'drill' => self::WIDGETS[$k][3], 'hidden' => in_array($k, $hidden, true)], $order),
        ];
    }

    public function saveLayout(User $user, array $order, array $hidden): array
    {
        $role = $this->roleSlug($user);
        $preset = $this->presetFor($role);
        UserDashboardLayout::updateOrCreate(['user_id' => $user->id, 'role_slug' => $role], ['layout' => ['order' => array_values(array_intersect($order, $preset)), 'hidden' => array_values(array_intersect($hidden, $preset))]]);

        return $this->layout($user);
    }

    /** One widget's data. Only widgets in the person's preset can be asked for. @return array<string, mixed>|null */
    public function widget(User $user, string $key, ?string $from, ?string $to): ?array
    {
        if (! in_array($key, $this->presetFor($this->roleSlug($user)), true)) {
            return null;
        }
        $scope = AccessScope::current($user);
        $range = [Carbon::parse($from ?: now()->startOfYear())->startOfDay(), Carbon::parse($to ?: now())->endOfDay()];
        $data = $this->{'w_'.$key}($user, $scope, $range);

        return ['key' => $key, 'title' => ['ar' => self::WIDGETS[$key][0], 'en' => self::WIDGETS[$key][1]], 'type' => self::WIDGETS[$key][2], 'drill' => self::WIDGETS[$key][3], 'range' => [$range[0]->toDateString(), $range[1]->toDateString()]] + $data;
    }

    // ---- helpers --------------------------------------------------------------------------------------------

    private function regs(AccessScope $scope, array $range, bool $dated = true)
    {
        $q = $scope->constrainThroughEmployee(Registration::query());

        return $dated ? $q->whereBetween('registrations.created_at', $range) : $q;
    }

    private function kpis(array $items): array
    {
        return ['items' => array_map(fn ($i) => ['key' => $i[0], 'label' => ['ar' => $i[1], 'en' => $i[2]], 'value' => $i[3]] + (isset($i[4]) ? ['unit' => $i[4]] : []), $items)];
    }

    private function bar(array $rows): array
    {
        return ['points' => array_map(fn ($r) => ['label' => (string) $r[0], 'value' => (float) $r[1]], $rows)];
    }

    private function groupsFor(AccessScope $scope)
    {
        $q = DB::table('training_groups as g')->whereNull('g.deleted_at');
        if (! $scope->isMinistryWide()) {
            $q->whereExists(fn ($w) => $w->select(DB::raw(1))->from('registrations as rr')->join('employees as ee', 'ee.id', '=', 'rr.employee_id')->whereColumn('rr.training_group_id', 'g.id')->whereIn('ee.school_id', $scope->schoolIds() ?? []));
        }

        return $q;
    }

    // ---- widgets --------------------------------------------------------------------------------------------

    private function w_courses_overview(User $u, AccessScope $s, array $r): array
    {
        $g = fn (array $statuses) => (clone $this->groupsFor($s))->whereIn('g.status', $statuses)->count();

        return $this->kpis([
            ['ongoing', 'جارية', 'Ongoing', $g(['ongoing'])], ['open', 'متاحة للتسجيل', 'Open for registration', $g(['registration_open'])], ['planned', 'مخطط لها', 'Planned', $g(['planned'])],
            ['completed', 'مكتملة في الفترة', 'Completed in the period', (clone $this->groupsFor($s))->where('g.status', 'completed')->whereBetween('g.end_date', [$r[0]->toDateString(), $r[1]->toDateString()])->count()],
        ]);
    }

    private function w_participation(User $u, AccessScope $s, array $r): array
    {
        $q = fn () => $this->regs($s, $r);

        return $this->kpis([
            ['nominated', 'مرشحون', 'Nominated', $q()->whereIn('source', ['school_nomination', 'center_nomination'])->count()],
            ['approved', 'معتمدون', 'Approved', $q()->whereIn('status', ['approved', 'completed'])->count()],
            ['attended', 'حضروا', 'Attended', $q()->where('attendance_percent', '>', 0)->count()],
            ['passed', 'ناجحون', 'Passed', $q()->where('pass_status', 'passed')->count()],
            ['failed', 'راسبون', 'Failed', $q()->where('pass_status', 'failed')->count()],
        ]);
    }

    private function w_attendance_rate(User $u, AccessScope $s, array $r): array
    {
        $avg = $this->regs($s, $r)->whereIn('status', ['approved', 'completed'])->whereNotNull('attendance_percent')->avg('attendance_percent');

        return ['value' => round((float) $avg, 1), 'target' => 80, 'unit' => '%'];
    }

    private function w_pass_rates(User $u, AccessScope $s, array $r): array
    {
        $q = fn (string $st) => $this->regs($s, $r)->whereIn('status', ['approved', 'completed'])->where('pass_status', $st)->count();

        return $this->bar([['passed', $q('passed')], ['failed', $q('failed')], ['pending', $this->regs($s, $r)->whereIn('status', ['approved', 'completed'])->where(fn ($w) => $w->whereNull('pass_status')->orWhere('pass_status', 'pending'))->count()]]);
    }

    private function w_plan_execution(User $u, AccessScope $s, array $r): array
    {
        $plan = TrainingPlan::whereIn('status', ['active', 'approved'])->orderByDesc('year')->first();
        if (! $plan) {
            return $this->kpis([['execution', 'نسبة تنفيذ الخطة', 'Plan execution', 0, '%'], ['changes', 'نسبة التغييرات بعد الاعتماد', 'Changes after approval', 0, '%']]) + ['note' => 'no_plan'];
        }
        $t = $this->plans->execution($plan)['totals'];

        return $this->kpis([
            ['execution', 'نسبة تنفيذ الخطة', 'Plan execution', $t['execution_percent'], '%'], ['changes', 'نسبة التغييرات بعد الاعتماد', 'Changes after approval', $t['changed_percent'], '%'],
            ['executed', 'مجموعات منفذة', 'Groups executed', $t['executed_groups']], ['deviations', 'انحرافات', 'Deviations', $t['deviations']],
        ]) + ['plan' => ['year' => $plan->year, 'id' => $plan->id]];
    }

    private function w_satisfaction_ranking(User $u, AccessScope $s, array $r): array
    {
        $rows = $s->constrainThroughEmployee(Evaluation::query())->whereBetween('submitted_at', $r)->join('programs', 'programs.id', '=', 'evaluations.program_id')
            ->groupBy('programs.id', 'programs.title_ar', 'programs.title_en')->selectRaw('programs.title_ar, programs.title_en, avg(satisfaction_score) as score, count(*) as n')->havingRaw('count(*) >= 1')->orderByDesc('score')->get();
        $row = fn ($x, $kind) => ['kind' => $kind, 'title' => ['ar' => $x->title_ar, 'en' => $x->title_en], 'score' => round($x->score / 20, 2), 'responses' => (int) $x->n];

        return ['rows' => array_merge($rows->take(3)->map(fn ($x) => $row($x, 'highest'))->all(), $rows->count() > 3 ? $rows->reverse()->take(3)->values()->map(fn ($x) => $row($x, 'lowest'))->all() : [])];
    }

    private function w_low_satisfaction_alerts(User $u, AccessScope $s, array $r): array
    {
        $rows = $s->constrainThroughEmployee(Evaluation::query())->whereBetween('submitted_at', $r)->join('programs', 'programs.id', '=', 'evaluations.program_id')
            ->groupBy('programs.id', 'programs.title_ar', 'programs.title_en')->selectRaw('programs.title_ar, programs.title_en, avg(satisfaction_score) as score, count(*) as n')->havingRaw('avg(satisfaction_score) < 70')->orderBy('score')->limit(8)->get();

        return ['items' => $rows->map(fn ($x) => ['title' => ['ar' => $x->title_ar, 'en' => $x->title_en], 'subtitle' => round($x->score / 20, 2).' / 5 · '.$x->n])->all()];
    }

    private function w_achievement_by_school(User $u, AccessScope $s, array $r): array
    {
        $rows = $this->regs($s, $r)->join('employees as e', 'e.id', '=', 'registrations.employee_id')->join('schools as sc', 'sc.id', '=', 'e.school_id')->where('registrations.status', 'completed')
            ->groupBy('sc.id', 'sc.name_ar')->selectRaw('sc.name_ar as label, count(*) as n')->orderByDesc('n')->limit(10)->get();

        return $this->bar($rows->map(fn ($x) => [$x->label, $x->n])->all());
    }

    private function w_achievement_by_job(User $u, AccessScope $s, array $r): array
    {
        $rows = $this->regs($s, $r)->join('employees as e', 'e.id', '=', 'registrations.employee_id')->join('job_titles as j', 'j.id', '=', 'e.job_title_id')->where('registrations.status', 'completed')
            ->groupBy('j.category')->selectRaw('j.category as label, count(*) as n')->orderByDesc('n')->limit(10)->get();

        return $this->bar($rows->map(fn ($x) => [$x->label ?? '—', $x->n])->all());
    }

    /** Rows that belong to an employee, limited by the person's scope (for tables read without Eloquent). */
    private function scoped(string $table, string $employeeColumn, AccessScope $s)
    {
        $q = DB::table("{$table} as t")->join('employees as sce', 'sce.id', '=', "t.{$employeeColumn}");
        if ($s->type() === 'none') {
            return $q->whereRaw('1 = 0');
        }
        if ($s->schoolIds() !== null) {
            $q->whereIn('sce.school_id', $s->schoolIds());
        }
        if ($s->departmentIds() !== null) {
            $q->whereIn('sce.department_id', $s->departmentIds());
        }

        return $q;
    }

    private function w_pending_approvals(User $u, AccessScope $s, array $r): array
    {
        return $this->kpis([
            ['registrations', 'تسجيلات', 'Registrations', $this->regs($s, $r, false)->whereIn('status', ['pending', 'pending_manager'])->count()],
            ['excuses', 'أعذار الغياب', 'Absence excuses', $this->scoped('absence_excuses', 'employee_id', $s)->where('t.status', 'pending')->count()],
            ['pd', 'أنشطة تطوير مهني', 'PD activities', $this->scoped('pd_activities', 'employee_id', $s)->where('t.status', 'pending')->count()],
            ['withdrawals', 'انسحابات', 'Withdrawals', DB::table('withdrawal_requests as t')->join('registrations as rr', 'rr.id', '=', 't.registration_id')->join('employees as sce', 'sce.id', '=', 'rr.employee_id')
                ->when($s->schoolIds() !== null, fn ($q) => $q->whereIn('sce.school_id', $s->schoolIds()))->where('t.status', 'pending')->count()],
        ]);
    }

    private function w_absence_alerts(User $u, AccessScope $s, array $r): array
    {
        $rows = $this->regs($s, $r, false)->join('employees as e', 'e.id', '=', 'registrations.employee_id')->join('users as us', 'us.id', '=', 'e.user_id')->join('programs as p', 'p.id', '=', 'registrations.program_id')
            ->whereIn('registrations.status', ['approved'])->where('registrations.attendance_percent', '<', 60)->where('registrations.attendance_percent', '>', 0)
            ->selectRaw('coalesce(us.name_ar, us.name) as name, p.title_ar, p.title_en, registrations.attendance_percent as pct')->orderBy('pct')->limit(8)->get();

        return ['items' => $rows->map(fn ($x) => ['title' => ['ar' => $x->name, 'en' => $x->name], 'subtitle' => $x->title_ar.' · '.round($x->pct).'%'])->all()];
    }

    private function me(User $u): ?string
    {
        return $u->employee?->id;
    }

    private function w_my_upcoming(User $u, AccessScope $s, array $r): array
    {
        $rows = DB::table('program_sessions as ps')->join('programs as p', 'p.id', '=', 'ps.program_id')->where('ps.starts_at', '>=', now())
            ->whereExists(fn ($w) => $w->select(DB::raw(1))->from('registrations as rr')->whereColumn('rr.program_id', 'ps.program_id')->where('rr.employee_id', $this->me($u) ?? '')->whereIn('rr.status', ['approved', 'completed']))
            ->orderBy('ps.starts_at')->limit(6)->get(['ps.title_ar', 'ps.title_en', 'ps.starts_at', 'p.title_ar as program_ar', 'p.title_en as program_en']);

        return ['items' => $rows->map(fn ($x) => ['title' => ['ar' => $x->title_ar ?: $x->program_ar, 'en' => $x->title_en ?: $x->program_en], 'subtitle' => substr((string) $x->starts_at, 0, 16)])->all()];
    }

    private function w_my_progress(User $u, AccessScope $s, array $r): array
    {
        $rows = DB::table('registrations as r')->join('programs as p', 'p.id', '=', 'r.program_id')->where('r.employee_id', $this->me($u) ?? '')->where('r.status', 'approved')->orderByDesc('r.updated_at')->limit(6)->get(['p.title_ar', 'p.title_en', 'r.course_percent', 'r.attendance_percent']);

        return ['items' => $rows->map(fn ($x) => ['title' => ['ar' => $x->title_ar, 'en' => $x->title_en], 'subtitle' => round((float) $x->course_percent).'%', 'percent' => (float) $x->course_percent])->all()];
    }

    private function w_my_hours(User $u, AccessScope $s, array $r): array
    {
        $e = $u->employee;
        if (! $e) {
            return ['value' => 0, 'target' => null, 'unit' => 'h'];
        }
        $sum = $this->hours->summary($e);

        return ['value' => (float) $sum['total'], 'target' => $sum['target'], 'unit' => 'h', 'year' => $sum['year']];
    }

    private function w_my_certificates(User $u, AccessScope $s, array $r): array
    {
        $q = DB::table('certificates')->where('employee_id', $this->me($u) ?? '')->where('status', 'valid');

        return $this->kpis([['total', 'إجمالي الشهادات', 'Certificates', (clone $q)->count()], ['year', 'هذا العام', 'This year', (clone $q)->whereBetween('issued_at', [now()->startOfYear(), now()])->count()], ['hours', 'ساعات', 'Hours', (float) (clone $q)->sum('hours')]]);
    }

    private function w_my_tasks(User $u, AccessScope $s, array $r): array
    {
        $eid = $this->me($u) ?? '';
        $tasks = DB::table('tasks as t')->join('registrations as r', fn ($j) => $j->on('r.program_id', '=', 't.program_id')->where('r.employee_id', $eid)->where('r.status', 'approved'))
            ->whereNotExists(fn ($w) => $w->select(DB::raw(1))->from('task_submissions as ts')->whereColumn('ts.task_id', 't.id')->where('ts.employee_id', $eid))->count();

        return $this->kpis([['tasks', 'مهام مطلوبة', 'Tasks to do', $tasks], ['surveys', 'تقييمات مطلوبة', 'Evaluations to do', DB::table('registrations')->where('employee_id', $eid)->where('status', 'completed')->where(fn ($w) => $w->where('evaluation_completed', false)->orWhereNull('evaluation_completed'))->count()]]);
    }

    private function w_recommendations(User $u, AccessScope $s, array $r): array
    {
        $rows = DB::table('training_groups as g')->join('programs as p', 'p.id', '=', 'g.program_id')->where('g.status', 'registration_open')->whereNull('g.deleted_at')->whereNotNull('g.published_at')
            ->whereNotExists(fn ($w) => $w->select(DB::raw(1))->from('registrations as rr')->whereColumn('rr.program_id', 'g.program_id')->where('rr.employee_id', $this->me($u) ?? ''))
            ->orderBy('g.start_date')->limit(5)->get(['p.title_ar', 'p.title_en', 'g.start_date']);

        return ['items' => $rows->map(fn ($x) => ['title' => ['ar' => $x->title_ar, 'en' => $x->title_en], 'subtitle' => (string) $x->start_date])->all()];
    }

    private function w_my_groups(User $u, AccessScope $s, array $r): array
    {
        $rows = DB::table('training_groups as g')->join('programs as p', 'p.id', '=', 'g.program_id')->where('g.supervisor_id', $u->id)->whereNull('g.deleted_at')->whereIn('g.status', ['planned', 'registration_open', 'ongoing'])->orderBy('g.start_date')->limit(10)
            ->selectRaw("g.code, p.title_ar, p.title_en, g.status, g.start_date, g.capacity, (select count(*) from registrations x where x.training_group_id = g.id and x.status in ('pending','approved','completed')) as taken")->get();

        return ['columns' => [['key' => 'group', 'label' => ['ar' => 'المجموعة', 'en' => 'Group']], ['key' => 'status', 'label' => ['ar' => 'الحالة', 'en' => 'Status']], ['key' => 'seats', 'label' => ['ar' => 'المقاعد', 'en' => 'Seats']]],
            'rows' => $rows->map(fn ($x) => ['group' => $x->code.' — '.$x->title_ar, 'status' => $x->status, 'seats' => $x->taken.' / '.$x->capacity])->all()];
    }

    private function w_tasks_to_review(User $u, AccessScope $s, array $r): array
    {
        $pending = DB::table('task_submissions')->whereIn('status', ['submitted', 'resubmitted', 'pending'])->count();

        return $this->kpis([['pending', 'بانتظار المراجعة', 'Waiting for review', $pending], ['returned', 'أُعيدت', 'Returned', DB::table('task_submissions')->where('status', 'returned')->count()]]);
    }

    private function w_logistics_status(User $u, AccessScope $s, array $r): array
    {
        return $this->bar(DB::table('logistics_requests')->select('status', DB::raw('count(*) as n'))->groupBy('status')->get()->map(fn ($x) => [$x->status, $x->n])->all());
    }

    private function w_workload_by_supervisor(User $u, AccessScope $s, array $r): array
    {
        $rows = DB::table('training_groups as g')->join('users as su', 'su.id', '=', 'g.supervisor_id')->whereNull('g.deleted_at')->whereIn('g.status', ['planned', 'registration_open', 'ongoing'])
            ->groupBy('su.id', 'su.name_ar', 'su.name')->selectRaw('coalesce(su.name_ar, su.name) as label, count(*) as n')->orderByDesc('n')->limit(10)->get();

        return $this->bar($rows->map(fn ($x) => [$x->label, $x->n])->all());
    }

    private function w_kits_by_status(User $u, AccessScope $s, array $r): array
    {
        return $this->bar(DB::table('training_kits')->select('status', DB::raw('count(*) as n'))->groupBy('status')->get()->map(fn ($x) => [$x->status, $x->n])->all());
    }

    private function w_kits_overdue(User $u, AccessScope $s, array $r): array
    {
        $rows = DB::table('training_kits')->whereNotNull('due_at')->where('due_at', '<', now())->whereNotIn('status', ['approved', 'published', 'archived'])->orderBy('due_at')->limit(8)->get(['code', 'title_ar', 'title_en', 'due_at', 'status']);

        return ['items' => $rows->map(fn ($x) => ['title' => ['ar' => $x->code.' — '.$x->title_ar, 'en' => $x->code.' — '.$x->title_en], 'subtitle' => substr((string) $x->due_at, 0, 10).' · '.$x->status])->all()];
    }

    private function w_needs_progress(User $u, AccessScope $s, array $r): array
    {
        return $this->bar(DB::table('training_needs')->select('status', DB::raw('count(*) as n'))->groupBy('status')->get()->map(fn ($x) => [$x->status, $x->n])->all());
    }

    private function w_gap_heatmap(User $u, AccessScope $s, array $r): array
    {
        $rows = DB::table('training_needs as n')->join('schools as sc', 'sc.id', '=', 'n.school_id')->groupBy('sc.region', 'n.priority')->selectRaw('sc.region as x, n.priority as y, count(*) as v')->get();

        return ['cells' => $rows->map(fn ($x) => ['x' => (string) $x->x, 'y' => (string) $x->y, 'value' => (int) $x->v])->all()];
    }

    private function w_rooms_utilisation(User $u, AccessScope $s, array $r): array
    {
        $day = ReportDatasetRegistry::day('starts_at');
        $rows = DB::table('room_bookings')->where('starts_at', '>=', now()->startOfDay())->where('starts_at', '<', now()->addDays(7)->startOfDay())->whereNotIn('status', ['cancelled', 'rejected'])
            ->groupByRaw($day)->orderByRaw($day)->selectRaw("{$day} as d, count(*) as n")->get();

        return $this->bar($rows->map(fn ($x) => [$x->d, $x->n])->all());
    }

    private function w_integration_health(User $u, AccessScope $s, array $r): array
    {
        $by = collect($this->kpi->dashboard())->keyBy('metric');

        return $this->kpis([
            ['uptime', 'التوافر', 'Uptime', $by['uptime']['value'], '%'], ['error_rate', 'نسبة الأخطاء', 'Error rate', $by['error_rate']['value'], '%'], ['p50', 'زمن الاستجابة', 'Response time', $by['response_time_p50']['value'], 'ms'],
            ['open_errors', 'أخطاء مفتوحة', 'Open errors', DB::table('error_logs')->where('status', 'open')->count()],
        ]);
    }

    private function w_executive_kpis(User $u, AccessScope $s, array $r): array
    {
        $k = $this->analytics->dashboard($s)['kpis'];

        return $this->kpis([
            ['employees', 'الموظفون', 'Employees', $k['total_employees']], ['participants', 'المشاركون', 'Participants', $k['participants']], ['hours', 'ساعات التدريب', 'Training hours', $k['training_hours']],
            ['certificates', 'الشهادات', 'Certificates', $k['certificates_issued']], ['impact', 'مؤشر الأثر', 'Impact', $k['impact_score']],
        ]);
    }

    private function w_my_trainer_courses(User $u, AccessScope $s, array $r): array
    {
        $tid = DB::table('trainers')->where('user_id', $u->id)->value('id');
        $rows = DB::table('group_trainers as gt')->join('training_groups as g', 'g.id', '=', 'gt.group_id')->join('programs as p', 'p.id', '=', 'g.program_id')->where('gt.trainer_id', $tid ?? '')->whereIn('g.status', ['planned', 'registration_open', 'ongoing'])
            ->selectRaw("g.code, p.title_ar, g.status, g.start_date, (select count(*) from registrations x where x.training_group_id = g.id and x.status in ('pending','approved','completed')) as taken")->orderBy('g.start_date')->limit(10)->get();

        return ['columns' => [['key' => 'group', 'label' => ['ar' => 'المجموعة', 'en' => 'Group']], ['key' => 'status', 'label' => ['ar' => 'الحالة', 'en' => 'Status']], ['key' => 'seats', 'label' => ['ar' => 'المتدربون', 'en' => 'Trainees']]],
            'rows' => $rows->map(fn ($x) => ['group' => $x->code.' — '.$x->title_ar, 'status' => $x->status, 'seats' => (string) $x->taken])->all()];
    }
}
