<?php

namespace App\Services\Plc;

use App\Exceptions\BusinessRuleException;
use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\GroupTrainer;
use App\Models\ImpactSurvey;
use App\Models\NotificationDelivery;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\User;
use App\Services\EvaluationService;
use App\Services\EvaluationSettings;
use App\Services\ImpactService;
use App\Services\NeedsSurveys\SurveySchema;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * PLC Phase 6: the evaluation layer of a workshop on the evaluation suite's forms and assignment engine.
 *
 * The suggestion rules (design §7) decide which instrument goes to whom and when: the workshop (satisfaction) form
 * to every seated trainee at the end of the last session; one trainer form per trainer at the end of that trainer's
 * last session; a session form per session for multi-session workshops when the policy says so; a community form
 * to trainees and coordinators when the workshop belongs to a community; impact follow-ups (+45/+90 trainee,
 * +60 manager) through the existing impact engine for the configured sources. Every suggestion shows its reason,
 * nothing goes out before the workshop has started, one assignment exists per (form, respondent, target), and a
 * sending is reported with the delivery evidence (the in-app notification row and the e-mail / SMS deliveries),
 * never as "sent" without it. A workshop closes when its mandatory instruments reached the policy's response rate,
 * or with a documented exception recorded by the centre.
 */
class WorkshopEvaluation
{
    /** States in which evaluations may be sent (by the clock or by hand). */
    public const SENDABLE = ['in_progress', 'completed', 'awaiting_evaluation'];

    public const RULE_PREFIX = 'plc.';

    public function __construct(
        private readonly PlcSettings $settings,
        private readonly EvaluationService $evaluations,
        private readonly EvaluationSettings $evaluationSettings,
        private readonly WorkshopWorkflow $workflow,
        private readonly WorkshopPeople $people,
        private readonly ImpactService $impact,
        private readonly NotificationService $notifications,
    ) {}

    // ───────────────────────────── forms

    /** The PLC instrument of this key (workshop, trainer, session, community), created the first time it is needed. */
    public function formFor(string $key): EvaluationForm
    {
        $form = EvaluationForm::where('kind', 'custom')->where('settings->plc', $key)->where('approval_status', 'approved')->orderByDesc('created_at')->first();
        if ($form) {
            return $form;
        }
        $r = fn (string $id, string $ar, string $en) => ['id' => $id, 'type' => 'rating', 'title' => $ar, 'title_en' => $en, 'required' => true, 'scale' => ['min' => 1, 'max' => 5], 'mode' => 'satisfaction'];
        $t = fn (string $id, string $ar, string $en) => ['id' => $id, 'type' => 'long_text', 'title' => $ar, 'title_en' => $en, 'required' => false];
        [$ar, $en, $questions] = match ($key) {
            'workshop' => ['تقييم ورشة مجتمع التعلم المهني', 'PLC workshop evaluation', [
                $r('objectives', 'وضوح أهداف الورشة وتحققها', 'Clarity and achievement of the workshop objectives'), $r('content', 'ملاءمة المحتوى لاحتياجي المهني', 'Fit of the content to my professional need'),
                $r('methods', 'فاعلية الأساليب والأنشطة', 'Effectiveness of the methods and activities'), $r('organisation', 'التنظيم والمكان والوقت', 'Organisation, venue and timing'),
                $r('apply', 'إمكانية تطبيق ما تعلمته في مدرستي', 'How far I can apply what I learned in my school'), $r('overall', 'التقييم العام للورشة', 'Overall rating of the workshop'),
                $t('strengths', 'أبرز ما استفدت منه', 'What I benefited from most'), $t('improve', 'ما الذي تقترح تحسينه؟', 'What would you improve?'),
            ]],
            'trainer' => ['تقييم مدرب الورشة', 'Workshop trainer evaluation', [
                $r('mastery', 'التمكن من المحتوى', 'Command of the content'), $r('clarity', 'وضوح العرض والشرح', 'Clarity of the presentation'), $r('interaction', 'التفاعل والإشراك', 'Interaction and engagement'),
                $r('time', 'إدارة الوقت', 'Time management'), $r('overall', 'التقييم العام للمدرب', 'Overall rating of the trainer'), $t('note', 'ملاحظة للمدرب', 'A note for the trainer'),
            ]],
            'session' => ['تقييم جلسة الورشة', 'Workshop session evaluation', [
                $r('value', 'قيمة الجلسة لعملي', 'Value of the session for my work'), $r('pace', 'ملاءمة الإيقاع والمدة', 'Pace and duration'), $r('overall', 'التقييم العام للجلسة', 'Overall rating of the session'), $t('note', 'ملاحظة', 'A note'),
            ]],
            'community' => ['تقييم مجتمع التعلم المهني', 'Professional learning community evaluation', [
                $r('collaboration', 'التعاون بين المدارس المشاركة', 'Collaboration between the participating schools'), $r('relevance', 'ارتباط الورشة بأهداف المجتمع', 'Fit with the community\'s goals'),
                $r('continuity', 'استمرارية الأثر في المجتمع', 'Continuity of the effect inside the community'), $r('overall', 'التقييم العام للمجتمع', 'Overall rating of the community'), $t('note', 'مقترحات للمجتمع', 'Suggestions for the community'),
            ]],
            default => throw new BusinessRuleException(__('messages.plc.evaluation_unknown'), 'plc_evaluation_unknown'),
        };

        return EvaluationForm::create(['kind' => 'custom', 'title_ar' => $ar, 'title_en' => $en, 'questions' => SurveySchema::normalize($questions), 'approval_status' => 'approved', 'approved_at' => now(), 'is_default' => false, 'is_system' => false, 'settings' => ['plc' => $key, 'evidence' => false, 'max_files' => 0]]);
    }

    // ───────────────────────────── the plan

    /**
     * Every job the rules produce for this workshop: one per instrument target (the workshop, each trainer, each
     * session, the community), with its respondents, timing and reason. Nothing is sent here.
     *
     * @return list<array{instrument: string, key: string, target: array, label: array, reason: array, due_at: Carbon|null, mandatory: bool, enabled: bool, due_days: int, applies: bool, respondents: Collection}>
     */
    public function jobs(PlcWorkshop $w): array
    {
        $w->loadMissing('program', 'community.lead', 'coordinator');
        $rules = $this->settings->evaluationRules();
        $sessions = ProgramSession::with('plcTrainers')->where('program_id', $w->program_id)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get();
        $last = $sessions->max('ends_at');
        $last = $last ? Carbon::parse($last) : ($w->program->end_date?->copy()->endOfDay());
        $seated = $this->seated($w);
        $trainees = $seated->map(fn (Registration $r) => ['user' => $r->employee?->user, 'registration' => $r, 'type' => 'trainee'])->filter(fn ($x) => $x['user'])->values();
        $jobs = [];

        $jobs[] = ['instrument' => 'workshop', 'key' => 'workshop', 'target' => [], 'label' => ['ar' => 'تقييم الورشة', 'en' => 'Workshop evaluation'],
            'reason' => ['ar' => 'يُرسل لكل متدرب مقعده معتمد بعد انتهاء آخر جلسة.', 'en' => 'Sent to every seated trainee after the last session ends.'],
            'due_at' => $last, 'applies' => true, 'respondents' => $trainees] + $this->ruleBits($rules['workshop']);

        $groupTrainers = GroupTrainer::with('trainer:id,name_ar,name_en')->where('group_id', $this->people->group($w)->id)->where('status', GroupTrainer::APPROVED)->get();
        foreach ($groupTrainers as $gt) {
            if (! $gt->trainer) {
                continue;
            }
            $own = $sessions->filter(fn (ProgramSession $s) => $s->plcTrainers->contains('trainer_id', $gt->trainer_id));
            $due = $own->isNotEmpty() ? Carbon::parse($own->max('ends_at')) : $last;
            $jobs[] = ['instrument' => 'trainer', 'key' => 'trainer:'.$gt->trainer_id, 'target' => ['trainer_id' => $gt->trainer_id], 'label' => ['ar' => $gt->trainer->name_ar, 'en' => $gt->trainer->name_en ?: $gt->trainer->name_ar],
                'reason' => ['ar' => 'نموذج لكل مدرب معتمد بعد انتهاء آخر جلسة له'.($own->isNotEmpty() ? ' ('.$own->count().' جلسة)' : '').'.', 'en' => 'One form per confirmed trainer after that trainer\'s last session'.($own->isNotEmpty() ? ' ('.$own->count().' sessions)' : '').'.'],
                'due_at' => $due, 'applies' => true, 'respondents' => $trainees] + $this->ruleBits($rules['trainer']);
        }

        $multi = $sessions->count() > 1;
        foreach ($sessions as $s) {
            $attended = Attendance::where('program_session_id', $s->id)->whereIn('status', ['present', 'late', 'left_early'])->pluck('employee_id');
            $who = $attended->isNotEmpty() ? $trainees->filter(fn ($x) => $attended->contains($x['registration']->employee_id))->values() : $trainees;
            $jobs[] = ['instrument' => 'session', 'key' => 'session:'.$s->id, 'target' => ['program_session_id' => $s->id], 'label' => ['ar' => ($s->title_ar ?: 'الجلسة '.$s->sequence), 'en' => ($s->title_en ?: 'Session '.$s->sequence)],
                'reason' => ['ar' => $multi ? 'ورشة متعددة الجلسات: نموذج قصير بعد كل جلسة لمن حضرها.' : 'الورشة من جلسة واحدة؛ يكفي تقييم الورشة.', 'en' => $multi ? 'Multi-session workshop: a short form after each session for those who attended it.' : 'Single-session workshop; the workshop evaluation is enough.'],
                'due_at' => Carbon::parse($s->ends_at), 'applies' => $multi, 'respondents' => $who, 'session_at' => $s->starts_at] + $this->ruleBits($rules['session']);
        }

        $coordinators = collect([$w->coordinator, $w->community?->lead])->filter()->unique('id')->map(fn (User $u) => ['user' => $u, 'registration' => null, 'type' => 'supervisor']);
        $jobs[] = ['instrument' => 'community', 'key' => 'community', 'target' => [], 'label' => ['ar' => $w->community?->title() ?? 'مجتمع التعلم', 'en' => $w->community?->title() ?? 'Learning community'],
            'reason' => ['ar' => $w->community_id ? 'الورشة ضمن مجتمع تعلم: يُرسل للمتدربين والمنسقين بعد انتهاء الورشة.' : 'الورشة ليست ضمن مجتمع تعلم.', 'en' => $w->community_id ? 'The workshop belongs to a community: sent to trainees and coordinators after the workshop ends.' : 'The workshop is not part of a community.'],
            'due_at' => $last, 'applies' => (bool) $w->community_id, 'respondents' => $trainees->concat($coordinators)->values()] + $this->ruleBits($rules['community']);

        return $jobs;
    }

    /** Sends the jobs that are due (or the chosen instruments, when a person sends by hand) and returns how many assignments were created. @param list<string>|null $only instrument keys or job keys */
    public function dispatch(PlcWorkshop $w, User $by, ?array $only = null, bool $force = false): int
    {
        if (! in_array($w->workflow_state, self::SENDABLE, true)) {
            throw new BusinessRuleException(__('messages.plc.evaluation_state'), 'plc_evaluation_state');
        }
        $group = $this->people->group($w);
        $n = 0;
        $sentLabels = [];
        foreach ($this->jobs($w) as $job) {
            if (! $job['applies']) {
                continue;
            }
            $chosen = $only === null || in_array($job['instrument'], $only, true) || in_array($job['key'], $only, true);
            if (! $chosen) {
                continue;
            }
            if ($only !== null && ! $job['enabled']) {
                throw new BusinessRuleException(__('messages.plc.evaluation_disabled'), 'plc_evaluation_disabled', ['instrument' => $job['instrument']]);
            }
            if (! $job['enabled'] || (! $force && ! ($job['due_at'] && $job['due_at']->lte(now())))) {
                continue;
            }
            $due = now()->addDays($job['due_days']);
            $detail = $job['instrument'] === 'trainer' ? ['ar' => 'المدرب: '.$job['label']['ar'], 'en' => 'Trainer: '.$job['label']['en']] : ($job['instrument'] === 'session' ? ['ar' => 'الجلسة: '.$job['label']['ar'], 'en' => 'Session: '.$job['label']['en']] : []);
            $made = 0;
            $form = $this->formFor($job['instrument']);
            foreach ($job['respondents'] as $r) {
                $a = $this->evaluations->assign($form, $w->program, $group, $r['user'], $r['type'], null, $due, $by, $job['target'] + ['rule_key' => self::RULE_PREFIX.$job['instrument'], 'detail' => $detail]);
                $made += (int) (bool) $a;
            }
            if ($made) {
                $n += $made;
                $sentLabels[] = $job['instrument'] === 'workshop' || $job['instrument'] === 'community' ? $job['label'] : ['ar' => $job['label']['ar'], 'en' => $job['label']['en']];
                $this->workflow->decision($w, 'evaluation', 'sent', null, ['instrument' => $job['instrument'], 'target' => $job['target'] ?: null, 'count' => $made, 'manual' => $only !== null || $force], $by);
            }
        }
        if ($n && ($owner = $this->owner($w)) && $owner->id !== $by->id) {
            $title = $w->program->title_ar;
            $this->notifications->send($owner->id, 'plc.evaluations_sent', ['ar' => 'أُرسلت تقييمات الورشة', 'en' => 'Workshop evaluations were sent'],
                ['ar' => "«{$title}»: أُرسل {$n} نموذج تقييم.", 'en' => "\"{$title}\": {$n} evaluation forms were sent."], ['program_id' => $w->program_id, 'link' => '/admin/communities/workshops/'.$w->program_id.'/evaluation']);
        }

        return $n;
    }

    /** When the clock completes the workshop: seated people who met the minimum attendance are completed (impact follow-ups scheduled per policy), due instruments go out when the policy sends automatically, and the workshop settles into its evaluation state. */
    public function onCompleted(PlcWorkshop $w, User $by): void
    {
        $w->loadMissing('program');
        $this->completeRegistrations($w);
        $rules = $this->settings->evaluationRules();
        if ($rules['auto_send']) {
            $this->dispatch($w, $by);
        } elseif ($owner = $this->owner($w)) {
            $title = $w->program->title_ar;
            $this->notifications->send($owner->id, 'plc.evaluations_ready', ['ar' => 'تقييمات الورشة جاهزة للإرسال', 'en' => 'Workshop evaluations are ready to send'],
                ['ar' => "اكتملت «{$title}»؛ راجع التقييمات المقترحة وأرسلها.", 'en' => "\"{$title}\" is complete; review the suggested evaluations and send them."], ['program_id' => $w->program_id, 'link' => '/admin/communities/workshops/'.$w->program_id.'/evaluation']);
        }
        $this->settle($w->refresh(), $by);
    }

    /** The clock's pass over a completed or awaiting workshop: due instruments, then the state. */
    public function tick(PlcWorkshop $w, User $by): void
    {
        if ($this->settings->evaluationRules()['auto_send'] && in_array($w->workflow_state, self::SENDABLE, true)) {
            $this->dispatch($w, $by);
        }
        if (in_array($w->workflow_state, ['completed', 'awaiting_evaluation'], true)) {
            $this->settle($w->refresh(), $by);
        }
    }

    /** completed → awaiting_evaluation once mandatory assignments exist; → closed once they are complete (or none is mandatory). */
    public function settle(PlcWorkshop $w, User $by): PlcWorkshop
    {
        $state = $this->completion($w);
        if ($w->workflow_state === 'completed') {
            if ($state['complete']) {
                return $this->closeNow($w, $by, 'complete');
            }
            if ($state['assigned']) {
                return $this->workflow->move($w, 'awaiting_evaluation', $by, null, ['auto' => 'clock', 'pending' => $state['blocking']]);
            }
        } elseif ($w->workflow_state === 'awaiting_evaluation' && $state['complete']) {
            return $this->closeNow($w, $by, 'complete');
        }

        return $w;
    }

    /** A person closes the workshop: allowed when the mandatory instruments are complete, otherwise only with a documented exception by the centre. */
    public function close(PlcWorkshop $w, User $by, ?string $reason = null, bool $exception = false): PlcWorkshop
    {
        if (! in_array($w->workflow_state, ['completed', 'awaiting_evaluation'], true)) {
            throw new BusinessRuleException(__('messages.plc.close_state'), 'plc_close_state');
        }
        $state = $this->completion($w);
        if ($state['complete']) {
            return $this->closeNow($w, $by, 'complete', $reason);
        }
        if (! $exception) {
            throw new BusinessRuleException(__('messages.plc.evaluation_incomplete'), 'plc_evaluation_incomplete', ['blocking' => $state['blocking']]);
        }
        if (! $by->hasPermission('plc.exceptions')) {
            throw new BusinessRuleException(__('messages.plc.exception_permission'), 'plc_exception_permission');
        }
        if (! filled($reason)) {
            throw new BusinessRuleException(__('messages.plc.reason_required'), 'plc_reason_required');
        }
        $this->workflow->decision($w, 'exception', 'evaluation_waived', $reason, ['blocking' => $state['blocking']], $by);

        return $this->closeNow($w, $by, 'exception', $reason);
    }

    /** Reminds the people who have not answered yet (at most once a day per assignment). */
    public function remind(PlcWorkshop $w, ?string $instrument, User $by): int
    {
        $n = 0;
        $this->assignments($w)->when($instrument, fn ($q) => $q->where('rule_key', self::RULE_PREFIX.$instrument))->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('reminded_at')->orWhere('reminded_at', '<=', now()->subDay()))->get()->each(function (EvaluationAssignment $a) use (&$n) {
                $this->evaluations->remind($a);
                $n++;
            });
        if ($n) {
            $this->workflow->decision($w, 'evaluation', 'reminded', null, ['instrument' => $instrument, 'count' => $n], $by);
        }

        return $n;
    }

    /** A cancelled workshop keeps the answers already given; the unanswered PLC assignments expire. */
    public function onCancelled(PlcWorkshop $w): int
    {
        return $this->assignments($w)->where('status', 'pending')->update(['status' => 'expired']);
    }

    // ───────────────────────────── the tracking view

    public function tracking(PlcWorkshop $w, User $user): array
    {
        $w->loadMissing('program', 'community');
        $rules = $this->settings->evaluationRules();
        $minResponses = (int) $this->evaluationSettings->get('anonymous_min_responses', 3);
        $rows = $this->assignments($w)->with(['respondent:id,name,name_ar', 'respondent.employee.school:id,name_ar,name_en', 'trainer:id,name_ar,name_en', 'session:id,sequence,title_ar,title_en', 'response:id,assignment_id,submitted_at', 'submission:id,submitted_at'])->orderBy('created_at')->get();
        $delivery = $this->deliveries($rows);
        $byKey = $rows->groupBy(fn (EvaluationAssignment $a) => $this->jobKey($a));

        $instruments = [];
        foreach ($this->jobs($w) as $job) {
            $mine = $byKey->get($job['key'], collect());
            $stats = $this->stats($mine, $delivery, $minResponses);
            $status = match (true) {
                ! $job['enabled'] => 'off',
                ! $job['applies'] => 'not_applicable',
                $stats['assigned'] > 0 && $job['mandatory'] && $stats['rate'] !== null && $stats['rate'] >= $rules['close_response_rate'] => 'complete',
                $stats['assigned'] > 0 => 'sent',
                $job['due_at'] && $job['due_at']->lte(now()) && in_array($w->workflow_state, self::SENDABLE, true) => 'due',
                default => 'waiting',
            };
            $instruments[] = ['instrument' => $job['instrument'], 'key' => $job['key'], 'label' => $job['label'], 'reason' => $job['reason'], 'enabled' => $job['enabled'], 'mandatory' => $job['mandatory'], 'due_days' => $job['due_days'],
                'applies' => $job['applies'], 'due_at' => $job['due_at']?->toIso8601String(), 'session_at' => isset($job['session_at']) ? Carbon::parse($job['session_at'])->toIso8601String() : null, 'targets' => $job['respondents']->count(), 'status' => $status] + $stats;
        }
        $completion = $this->completion($w);
        $impact = $this->impactSummary($w, $rules);
        $canManage = $user->hasPermission('plc.evaluations.manage') || $user->hasPermission('plc.manage');

        return [
            'workshop' => ['id' => $w->program_id, 'title_ar' => $w->program->title_ar, 'title_en' => $w->program->title_en, 'state' => $w->workflow_state, 'source' => $w->source, 'community' => $w->community?->title(), 'state_changed_at' => $w->state_changed_at?->toIso8601String()],
            'rules' => $rules, 'min_responses' => $minResponses,
            'instruments' => $instruments,
            'summary' => $this->stats($rows, $delivery, $minResponses),
            'completion' => $completion,
            'impact' => $impact,
            'assignments' => $rows->map(fn (EvaluationAssignment $a) => $this->row($a, $delivery[$a->id] ?? null))->values()->all(),
            'can' => [
                'send' => $canManage && in_array($w->workflow_state, self::SENDABLE, true),
                'remind' => $canManage && $rows->where('status', 'pending')->isNotEmpty(),
                'close' => $canManage && in_array($w->workflow_state, ['completed', 'awaiting_evaluation'], true),
                'exception' => $user->hasPermission('plc.exceptions'),
                'settings' => $user->hasPermission('plc.settings'),
            ],
        ];
    }

    /** The assignments as a CSV (names, instrument, target, status, dates — never the answers or scores). */
    public function csv(PlcWorkshop $w): string
    {
        $rows = $this->assignments($w)->with(['respondent:id,name,name_ar', 'respondent.employee.school:id,name_ar,name_en', 'trainer:id,name_ar,name_en', 'session:id,sequence,title_ar,title_en', 'response:id,assignment_id,submitted_at', 'submission:id,submitted_at'])->orderBy('created_at')->get();
        $delivery = $this->deliveries($rows);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['respondent', 'school', 'instrument', 'target', 'status', 'notified', 'email', 'sms', 'sent_at', 'due_at', 'submitted_at']);
        foreach ($rows as $a) {
            $r = $this->row($a, $delivery[$a->id] ?? null);
            fputcsv($out, [$r['name'], $r['school'], $r['instrument'], $r['target']['ar'] ?? '', $r['status'], $r['delivery']['in_app'] ? 'yes' : 'no', $r['delivery']['email'] ?? '', $r['delivery']['sms'] ?? '', $r['sent_at'], $r['due_at'], $r['submitted_at']]);
        }
        rewind($out);

        return stream_get_contents($out);
    }

    // ───────────────────────────── helpers

    /** Whether the mandatory instruments are complete, and which ones block the close. @return array{complete: bool, assigned: int, blocking: list<array>} */
    public function completion(PlcWorkshop $w): array
    {
        $rules = $this->settings->evaluationRules();
        $rows = $this->assignments($w)->get(['id', 'rule_key', 'trainer_id', 'program_session_id', 'status']);
        $byKey = $rows->groupBy(fn (EvaluationAssignment $a) => $this->jobKey($a));
        $blocking = [];
        $assigned = 0;
        foreach ($this->jobs($w) as $job) {
            if (! $job['applies'] || ! $job['enabled'] || ! $job['mandatory']) {
                continue;
            }
            if ($job['respondents']->isEmpty()) {
                continue;
            }
            $mine = $byKey->get($job['key'], collect());
            $assigned += $mine->count();
            $rate = $mine->count() ? round($mine->where('status', 'submitted')->count() / $mine->count() * 100, 1) : null;
            if ($rate === null || $rate < $rules['close_response_rate']) {
                $blocking[] = ['instrument' => $job['instrument'], 'key' => $job['key'], 'label' => $job['label'], 'assigned' => $mine->count(), 'submitted' => $mine->where('status', 'submitted')->count(), 'rate' => $rate, 'required' => $rules['close_response_rate']];
            }
        }

        return ['complete' => $blocking === [], 'assigned' => $assigned, 'blocking' => $blocking];
    }

    private function closeNow(PlcWorkshop $w, User $by, string $how, ?string $reason = null): PlcWorkshop
    {
        $w = $this->workflow->move($w, 'closed', $by, $reason, ['evaluation' => $how]);
        $title = $w->program->title_ar;
        foreach ($this->owners($w, $by) as $uid) {
            $this->notifications->send($uid, 'plc.closed', ['ar' => 'أُغلقت الورشة', 'en' => 'The workshop is closed'],
                ['ar' => "«{$title}»: ".($how === 'exception' ? 'أُغلقت باستثناء موثّق.' : 'اكتملت تقييماتها وأُغلقت.'), 'en' => "\"{$title}\": ".($how === 'exception' ? 'closed with a documented exception.' : 'its evaluations are complete and it is closed.')], ['program_id' => $w->program_id, 'link' => '/admin/communities/workshops/'.$w->program_id]);
        }

        return $w;
    }

    private function completeRegistrations(PlcWorkshop $w): void
    {
        $rules = $this->settings->evaluationRules();
        $min = (float) ($w->program->min_attendance_percent ?? 0);
        $withImpact = in_array($w->source, $rules['impact_sources'], true);
        foreach ($this->seated($w) as $r) {
            if ($r->status !== Registration::STATUS_APPROVED || (float) ($r->attendance_percent ?? 0) < $min) {
                continue;
            }
            $r->update(['status' => Registration::STATUS_COMPLETED, 'completed_at' => $r->completed_at ?? now()]);
            if ($withImpact) {
                $this->impact->scheduleFollowUps($r->refresh());
            }
        }
    }

    private function impactSummary(PlcWorkshop $w, array $rules): array
    {
        $applies = in_array($w->source, $rules['impact_sources'], true);
        $surveys = ImpactSurvey::where('program_id', $w->program_id)->get(['id', 'stage_days', 'scheduled_for', 'status']);
        $managers = EvaluationAssignment::where('program_id', $w->program_id)->where('respondent_type', 'manager')->get(['id', 'status']);
        $completed = Registration::where('program_id', $w->program_id)->where('status', Registration::STATUS_COMPLETED)->count();
        $impactDays = (int) $this->evaluationSettings->get('impact.trainee_days', 45);
        $managerDays = (int) $this->evaluationSettings->get('impact.manager_days', 60);

        return [
            'applies' => $applies, 'sources' => $rules['impact_sources'], 'completed_registrations' => $completed,
            'reason' => $applies ? ['ar' => "مصدر الورشة ({$w->source}) ضمن مصادر قياس الأثر: نموذج المتدرب بعد {$impactDays} يوماً ونموذج المدير بعد {$managerDays} يوماً من إكمال كل متدرب.", 'en' => "The workshop source ({$w->source}) is among the impact sources: the trainee form {$impactDays} days and the manager form {$managerDays} days after each trainee completes."]
                : ['ar' => 'مصدر الورشة ليس ضمن مصادر قياس الأثر في السياسة.', 'en' => 'The workshop source is not among the policy\'s impact sources.'],
            'trainee' => ['scheduled' => $surveys->where('status', 'scheduled')->count(), 'sent' => $surveys->where('status', 'sent')->count(), 'completed' => $surveys->where('status', 'completed')->count(), 'expired' => $surveys->where('status', 'expired')->count(),
                'next_at' => optional($surveys->where('status', 'scheduled')->sortBy('scheduled_for')->first())->scheduled_for?->toDateString(), 'stages' => $surveys->pluck('stage_days')->unique()->sort()->values()->all()],
            'manager' => ['assigned' => $managers->count(), 'submitted' => $managers->where('status', 'submitted')->count(), 'days' => $managerDays],
        ];
    }

    /** @param Collection<int, EvaluationAssignment> $rows */
    private function stats(Collection $rows, array $delivery, int $minResponses): array
    {
        $assigned = $rows->count();
        $submitted = $rows->where('status', 'submitted');
        $pending = $rows->where('status', 'pending');
        $scores = $submitted->pluck('score')->filter(fn ($s) => $s !== null);
        $notified = $rows->filter(fn ($a) => ($delivery[$a->id]['in_app'] ?? false))->count();
        $failed = $rows->filter(fn ($a) => ($delivery[$a->id]['failed'] ?? false))->count();

        return [
            'assigned' => $assigned, 'submitted' => $submitted->count(), 'pending' => $pending->count(), 'expired' => $rows->where('status', 'expired')->count(),
            'overdue' => $pending->filter(fn ($a) => $a->due_at && $a->due_at->isPast())->count(),
            'rate' => $assigned ? round($submitted->count() / $assigned * 100, 1) : null,
            'notified' => $notified, 'unsent' => $assigned - $notified, 'failed' => $failed,
            'score' => $scores->count() >= $minResponses ? round((float) $scores->avg(), 1) : null, 'score_hidden' => $scores->count() > 0 && $scores->count() < $minResponses,
        ];
    }

    private function row(EvaluationAssignment $a, ?array $delivery): array
    {
        $target = $a->trainer ? ['ar' => $a->trainer->name_ar, 'en' => $a->trainer->name_en ?: $a->trainer->name_ar]
            : ($a->session ? ['ar' => $a->session->title_ar ?: 'الجلسة '.$a->session->sequence, 'en' => $a->session->title_en ?: 'Session '.$a->session->sequence] : null);
        $submittedAt = $a->response?->submitted_at ?? $a->submission?->submitted_at ?? ($a->status === 'submitted' ? $a->updated_at : null);

        return [
            'id' => $a->id, 'instrument' => substr((string) $a->rule_key, strlen(self::RULE_PREFIX)), 'key' => $this->jobKey($a), 'name' => $a->respondent?->displayName(), 'school' => $a->respondent?->employee?->school?->name_ar,
            'respondent_type' => $a->respondent_type, 'target' => $target, 'status' => $a->status, 'overdue' => $a->status === 'pending' && $a->due_at?->isPast(),
            'sent_at' => $a->sent_at?->toIso8601String(), 'due_at' => $a->due_at?->toIso8601String(), 'reminded_at' => $a->reminded_at?->toIso8601String(), 'submitted_at' => $submittedAt ? Carbon::parse($submittedAt)->toIso8601String() : null,
            'delivery' => $delivery ?? ['in_app' => false, 'email' => null, 'sms' => null, 'failed' => false],
        ];
    }

    /** The delivery evidence per assignment: the in-app notification row and the e-mail / SMS delivery statuses. @return array<string, array{in_app: bool, email: ?string, sms: ?string, failed: bool}> */
    private function deliveries(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }
        $ids = $rows->pluck('id')->all();
        $notifications = AppNotification::whereIn('type', ['evaluation.assigned', 'impact.manager_due'])->whereIn('user_id', $rows->pluck('respondent_user_id')->unique()->all())
            ->where('created_at', '>=', $rows->min('created_at')->copy()->subMinute())->get(['id', 'user_id', 'data'])
            ->filter(fn ($n) => in_array($n->data['assignment_id'] ?? null, $ids, true))->keyBy(fn ($n) => $n->data['assignment_id']);
        $deliveries = NotificationDelivery::whereIn('notification_id', $notifications->pluck('id')->all())->get(['notification_id', 'channel', 'status'])->groupBy('notification_id');
        $out = [];
        foreach ($rows as $a) {
            $n = $notifications->get($a->id);
            $d = $n ? $deliveries->get($n->id, collect()) : collect();
            $email = $d->firstWhere('channel', 'email')?->status;
            $sms = $d->firstWhere('channel', 'sms')?->status;
            $out[$a->id] = ['in_app' => (bool) $n, 'email' => $email, 'sms' => $sms, 'failed' => $d->isNotEmpty() && $d->every(fn ($x) => $x->status === 'failed')];
        }

        return $out;
    }

    private function jobKey(EvaluationAssignment $a): string
    {
        return match (true) {
            (bool) $a->trainer_id => 'trainer:'.$a->trainer_id,
            (bool) $a->program_session_id => 'session:'.$a->program_session_id,
            default => substr((string) $a->rule_key, strlen(self::RULE_PREFIX)),
        };
    }

    private function ruleBits(array $rule): array
    {
        return ['enabled' => (bool) $rule['enabled'], 'mandatory' => (bool) $rule['mandatory'], 'due_days' => (int) $rule['due_days']];
    }

    private function assignments(PlcWorkshop $w)
    {
        return EvaluationAssignment::where('program_id', $w->program_id)->where('rule_key', 'like', self::RULE_PREFIX.'%');
    }

    /** @return Collection<int, Registration> */
    private function seated(PlcWorkshop $w): Collection
    {
        return Registration::with(['employee.user:id,name,name_ar,status', 'employee.school:id,name_ar,name_en'])->where('program_id', $w->program_id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get()->sortBy(fn ($r) => $r->employee?->user?->displayName())->values();
    }

    private function owner(PlcWorkshop $w): ?User
    {
        return $w->coordinator ?? User::find($w->program?->created_by);
    }

    /** @return list<string> */
    private function owners(PlcWorkshop $w, User $except): array
    {
        return array_values(array_unique(array_filter([$w->coordinator_user_id, $w->program?->created_by], fn ($id) => $id && $id !== $except->id)));
    }
}
