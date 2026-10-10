<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Certificate;
use App\Models\EvaluationAssignment;
use App\Models\EvaluationForm;
use App\Models\EvaluationResponse;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\NeedsSurveys\SurveySchema;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
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

    public function __construct(private readonly NotificationService $notifications, private readonly EvaluationSettings $settings, private readonly FileStorage $storage) {}

    // ───────────────────────────── forms

    /** The stock forms, created the first time they are needed so there is something to start from. */
    public function ensureDefaults(): void
    {
        if (EvaluationForm::where('is_default', true)->exists()) {
            return;
        }
        $r = fn (string $id, string $ar, string $en, bool $req = true) => ['id' => $id, 'type' => 'rating', 'title' => $ar, 'title_en' => $en, 'required' => $req, 'scale' => ['min' => 1, 'max' => 5], 'mode' => 'competence'];
        $t = fn (string $id, string $ar, string $en, bool $evidence = false) => ['id' => $id, 'type' => 'long_text', 'title' => $ar, 'title_en' => $en, 'required' => false] + ($evidence ? ['evidence' => true] : []);
        $defaults = [
            'trainer_reflection' => ['تأمل المدرب الذاتي', 'Trainer self-reflection', [
                $r('engagement', 'مستوى تفاعل المتدربين', 'Trainee engagement'), $r('objectives', 'مدى تحقق أهداف البرنامج', 'How far the objectives were met'), $r('content_time', 'ملاءمة المحتوى والوقت', 'Fit of content and time'), $r('own_delivery', 'تقييمي لأدائي في التقديم', 'My own delivery'),
                ['id' => 'difficulties', 'type' => 'yes_no', 'title' => 'هل واجهتك صعوبات تنظيمية أو تقنية؟', 'title_en' => 'Did you face organisational or technical difficulties?', 'required' => false],
                $t('difficulty_details', 'اشرح الصعوبات (يمكن إرفاق دليل)', 'Describe the difficulties (evidence can be attached)', true), $t('worked', 'ما الذي نجح؟', 'What worked well?'), $t('change', 'ما الذي كنت ستغيّره؟', 'What would you change?'), $t('recommend', 'توصيات لتطوير البرنامج', 'Recommendations to improve the program'),
            ]],
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
        // The older instruments appear in the list as read-only adapters, so the registry shows all of them.
        foreach ([['satisfaction', 'استبيان رضا المتدربين', 'Trainee satisfaction survey'], ['impact_trainee', 'نموذج أثر التدريب — المتدرب', 'Training impact — trainee'], ['impact_manager', 'نموذج أثر التدريب — المدير المباشر', 'Training impact — direct manager']] as [$kind, $ar, $en]) {
            EvaluationForm::create(['kind' => $kind, 'title_ar' => $ar, 'title_en' => $en, 'questions' => [], 'approval_status' => 'approved', 'approved_at' => now(), 'is_default' => true, 'is_system' => true, 'settings' => ['evidence' => $kind !== 'satisfaction']]);
        }
    }

    public function defaultFor(string $kind): EvaluationForm
    {
        $this->ensureDefaults();

        return EvaluationForm::where('kind', $kind)->where('approval_status', 'approved')->orderByDesc('is_default')->latest()->firstOrFail();
    }

    // ───────────────────────────── assignments

    public function assign(EvaluationForm $form, Program $program, ?TrainingGroup $group, User $respondent, string $type, ?Registration $subject = null, ?Carbon $due = null, ?User $by = null): ?EvaluationAssignment
    {
        $existing = EvaluationAssignment::where(['form_id' => $form->id, 'group_id' => $group?->id, 'respondent_user_id' => $respondent->id, 'subject_registration_id' => $subject?->id])->first();
        if ($existing) {
            return null;
        }
        $a = EvaluationAssignment::create(['form_id' => $form->id, 'program_id' => $program->id, 'group_id' => $group?->id, 'respondent_type' => $type, 'respondent_user_id' => $respondent->id, 'subject_registration_id' => $subject?->id,
            'due_at' => $due ?? now()->addDays((int) $this->settings->get('impact.expiry_days', 30)), 'sent_at' => now(), 'assigned_by' => $by?->id]);

        $this->notifications->send($respondent->id, $type === 'manager' ? 'impact.manager_due' : 'evaluation.assigned', ['ar' => 'نموذج تقييم بانتظارك', 'en' => 'An evaluation form is waiting for you'],
            ['ar' => "مطلوب منك تعبئة «{$form->title_ar}» لبرنامج «{$program->title_ar}».", 'en' => "Please fill in \"{$form->title_en}\" for \"{$program->title_en}\"."], ['assignment_id' => $a->id, 'program_id' => $program->id]);

        return $a;
    }

    /** At the end of a group: reflection to each trainer, feedback to the program supervisor and the planning specialists. */
    public function assignForGroup(TrainingGroup $group): int
    {
        $this->ensureDefaults();
        $group->loadMissing('program', 'trainers.trainer');
        $program = $group->program;
        $n = 0;

        foreach ($group->trainers->where('status', 'approved') as $gt) {
            if ($user = $gt->trainer?->user_id ? User::find($gt->trainer->user_id) : null) {
                $n += (int) (bool) $this->assign($this->defaultFor('trainer_reflection'), $program, $group, $user, 'trainer');
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
    public function submit(EvaluationAssignment $a, array $answers, array $evidence, User $by): EvaluationResponse
    {
        abort_unless($a->respondent_user_id === $by->id, 403);
        $a->loadMissing('form', 'program');
        if ($a->status === 'submitted') {
            throw new BusinessRuleException(__('messages.evaluation.submitted'), 'evaluation_submitted');
        }
        if ($a->status === 'expired') {
            throw new BusinessRuleException(__('messages.evaluation.expired'), 'evaluation_expired');
        }

        $questions = $a->form->questions;
        $clean = SurveySchema::validateAnswers($questions, $answers);
        $stored = $this->storeEvidence($a, $questions, $evidence);

        $response = EvaluationResponse::create(['assignment_id' => $a->id, 'answers' => $clean, 'evidence' => $stored ?: null, 'form_version' => $a->form->version, 'score' => $this->score($questions, $clean), 'submitted_at' => now()]);
        $a->update(['status' => 'submitted']);
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

        return $response;
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
                'nps' => $v * 10,
                'yes_no' => $v === 'yes' ? 100 : 0,
                default => null,
            };
        }
        $values = array_values(array_filter($values, fn ($x) => $x !== null));

        return $values ? round(array_sum($values) / count($values), 2) : null;
    }
}
