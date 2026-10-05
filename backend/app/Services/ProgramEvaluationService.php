<?php

namespace App\Services;

use App\Models\EvaluationInterview;
use App\Models\EvaluationResponse;
use App\Models\ImpactSurvey;
use App\Models\Program;
use App\Models\ProgramEvaluationReport;
use App\Models\Registration;
use App\Models\Role;
use App\Models\SupervisorEvaluation;
use App\Models\TrainingGroup;
use App\Models\User;

/**
 * Gathers every instrument of a program (or one group) into one set of numbers, proposes a classification with its
 * reasons from editable thresholds, and drafts strengths, improvements and recommendations for the planning specialist to edit.
 */
class ProgramEvaluationService
{
    public function __construct(
        private readonly EvaluationSettings $settings,
        private readonly ComparativeAnalysis $comparative,
        private readonly SatisfactionAlertService $alerts,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array<string, mixed> */
    public function metrics(Program $program, ?TrainingGroup $group = null): array
    {
        $regs = Registration::where('program_id', $program->id)->when($group, fn ($q) => $q->where('training_group_id', $group->id))->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get();
        $ids = $regs->pluck('id');
        $n = $regs->count();
        $sat = $this->alerts->stats($program, $group);
        $anonMin = (int) $this->settings->get('anonymous_min_responses', 3);
        $gain = $this->comparative->forProgram($program->id, $group?->id);
        $impactRegs = $regs->whereNotNull('impact_score');
        $responses = EvaluationResponse::with('assignment.form')->whereHas('assignment', fn ($q) => $q->where('program_id', $program->id)->when($group, fn ($w) => $w->where('group_id', $group->id)))->get();
        $byKind = fn (string $kind) => $responses->filter(fn ($r) => $r->assignment->form->kind === $kind);
        $avgScore = fn ($c) => $c->whereNotNull('score')->isNotEmpty() ? round((float) $c->whereNotNull('score')->avg('score'), 1) : null;
        $interviews = EvaluationInterview::where('program_id', $program->id)->when($group, fn ($q) => $q->where('group_id', $group->id))->get();

        return [
            'participants' => $n,
            'attendance_average' => $n ? round((float) $regs->avg('attendance_percent'), 1) : null,
            'pass_rate' => $n ? round($regs->whereIn('pass_status', ['passed', 'exempted'])->count() / $n * 100, 1) : null,
            'completion_rate' => $n ? round($regs->where('status', Registration::STATUS_COMPLETED)->count() / $n * 100, 1) : null,
            'satisfaction' => ['average' => $sat['responses'] >= $anonMin || $sat['responses'] === 0 ? $sat['average'] : null, 'responses' => $sat['responses'], 'response_rate' => $sat['response_rate'], 'hidden_until' => $sat['responses'] > 0 && $sat['responses'] < $anonMin ? $anonMin : null],
            'knowledge_gain' => ['average_percent' => $gain['average_gain_percent'], 'pre' => $gain['pre_average'], 'post' => $gain['post_average'], 'participants' => $gain['participants_with_both'], 'target_met' => $gain['target_met'], 'significance' => $gain['significance']],
            'impact' => ['score' => $impactRegs->isNotEmpty() ? round((float) $impactRegs->avg('impact_score'), 1) : null, 'trainee_application' => ($v = ImpactSurvey::whereIn('registration_id', $ids)->where('status', 'completed')->avg('application_score')) !== null ? round((float) $v, 1) : null,
                'manager_application' => ($m = SupervisorEvaluation::whereIn('registration_id', $ids)->avg('application_score')) !== null ? round((float) $m, 1) : null],
            'trainer_reflection' => ['responses' => $byKind('trainer_reflection')->count(), 'score' => $avgScore($byKind('trainer_reflection'))],
            'planning_evaluation' => ['responses' => $byKind('planning_evaluation')->count(), 'score' => $avgScore($byKind('planning_evaluation'))],
            'supervisor_feedback' => ['responses' => $byKind('supervisor_feedback')->count(), 'score' => $avgScore($byKind('supervisor_feedback'))],
            'specialist_feedback' => ['responses' => $byKind('specialist_feedback')->count(), 'score' => $avgScore($byKind('specialist_feedback'))],
            'interviews' => ['count' => $interviews->count(), 'positive' => $interviews->where('sentiment', 'positive')->count(), 'neutral' => $interviews->where('sentiment', 'neutral')->count(), 'negative' => $interviews->where('sentiment', 'negative')->count()],
        ];
    }

    /**
     * successful when every available metric reaches its good mark (and at least two are available); weak when two or more
     * fall under their lower bound; anything else needs review.
     *
     * @return array{classification: string, reasons: list<array<string, mixed>>}
     */
    public function classify(array $metrics): array
    {
        $t = $this->settings->get('classification');
        $checks = [
            ['key' => 'satisfaction', 'value' => $metrics['satisfaction']['average'], 'good' => $t['satisfaction_good'], 'low' => $t['satisfaction_low']],
            ['key' => 'knowledge_gain', 'value' => $metrics['knowledge_gain']['average_percent'], 'good' => $t['gain_good'], 'low' => $t['gain_low']],
            ['key' => 'impact', 'value' => $metrics['impact']['score'], 'good' => $t['impact_good'], 'low' => $t['impact_low']],
        ];
        $reasons = [];
        $available = array_values(array_filter($checks, fn ($c) => $c['value'] !== null));
        foreach ($checks as $c) {
            $reasons[] = $c + ['state' => $c['value'] === null ? 'missing' : ($c['value'] >= $c['good'] ? 'good' : ($c['value'] < $c['low'] ? 'low' : 'between'))];
        }
        $low = count(array_filter($reasons, fn ($r) => $r['state'] === 'low'));
        $allGood = $available !== [] && count(array_filter($reasons, fn ($r) => in_array($r['state'], ['between', 'low'], true))) === 0;

        $class = match (true) {
            $low >= 2 => 'weak_stop',
            $allGood && count($available) >= 2 => 'successful_continue',
            default => 'needs_review',
        };

        return ['classification' => $class, 'reasons' => $reasons];
    }

    /** Rule-based drafts for the planning specialist to edit (AI-assisted drafting arrives with Phase 15). @return array{strengths: list<array<string, string>>, improvements: list<array<string, string>>, recommendations_ar: string, recommendations_en: string} */
    public function draft(array $metrics, array $reasons, string $classification): array
    {
        $names = ['satisfaction' => ['رضا المتدربين', 'Trainee satisfaction'], 'knowledge_gain' => ['مكسب المعرفة', 'Knowledge gain'], 'impact' => ['أثر التدريب', 'Training impact']];
        $strengths = [];
        $improve = [];
        foreach ($reasons as $r) {
            if ($r['state'] === 'good') {
                $strengths[] = ['ar' => "{$names[$r['key']][0]} بلغ {$r['value']} (الحد المرجو {$r['good']}).", 'en' => "{$names[$r['key']][1]} reached {$r['value']} (target {$r['good']})."];
            } elseif (in_array($r['state'], ['between', 'low'], true)) {
                $improve[] = ['ar' => "{$names[$r['key']][0]} بلغ {$r['value']} وهو دون المرجو ({$r['good']}).", 'en' => "{$names[$r['key']][1]} is {$r['value']}, below the target ({$r['good']})."];
            } else {
                $improve[] = ['ar' => "لا بيانات كافية عن {$names[$r['key']][0]}.", 'en' => "Not enough data on {$names[$r['key']][1]}."];
            }
        }
        if (($metrics['attendance_average'] ?? 0) >= 85) {
            $strengths[] = ['ar' => "التزام عالٍ بالحضور ({$metrics['attendance_average']}٪).", 'en' => "High attendance ({$metrics['attendance_average']}%)."];
        }
        if (($metrics['interviews']['negative'] ?? 0) > ($metrics['interviews']['positive'] ?? 0)) {
            $improve[] = ['ar' => 'غلبت الملاحظات السلبية في المقابلات.', 'en' => 'The interviews leaned negative.'];
        }

        [$ar, $en] = match ($classification) {
            'successful_continue' => ['البرنامج ناجح؛ يوصى بالاستمرار فيه وتوسيع نطاقه عند الحاجة.', 'The program is successful; continue it and widen it where needed.'],
            'weak_stop' => ['مؤشرات البرنامج ضعيفة؛ يوصى بإيقافه أو إعادة تصميمه جذرياً قبل تنفيذه مجدداً.', 'The program indicators are weak; stop it or redesign it substantially before running it again.'],
            default => ['البرنامج بحاجة إلى مراجعة؛ يوصى بمعالجة نقاط التحسين أعلاه قبل الدورة القادمة.', 'The program needs review; address the improvement points above before the next run.'],
        };

        return ['strengths' => $strengths, 'improvements' => $improve, 'recommendations_ar' => $ar, 'recommendations_en' => $en];
    }

    public function generate(Program $program, ?TrainingGroup $group, User $by): ProgramEvaluationReport
    {
        $metrics = $this->metrics($program, $group);
        $c = $this->classify($metrics);
        $d = $this->draft($metrics, $c['reasons'], $c['classification']);

        return ProgramEvaluationReport::create(['program_id' => $program->id, 'group_id' => $group?->id, 'period' => now()->format('Y-m-d'), 'metrics' => $metrics, 'qualitative' => ['strengths' => $d['strengths'], 'improvements' => $d['improvements']],
            'classification' => $c['classification'], 'classification_reasons' => $c['reasons'], 'recommendations_ar' => $d['recommendations_ar'], 'recommendations_en' => $d['recommendations_en'], 'status' => 'draft', 'prepared_by' => $by->id]);
    }

    public function markReviewed(ProgramEvaluationReport $r): ProgramEvaluationReport
    {
        $r->update(['status' => 'reviewed']);
        $r->loadMissing('program');
        foreach (User::whereHas('roles', fn ($q) => $q->where('slug', Role::PLANNING_HEAD))->where('status', 'active')->pluck('id') as $uid) {
            $this->notifications->send($uid, 'evaluation_report.ready_for_approval', ['ar' => 'تقرير تقييم بانتظار الاعتماد', 'en' => 'An evaluation report awaits approval'],
                ['ar' => "تقرير تقييم برنامج «{$r->program->title_ar}» جاهز للاعتماد.", 'en' => "The evaluation report of \"{$r->program->title_en}\" is ready for approval."], ['report_id' => $r->id]);
        }

        return $r;
    }

    public function approve(ProgramEvaluationReport $r, User $by): ProgramEvaluationReport
    {
        $r->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()]);
        $r->loadMissing('program');
        if ($r->prepared_by) {
            $this->notifications->send($r->prepared_by, 'evaluation_report.approved', ['ar' => 'اعتُمد تقرير التقييم', 'en' => 'The evaluation report was approved'],
                ['ar' => "اعتُمد تقرير برنامج «{$r->program->title_ar}».", 'en' => "The report of \"{$r->program->title_en}\" was approved."], ['report_id' => $r->id]);
        }

        return $r;
    }

    /** The report as a document for the exporter (PDF, Word, Excel). @return array<string, mixed> */
    public function document(ProgramEvaluationReport $r, string $locale = 'ar'): array
    {
        $ar = $locale === 'ar';
        $L = fn (string $a, string $e) => $ar ? $a : $e;
        $r->loadMissing('program');
        $m = $r->metrics;
        $p = $r->program;
        $class = ['successful_continue' => $L('ناجح — يُستمر فيه', 'Successful — continue'), 'needs_review' => $L('يحتاج مراجعة', 'Needs review'), 'weak_stop' => $L('ضعيف — يُوقف أو يُعاد تصميمه', 'Weak — stop or redesign')][$r->classification];
        $n = fn ($v, $suffix = '') => $v === null ? '—' : $v.$suffix;
        $names = ['satisfaction' => $L('رضا المتدربين', 'Trainee satisfaction'), 'knowledge_gain' => $L('مكسب المعرفة %', 'Knowledge gain %'), 'impact' => $L('أثر التدريب', 'Training impact')];
        $state = ['good' => $L('محقق', 'Met'), 'between' => $L('دون المرجو', 'Below target'), 'low' => $L('ضعيف', 'Low'), 'missing' => $L('لا بيانات', 'No data')];
        $sig = $m['knowledge_gain']['significance'] ?? [];
        $sigText = ($sig['enough'] ?? false) ? $L(
            ($sig['level'] ?? 'none') === 'none' ? 'الفرق بين الاختبارين قد يكون بفعل الصدفة.' : 'الفرق بين الاختبارين القبلي والبعدي مؤكد إحصائياً على الأرجح.',
            ($sig['level'] ?? 'none') === 'none' ? 'The difference between the two tests may be due to chance.' : 'The difference between the pre- and post-test is very likely real.',
        ) : $L('عدد الأزواج غير كافٍ لحكم إحصائي.', 'Too few pairs for a statistical judgement.');

        return [
            'title' => $L('تقرير تقييم البرنامج: ', 'Program evaluation report: ').($ar ? $p->title_ar : $p->title_en),
            'subtitle' => $L('الحالة: ', 'Status: ').$r->status.' · '.$r->period,
            'sections' => [
                ['heading' => $L('التصنيف', 'Classification'), 'paragraphs' => [$class], 'table' => ['head' => [$L('المؤشر', 'Indicator'), $L('القيمة', 'Value'), $L('المرجو', 'Target'), $L('الحالة', 'State')], 'rows' => array_map(fn ($x) => [$names[$x['key']], $n($x['value']), $x['good'], $state[$x['state']]], $r->classification_reasons ?? [])]],
                ['heading' => $L('المؤشرات الرئيسة', 'Key indicators'), 'bars' => array_values(array_filter([
                    ['label' => $L('متوسط الحضور', 'Average attendance'), 'value' => (float) ($m['attendance_average'] ?? 0), 'max' => 100], ['label' => $L('نسبة النجاح', 'Pass rate'), 'value' => (float) ($m['pass_rate'] ?? 0), 'max' => 100],
                    ['label' => $names['satisfaction'], 'value' => (float) ($m['satisfaction']['average'] ?? 0), 'max' => 100], ['label' => $names['impact'], 'value' => (float) ($m['impact']['score'] ?? 0), 'max' => 100],
                    ['label' => $names['knowledge_gain'], 'value' => (float) ($m['knowledge_gain']['average_percent'] ?? 0), 'max' => 100],
                ]))],
                ['heading' => $L('المقارنة القبلية والبعدية', 'Pre / post comparison'), 'paragraphs' => [$sigText], 'table' => ['head' => [$L('القبلي', 'Pre'), $L('البعدي', 'Post'), $L('المكسب %', 'Gain %'), $L('الأزواج', 'Pairs')], 'rows' => [[$n($m['knowledge_gain']['pre']), $n($m['knowledge_gain']['post']), $n($m['knowledge_gain']['average_percent']), $m['knowledge_gain']['participants']]]]],
                ['heading' => $L('أدوات التقييم', 'Instruments'), 'table' => ['head' => [$L('الأداة', 'Instrument'), $L('الاستجابات', 'Responses'), $L('المتوسط', 'Average')], 'rows' => [
                    [$L('رضا المتدربين', 'Satisfaction'), $m['satisfaction']['responses'], $n($m['satisfaction']['average'])], [$L('تأمل المدرب', 'Trainer reflection'), $m['trainer_reflection']['responses'], $n($m['trainer_reflection']['score'])],
                    [$L('تقييم فريق التخطيط', 'Planning evaluation'), $m['planning_evaluation']['responses'], $n($m['planning_evaluation']['score'])], [$L('مشرف البرنامج', 'Program supervisor'), $m['supervisor_feedback']['responses'], $n($m['supervisor_feedback']['score'])],
                    [$L('أخصائي التخطيط', 'Planning specialist'), $m['specialist_feedback']['responses'], $n($m['specialist_feedback']['score'])], [$L('المقابلات', 'Interviews'), $m['interviews']['count'], '+'.$m['interviews']['positive'].' / −'.$m['interviews']['negative']],
                ]]],
                ['heading' => $L('نقاط القوة', 'Strengths'), 'bullets' => array_map(fn ($x) => $x[$locale] ?? '', $r->qualitative['strengths'] ?? [])],
                ['heading' => $L('فرص التحسين', 'Improvements'), 'bullets' => array_map(fn ($x) => $x[$locale] ?? '', $r->qualitative['improvements'] ?? [])],
                ['heading' => $L('التوصيات', 'Recommendations'), 'paragraphs' => [$ar ? (string) $r->recommendations_ar : (string) $r->recommendations_en]],
            ],
        ];
    }
}
