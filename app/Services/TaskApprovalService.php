<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\TaskSubmission;
use App\Models\User;

/**
 * Task decisions under the three approval modes: the trainer alone, the trainer then the supervisor, or automatic for
 * self-assessed tasks. Every decision refreshes the pass status.
 */
class TaskApprovalService
{
    public function __construct(
        private readonly PassingPolicyService $passing,
        private readonly CertificateService $certificates,
        private readonly NotificationService $notifications,
    ) {}

    public function mode(TaskSubmission $s): string
    {
        $s->loadMissing('registration.program');

        return $this->passing->policy($s->registration)['task_approval'];
    }

    /** A self-assessed task in an automatic program is approved the moment it is submitted. */
    public function onSubmitted(TaskSubmission $s): TaskSubmission
    {
        $s->loadMissing('task', 'registration');
        if ($s->task->self_assessed && $this->mode($s) === 'auto') {
            $s->update(['status' => TaskSubmission::STATUS_APPROVED, 'trainer_decision' => 'auto', 'trainer_decided_at' => now(), 'reviewed_at' => now()]);
            $this->tell($s, 'task.approved', ['ar' => 'تم اعتماد مهمتك', 'en' => 'Your task was approved'], '');
        }
        $this->certificates->refreshStatus($s->registration);

        return $s->refresh();
    }

    /** @param  'approved'|'rejected'|'returned'  $decision */
    public function trainerDecide(TaskSubmission $s, string $decision, ?string $feedback, User $by): TaskSubmission
    {
        $two = $this->mode($s) === 'trainer_then_supervisor';
        $status = match ($decision) {
            'approved' => $two ? TaskSubmission::STATUS_PENDING_FINAL : TaskSubmission::STATUS_APPROVED,
            'rejected' => TaskSubmission::STATUS_REJECTED,
            default => TaskSubmission::STATUS_CHANGES,
        };
        $s->update([
            'status' => $status, 'feedback' => $feedback, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'trainer_decision' => $decision, 'trainer_id' => $by->id, 'trainer_decided_at' => now(),
            'supervisor_decision' => null, 'supervisor_id' => null, 'supervisor_decided_at' => null,
            'returned_count' => $s->returned_count + ($decision === 'returned' ? 1 : 0),
        ]);
        $this->afterDecision($s, $status, $feedback);

        return $s->refresh();
    }

    /** @param  'approved'|'rejected'|'returned'  $decision */
    public function finalDecide(TaskSubmission $s, string $decision, ?string $feedback, User $by): TaskSubmission
    {
        if ($s->status !== TaskSubmission::STATUS_PENDING_FINAL) {
            throw new BusinessRuleException(__('messages.passing.task_not_final'), 'task_not_final');
        }
        $status = match ($decision) {
            'approved' => TaskSubmission::STATUS_APPROVED,
            'rejected' => TaskSubmission::STATUS_REJECTED,
            default => TaskSubmission::STATUS_CHANGES,
        };
        $s->update([
            'status' => $status, 'feedback' => $feedback ?? $s->feedback, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'supervisor_decision' => $decision, 'supervisor_id' => $by->id, 'supervisor_decided_at' => now(),
            'returned_count' => $s->returned_count + ($decision === 'returned' ? 1 : 0),
        ]);
        $this->afterDecision($s, $status, $feedback, true);

        return $s->refresh();
    }

    private function afterDecision(TaskSubmission $s, string $status, ?string $feedback, bool $final = false): void
    {
        $this->certificates->refreshStatus($s->registration()->first());
        $suffix = filled($feedback) ? ' — '.$feedback : '';
        match ($status) {
            TaskSubmission::STATUS_APPROVED => $this->tell($s, $final ? 'task.final_approved' : 'task.approved', $final ? ['ar' => 'اعتُمدت مهمتك نهائياً', 'en' => 'Your task was finally approved'] : ['ar' => 'تم اعتماد مهمتك', 'en' => 'Your task was approved'], $suffix),
            TaskSubmission::STATUS_REJECTED => $this->tell($s, 'task.rejected', ['ar' => 'تم رفض مهمتك', 'en' => 'Your task was rejected'], $suffix),
            TaskSubmission::STATUS_CHANGES => $this->tell($s, 'task.returned', ['ar' => 'أُعيدت مهمتك للتعديل', 'en' => 'Your task was returned for changes'], $suffix),
            default => null, // waiting for the supervisor: the trainee is told once it is decided
        };
    }

    private function tell(TaskSubmission $s, string $event, array $title, string $suffix): void
    {
        $s->loadMissing('task', 'employee');
        $this->notifications->send($s->employee->user_id, $event, $title, ['ar' => $s->task->title_ar.$suffix, 'en' => $s->task->title_en.$suffix], ['task_id' => $s->task_id, 'submission_id' => $s->id]);
    }
}
