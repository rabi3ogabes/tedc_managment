<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Exceptions\BusinessRuleException;
use App\Http\Resources\CertificateResource;
use App\Http\Resources\EmployeeResource;
use App\Models\Certificate;
use App\Models\Evaluation;
use App\Models\ImpactSurvey;
use App\Models\Program;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Models\TrainerCertificate;
use App\Services\Assessment\KnowledgeService;
use App\Services\CertificateService;
use App\Services\EvaluationService;
use App\Services\FileStorage;
use App\Services\ImpactService;
use App\Services\SatisfactionAlertService;
use App\Services\TaskApprovalService;
use App\Services\TrainerCertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Tasks, evaluations, certificate wallet, impact surveys and supervisor feedback.
 */
class MyOutcomesController extends MyTrainingController
{
    private const MIME_GROUPS = [
        'pdf' => ['application/pdf'],
        'word' => ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
    ];

    public function tasks(): JsonResponse
    {
        $registrations = Registration::with('program:id,title_ar,title_en')
            ->where('employee_id', $this->employee()->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->get()->keyBy('program_id');

        $submissions = TaskSubmission::whereIn('registration_id', $registrations->pluck('id'))->get()->keyBy('task_id');

        $tasks = Task::whereIn('program_id', $registrations->keys())->orderBy('due_at')->get()->map(fn (Task $task) => [
            'id' => $task->id,
            'program' => $registrations[$task->program_id]->program->translate('title'),
            'registration_id' => $registrations[$task->program_id]->id,
            'title' => $task->translate('title'),
            'instructions' => $task->translate('instructions'),
            'due_at' => $task->due_at?->toIso8601String(),
            'submission_types' => $task->submission_types,
            'max_file_mb' => $task->max_file_mb,
            'is_required' => $task->is_required,
            'submission' => ($s = $submissions->get($task->id)) ? [
                'id' => $s->id,
                'status' => $s->status,
                'feedback' => $s->feedback,
                'text_response' => $s->text_response,
                'file_name' => $s->file_name,
                'version' => $s->version,
                'updated_at' => $s->updated_at->toIso8601String(),
            ] : null,
        ]);

        return response()->json(['data' => $tasks]);
    }

    public function submitTask(Request $request, Task $task, FileStorage $storage, CertificateService $certificates): JsonResponse
    {
        $registration = Registration::where('program_id', $task->program_id)
            ->where('employee_id', $this->employee()->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])
            ->firstOrFail();

        $types = $task->submission_types ?? [];
        $data = $request->validate([
            'text_response' => [in_array('text', $types, true) && count($types) === 1 ? 'required' : 'nullable', 'string', 'max:20000'],
            'file' => ['nullable', 'file', 'max:'.($task->max_file_mb * 1024)],
        ]);

        if (empty($data['text_response']) && ! $request->hasFile('file')) {
            throw ValidationException::withMessages(['text_response' => __('messages.task_answer_required')]);
        }

        $existing = TaskSubmission::where('task_id', $task->id)->where('registration_id', $registration->id)->first();
        if (in_array($existing?->status, [TaskSubmission::STATUS_APPROVED, TaskSubmission::STATUS_PENDING_FINAL], true)) {
            throw new BusinessRuleException(__('messages.tasks.locked'), 'task_locked');
        }

        $attributes = [
            'employee_id' => $registration->employee_id,
            'text_response' => $data['text_response'] ?? $existing?->text_response,
            'status' => TaskSubmission::STATUS_SUBMITTED,
            'version' => ($existing?->version ?? 0) + 1,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'trainer_decision' => null, 'trainer_id' => null, 'trainer_decided_at' => null, 'supervisor_decision' => null, 'supervisor_id' => null, 'supervisor_decided_at' => null,
        ];

        if ($file = $request->file('file')) {
            $mime = (string) $file->getMimeType();
            $allowed = collect($types)->flatMap(fn ($t) => self::MIME_GROUPS[$t] ?? [])->all();
            if (! in_array($mime, $allowed, true)) {
                throw new BusinessRuleException(__('messages.tasks.type_not_allowed'), 'type_not_allowed');
            }
            $attributes += [
                'file_path' => $storage->upload($file, 'submissions', "{$task->program_id}/{$task->id}"),
                'file_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $mime,
            ];
        }

        $submission = TaskSubmission::updateOrCreate(['task_id' => $task->id, 'registration_id' => $registration->id], $attributes);
        $submission = app(TaskApprovalService::class)->onSubmitted($submission);

        return response()->json(['data' => $submission], 201);
    }

    public function submitEvaluation(Request $request, Registration $registration, CertificateService $certificates, ImpactService $impact): JsonResponse
    {
        $this->own($registration);
        abort_unless(in_array($registration->status, [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED], true), 422);
        if (! $registration->program->surveyIsOpen()) {
            throw new BusinessRuleException(__('messages.survey.closed'), 'survey_closed');
        }

        $data = $request->validate([
            'ratings' => ['required', 'array', 'min:1'],
            'ratings.*' => ['integer', 'between:1,5'],
            'pre_test_score' => ['nullable', 'numeric', 'between:0,100'],
            'post_test_score' => ['nullable', 'numeric', 'between:0,100'],
            'comments' => ['nullable', 'string', 'max:3000'],
            'allow_testimonial' => ['sometimes', 'boolean'],
        ]);

        // Real tests win over typed numbers: the pre / post scores come from the graded attempts when they exist.
        $real = app(KnowledgeService::class)->scoresFor($registration);
        $data['pre_test_score'] = $real['pre'] ?? ($data['pre_test_score'] ?? null);
        $data['post_test_score'] = $real['post'] ?? ($data['post_test_score'] ?? null);

        $satisfaction = round(collect($data['ratings'])->avg() / 5 * 100, 2);

        $evaluation = Evaluation::updateOrCreate(['registration_id' => $registration->id], $data + [
            'program_id' => $registration->program_id,
            'employee_id' => $registration->employee_id,
            'satisfaction_score' => $satisfaction,
            'submitted_at' => now(),
        ]);

        $certificates->refreshStatus($registration);
        $impact->score($registration);
        // A low average with enough answers raises the leadership alert (once per group).
        app(SatisfactionAlertService::class)->check($registration);
        // The survey unlocks an already issued certificate: tell the trainee it can be downloaded.
        if ($certificate = $registration->certificate) {
            $certificates->announce($certificate);
        }

        return response()->json(['data' => $evaluation], 201);
    }

    public function certificates(CertificateService $service): JsonResponse
    {
        $items = Certificate::with(['program', 'registration.evaluation'])->where('employee_id', $this->employee()->id)->latest('issued_at')->get();

        return response()->json(['data' => $items->map(fn (Certificate $c) => (new CertificateResource($c))->resolve() + [
            'kind' => 'trainee',
            // The certificate is downloadable once the program survey has been filled in.
            'downloadable' => $service->downloadable($c),
            'survey_required' => $c->status === 'valid' && ! $service->downloadable($c),
            'registration_id' => $c->registration_id,
            'type' => $c->type,
        ])]);
    }

    /** The trainer's thank-you certificates, with their progress towards the ones not yet earned. */
    public function trainerCertificates(TrainerCertificateService $service): JsonResponse
    {
        $trainer = $this->user()->trainer;
        if (! $trainer) {
            return response()->json(['data' => []]);
        }

        $issued = TrainerCertificate::with('program')->where('trainer_id', $trainer->id)->latest('issued_at')->get();
        $earned = $issued->map(fn (TrainerCertificate $c) => [
            'id' => $c->id, 'kind' => 'trainer', 'certificate_no' => $c->certificate_no, 'verification_code' => $c->verification_code,
            'verification_url' => $c->verificationUrl(), 'issued_at' => $c->issued_at->toIso8601String(), 'hours' => $c->hours,
            'status' => $c->status, 'downloadable' => $c->status === 'valid', 'survey_required' => false,
            'program' => ['id' => $c->program->id, 'code' => $c->program->code, 'title' => $c->program->translate('title')],
            'download_url' => route('api.trainer-certificates.download', $c->id),
        ]);

        // Programs where the trainer still has hours to deliver: shown as locked, with the hours so far.
        $pending = Program::whereIn('id', ProgramSession::where('trainer_id', $trainer->id)->where('status', '!=', 'cancelled')->select('program_id'))
            ->whereNotIn('id', $issued->pluck('program_id'))->get()
            ->map(function (Program $p) use ($trainer, $service) {
                $progress = $service->progress($trainer, $p);

                return [
                    'id' => 'pending-'.$p->id, 'kind' => 'trainer', 'status' => 'pending', 'downloadable' => false, 'survey_required' => false,
                    'program' => ['id' => $p->id, 'code' => $p->code, 'title' => $p->translate('title')],
                    'hours' => $progress['hours'], 'sessions_done' => $progress['delivered'], 'sessions_total' => $progress['planned'],
                ];
            });

        return response()->json(['data' => $earned->concat($pending)->values()]);
    }

    public function downloadTrainerCertificate(TrainerCertificate $certificate, TrainerCertificateService $service): Response
    {
        $user = $this->user();
        $owner = $user->trainer?->id === $certificate->trainer_id;
        abort_unless($owner || $user->hasPermission('certificates.view'), 403);
        abort_unless($certificate->status === 'valid', 403, __('messages.certificate.blocked'));

        return response($service->pdf($certificate), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$certificate->certificate_no.'.pdf"',
        ]);
    }

    /**
     * Streams the certificate PDF to its owner or to staff allowed to view certificates.
     */
    public function downloadCertificate(Certificate $certificate, FileStorage $storage, CertificateService $service): Response
    {
        $user = $this->user();
        $owner = $user->employee?->id === $certificate->employee_id;
        $staff = $user->hasPermission('certificates.view') && $this->scope()->allowsEmployee($certificate->employee);
        abort_unless($owner || $staff, 403);
        // A trainee gets the PDF only after completing the program survey (staff are not held to it).
        if (! $staff && ! $service->downloadable($certificate)) {
            throw new BusinessRuleException(__('messages.certificate.survey_first'), 'survey_required');
        }

        try {
            $pdf = $certificate->file_path ? $storage->get('certificates', $certificate->file_path) : $service->render($certificate);
        } catch (Throwable) {
            $pdf = $service->render($certificate);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$certificate->certificate_no.'.pdf"',
        ]);
    }

    public function surveys(): JsonResponse
    {
        return response()->json(['data' => ImpactSurvey::with('program:id,title_ar,title_en')
            ->where('employee_id', $this->employee()->id)
            ->whereIn('status', ['sent', 'completed'])
            ->orderByDesc('scheduled_for')
            ->get()
            ->map(fn (ImpactSurvey $s) => [
                'id' => $s->id,
                'program' => $s->program->translate('title'),
                'stage_days' => $s->stage_days,
                'status' => $s->status,
                'scheduled_for' => $s->scheduled_for->toDateString(),
                'completed_at' => $s->completed_at?->toIso8601String(),
                'answers' => $s->status === 'completed' ? $s->only(['applied_learning', 'application_score', 'changes_observed', 'skills_improved', 'needs_support', 'support_details']) : null,
            ])]);
    }

    public function submitSurvey(Request $request, ImpactSurvey $survey, ImpactService $impact): JsonResponse
    {
        abort_unless($survey->employee_id === $this->employee()->id, 404);

        $data = $request->validate([
            'applied_learning' => ['required', 'in:yes,partially,no'],
            'application_score' => ['nullable', 'integer', 'between:0,100'],
            'changes_observed' => ['nullable', 'string', 'max:3000'],
            'skills_improved' => ['nullable', 'array', 'max:20'],
            'skills_improved.*' => ['string', 'max:120'],
            'needs_support' => ['required', 'boolean'],
            'support_details' => ['nullable', 'required_if:needs_support,true', 'string', 'max:2000'],
            'evidence' => ['sometimes', 'array', 'max:5'],
            'evidence.*' => ['nullable'],
        ]);

        $evidence = $this->evidence($request, "impact/{$survey->id}");
        $result = $impact->submitSurvey($survey, collect($data)->except('evidence')->all());
        $evidence && $result->update(['evidence' => $evidence]);

        return response()->json(['data' => $result->refresh()]);
    }

    // Supervisor -------------------------------------------------------------

    public function team(): JsonResponse
    {
        $me = $this->employee();

        $team = $me->subordinates()->with(['user', 'jobTitle', 'registrations' => fn ($q) => $q->where('status', Registration::STATUS_COMPLETED)->with(['program:id,title_ar,title_en', 'supervisorEvaluations'])])->get();

        return response()->json(['data' => $team->map(fn ($employee) => [
            'employee' => (new EmployeeResource($employee))->resolve(),
            'completed' => $employee->registrations->map(fn ($r) => [
                'registration_id' => $r->id,
                'program' => $r->program->translate('title'),
                'completed_at' => $r->completed_at?->toDateString(),
                'impact_score' => $r->impact_score,
                'evaluated' => $r->supervisorEvaluations->isNotEmpty(),
            ]),
        ])]);
    }

    public function supervisorEvaluation(Request $request, Registration $registration, ImpactService $impact): JsonResponse
    {
        abort_unless($registration->employee->supervisor_id === $this->employee()->id, 403, __('auth.forbidden'));

        $data = $request->validate([
            'application_score' => ['required', 'integer', 'between:0,100'],
            'behavior_change' => ['nullable', 'string', 'max:3000'],
            'comments' => ['nullable', 'string', 'max:3000'],
            'recommendations' => ['nullable', 'string', 'max:3000'],
            'evidence' => ['sometimes', 'array', 'max:5'],
            'evidence.*' => ['nullable'],
        ]);

        $evidence = $this->evidence($request, "manager/{$registration->id}");
        $result = $impact->submitSupervisorEvaluation($registration, $this->user(), collect($data)->except('evidence')->all());
        $evidence && $result->update(['evidence' => $evidence]);

        return response()->json(['data' => $result->refresh()], 201);
    }

    /** Evidence sent with an impact form: files (`evidence[]` uploads) and http(s) links (`evidence[]` strings). @return list<array<string, mixed>> */
    private function evidence(Request $request, string $directory): array
    {
        $items = array_merge($request->file('evidence', []) ?: [], array_filter((array) $request->input('evidence', []), 'is_string'));

        return $items ? app(EvaluationService::class)->evidenceItems($items, $directory, 5) : [];
    }
}
