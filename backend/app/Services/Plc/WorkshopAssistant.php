<?php

namespace App\Services\Plc;

use App\Ai\AiGuard;
use App\Exceptions\BusinessRuleException;
use App\Models\AiLog;
use App\Models\AuditLog;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationResponse;
use App\Models\GroupTrainer;
use App\Models\ImpactSurvey;
use App\Models\PlcDecision;
use App\Models\PlcNomination;
use App\Models\PlcWorkshop;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\User;
use App\Services\Ai\AiModelSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * PLC Phase 8: the advisory layer. A planning checklist and completion tracker computed from the workshop's own
 * records (every item says why and where to go), the explanations behind the school and trainee suggestions,
 * the decision log grouped by kind, the need → workshop → impact chain, and feedback themes from the open-text
 * answers of the PLC forms through the AI guard, only when the Ministry's PLC policy opts in. Nothing here decides
 * anything: every item is a suggestion for a person to act on.
 */
class WorkshopAssistant
{
    public const FEATURE = 'plc';

    public const MIN_ANSWERS = 3;

    private const MAX_ANSWERS = 150;

    private const MAX_ANSWER_CHARS = 400;

    public function __construct(private readonly PlcSettings $settings, private readonly WorkshopPeople $people, private readonly WorkshopEvaluation $evaluation, private readonly AiGuard $guard, private readonly AiModelSettings $models) {}

    // ───────────────────────────── the checklist

    public function checklist(PlcWorkshop $w, User $user): array
    {
        $w->loadMissing('program', 'need', 'community', 'schools', 'hostSchool');
        $p = $w->program;
        $base = '/admin/communities/workshops/'.$w->program_id;
        $state = $w->workflow_state;
        $order = array_flip(WorkshopWorkflow::STATES);
        $reached = fn (string $s) => ($order[$state] ?? 0) >= ($order[$s] ?? 0) && ! in_array($state, ['rejected', 'cancelled'], true);
        $editable = in_array($state, WorkshopWorkflow::EDITABLE, true);

        $schools = $w->schools->where('status', '!=', 'excluded');
        $participants = $schools->where('role', '!=', 'host');
        $noms = PlcNomination::where('program_id', $w->program_id)->get(['id', 'status', 'clashes', 'school_id']);
        $seated = $noms->whereIn('status', PlcNomination::SEATED)->count();
        $withClashes = $noms->filter(fn ($n) => ! empty($n->clashes))->count();
        $seats = (int) $schools->sum('seats');
        $group = $p->groups()->orderBy('sequence')->first();
        $trainers = $group ? GroupTrainer::where('group_id', $group->id)->whereIn('status', [GroupTrainer::PROPOSED, GroupTrainer::APPROVED])->get(['status']) : collect();
        $confirmed = $trainers->where('status', GroupTrainer::APPROVED)->count();
        $sessions = ProgramSession::with('plcTrainers')->where('program_id', $w->program_id)->where('status', '!=', 'cancelled')->get();
        $incomplete = $sessions->filter(fn ($s) => (($s->mode ?: 'in_person') !== 'online' && ! $s->training_room_id && ! $s->location_text) || (($s->mode ?: 'in_person') !== 'in_person' && ! $s->online_url) || $s->plcTrainers->isEmpty())->count();
        $locked = $sessions->whereNotNull('attendance_locked_at')->count();
        $ended = $sessions->filter(fn ($s) => $s->ends_at && $s->ends_at->isPast())->count();
        $completion = in_array($state, ['completed', 'awaiting_evaluation', 'closed', 'archived'], true) ? $this->evaluation->completion($w) : null;
        $pending = collect($w->approval_path ?? [])->firstWhere('status', 'pending');

        $items = [];
        $add = function (string $key, string $status, string $ar, string $en, ?string $link = null) use (&$items) {
            $items[] = ['key' => $key, 'status' => $status, 'detail' => ['ar' => $ar, 'en' => $en], 'link' => $link];
        };

        $add('source', $w->source ? 'done' : 'todo', $w->source ? 'المصدر: '.$w->source.($w->source_ref ? ' · '.$w->source_ref : '').($w->need ? ' · من احتياج مسجّل' : '') : 'حدّد مصدر الورشة (راصد، المركز، جهة أخرى، مدرسة).',
            $w->source ? 'Source: '.$w->source.($w->source_ref ? ' · '.$w->source_ref : '').($w->need ? ' · from a registered need' : '') : 'Set the workshop source (راصد, the centre, another entity, a school).', $editable ? $base.'/edit' : null);
        $add('basics', $p->start_date && $p->capacity ? ($p->total_hours ? 'done' : 'warn') : 'todo', $p->start_date && $p->capacity ? ($p->total_hours ? 'الأساسيات مكتملة: تاريخ البداية والسعة والساعات.' : 'تاريخ البداية والسعة محددان؛ أضف إجمالي الساعات.') : 'أكمل تاريخ البداية والسعة والساعات.', $p->start_date && $p->capacity ? ($p->total_hours ? 'Basics complete: start date, capacity and hours.' : 'Start date and capacity set; add the total hours.') : 'Complete the start date, capacity and hours.', $editable ? $base.'/edit' : null);
        $add('host', $w->host_school_id ? 'done' : 'todo', $w->host_school_id ? 'المدرسة المستضيفة: '.$w->hostSchool?->name_ar : 'اختر المدرسة المستضيفة.', $w->host_school_id ? 'Host school: '.($w->hostSchool?->name_en ?: $w->hostSchool?->name_ar) : 'Choose the host school.', $base.'/schools');
        if (! $w->isInternal()) {
            $add('schools', $participants->isNotEmpty() ? ($seats >= (int) $p->capacity || $seats === 0 ? 'done' : 'warn') : 'warn',
                $participants->isNotEmpty() ? $participants->count().' مدرسة مشاركة · '.$seats.' مقعداً من '.$p->capacity.($seats && $seats < (int) $p->capacity ? ' (المقاعد أقل من السعة)' : '') : 'لا مدارس مشاركة بعد؛ أضفها ووزّع المقاعد، أو أبقِ الورشة للمستضيفة وحدها.',
                $participants->isNotEmpty() ? $participants->count().' participating schools · '.$seats.' seats of '.$p->capacity.($seats && $seats < (int) $p->capacity ? ' (seats below capacity)' : '') : 'No participating schools yet; add them and share the seats, or keep the workshop for the host alone.', $base.'/schools');
        }
        $add('trainees', $seated > 0 ? ($seated >= (int) $p->capacity * 0.5 ? 'done' : 'warn') : 'todo',
            $seated > 0 ? $seated.' مقعداً مشغولاً من '.$p->capacity.($withClashes ? ' · '.$withClashes.' ترشيحاً بتعارض' : '').($noms->where('status', 'waitlisted')->count() ? ' · '.$noms->where('status', 'waitlisted')->count().' في الانتظار' : '') : 'رشّح المتدربين واعتمد الترشيحات.',
            $seated > 0 ? $seated.' seats taken of '.$p->capacity.($withClashes ? ' · '.$withClashes.' nominations with clashes' : '').($noms->where('status', 'waitlisted')->count() ? ' · '.$noms->where('status', 'waitlisted')->count().' waiting' : '') : 'Nominate the trainees and approve the nominations.', $base.'/trainees');
        $add('trainers', $confirmed > 0 ? 'done' : ($trainers->isNotEmpty() ? 'warn' : 'todo'), $confirmed > 0 ? $confirmed.' مدرباً مؤكداً' : ($trainers->isNotEmpty() ? 'مدرب مقترح بانتظار التأكيد' : 'اقترح مدرباً وأكّد تكليفه.'), $confirmed > 0 ? $confirmed.' confirmed trainers' : ($trainers->isNotEmpty() ? 'A proposed trainer awaits confirmation' : 'Propose a trainer and confirm the assignment.'), $base.'/trainers');
        $add('sessions', $sessions->isNotEmpty() ? ($incomplete ? 'warn' : 'done') : 'todo', $sessions->isNotEmpty() ? $sessions->count().' جلسة'.($incomplete ? ' · '.$incomplete.' تنقصها قاعة أو رابط أو مدرب' : ' مكتملة') : 'خطّط الجلسات: الوقت والصيغة والمكان أو الرابط والمدرب.', $sessions->isNotEmpty() ? $sessions->count().' sessions'.($incomplete ? ' · '.$incomplete.' missing a room, link or trainer' : ' complete') : 'Plan the sessions: time, mode, venue or link, trainer.', $base.'/schedule');
        $add('approval', $reached('approved') ? 'done' : ($state === 'submitted' ? 'warn' : 'todo'), $reached('approved') ? 'معتمدة' : ($state === 'submitted' ? 'بانتظار خطوة: '.($pending['key'] ?? '') : ($state === 'needs_changes' ? 'طُلب تعديل: '.($w->decision_note ?? '') : 'قدّم الورشة للمراجعة بعد اكتمال ما سبق.')), $reached('approved') ? 'Approved' : ($state === 'submitted' ? 'Awaiting step: '.($pending['key'] ?? '') : ($state === 'needs_changes' ? 'Changes requested: '.($w->decision_note ?? '') : 'Submit for review once the above is complete.')), $base);
        $add('scheduled', $reached('scheduled') ? 'done' : 'todo', $reached('scheduled') ? 'أُرسلت الدعوات للمتدربين والمدربين.' : 'ثبّت الجدول لإرسال الدعوات.', $reached('scheduled') ? 'Invitations sent to trainees and trainers.' : 'Confirm the schedule to send the invitations.', $base.'/schedule');
        $add('attendance', $sessions->isNotEmpty() && $locked === $sessions->count() ? 'done' : ($ended > $locked ? 'warn' : 'todo'), $sessions->isNotEmpty() ? $locked.' من '.$sessions->count().' جلسة مقفلة السجل'.($ended > $locked ? ' · '.($ended - $locked).' انتهت ولم تُقفل' : '') : 'يُسجَّل الحضور بعد الجدولة.', $sessions->isNotEmpty() ? $locked.' of '.$sessions->count().' sessions locked'.($ended > $locked ? ' · '.($ended - $locked).' ended and not locked' : '') : 'Attendance is recorded after scheduling.', $base.'/attendance');
        $add('evaluation', $completion ? ($completion['complete'] ? 'done' : ($completion['assigned'] ? 'warn' : 'todo')) : 'todo', $completion ? ($completion['complete'] ? 'التقييمات الإلزامية مكتملة.' : ($completion['assigned'] ? 'بانتظار الإجابات: '.collect($completion['blocking'])->map(fn ($b) => $b['label']['ar'].' '.($b['rate'] ?? 0).'%')->implode('، ') : 'لم تُرسل التقييمات بعد.')) : 'تُرسل التقييمات بعد اكتمال الورشة.', $completion ? ($completion['complete'] ? 'Mandatory evaluations complete.' : ($completion['assigned'] ? 'Awaiting answers: '.collect($completion['blocking'])->map(fn ($b) => $b['label']['en'].' '.($b['rate'] ?? 0).'%')->implode(', ') : 'Evaluations not sent yet.')) : 'Evaluations go out once the workshop completes.', $base.'/evaluation');
        $add('closed', $reached('closed') ? 'done' : 'todo', $reached('closed') ? 'مغلقة' : 'تُغلق بعد اكتمال التقييمات أو باستثناء موثّق.', $reached('closed') ? 'Closed' : 'Closes once the evaluations are complete or with a documented exception.', $base.'/evaluation');

        $done = count(array_filter($items, fn ($i) => $i['status'] === 'done'));
        // The next step is the first thing not started; a warning (something incomplete but not blocking) comes after.
        $next = collect($items)->first(fn ($i) => $i['status'] === 'todo') ?? collect($items)->first(fn ($i) => $i['status'] === 'warn');
        $decisions = PlcDecision::where('subject_type', PlcWorkshop::class)->where('subject_id', $w->program_id)->get(['kind', 'decision']);

        return [
            'state' => $state, 'progress' => (int) round($done / max(1, count($items)) * 100), 'done' => $done, 'total' => count($items), 'items' => $items, 'next' => $next,
            'explanations' => [
                'schools' => $schools->map(fn ($s) => ['school_id' => $s->school_id, 'role' => $s->role, 'reasons' => array_values((array) ($s->suggestion_reasons ?? [])), 'distance_km' => $s->distance_km])->values()->all(),
                'trainees' => ['seated' => $seated, 'with_clashes' => $withClashes, 'by_status' => $noms->countBy('status')->all(), 'schools' => $noms->groupBy('school_id')->map->count()->all()],
            ],
            'decisions' => ['total' => $decisions->count(), 'by_kind' => $decisions->countBy('kind')->all(), 'exceptions' => $decisions->where('kind', 'exception')->count()],
            'impact' => $this->impactChain($w),
            'themes' => $this->themesStatus($w, $user),
        ];
    }

    /** The need → workshop → attendance → evaluation → impact chain, so the loop from a registered need to its effect is visible. */
    public function impactChain(PlcWorkshop $w): array
    {
        $seated = Registration::where('program_id', $w->program_id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get(['id', 'status', 'attendance_percent']);
        $forms = EvaluationAssignment::where('program_id', $w->program_id)->where('rule_key', 'plc.workshop')->get(['status', 'score']);
        $impact = ImpactSurvey::where('program_id', $w->program_id)->get(['status']);

        return [
            'need' => $w->need ? ['id' => $w->need->id, 'title' => $w->need->title, 'reference_no' => $w->need->reference_no, 'source' => $w->need->source] : null,
            'seated' => $seated->count(), 'completed' => $seated->where('status', Registration::STATUS_COMPLETED)->count(),
            'attendance' => $seated->count() ? round((float) $seated->avg('attendance_percent'), 1) : null,
            'evaluation' => ['assigned' => $forms->count(), 'submitted' => $forms->where('status', 'submitted')->count()],
            'impact' => ['scheduled' => $impact->where('status', 'scheduled')->count(), 'sent' => $impact->where('status', 'sent')->count(), 'completed' => $impact->where('status', 'completed')->count()],
        ];
    }

    // ───────────────────────────── feedback themes (AI, opt-in)

    public function themesStatus(PlcWorkshop $w, User $user): array
    {
        [, $count] = $this->answers($w);
        $optIn = (bool) $this->settings->get('ai_feedback_opt_in');
        $cached = Cache::get($this->cacheKey($w));

        return [
            'opt_in' => $optIn, 'available' => $optIn && $this->guard->available(self::FEATURE, true), 'answers' => $count, 'min_answers' => self::MIN_ANSWERS,
            'can_generate' => $optIn && $this->guard->available(self::FEATURE, true) && $count >= self::MIN_ANSWERS && ($user->hasPermission('plc.evaluations.manage') || $user->hasPermission('plc.manage')),
            'result' => $cached,
        ];
    }

    /** Themes, sentiment, quotes and recommendations from the PLC forms' open answers; the names of the respondents are hidden before anything leaves. */
    public function themes(PlcWorkshop $w, User $by, string $locale = 'ar', bool $fresh = false): array
    {
        if (! $this->settings->get('ai_feedback_opt_in')) {
            throw new BusinessRuleException(__('messages.plc.ai_opt_out'), 'plc_ai_opt_out');
        }
        [$blocks, $count, $names] = $this->answers($w, true);
        if ($count < self::MIN_ANSWERS) {
            throw new BusinessRuleException(__('messages.plc.ai_too_few', ['n' => self::MIN_ANSWERS]), 'plc_ai_too_few');
        }
        $key = $this->cacheKey($w);
        if (! $fresh && ($cached = Cache::get($key))) {
            return $cached;
        }
        $w->loadMissing('program');
        $system = $locale === 'en'
            ? 'You analyse feedback on a professional-learning-community workshop for a government training centre (teachers and school leaders). Work only from the answers given; never invent facts, never guess who wrote an answer, never output names, e-mails or phone numbers. Quotes must be verbatim fragments of at most 20 words. Every recommendation is advice for the centre to consider, never a decision. Answer in English.'
            : 'أنت تحلل تغذية راجعة عن ورشة مجتمع تعلم مهني لمركز تدريب حكومي (معلمون وقادة مدارس). اعمل فقط من الإجابات المعطاة؛ لا تخترع حقائق، ولا تخمّن هوية كاتب أي إجابة، ولا تُخرج أسماء أو بريداً أو أرقام هواتف. الاقتباسات مقاطع حرفية لا تتجاوز 20 كلمة. كل توصية اقتراح للمركز لينظر فيه، لا قرار. أجب بالعربية الفصحى.';
        $prompt = ($locale === 'en' ? 'Workshop: ' : 'الورشة: ').$w->program->title_ar."\n\n".$blocks;
        $schema = ['type' => 'object', 'required' => ['summary', 'themes', 'sentiment', 'recommendations'], 'properties' => [
            'summary' => ['type' => 'string'],
            'sentiment' => ['type' => 'object', 'properties' => ['positive' => ['type' => 'integer'], 'neutral' => ['type' => 'integer'], 'negative' => ['type' => 'integer']]],
            'themes' => ['type' => 'array', 'maxItems' => 8, 'items' => ['type' => 'object', 'required' => ['title', 'count'], 'properties' => ['title' => ['type' => 'string'], 'count' => ['type' => 'integer'], 'quotes' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'string']]]]],
            'recommendations' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'string']],
        ]];
        $out = $this->guard->json(self::FEATURE, $system, $prompt, $schema, $by, true, $names);
        if (! $out || ! isset($out['themes'])) {
            throw new BusinessRuleException(__('messages.plc.ai_unavailable'), 'plc_ai_unavailable');
        }
        $themes = [];
        foreach (array_slice((array) $out['themes'], 0, 8) as $t) {
            if (is_array($t) && trim((string) ($t['title'] ?? '')) !== '') {
                $themes[] = ['title' => Str::limit(trim((string) $t['title']), 120, ''), 'count' => max(0, min($count, (int) ($t['count'] ?? 0))), 'quotes' => array_values(array_slice(array_filter(array_map(fn ($q) => Str::limit(trim((string) $q), 160, '…'), (array) ($t['quotes'] ?? []))), 0, 3))];
            }
        }
        $result = [
            'summary' => Str::limit(trim((string) ($out['summary'] ?? '')), 1200, '…'), 'themes' => $themes,
            'sentiment' => ['positive' => (int) ($out['sentiment']['positive'] ?? 0), 'neutral' => (int) ($out['sentiment']['neutral'] ?? 0), 'negative' => (int) ($out['sentiment']['negative'] ?? 0)],
            'recommendations' => array_values(array_slice(array_filter(array_map(fn ($r) => Str::limit(trim((string) $r), 300, '…'), (array) ($out['recommendations'] ?? []))), 0, 6)),
            'answers_count' => $count, 'model' => $this->models->resolve('text')['model']['model'] ?? null, 'redactions' => (int) (AiLog::where('feature', self::FEATURE)->orderByDesc('created_at')->value('redactions') ?? 0),
            'generated_at' => now()->toIso8601String(), 'generated_by' => $by->displayName(), 'advisory' => true,
        ];
        Cache::put($key, $result, now()->addDay());
        AuditLog::create(['user_id' => $by->id, 'action' => 'plc_feedback_themes', 'auditable_type' => PlcWorkshop::class, 'auditable_id' => $w->program_id, 'new_values' => ['answers' => $count, 'model' => $result['model'], 'redactions' => $result['redactions']]]);

        return $result;
    }

    /** @return array{0: string, 1: int, 2: list<string>} the answer blocks, how many answers, the respondents' names */
    private function answers(PlcWorkshop $w, bool $withNames = false): array
    {
        $rows = EvaluationResponse::with('assignment.form:id,questions', 'assignment.respondent:id,name,name_ar')->whereHas('assignment', fn ($q) => $q->where('program_id', $w->program_id)->where('rule_key', 'like', 'plc.%'))->get();
        $byQuestion = [];
        $names = [];
        $count = 0;
        foreach ($rows as $r) {
            $questions = collect($r->assignment?->form?->questions ?? [])->where('type', 'long_text')->keyBy('id');
            foreach ($questions as $id => $q) {
                $text = trim((string) ($r->answers[$id] ?? ''));
                if ($text === '' || $count >= self::MAX_ANSWERS) {
                    continue;
                }
                $byQuestion[$q['title']][] = str_replace(["\r", "\n"], ' ', Str::limit($text, self::MAX_ANSWER_CHARS, '…'));
                $count++;
            }
            if ($withNames && $r->assignment?->respondent) {
                $names[] = $r->assignment->respondent->name;
                $names[] = $r->assignment->respondent->name_ar;
            }
        }
        $blocks = [];
        foreach ($byQuestion as $title => $answers) {
            $blocks[] = '### '.$title."\n".collect($answers)->map(fn ($t, $i) => ($i + 1).'. '.$t)->implode("\n");
        }

        return [implode("\n\n", $blocks), $count, array_values(array_unique(array_filter($names)))];
    }

    private function cacheKey(PlcWorkshop $w): string
    {
        $stamp = EvaluationResponse::whereHas('assignment', fn ($q) => $q->where('program_id', $w->program_id)->where('rule_key', 'like', 'plc.%'))->max('submitted_at');

        return 'plc.themes.'.$w->program_id.'.'.md5((string) $stamp);
    }
}
