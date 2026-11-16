<?php

namespace App\Services\Plc;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\PlcDecision;
use App\Models\PlcWorkshop;
use App\Models\Program;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * The workshop state machine. Every transition is checked here, recorded in the decision log and the audit
 * log, mirrored onto the programme's own status so the existing screens stay right, and notified.
 */
class WorkshopWorkflow
{
    public const STATES = ['draft', 'submitted', 'needs_changes', 'approved', 'scheduled', 'ready', 'in_progress', 'completed', 'awaiting_evaluation', 'closed', 'archived', 'rejected', 'cancelled'];

    public const TRANSITIONS = [
        'draft' => ['submitted', 'cancelled'],
        'submitted' => ['needs_changes', 'approved', 'rejected', 'cancelled'],
        'needs_changes' => ['submitted', 'cancelled'],
        'approved' => ['scheduled', 'cancelled'],
        'scheduled' => ['ready', 'needs_changes', 'cancelled'],
        'ready' => ['in_progress', 'cancelled'],
        'in_progress' => ['completed', 'cancelled'],
        'completed' => ['awaiting_evaluation', 'closed'],
        'awaiting_evaluation' => ['closed'],
        'closed' => ['archived'],
        'archived' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    /** Transitions that must carry a reason. */
    public const REASON_REQUIRED = ['needs_changes', 'rejected', 'cancelled'];

    /** States in which the draft (title, source, schools …) may still be edited. */
    public const EDITABLE = ['draft', 'needs_changes', 'submitted'];

    public function __construct(private readonly PlcSettings $settings, private readonly NotificationService $notifications) {}

    public function canTransition(PlcWorkshop $w, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$w->workflow_state] ?? [], true);
    }

    public function submit(PlcWorkshop $w, User $by): PlcWorkshop
    {
        $this->assertCan($w, 'submitted');
        $missing = [];
        if (! $w->source) {
            $missing[] = 'source';
        }
        if (! $w->host_school_id) {
            $missing[] = 'host_school_id';
        }
        if (! $w->program->start_date) {
            $missing[] = 'start_date';
        }
        if ($missing) {
            throw new BusinessRuleException(__('messages.plc.incomplete'), 'plc_incomplete', ['missing' => $missing]);
        }
        // A fresh path on every submission: every step must be taken again after changes.
        $path = array_map(fn ($s) => $s + ['status' => 'pending', 'by' => null, 'at' => null, 'note' => null], $this->settings->approvalPath($w->scenario));
        $w->approval_path = $path;

        return $this->move($w, 'submitted', $by);
    }

    /** The approver takes the next pending step they are allowed to take; the last step approves the workshop. */
    public function approve(PlcWorkshop $w, User $by, ?string $note = null): PlcWorkshop
    {
        if ($w->workflow_state !== 'submitted') {
            throw new BusinessRuleException(__('messages.plc.bad_transition', ['state' => $w->workflow_state]), 'plc_bad_transition');
        }
        $path = $w->approval_path ?? [];
        $index = null;
        foreach ($path as $i => $step) {
            if (($step['status'] ?? 'pending') === 'pending') {
                $index = $i;
                break;
            }
        }
        if ($index === null) {
            return $this->move($w, 'approved', $by, $note);
        }
        if (! $by->hasPermission($path[$index]['permission'])) {
            throw new BusinessRuleException(__('messages.plc.not_your_step', ['step' => $path[$index]['key']]), 'plc_not_your_step', ['step' => $path[$index]['key'], 'permission' => $path[$index]['permission']]);
        }
        $path[$index] = ['status' => 'approved', 'by' => $by->id, 'at' => now()->toIso8601String(), 'note' => $note] + $path[$index];
        $w->approval_path = $path;
        $remaining = array_filter($path, fn ($s) => $s['status'] === 'pending');
        $this->decision($w, 'approval_step', $path[$index]['key'], $note, ['remaining' => count($remaining)], $by);
        if ($remaining) {
            $w->save();

            return $w;
        }

        return $this->move($w, 'approved', $by, $note);
    }

    public function requestChanges(PlcWorkshop $w, User $by, string $reason): PlcWorkshop
    {
        $this->assertStep($w, $by);

        return $this->move($w, 'needs_changes', $by, $reason);
    }

    public function reject(PlcWorkshop $w, User $by, string $reason): PlcWorkshop
    {
        $this->assertStep($w, $by);

        return $this->move($w, 'rejected', $by, $reason);
    }

    public function cancel(PlcWorkshop $w, User $by, string $reason): PlcWorkshop
    {
        return $this->move($w, 'cancelled', $by, $reason);
    }

    /** Generic transition for the operational states (scheduled, ready, in_progress …) used by later phases. */
    public function move(PlcWorkshop $w, string $to, User $by, ?string $reason = null, array $data = []): PlcWorkshop
    {
        $this->assertCan($w, $to);
        if (in_array($to, self::REASON_REQUIRED, true) && ! filled($reason)) {
            throw new BusinessRuleException(__('messages.plc.reason_required'), 'plc_reason_required');
        }
        $from = $w->workflow_state;

        return DB::transaction(function () use ($w, $to, $by, $reason, $data, $from) {
            $w->forceFill(['workflow_state' => $to, 'state_changed_at' => now(), 'decision_note' => $reason])->save();
            $this->syncProgram($w, $to, $reason);
            $this->decision($w, 'transition', $to, $reason, ['from' => $from] + $data, $by);
            $this->notify($w, $to, $by, $reason);
            if ($to === 'approved') {
                // Approved nominations become registrations on the programme engine (seats, attendance, certificates).
                app(WorkshopPeople::class)->materialise($w->refresh(), $by);
            }
            if ($to === 'cancelled') {
                app(WorkshopEvaluation::class)->onCancelled($w);
            }

            return $w->refresh();
        });
    }

    /** Keeps the programme's own status and approval status in step so every existing screen reads right. */
    private function syncProgram(PlcWorkshop $w, string $to, ?string $reason): void
    {
        $program = $w->program;
        $patch = match ($to) {
            'draft', 'needs_changes' => ['approval_status' => 'pending', 'status' => Program::STATUS_DRAFT],
            'submitted' => ['approval_status' => 'pending', 'status' => Program::STATUS_DRAFT],
            'approved', 'scheduled', 'ready' => ['approval_status' => 'approved', 'status' => Program::STATUS_REGISTRATION_OPEN],
            'in_progress' => ['approval_status' => 'approved', 'status' => Program::STATUS_IN_PROGRESS],
            'completed', 'awaiting_evaluation', 'closed' => ['approval_status' => 'approved', 'status' => Program::STATUS_COMPLETED],
            'archived' => ['status' => Program::STATUS_ARCHIVED],
            'rejected' => ['approval_status' => 'rejected', 'status' => Program::STATUS_DRAFT],
            'cancelled' => ['status' => Program::STATUS_CANCELLED],
            default => [],
        };
        if ($patch) {
            $program->update($patch);
        }
    }

    public function decision(PlcWorkshop $w, string $kind, string $decision, ?string $reason, array $data, User $by): PlcDecision
    {
        $row = PlcDecision::create([
            'subject_type' => PlcWorkshop::class, 'subject_id' => $w->program_id, 'kind' => $kind, 'decision' => $decision,
            'reason' => $reason, 'data' => $data ?: null, 'decided_by' => $by->id, 'created_at' => now(),
        ]);
        AuditLog::create([
            'user_id' => $by->id, 'action' => 'plc.'.$kind.'.'.$decision, 'auditable_type' => Program::class, 'auditable_id' => $w->program_id,
            'old_values' => null, 'new_values' => ['title' => $w->program?->title_ar, 'reason' => $reason] + $data,
            'ip_address' => Request::ip(), 'user_agent' => substr((string) Request::userAgent(), 0, 255), 'url' => substr((string) Request::fullUrl(), 0, 255),
        ]);

        return $row;
    }

    private function notify(PlcWorkshop $w, string $to, User $by, ?string $reason): void
    {
        $program = $w->program;
        $title = ['ar' => $program->title_ar, 'en' => $program->title_en ?: $program->title_ar];
        $link = ['program_id' => $w->program_id, 'link' => '/admin/communities/workshops/'.$w->program_id];
        $owners = array_values(array_unique(array_filter([$program->created_by, $w->coordinator_user_id], fn ($id) => $id && $id !== $by->id)));

        if ($to === 'submitted') {
            $step = collect($w->approval_path ?? [])->firstWhere('status', 'pending');
            if ($step) {
                $approvers = User::whereHas('roles.permissions', fn ($q) => $q->where('slug', $step['permission']))->pluck('id')->reject(fn ($id) => $id === $by->id)->values();
                if ($approvers->isNotEmpty()) {
                    $this->notifications->broadcast($approvers, 'plc.workshop_submitted', ['ar' => 'ورشة بانتظار مراجعتك', 'en' => 'A workshop awaits your review'], ['ar' => '«'.$title['ar'].'»', 'en' => '"'.$title['en'].'"'], $link);
                }
            }
        } elseif (in_array($to, ['needs_changes', 'approved', 'rejected', 'cancelled'], true) && $owners) {
            $labels = [
                'needs_changes' => ['ar' => 'طُلب تعديل الورشة', 'en' => 'Changes requested on your workshop'],
                'approved' => ['ar' => 'اعتُمدت الورشة', 'en' => 'Your workshop was approved'],
                'rejected' => ['ar' => 'لم تُعتمد الورشة', 'en' => 'Your workshop was not approved'],
                'cancelled' => ['ar' => 'أُلغيت الورشة', 'en' => 'The workshop was cancelled'],
            ];
            $this->notifications->broadcast($owners, 'plc.workshop_'.($to === 'needs_changes' ? 'changes' : ($to === 'cancelled' ? 'cancelled' : 'decided')), $labels[$to],
                ['ar' => '«'.$title['ar'].'»'.($reason ? ' — '.$reason : ''), 'en' => '"'.$title['en'].'"'.($reason ? ' — '.$reason : '')], $link);
        }
    }

    private function assertCan(PlcWorkshop $w, string $to): void
    {
        if (! $this->canTransition($w, $to)) {
            throw new BusinessRuleException(__('messages.plc.bad_transition', ['state' => $w->workflow_state]), 'plc_bad_transition', ['state' => $w->workflow_state, 'to' => $to]);
        }
    }

    /** Only someone who may take the current pending step may send a submitted workshop back or reject it. */
    private function assertStep(PlcWorkshop $w, User $by): void
    {
        if ($w->workflow_state !== 'submitted') {
            throw new BusinessRuleException(__('messages.plc.bad_transition', ['state' => $w->workflow_state]), 'plc_bad_transition');
        }
        $step = collect($w->approval_path ?? [])->firstWhere('status', 'pending');
        if ($step && ! $by->hasPermission($step['permission'])) {
            throw new BusinessRuleException(__('messages.plc.not_your_step', ['step' => $step['key']]), 'plc_not_your_step');
        }
    }
}
