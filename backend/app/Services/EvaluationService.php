<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Certificate;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\Program;
use App\Models\ProgramInstrument;
use App\Models\Registration;
use App\Models\Role;
use App\Models\Survey;
use App\Models\SurveyFile;
use App\Models\SurveySubmission;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\NeedsSurveys\SurveySchema;
use App\Services\Programs\ProgramInstruments;
use App\Services\Surveys\EvaluationAnswer;
use App\Services\Surveys\LegacyQuestions;
use App\Services\Surveys\SurveyCollector;
use App\Support\EvaluationTemplates;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The evaluation instruments that are driven by forms: trainer self-reflection, planning-team evaluation, supervisor and
 * specialist feedback, and the manager's impact form requests. Assignments go out by themselves at the right moment,
 * are reminded and expire; answers can carry evidence (files or links).
 */
class EvaluationService
{
    public const FORM_KINDS = ['trainer_reflection', 'planning_evaluation', 'supervisor_feedback', 'specialist_feedback', 'custom'];

    public const RESPONDENT = ['trainer_reflection' => 'trainer', 'planning_evaluation' => 'planning_member', 'supervisor_feedback' => 'supervisor', 'specialist_feedback' => 'planning_specialist', 'impact_manager' => 'manager', 'custom' => 'planning_member'];

    public const EVIDENCE_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'video/mp4'];

    public function __construct(private readonly NotificationService $notifications, private readonly EvaluationSettings $settings, private readonly FileStorage $storage, private readonly SurveyCollector $collector) {}

    // ───────────────────────────── forms

    /** The stock forms, created the first time they are needed so there is something to start from. */
    public function ensureDefaults(): void
    {
        if (EvaluationForm::where('is_default', true)->exists()) {
            $this->adoptCentreForms();

            return;
        }
        $r = fn (string $id, string $ar, string $en, bool $req = true) => ['id' => $id, 'type' => 'rating', 'title' => $ar, 'title_en' => $en, 'required' => $req, 'scale' => ['min' => 1, 'max' => 5], 'mode' => 'competence'];
        $t = fn (string $id, string $ar, string $en, bool $evidence = false) => ['id' => $id, 'type' => 'long_text', 'title' => $ar, 'title_en' => $en, 'required' => false] + ($evidence ? ['evidence' => true] : []);
        $defaults = [
            'planning_evaluation' => ['تقييم فريق التخطيط', 'Planning-team evaluation', [
                $r('design', 'جودة تصميم البرنامج', 'Quality of the program design'), $r('materials', 'جودة المواد التدريبية', 'Quality of the training materials'), $r('organisation', 'التنظيم واللوجستيات', 'Organisation and logistics'), $r('trainer', 'أداء المدرب', 'Trainer performance'), $r('alignment', 'التوافق مع الخطة والاحتياج', 'Alignment with the plan and the need'),
                $t('strengths', 'نقاط القوة', 'Strengths', true), $t('improvements', 'فرص التحسين', 'Improvements'),
            ]],
            'supervisor_feedback' => ['تغذية راجعة من مشرف البرنامج', 'Program supervisor feedback', [
                $r('delivery', 'سير التنفيذ كما خُطط له', 'Delivery as planned'), $r('participants', 'التزام المشاركين', 'Participant commitment'), $r('support', 'كفاية الدعم المقدّم', 'Adequacy of the support'),
                $t('observations', 'ملاحظات المشرف', 'Supervisor observations', true), $t('recommend', 'التوصيات', 'Recommendations'),
            ]],
            'specialist_feedback' => ['تغذية راجعة من أخصائي التخطيط', 'Planning-specialist feedback', [
                $r('plan_fit', 'مدى مطابقة البرنامج للخطة السنوية', 'Match with the annual plan'), $r('need_fit', 'مدى معالجته للفجوات المرصودة', 'How well it addresses the identified gaps'), $r('indicators', 'تحقق مؤشرات الأداء', 'Achievement of the performance indicators'),
                $t('notes', 'ملاحظات الأخصائي', 'Specialist notes', true), $t('recommend', 'التوصيات للدورة القادمة', 'Recommendations for the next run'),
            ]],
        ];
        foreach ($defaults as $kind => [$ar, $en, $questions]) {
            EvaluationForm::create(['kind' => $kind, 'title_ar' => $ar, 'title_en' => $en, 'questions' => SurveySchema::normalize($questions), 'approval_status' => 'approved', 'approved_at' => now(), 'is_default' => true, 'settings' => ['evidence' => true, 'max_files' => 3]]);
        }
        // The trainer's self-reflection is the centre's own form (EvaluationTemplates).
        $reflection = EvaluationTemplates::trainerReflection();
        EvaluationForm::create(['kind' => 'trainer_reflection', 'title_ar' => $reflection['title_ar'], 'title_en' => $reflection['title_en'], 'questions' => SurveySchema::normalize($reflection['questions']),
            'approval_status' => 'approved', 'approved_at' => now(), 'is_default' => true, 'settings' => ['evidence' => false, 'max_files' => 3, 'template' => EvaluationTemplates::REFLECTION]]);
        // The older instruments appear in the list as read-only adapters, so the registry shows all of them.
        foreach ([['satisfaction', 'استبيان رضا المتدربين', 'Trainee satisfaction survey'], ['impact_trainee', 'نموذج أثر التدريب — المتدرب', 'Training impact — trainee'], ['impact_manager', 'نموذج أثر التدريب — المدير المباشر', 'Training impact — direct manager']] as [$kind, $ar, $en]) {
            EvaluationForm::create(['kind' => $kind, 'title_ar' => $ar, 'title_en' => $en, 'questions' => [], 'approval_status' => 'approved', 'approved_at' => now(), 'is_default' => true, 'is_system' => true, 'settings' => ['evidence' => $kind !== 'satisfaction']]);
        }
        // The trainee form (the satisfaction survey) carries the centre's questions, which the trainee's evaluation window shows.
        $trainee = EvaluationTemplates::trainee();
        EvaluationForm::where('kind', 'satisfaction')->where('is_system', true)->update(['title_ar' => $trainee['title_ar'], 'title_en' => $trainee['title_en'], 'questions' => json_encode(SurveySchema::normalize($trainee['questions']), JSON_UNESCAPED_UNICODE),
            'settings' => json_encode(['evidence' => false, 'template' => EvaluationTemplates::TRAINEE, 'intro_ar' => $trainee['intro_ar'], 'intro_en' => $trainee['intro_en']], JSON_UNESCAPED_UNICODE)]);
    }

    /**
     * The centre's own forms (EvaluationTemplates, docs/saber/08-centre-forms.md) on a database set up before them — run by migration 009200
     * and again the first time the forms are needed, so the switch never waits for a deploy step. The trainee (satisfaction) form takes the
     * centre's questions; «التقييم الذاتي للمدرب» becomes the default self-reflection and takes over the programmes that used the previous
     * default (a programme that chose another form keeps it). Earlier forms and answers stay; nothing happens once done.
     */
    public function adoptCentreForms(): void
    {
        $trainee = EvaluationTemplates::trainee();
        foreach (EvaluationForm::where('kind', 'satisfaction')->get() as $form) {
            if (($form->settings['template'] ?? null) !== EvaluationTemplates::TRAINEE) {
                $form->update(['title_ar' => $trainee['title_ar'], 'title_en' => $trainee['title_en'], 'questions' => SurveySchema::normalize($trainee['questions']),
                    'settings' => array_merge($form->settings ?? [], ['template' => EvaluationTemplates::TRAINEE, 'intro_ar' => $trainee['intro_ar'], 'intro_en' => $trainee['intro_en']])]);
            }
        }

        $reflections = EvaluationForm::where('kind', 'trainer_reflection')->get(['id', 'settings', 'is_default']);
        if ($reflections->isEmpty() || $reflections->contains(fn ($f) => ($f->settings['template'] ?? null) === EvaluationTemplates::REFLECTION)) {
            return;
        }
        DB::transaction(function () use ($reflections) {
            $previous = $reflections->where('is_default', true)->pluck('id');
            $reflection = EvaluationTemplates::trainerReflection();
            $form = EvaluationForm::create(['kind' => 'trainer_reflection', 'title_ar' => $reflection['title_ar'], 'title_en' => $reflection['title_en'], 'questions' => SurveySchema::normalize($reflection['questions']),
                'approval_status' => 'approved', 'approved_at' => now(), 'is_default' => false, 'settings' => ['evidence' => false, 'max_files' => 3, 'template' => EvaluationTemplates::REFLECTION]]);
            EvaluationForm::where('kind', 'trainer_reflection')->whereKeyNot($form->id)->update(['is_default' => false]);
            $form->update(['is_default' => true]);
            ProgramInstrument::where('kind', 'trainer_reflection')->where(fn ($q) => $q->whereIn('form_id', $previous)->orWhereNull('form_id'))->update(['form_id' => $form->id]);
        });
    }

    public function defaultFor(string $kind): EvaluationForm
    {
        $this->ensureDefaults();

        return EvaluationForm::where('kind', $kind)->where('approval_status', 'approved')->orderByDesc('is_default')->latest()->firstOrFail();
    }

    // ───────────────────────────── assignments

    /**
     * One assignment per form, respondent and target; `$target` may carry `program_session_id`, `trainer_id` (what is
     * being evaluated, PLC Phase 6), `rule_key` (why it was assigned) and `detail` (ar/en, appended to the message).
     */
    public function assign(EvaluationForm $form, Program $program, ?TrainingGroup $group, User $respondent, string $type, ?Registration $subject = null, ?Carbon $due = null, ?User $by = null, array $target = []): ?EvaluationAssignment
    {
        $sessionId = $target['program_session_id'] ?? null;
        $trainerId = $target['trainer_id'] ?? null;
        $existing = EvaluationAssignment::where(['form_id' => $form->id, 'group_id' => $group?->id, 'respondent_user_id' => $respondent->id, 'subject_registration_id' => $subject?->id, 'program_session_id' => $sessionId, 'trainer_id' => $trainerId])->first();
        if ($existing) {
            return null;
        }
        $a = EvaluationAssignment::create(['form_id' => $form->id, 'program_id' => $program->id, 'group_id' => $group?->id, 'respondent_type' => $type, 'respondent_user_id' => $respondent->id, 'subject_registration_id' => $subject?->id,
            'program_session_id' => $sessionId, 'trainer_id' => $trainerId, 'rule_key' => $target['rule_key'] ?? null,
            'due_at' => $due ?? now()->addDays((int) $this->settings->get('impact.expiry_days', 30)), 'sent_at' => now(), 'assigned_by' => $by?->id, 'survey_id' => $form->survey_id]);

        $detail = $target['detail'] ?? [];
        $this->notifications->send($respondent->id, $type === 'manager' ? 'impact.manager_due' : 'evaluation.assigned', ['ar' => 'نموذج تقييم بانتظارك', 'en' => 'An evaluation form is waiting for you'],
            ['ar' => "مطلوب منك تعبئة «{$form->title_ar}» لبرنامج «{$program->title_ar}».".(! empty($detail['ar']) ? ' '.$detail['ar'] : ''), 'en' => "Please fill in \"{$form->title_en}\" for \"{$program->title_en}\".".(! empty($detail['en']) ? ' '.$detail['en'] : '')], ['assignment_id' => $a->id, 'program_id' => $program->id]);

        return $a;
    }

    /** At the end of a group: reflection to each trainer, feedback to the program supervisor and the planning specialists. */
    public function assignForGroup(TrainingGroup $group): int
    {
        $this->ensureDefaults();
        $group->loadMissing('program', 'trainers.trainer');
        $program = $group->program;
        $n = 0;

        // The programme's own choice (generated from its kits): the self-reflection can be switched off, or use another approved form.
        $instruments = app(ProgramInstruments::class);
        if ($instruments->enabled($program, 'trainer_reflection')) {
            $formId = $instruments->formFor($program, 'trainer_reflection');
            $reflection = ($formId ? EvaluationForm::where('id', $formId)->where('approval_status', 'approved')->first() : null) ?? $this->defaultFor('trainer_reflection');
            foreach ($group->trainers->where('status', 'approved') as $gt) {
                if ($user = $gt->trainer?->user_id ? User::find($gt->trainer->user_id) : null) {
                    $n += (int) (bool) $this->assign($reflection, $program, $group, $user, 'trainer');
                }
            }
        }
        if ($program->coordinator_id && ($coordinator = User::find($program->coordinator_id))) {
            $n += (int) (bool) $this->assign($this->defaultFor('supervisor_feedback'), $program, $group, $coordinator, 'supervisor');
        }
        foreach (User::whereHas('roles', fn ($q) => $q->where('slug', Role::PLANNING_SPECIALIST))->where('status', 'active')->get() as $specialist) {
            $n += (int) (bool) $this->assign($this->defaultFor('specialist_feedback'), $program, $group, $specialist, 'planning_specialist');
        }

        return $n;
    }

    /** The planning head hands a form to chosen members (planning-team evaluation, or any custom form). @param list<string> $userIds */
    public function assignManual(EvaluationForm $form, TrainingGroup $group, array $userIds, ?Carbon $due, User $by): int
    {
        $group->loadMissing('program');
        $type = self::RESPONDENT[$form->kind] ?? 'planning_member';
        $n = 0;
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            $n += (int) (bool) $this->assign($form, $group->program, $group, $user, $type, null, $due, $by);
        }

        return $n;
    }

    /** The manager's impact form, asked of each trainee's direct manager once the schedule says so. */
    public function dispatchManagerImpact(): int
    {
        $this->ensureDefaults();
        $days = (int) $this->settings->get('impact.manager_days', 60);
        $form = EvaluationForm::where('kind', 'impact_manager')->firstOrFail();
        $n = 0;
        Registration::with(['employee.supervisor.user', 'program', 'trainingGroup'])->where('status', Registration::STATUS_COMPLETED)->whereNotNull('completed_at')->where('completed_at', '<=', now()->subDays($days))
            ->where('completed_at', '>=', now()->subDays($days + 120))->chunkById(200, function ($registrations) use ($form, &$n) {
                foreach ($registrations as $r) {
                    $manager = $r->employee->supervisor?->user;
                    if (! $manager || $r->supervisorEvaluations()->exists()) {
                        continue;
                    }
                    $n += (int) (bool) $this->assign($form, $r->program, $r->trainingGroup, $manager, 'manager', $r);
                }
            });

        return $n;
    }

    /** Reminds the ones who have not answered and closes the ones that ran out of time. @return array{reminded: int, expired: int} */
    public function remindAndExpire(): array
    {
        $after = (int) $this->settings->get('impact.reminder_after_days', 7);
        $reminded = 0;
        EvaluationAssignment::with('form', 'program')->where('status', 'pending')->whereNull('reminded_at')->where('sent_at', '<=', now()->subDays($after))->each(function (EvaluationAssignment $a) use (&$reminded) {
            $this->remind($a);
            $reminded++;
        });
        $expired = EvaluationAssignment::where('status', 'pending')->whereNotNull('due_at')->where('due_at', '<', now())->update(['status' => 'expired']);

        return ['reminded' => $reminded, 'expired' => $expired];
    }

    public function remind(EvaluationAssignment $a): void
    {
        $a->loadMissing('form', 'program');
        $this->notifications->send($a->respondent_user_id, 'evaluation.reminder', ['ar' => 'تذكير بنموذج تقييم', 'en' => 'Evaluation reminder'],
            ['ar' => "لم تعبّئ «{$a->form->title_ar}» لبرنامج «{$a->program->title_ar}» بعد.", 'en' => "You have not filled in \"{$a->form->title_en}\" for \"{$a->program->title_en}\" yet."], ['assignment_id' => $a->id]);
        $a->update(['reminded_at' => now()]);
    }

    // ───────────────────────────── answering

    /**
     * @param  array<string, mixed>  $answers
     * @param  array<string, list<UploadedFile|string>>  $evidence  per question: uploaded files and/or http(s) links
     */
    public function submit(EvaluationAssignment $a, array $answers, array $evidence, User $by, ?int $durationSeconds = null, string $source = 'web'): EvaluationAssignment
    {
        abort_unless($a->respondent_user_id === $by->id, 403);
        $a->loadMissing('form', 'program', 'survey.currentVersion');
        if ($a->status === 'submitted') {
            throw new BusinessRuleException(__('messages.evaluation.submitted'), 'evaluation_submitted');
        }
        if ($a->status === 'expired') {
            throw new BusinessRuleException(__('messages.evaluation.expired'), 'evaluation_expired');
        }

        if ($a->survey && $a->survey->currentVersion) {
            // The form lives on the engine: its validation, its drafts and its file answers (Gate 5).
            $result = $this->collector->submitFor($a->survey, $by, $a->id, $answers, $durationSeconds, $source);
            $a->update(['status' => 'submitted', 'submission_id' => $result['submission']->id, 'score' => $this->score($a->survey->currentVersion->questions, $result['submission']->answers)]);
        } else {
            $questions = $a->form->questions;
            $clean = SurveySchema::validateAnswers($questions, $answers);
            $stored = $this->storeEvidence($a, $questions, $evidence);
            $score = $this->score($questions, $clean);
            EvaluationResponse::create(['assignment_id' => $a->id, 'answers' => $clean, 'evidence' => $stored ?: null, 'form_version' => $a->form->version, 'score' => $score, 'submitted_at' => now()]);
            $a->update(['status' => 'submitted', 'score' => $score]);
        }
        // A certificate that was waiting for this form can now be downloaded: tell its holder.
        if ($a->form->settings['required_for_certificate'] ?? false) {
            $certificates = app(CertificateService::class);
            Certificate::where('program_id', $a->program_id)->where('status', 'valid')->whereHas('employee', fn ($q) => $q->where('user_id', $by->id))->get()->each(fn (Certificate $c) => $certificates->announce($c));
        }

        if ($a->form->kind === 'trainer_reflection') {
            foreach (User::whereHas('roles', fn ($q) => $q->whereIn('slug', [Role::PLANNING_HEAD, Role::PLANNING_SPECIALIST]))->where('status', 'active')->pluck('id') as $uid) {
                $this->notifications->send($uid, 'reflection.results_ready', ['ar' => 'وصل تأمل مدرب', 'en' => 'A trainer reflection arrived'],
                    ['ar' => "أرسل مدرب تأمله الذاتي لبرنامج «{$a->program->title_ar}».", 'en' => "A trainer sent their self-reflection for \"{$a->program->title_en}\"."], ['assignment_id' => $a->id, 'program_id' => $a->program_id]);
            }
        }

        return $a->fresh(['form', 'program']);
    }

    // ───────────────────────────── reading answers, wherever they live

    /**
     * Every answer to the assignments a scope selects, shaped alike whether the engine or the legacy table stores it:
     * `answers`, `score`, `submitted_at`, `evidence` and the `assignment` (with its form and respondent).
     *
     * @param  callable(Builder): void  $scope
     * @return Collection<int, EvaluationResponse|EvaluationAnswer>
     */
    public function answersFor(callable $scope): Collection
    {
        $q = EvaluationAssignment::with(['form', 'respondent:id,name,name_ar', 'response']);
        $scope($q);
        $assignments = $q->get();
        $ids = $assignments->whereNotNull('submission_id')->pluck('submission_id');
        $submissions = $ids->isEmpty() ? collect() : SurveySubmission::with('version:id,questions')->whereIn('id', $ids)->get()->keyBy('id');
        $files = $ids->isEmpty() ? collect() : SurveyFile::whereIn('submission_id', $ids)->orderBy('created_at')->get()->groupBy('submission_id');

        return $assignments->map(function (EvaluationAssignment $a) use ($submissions, $files) {
            if ($a->submission_id && ($s = $submissions->get($a->submission_id))) {
                return EvaluationAnswer::fromSubmission($a, $s, $files->get($s->id) ?? collect(), $s->version?->questions ?? []);
            }
            if ($a->response) {
                $a->response->setRelation('assignment', $a);

                return $a->response;
            }

            return null;
        })->filter()->values();
    }

    /** The questions results are computed on: the engine version's (without the evidence companions) when the form lives there. */
    public function questionsFor(EvaluationForm $form): array
    {
        $survey = $form->survey_id ? Survey::with('currentVersion')->find($form->survey_id) : null;
        $questions = $survey?->currentVersion?->questions ?? $survey?->questions ?? $form->questions ?? [];

        return $survey ? array_values(array_filter($questions, fn ($q) => ! LegacyQuestions::isCompanion($q) && ! in_array($q['type'], ['instructions', 'page_break'], true))) : $questions;
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function storeEvidence(EvaluationAssignment $a, array $questions, array $evidence): array
    {
        $allowed = collect($questions)->filter(fn ($q) => ! empty($q['evidence']))->pluck('id')->all();
        $out = [];
        foreach ($evidence as $qid => $items) {
            if (! in_array($qid, $allowed, true) && ! ($a->form->settings['evidence'] ?? false)) {
                throw ValidationException::withMessages(["evidence.$qid" => __('messages.evaluation.no_evidence')]);
            }
            $out[$qid] = $this->evidenceItems((array) $items, $a->id, (int) ($a->form->settings['max_files'] ?? 3), "evidence.$qid");
        }

        return $out;
    }

    /**
     * Validates and stores evidence: files (PDF, images, Office, MP4, up to 10 MB) and http(s) links.
     *
     * @param  list<UploadedFile|string>  $items
     * @return list<array<string, mixed>>
     */
    public function evidenceItems(array $items, string $directory, int $max, string $field = 'evidence'): array
    {
        $items = array_values($items);
        if (count($items) > $max) {
            throw ValidationException::withMessages([$field => __('messages.evaluation.too_many_files', ['max' => $max])]);
        }
        $out = [];
        foreach ($items as $item) {
            if ($item instanceof UploadedFile) {
                if (! in_array((string) $item->getMimeType(), self::EVIDENCE_MIMES, true) || $item->getSize() > 10 * 1024 * 1024) {
                    throw ValidationException::withMessages([$field => __('messages.evaluation.bad_file')]);
                }
                $out[] = ['type' => 'file', 'name' => mb_substr($item->getClientOriginalName(), 0, 200), 'path' => $this->storage->upload($item, 'evidence', $directory)];
            } elseif (is_string($item) && preg_match('#^https?://#i', $item)) {
                $out[] = ['type' => 'link', 'url' => mb_substr($item, 0, 500)];
            } else {
                throw ValidationException::withMessages([$field => __('messages.evaluation.bad_file')]);
            }
        }

        return $out;
    }

    /** The average of the rating-like answers on a 0–100 scale (null when there are none). */
    public function score(array $questions, array $answers): ?float
    {
        $values = [];
        foreach ($questions as $q) {
            $v = $answers[$q['id']] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $values[] = match ($q['type']) {
                'rating', 'scale' => ($v - ($q['scale']['min'] ?? 1)) / max(1, ($q['scale']['max'] ?? 5) - ($q['scale']['min'] ?? 1)) * 100,
                'matrix' => is_array($v) && $v !== [] ? (array_sum($v) / count($v) - ($q['scale']['min'] ?? 1)) / max(1, ($q['scale']['max'] ?? 5) - ($q['scale']['min'] ?? 1)) * 100 : null,
                'nps' => $v * 10,
                'yes_no' => $v === 'yes' ? 100 : 0,
                default => null,
            };
        }
        $values = array_values(array_filter($values, fn ($x) => $x !== null));

        return $values ? round(array_sum($values) / count($values), 2) : null;
    }
}
