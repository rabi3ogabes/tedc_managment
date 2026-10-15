<?php

namespace App\Services\Plc;

use App\Models\EvaluationAssignment;
use App\Models\ImpactSurvey;
use App\Models\PlcCommunity;
use App\Models\PlcDecision;
use App\Models\PlcWorkshop;
use App\Models\PlcWorkshopSchool;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\School;
use App\Models\User;
use App\Services\EvaluationSettings;
use App\Services\Reports\ReportExporter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * PLC Phase 7: one place that counts. The dashboard, the reports tab, the community and school views and the
 * exports all read the same figures from here, computed live inside the person's scope with the same filters,
 * so a number on one screen is the same number on another.
 */
class PlcReports
{
    public const FILTERS = ['from', 'to', 'source', 'scenario', 'state', 'mode', 'school_id', 'community_id', 'region'];

    /** Workshops that happened or are happening: their attendance and evaluations count. */
    public const HELD = ['in_progress', 'completed', 'awaiting_evaluation', 'closed', 'archived'];

    public function __construct(private readonly WorkshopService $workshops, private readonly CommunityService $communities, private readonly NeedsService $needs, private readonly EvaluationSettings $evaluationSettings, private readonly PlcSettings $settings, private readonly ReportExporter $exporter) {}

    /** The scoped, filtered workshop query every figure starts from. */
    public function query(User $user, array $f = []): Builder
    {
        $q = $this->workshops->query($user)->with('program:id,code,title_ar,title_en,status,start_date,end_date,delivery_mode,capacity,created_by,min_attendance_percent');
        if (! empty($f['from'])) {
            $q->whereHas('program', fn ($p) => $p->whereDate('start_date', '>=', $f['from']));
        }
        if (! empty($f['to'])) {
            $q->whereHas('program', fn ($p) => $p->whereDate('start_date', '<=', $f['to']));
        }
        foreach (['source', 'scenario'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['state'])) {
            $q->where('workflow_state', $f['state']);
        }
        if (! empty($f['mode'])) {
            $q->whereHas('program', fn ($p) => $p->where('delivery_mode', $f['mode']));
        }
        if (! empty($f['community_id'])) {
            $q->where('community_id', $f['community_id']);
        }
        if (! empty($f['school_id'])) {
            $q->where(fn ($w) => $w->where('host_school_id', $f['school_id'])->orWhereHas('schools', fn ($s) => $s->where('school_id', $f['school_id'])->where('status', '!=', 'excluded')));
        }
        if (! empty($f['region'])) {
            $q->whereHas('hostSchool', fn ($s) => $s->where('region', $f['region']));
        }

        return $q;
    }

    public function build(User $user, array $f = []): array
    {
        $f = array_intersect_key($f, array_flip(self::FILTERS));
        $all = $this->query($user, $f)->get();
        $ids = $all->pluck('program_id')->all();
        $held = $all->whereIn('workflow_state', self::HELD);
        $heldIds = $held->pluck('program_id')->all();
        $minResponses = (int) $this->evaluationSettings->get('anonymous_min_responses', 3);
        $rules = $this->settings->evaluationRules();

        $seated = Registration::with('employee:id,school_id')->whereIn('program_id', $ids)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get(['id', 'program_id', 'employee_id', 'status', 'attendance_percent']);
        $seatedHeld = $seated->whereIn('program_id', $heldIds);
        $sessions = ProgramSession::whereIn('program_id', $ids)->where('status', '!=', 'cancelled')->get(['id', 'program_id', 'starts_at', 'ends_at', 'mode']);
        $assignments = EvaluationAssignment::whereIn('program_id', $ids)->where('rule_key', 'like', 'plc.%')->get(['id', 'program_id', 'rule_key', 'status', 'score', 'respondent_user_id']);
        $impact = ImpactSurvey::whereIn('program_id', $ids)->get(['id', 'program_id', 'status']);
        $managerForms = EvaluationAssignment::whereIn('program_id', $ids)->where('respondent_type', 'manager')->get(['id', 'status']);
        $schoolRows = PlcWorkshopSchool::with('school:id,name_ar,name_en,region,gender,type')->whereIn('program_id', $ids)->where('status', '!=', 'excluded')->get();
        $closedByException = PlcDecision::where('subject_type', PlcWorkshop::class)->whereIn('subject_id', $ids)->where('kind', 'exception')->where('decision', 'evaluation_waived')->distinct()->count('subject_id');

        $count = fn (Collection $c, string $key) => $c->groupBy($key)->map->count()->sortKeys()->all();
        $byProgram = $seatedHeld->groupBy('program_id');
        $minOf = fn (string $pid) => (float) ($all->firstWhere('program_id', $pid)?->program?->min_attendance_percent ?? 0);
        $meeting = $seatedHeld->filter(fn ($r) => (float) ($r->attendance_percent ?? 0) >= $minOf($r->program_id))->count();

        $evalStats = function (Collection $rows) use ($minResponses) {
            $submitted = $rows->where('status', 'submitted');
            $scores = $submitted->pluck('score')->filter(fn ($s) => $s !== null);

            return ['assigned' => $rows->count(), 'submitted' => $submitted->count(), 'rate' => $rows->count() ? round($submitted->count() / $rows->count() * 100, 1) : null,
                'score' => $scores->count() >= $minResponses ? round((float) $scores->avg(), 1) : null, 'score_hidden' => $scores->count() > 0 && $scores->count() < $minResponses];
        };

        // Needs: converted share, and how long a need waited before its workshop was created.
        $needs = $this->needs->query($user)->when(! empty($f['source']), fn ($q) => $q->where('source', $f['source']))->when(! empty($f['from']), fn ($q) => $q->whereDate('received_at', '>=', $f['from']))->when(! empty($f['to']), fn ($q) => $q->whereDate('received_at', '<=', $f['to']))->get(['id', 'source', 'review_status', 'received_at', 'created_at']);
        $needsById = $needs->keyBy('id');
        $waits = $all->filter(fn ($w) => $w->need_id && $needsById->has($w->need_id))->map(fn ($w) => max(0, Carbon::parse($needsById[$w->need_id]->received_at ?? $needsById[$w->need_id]->created_at)->diffInDays($w->created_at)));

        // Trend by month of the programme's start date, oldest first.
        $trend = $all->filter(fn ($w) => $w->program?->start_date)->groupBy(fn ($w) => $w->program->start_date->format('Y-m'))->map(fn (Collection $g, $m) => ['month' => $m, 'workshops' => $g->count(), 'seated' => $seated->whereIn('program_id', $g->pluck('program_id')->all())->count()])->sortKeys()->values()->all();

        // Per school: hosted, participating, seats, attendance and the workshop form's rate.
        $bySchool = $schoolRows->groupBy('school_id')->map(function (Collection $rows) use ($seatedHeld, $assignments, $evalStats) {
            $school = $rows->first()->school;
            $pids = $rows->pluck('program_id')->all();
            $people = $seatedHeld->whereIn('program_id', $pids)->filter(fn ($r) => $r->employee?->school_id === $rows->first()->school_id);
            $att = $people->pluck('attendance_percent')->filter(fn ($v) => $v !== null);
            $ev = $evalStats($assignments->whereIn('program_id', $pids)->where('rule_key', 'plc.workshop'));

            return ['school_id' => $rows->first()->school_id, 'name' => $school?->name_ar, 'name_en' => $school?->name_en, 'region' => $school?->region, 'gender' => $school?->gender,
                'hosted' => $rows->where('role', 'host')->count(), 'participating' => $rows->where('role', '!=', 'host')->count(), 'workshops' => count(array_unique($pids)),
                'people' => $people->count(), 'attendance' => $att->count() ? round((float) $att->avg(), 1) : null, 'evaluation_rate' => $ev['rate']];
        })->sortByDesc('workshops')->values()->all();

        // Per community.
        $byCommunity = $all->filter(fn ($w) => $w->community_id)->groupBy('community_id')->map(function (Collection $ws) use ($seatedHeld, $assignments, $evalStats, $count) {
            $pids = $ws->pluck('program_id')->all();
            $att = $seatedHeld->whereIn('program_id', $pids)->pluck('attendance_percent')->filter(fn ($v) => $v !== null);
            $c = $ws->first()->community;

            return ['community_id' => $ws->first()->community_id, 'title' => $c?->title(), 'workshops' => $ws->count(), 'by_state' => $count($ws, 'workflow_state'), 'people' => $seatedHeld->whereIn('program_id', $pids)->count(),
                'attendance' => $att->count() ? round((float) $att->avg(), 1) : null] + array_intersect_key($evalStats($assignments->whereIn('program_id', $pids)->where('rule_key', 'plc.workshop')), array_flip(['rate', 'score']));
        })->sortByDesc('workshops')->values()->all();

        $att = $seatedHeld->pluck('attendance_percent')->filter(fn ($v) => $v !== null);
        $overdueClose = $all->filter(fn ($w) => in_array($w->workflow_state, ['completed', 'awaiting_evaluation'], true) && $w->program?->end_date && $w->program->end_date->lt(today()->subDays(14)));

        return [
            'filters' => $f, 'generated_at' => now()->toIso8601String(),
            'workshops' => ['total' => $all->count(), 'held' => $held->count(), 'by_state' => $count($all, 'workflow_state'), 'by_source' => $count($all, 'source'), 'by_scenario' => $count($all, 'scenario'),
                'by_mode' => $all->groupBy(fn ($w) => $w->program?->delivery_mode ?? 'in_person')->map->count()->all(), 'closed_by_exception' => $closedByException, 'overdue_close' => $overdueClose->count()],
            'sessions' => ['total' => $sessions->count(), 'held' => $sessions->filter(fn ($s) => Carbon::parse($s->ends_at)->isPast())->count(), 'by_mode' => $count($sessions, 'mode'), 'hours' => round($sessions->sum(fn ($s) => Carbon::parse($s->starts_at)->diffInMinutes(Carbon::parse($s->ends_at))) / 60, 1)],
            'people' => ['capacity' => (int) $all->sum(fn ($w) => (int) ($w->program?->capacity ?? 0)), 'seated' => $seated->count(), 'completed' => $seated->where('status', Registration::STATUS_COMPLETED)->count(), 'unique' => $seated->pluck('employee_id')->unique()->count(),
                'schools' => $schoolRows->pluck('school_id')->unique()->count(), 'communities' => $all->pluck('community_id')->filter()->unique()->count()],
            'attendance' => ['people' => $seatedHeld->count(), 'rate' => $att->count() ? round((float) $att->avg(), 1) : null, 'meeting' => $meeting, 'meeting_rate' => $seatedHeld->count() ? round($meeting / $seatedHeld->count() * 100, 1) : null, 'workshops_with_records' => $byProgram->count()],
            'evaluation' => ['all' => $evalStats($assignments), 'workshop' => $evalStats($assignments->where('rule_key', 'plc.workshop')), 'trainer' => $evalStats($assignments->where('rule_key', 'plc.trainer')), 'session' => $evalStats($assignments->where('rule_key', 'plc.session')), 'community' => $evalStats($assignments->where('rule_key', 'plc.community')),
                'min_responses' => $minResponses, 'close_response_rate' => $rules['close_response_rate']],
            'impact' => ['trainee' => $count($impact, 'status') + ['total' => $impact->count()], 'manager' => ['assigned' => $managerForms->count(), 'submitted' => $managerForms->where('status', 'submitted')->count()], 'sources' => $rules['impact_sources']],
            'needs' => ['total' => $needs->count(), 'by_status' => $count($needs, 'review_status'), 'by_source' => $count($needs, 'source'), 'converted_rate' => $needs->count() ? round($needs->where('review_status', 'converted')->count() / $needs->count() * 100, 1) : null,
                'median_wait_days' => $waits->count() ? (int) $waits->sort()->values()[intdiv($waits->count() - 1, 2)] : null],
            'trend' => $trend, 'by_school' => $bySchool, 'by_community' => $byCommunity,
            'overdue_close' => $overdueClose->sortBy(fn ($w) => $w->program->end_date)->take(10)->map(fn ($w) => $this->workshops->summary($w))->values()->all(),
            'top_attendance' => $byProgram->map(fn (Collection $rs, $pid) => ['id' => $pid, 'title_ar' => $all->firstWhere('program_id', $pid)?->program?->title_ar, 'title_en' => $all->firstWhere('program_id', $pid)?->program?->title_en, 'people' => $rs->count(), 'rate' => round((float) $rs->avg('attendance_percent'), 1)])->sortByDesc('rate')->take(5)->values()->all(),
        ];
    }

    /** The same figures as an exportable document (xlsx, pdf, docx, csv). */
    public function document(array $r, string $locale, array $labels = []): array
    {
        $ar = $locale === 'ar';
        $L = fn (string $a, string $e) => $ar ? $a : $e;
        $pct = fn ($v) => $v === null ? '—' : $v.'%';
        $sections = [];
        $sections[] = ['heading' => $L('الملخص', 'Summary'), 'paragraphs' => [
            $L('الورش: ', 'Workshops: ').$r['workshops']['total'].$L(' (منفذة أو جارية: ', ' (held or running: ').$r['workshops']['held'].')',
            $L('الجلسات: ', 'Sessions: ').$r['sessions']['total'].$L(' · ساعات: ', ' · hours: ').$r['sessions']['hours'],
            $L('المقاعد المشغولة: ', 'Seated people: ').$r['people']['seated'].$L(' من ', ' of ').$r['people']['capacity'].$L(' · أكملوا: ', ' · completed: ').$r['people']['completed'].$L(' · مدارس: ', ' · schools: ').$r['people']['schools'],
            $L('نسبة الحضور: ', 'Attendance: ').$pct($r['attendance']['rate']).$L(' · بلغوا الحد الأدنى: ', ' · met the minimum: ').$r['attendance']['meeting'].$L(' من ', ' of ').$r['attendance']['people'],
            $L('الاستجابة لتقييم الورشة: ', 'Workshop evaluation response: ').$pct($r['evaluation']['workshop']['rate']).$L(' · المتوسط: ', ' · average: ').$pct($r['evaluation']['workshop']['score']),
            $L('الاحتياجات: ', 'Needs: ').$r['needs']['total'].$L(' · حُوِّل منها: ', ' · converted: ').$pct($r['needs']['converted_rate']),
        ]];
        $bars = fn (array $map, array $names = []) => array_map(fn ($k, $v) => ['label' => $names[$k] ?? (string) $k, 'value' => (float) $v, 'max' => max(1, (float) max($map ?: [1]))], array_keys($map), $map);
        $sections[] = ['heading' => $L('الورش حسب الحالة', 'Workshops by state'), 'bars' => $bars($r['workshops']['by_state'], $labels['state'] ?? [])];
        $sections[] = ['heading' => $L('الورش حسب المصدر', 'Workshops by source'), 'bars' => $bars($r['workshops']['by_source'], $labels['source'] ?? [])];
        $sections[] = ['heading' => $L('الورش حسب الشهر', 'Workshops by month'), 'table' => ['head' => [$L('الشهر', 'Month'), $L('الورش', 'Workshops'), $L('المقاعد', 'Seated')], 'rows' => array_map(fn ($t) => [$t['month'], $t['workshops'], $t['seated']], $r['trend'])]];
        $sections[] = ['heading' => $L('المدارس', 'Schools'), 'table' => ['head' => [$L('المدرسة', 'School'), $L('المنطقة', 'Region'), $L('مستضيفة', 'Hosted'), $L('مشاركة', 'Participating'), $L('متدربون', 'People'), $L('الحضور', 'Attendance'), $L('استجابة التقييم', 'Evaluation response')],
            'rows' => array_map(fn ($s) => [$ar ? $s['name'] : ($s['name_en'] ?: $s['name']), $s['region'], $s['hosted'], $s['participating'], $s['people'], $pct($s['attendance']), $pct($s['evaluation_rate'])], $r['by_school'])]];
        if ($r['by_community']) {
            $sections[] = ['heading' => $L('المجتمعات', 'Communities'), 'table' => ['head' => [$L('المجتمع', 'Community'), $L('الورش', 'Workshops'), $L('متدربون', 'People'), $L('الحضور', 'Attendance'), $L('استجابة التقييم', 'Evaluation response'), $L('المتوسط', 'Average')],
                'rows' => array_map(fn ($c) => [$c['title'], $c['workshops'], $c['people'], $pct($c['attendance']), $pct($c['rate']), $pct($c['score'])], $r['by_community'])]];
        }
        $sections[] = ['heading' => $L('التقييم وقياس الأثر', 'Evaluation and impact'), 'table' => ['head' => [$L('الأداة', 'Instrument'), $L('مرسلة', 'Assigned'), $L('مجابة', 'Answered'), $L('النسبة', 'Rate'), $L('المتوسط', 'Average')],
            'rows' => array_map(fn ($k) => [$labels['instrument'][$k] ?? $k, $r['evaluation'][$k]['assigned'], $r['evaluation'][$k]['submitted'], $pct($r['evaluation'][$k]['rate']), $pct($r['evaluation'][$k]['score'])], ['workshop', 'trainer', 'session', 'community'])],
            'paragraphs' => [$L('نماذج أثر المتدربين: ', 'Trainee impact forms: ').$r['impact']['trainee']['total'].$L(' · مكتملة: ', ' · completed: ').($r['impact']['trainee']['completed'] ?? 0).$L(' · نماذج المديرين: ', ' · manager forms: ').$r['impact']['manager']['submitted'].'/'.$r['impact']['manager']['assigned']]];

        $sub = [];
        foreach ($r['filters'] as $k => $v) {
            if ($v !== null && $v !== '') {
                $sub[] = "$k: $v";
            }
        }

        return ['title' => $L('تقرير مجتمعات التعلم المهنية', 'Professional learning communities report'), 'subtitle' => ($sub ? implode(' · ', $sub).' · ' : '').$L('أُعدّ في ', 'Generated ').now()->format('Y-m-d H:i'), 'sections' => $sections];
    }

    public function export(User $user, array $f, string $format, string $locale, array $labels = []): string
    {
        return $this->exporter->render($this->document($this->build($user, $f), $locale, $labels), $format, $locale);
    }

    /** The filter options inside the person's scope. */
    public function options(User $user): array
    {
        $ids = $this->workshops->query($user)->toBase()->pluck('plc_workshops.program_id');
        $schoolIds = PlcWorkshopSchool::whereIn('program_id', $ids)->where('status', '!=', 'excluded')->pluck('school_id')->unique();

        return [
            'schools' => School::whereIn('id', $schoolIds)->orderBy('name_ar')->get(['id', 'name_ar', 'name_en', 'region'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name_ar, 'name_en' => $s->name_en, 'region' => $s->region])->all(),
            'communities' => $this->communities->query($user)->orderBy('title_ar')->get()->map(fn (PlcCommunity $c) => ['id' => $c->id, 'title' => $c->title()])->all(),
            'regions' => School::whereIn('id', $schoolIds)->whereNotNull('region')->distinct()->orderBy('region')->pluck('region')->all(),
        ];
    }
}
