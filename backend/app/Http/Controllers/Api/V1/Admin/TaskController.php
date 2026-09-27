<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\Task;
use App\Models\TaskSubmission;
use App\Services\CertificateService;
use App\Services\FileStorage;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    public function index(Program $program): JsonResponse
    {
        return response()->json(['data' => $program->tasks()->withCount([
            'submissions',
            'submissions as pending_count' => fn ($q) => $q->where('status', TaskSubmission::STATUS_SUBMITTED),
            'submissions as approved_count' => fn ($q) => $q->where('status', TaskSubmission::STATUS_APPROVED),
        ])->orderBy('due_at')->get()]);
    }

    public function store(Request $request, Program $program): JsonResponse
    {
        $task = $program->tasks()->create($this->validated($request) + ['created_by' => $this->user()->id]);

        return response()->json(['data' => $task], 201);
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        $task->update($this->validated($request, true));

        return response()->json(['data' => $task]);
    }

    public function destroy(Task $task): JsonResponse
    {
        $task->delete();

        return response()->json(null, 204);
    }

    public function submissions(Request $request, Task $task): JsonResponse
    {
        $submissions = $task->submissions()->with('employee.user:id,name,name_ar')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('updated_at')->get()
            ->map(fn (TaskSubmission $s) => $s->toArray() + [
                'employee_name' => $s->employee->user->displayName(),
                'employee_no' => $s->employee->employee_no,
                'file_url' => $s->file_path ? route('api.submissions.file', $s->id) : null,
            ]);

        return response()->json(['data' => $submissions]);
    }

    /**
     * Trainer decision: approve, reject or request changes.
     */
    public function review(Request $request, TaskSubmission $submission, CertificateService $certificates, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([TaskSubmission::STATUS_APPROVED, TaskSubmission::STATUS_REJECTED, TaskSubmission::STATUS_CHANGES])],
            'feedback' => ['nullable', 'required_unless:status,approved', 'string', 'max:2000'],
        ]);

        $submission->update($data + ['reviewed_by' => $this->user()->id, 'reviewed_at' => now()]);
        $certificates->refreshStatus($submission->registration);

        $labels = [
            TaskSubmission::STATUS_APPROVED => ['ar' => 'تم اعتماد مهمتك', 'en' => 'Your task was approved'],
            TaskSubmission::STATUS_REJECTED => ['ar' => 'تم رفض مهمتك', 'en' => 'Your task was rejected'],
            TaskSubmission::STATUS_CHANGES => ['ar' => 'مطلوب تعديلات على مهمتك', 'en' => 'Changes requested on your task'],
        ];
        $suffix = filled($data['feedback'] ?? null) ? ' — '.$data['feedback'] : '';
        $notifications->send(
            $submission->employee->user_id,
            'task.'.$data['status'],
            $labels[$data['status']],
            ['ar' => $submission->task->title_ar.$suffix, 'en' => $submission->task->title_en.$suffix],
            ['task_id' => $submission->task_id, 'submission_id' => $submission->id],
        );

        return response()->json(['data' => $submission->refresh()]);
    }

    public function file(TaskSubmission $submission, FileStorage $storage): JsonResponse
    {
        abort_unless($submission->file_path, 404);

        return response()->json(['data' => ['url' => $storage->temporaryUrl('submissions', $submission->file_path), 'name' => $submission->file_name]]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title_ar' => [$required, 'string', 'max:255'],
            'title_en' => [$required, 'string', 'max:255'],
            'instructions_ar' => ['nullable', 'string'],
            'instructions_en' => ['nullable', 'string'],
            'program_session_id' => ['nullable', 'uuid', 'exists:program_sessions,id'],
            'due_at' => ['nullable', 'date'],
            'submission_types' => [$required, 'array', 'min:1'],
            'submission_types.*' => [Rule::in(Task::TYPES)],
            'max_file_mb' => ['sometimes', 'integer', 'between:1,100'],
            'is_required' => ['sometimes', 'boolean'],
        ]);
    }
}
