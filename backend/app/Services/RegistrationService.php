<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\Nomination;
use App\Models\Program;
use App\Models\Registration;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Models\WaitingList;
use App\Services\Eligibility\EligibilityEngine;
use Illuminate\Support\Facades\DB;

/**
 * Registration workflow for all four channels:
 * self-registration, school nomination, training-center nomination and bulk import.
 */
class RegistrationService
{
    /** Allowed status transitions. */
    private const TRANSITIONS = [
        Registration::STATUS_PENDING_MANAGER => [Registration::STATUS_PENDING, Registration::STATUS_REJECTED, Registration::STATUS_CANCELLED, Registration::STATUS_WITHDRAWN],
        Registration::STATUS_PENDING => [Registration::STATUS_APPROVED, Registration::STATUS_REJECTED, Registration::STATUS_CANCELLED, Registration::STATUS_WAITLISTED, Registration::STATUS_WITHDRAWN],
        Registration::STATUS_WAITLISTED => [Registration::STATUS_APPROVED, Registration::STATUS_PENDING, Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED, Registration::STATUS_WITHDRAWN],
        Registration::STATUS_APPROVED => [Registration::STATUS_CANCELLED, Registration::STATUS_COMPLETED, Registration::STATUS_WITHDRAWN],
        Registration::STATUS_WITHDRAWN => [],
        Registration::STATUS_REJECTED => [],
        Registration::STATUS_CANCELLED => [],
        Registration::STATUS_COMPLETED => [],
    ];

    public function __construct(
        private readonly EligibilityEngine $eligibility,
        private readonly NotificationService $notifications,
        private readonly SeatAllocationService $seats,
        private readonly RegistrationPriorityService $priority,
        private readonly ConflictService $conflicts,
    ) {}

    /**
     * @param  bool  $override  training-center staff may bypass the window / eligibility (recorded in the snapshot)
     */
    public function register(Program $program, Employee $employee, string $source, ?User $actor = null, ?Nomination $nomination = null, bool $override = false, ?TrainingGroup $group = null): Registration
    {
        if ($group && $group->program_id !== $program->id) {
            throw new BusinessRuleException(__('messages.groups.not_of_program'), 'group_mismatch');
        }
        // A program that runs several groups registers into one of them (the chosen one, else the first open); a single-group program behaves as before.
        $multi = ! $program->hasSingleGroup();
        $group ??= $multi ? $program->primaryGroup() : null;

        if (in_array($program->approval_status, ['pending', 'rejected'], true)) {
            throw new BusinessRuleException(__('messages.workshops.not_approved'), 'workshop_not_approved');
        }

        if (! $program->allowsMode($source)) {
            throw new BusinessRuleException(__('messages.registration.mode_not_allowed'), 'mode_not_allowed');
        }

        if (! $override && ! ($multi ? $group?->isRegistrationOpen() : $program->isRegistrationOpen())) {
            throw new BusinessRuleException(__('messages.registration.closed'), 'registration_closed');
        }

        $result = $this->eligibility->evaluate($program, $employee);
        if (! $result->eligible && ! $override) {
            throw new BusinessRuleException(__('messages.registration.not_eligible'), 'not_eligible', $result->jsonSerialize());
        }

        $effective = $multi ? $group : ($group ?? $program->primaryGroup());
        $snapshotExtras = [];

        // The same or an equivalent program already completed: block, warn or allow, as the program is set up.
        $done = $this->conflicts->completedEquivalent($employee, $program);
        if ($done && ! $override) {
            $policy = $program->repeat_policy ?? 'block';
            if ($policy === 'block') {
                throw new BusinessRuleException(__('messages.registration.already_completed', ['program' => $done->program->translate('title')]), 'already_completed');
            }
            if ($policy === 'warn') {
                $snapshotExtras['repeat_warning'] = $done->program->translate('title');
            }
        }

        // A clash with an approved program is always blocked; with the overlap setting off any seat-holding registration counts.
        if (! $override) {
            $statuses = ($effective?->allow_overlap_until_approved ?? true) ? [Registration::STATUS_APPROVED] : Registration::SEAT_HOLDING;
            $clash = $this->conflicts->conflicts($employee, $program, $effective, $statuses);
            if ($clash) {
                throw new BusinessRuleException(__('messages.registration.time_conflict', ['program' => $clash[0]['program']]), 'time_conflict', ['conflicts' => $clash]);
            }
        }

        return DB::transaction(function () use ($program, $employee, $source, $actor, $nomination, $override, $result, $multi, $group, $effective, $snapshotExtras) {
            // Serialize seat allocation per program.
            Program::whereKey($program->id)->lockForUpdate()->first();

            $existing = Registration::where('program_id', $program->id)->where('employee_id', $employee->id)->first();
            if ($existing && ! in_array($existing->status, [Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED], true)) {
                throw new BusinessRuleException(__('messages.registration.duplicate'), 'duplicate');
            }

            $hasSeat = ($multi ? $group->seatsAvailable() : $program->seatsAvailable()) > 0;
            $pool = $hasSeat && $effective ? $this->seats->claim($effective, $employee) : ($hasSeat ? ['open', null] : null);
            $hasSeat = $pool !== null;
            $autoApprove = in_array($source, [Registration::SOURCE_CENTER, Registration::SOURCE_BULK], true) || ($effective?->approval_mode === 'auto');
            $manager = ! $autoApprove && $source === Registration::SOURCE_SELF && ($effective?->approval_mode ?? 'manager_then_center') === 'manager_then_center' ? $this->resolveManager($employee) : null;

            $status = match (true) {
                ! $hasSeat => Registration::STATUS_WAITLISTED,
                $autoApprove => Registration::STATUS_APPROVED,
                $manager !== null => Registration::STATUS_PENDING_MANAGER,
                default => Registration::STATUS_PENDING,
            };
            $rank = $this->priority->score($employee, $program, $effective);

            $attributes = [
                'training_group_id' => $multi ? $group->id : ($group?->id ?? $program->primaryGroup()?->id),
                'source' => $source,
                'status' => $status,
                'nomination_id' => $nomination?->id,
                'eligibility_snapshot' => $result->jsonSerialize() + ['override' => $override && ! $result->eligible, 'override_by' => $override ? $actor?->id : null] + $snapshotExtras,
                'seat_entity_type' => $pool[0] ?? null,
                'seat_entity_id' => $pool[1] ?? null,
                'priority_score' => $rank['score'],
                'priority_explanation' => $rank['explanation'],
                'manager_id' => $manager?->id,
                'approved_by' => $status === Registration::STATUS_APPROVED ? $actor?->id : null,
                'approved_at' => $status === Registration::STATUS_APPROVED ? now() : null,
                'attendance_percent' => 0,
                'tasks_completed' => false,
                'evaluation_completed' => false,
                'certificate_status' => 'pending',
            ];

            $registration = $existing
                ? tap($existing)->update($attributes)
                : Registration::create($attributes + ['program_id' => $program->id, 'employee_id' => $employee->id]);

            if ($status === Registration::STATUS_WAITLISTED) {
                $this->addToWaitingList($registration);
            }

            $this->notifyStatus($registration);

            return $registration;
        });
    }

    public function nominate(Program $program, Employee $employee, User $actor, string $nominatorType, ?string $justification = null, bool $override = false, ?TrainingGroup $group = null): Registration
    {
        $source = $nominatorType === 'school_admin' ? Registration::SOURCE_SCHOOL : Registration::SOURCE_CENTER;

        return DB::transaction(function () use ($program, $employee, $actor, $nominatorType, $justification, $override, $source, $group) {
            $nomination = Nomination::create([
                'program_id' => $program->id,
                'employee_id' => $employee->id,
                'school_id' => $employee->school_id,
                'nominated_by' => $actor->id,
                'nominator_type' => $nominatorType,
                'justification' => $justification,
                'status' => 'pending',
            ]);

            $registration = $this->register($program, $employee, $source, $actor, $nomination, $override, $group);
            $nomination->update(['status' => 'converted', 'decided_at' => now()]);

            return $registration;
        });
    }

    public function transition(Registration $registration, string $to, ?User $actor = null, ?string $notes = null, ?string $overrideReason = null): Registration
    {
        $from = $registration->status;

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new BusinessRuleException(__('messages.registration.invalid_transition', ['from' => $from, 'to' => $to]), 'invalid_transition');
        }
        if ($to === Registration::STATUS_APPROVED) {
            $this->assertCanApprove($registration, $overrideReason);
        }

        DB::transaction(function () use ($registration, $to, $actor, $notes, $from) {
            $registration->update(array_filter([
                'status' => $to,
                'notes' => $notes ?? $registration->notes,
                'approved_by' => $to === Registration::STATUS_APPROVED ? $actor?->id : $registration->approved_by,
                'approved_at' => $to === Registration::STATUS_APPROVED ? now() : $registration->approved_at,
                'center_decided_by' => in_array($to, [Registration::STATUS_APPROVED, Registration::STATUS_REJECTED], true) && $actor ? $actor->id : $registration->center_decided_by,
                'center_decided_at' => in_array($to, [Registration::STATUS_APPROVED, Registration::STATUS_REJECTED], true) && $actor ? now() : $registration->center_decided_at,
                'completed_at' => $to === Registration::STATUS_COMPLETED ? now() : $registration->completed_at,
            ], fn ($v) => $v !== null));

            if ($from === Registration::STATUS_WAITLISTED) {
                WaitingList::where('registration_id', $registration->id)->update(['status' => in_array($to, [Registration::STATUS_CANCELLED, Registration::STATUS_WITHDRAWN], true) ? 'expired' : 'promoted', 'promoted_at' => now()]);
            }

            if (in_array($from, Registration::SEAT_HOLDING, true) && in_array($to, [Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED, Registration::STATUS_WITHDRAWN], true)) {
                $this->promoteFromWaitingList($registration->program, $registration->trainingGroup);
            }
        });

        $this->notifyStatus($registration->refresh());

        return $registration;
    }

    /**
     * Moves the best-ranked waiting employee into a freed seat: highest priority first, then who registered first.
     * With seat allocations the person must also fit a pool they may use.
     */
    public function promoteFromWaitingList(Program $program, ?TrainingGroup $group = null): ?Registration
    {
        // With several groups each one has its own seats and its own waiting list.
        $perGroup = $group !== null && ! $program->hasSingleGroup();
        if (($perGroup ? $group->seatsAvailable() : $program->seatsAvailable()) <= 0) {
            return null;
        }
        $effective = $group ?? $program->primaryGroup();

        $entries = WaitingList::where('waiting_lists.program_id', $program->id)->where('waiting_lists.status', 'waiting')->when($perGroup, fn ($q) => $q->where('waiting_lists.training_group_id', $group->id))
            ->join('registrations', 'registrations.id', '=', 'waiting_lists.registration_id')->orderByRaw('registrations.priority_score IS NULL')->orderByDesc('registrations.priority_score')->orderBy('waiting_lists.position')
            ->select('waiting_lists.*')->get();
        foreach ($entries as $entry) {
            $registration = $entry->registration;
            $pool = $effective ? $this->seats->claim($effective, $registration->employee) : ['open', null];
            if ($pool === null) {
                continue;
            }
            $entry->update(['status' => 'promoted', 'promoted_at' => now()]);
            $registration->update(['status' => Registration::STATUS_PENDING, 'seat_entity_type' => $pool[0], 'seat_entity_id' => $pool[1]]);
            $this->notifyStatus($registration);

            return $registration;
        }

        return null;
    }

    /** Direct manager, else the academic deputy of the school; null when nobody can decide (the request goes straight to the centre). */
    public function resolveManager(Employee $employee): ?User
    {
        $supervisor = $employee->supervisor?->user;
        if ($supervisor && $supervisor->id !== $employee->user_id) {
            return $supervisor;
        }
        if (! $employee->school_id) {
            return null;
        }

        $deputyId = RoleUser::whereHas('role', fn ($r) => $r->where('slug', Role::ACADEMIC_DEPUTY))->where('scope_type', 'school')->where('scope_id', $employee->school_id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->where('user_id', '!=', $employee->user_id)->pluck('user_id')->first();

        return $deputyId ? User::where('status', 'active')->find($deputyId) : null;
    }

    /** The manager's decision on the first stage: approve (→ the centre) or reject with a note. */
    public function managerDecision(Registration $registration, User $by, string $decision, ?string $note): Registration
    {
        if ($registration->status !== Registration::STATUS_PENDING_MANAGER) {
            throw new BusinessRuleException(__('messages.registration.invalid_transition', ['from' => $registration->status, 'to' => $decision]), 'invalid_transition');
        }
        if ($decision === 'rejected' && ! filled($note)) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }
        DB::transaction(function () use ($registration, $by, $decision, $note) {
            $registration->update(['status' => $decision === 'approved' ? Registration::STATUS_PENDING : Registration::STATUS_REJECTED, 'manager_id' => $by->id, 'manager_decided_at' => now(), 'manager_note' => $note]);
            if ($decision === 'rejected') {
                $this->promoteFromWaitingList($registration->program, $registration->trainingGroup);
            }
        });
        $registration->refresh();
        $this->notifyStatus($registration);

        return $registration;
    }

    /** Centre approval waits for the window to close and refuses a second overlapping approval (staff may override with a reason). */
    private function assertCanApprove(Registration $registration, ?string $overrideReason): void
    {
        $registration->loadMissing(['program', 'trainingGroup', 'employee']);
        $group = $registration->trainingGroup;
        $override = filled($overrideReason);
        $closes = $group?->registration_closes_at ?? $registration->program->registration_closes_at;
        if (! $override && ($group?->approve_after_window ?? true) && $closes && $closes->isFuture() && $registration->source === Registration::SOURCE_SELF) {
            throw new BusinessRuleException(__('messages.registration.window_open', ['date' => $closes->toDateTimeString()]), 'window_open', ['closes_at' => $closes->toIso8601String()]);
        }
        if (! $override) {
            // The room, the building and the place limit how many people can be approved, however many seats the group has.
            $room = $group?->room ?? ($registration->program->sessions()->whereNotNull('training_room_id')->first()?->room);
            if ($room) {
                $cap = min(array_filter([$group?->capacity, app(RoomService::class)->effectiveCapacity($room)]));
                $taken = Registration::where('program_id', $registration->program_id)->when($group, fn ($q) => $q->where('training_group_id', $group->id))->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->count();
                if ($taken >= $cap) {
                    throw new BusinessRuleException(__('messages.room.over_capacity', ['room' => $room->translate('name'), 'capacity' => $cap, 'needed' => $taken + 1]), 'room_capacity', ['capacity' => $cap]);
                }
            }
            $clash = $this->conflicts->conflicts($registration->employee, $registration->program, $group, [Registration::STATUS_APPROVED], $registration->id);
            if ($clash) {
                throw new BusinessRuleException(__('messages.registration.time_conflict', ['program' => $clash[0]['program']]), 'time_conflict', ['conflicts' => $clash]);
            }
        }
    }

    private function addToWaitingList(Registration $registration): void
    {
        $position = (int) WaitingList::where('program_id', $registration->program_id)->when($registration->training_group_id, fn ($q, $g) => $q->where('training_group_id', $g))->max('position') + 1;

        WaitingList::updateOrCreate(
            ['program_id' => $registration->program_id, 'employee_id' => $registration->employee_id],
            ['registration_id' => $registration->id, 'training_group_id' => $registration->training_group_id, 'position' => $position, 'status' => 'waiting', 'promoted_at' => null],
        );
    }

    private function notifyStatus(Registration $registration): void
    {
        $registration->loadMissing(['program', 'employee']);
        $program = $registration->program;

        $labels = [
            Registration::STATUS_PENDING_MANAGER => ['ar' => 'بانتظار موافقة المدير المباشر', 'en' => 'awaiting your direct manager\'s approval'],
            Registration::STATUS_PENDING => ['ar' => 'قيد المراجعة', 'en' => 'under review'],
            Registration::STATUS_WITHDRAWN => ['ar' => 'منسحب', 'en' => 'withdrawn'],
            Registration::STATUS_APPROVED => ['ar' => 'معتمد', 'en' => 'approved'],
            Registration::STATUS_REJECTED => ['ar' => 'مرفوض', 'en' => 'rejected'],
            Registration::STATUS_WAITLISTED => ['ar' => 'في قائمة الانتظار', 'en' => 'on the waiting list'],
            Registration::STATUS_CANCELLED => ['ar' => 'ملغى', 'en' => 'cancelled'],
            Registration::STATUS_COMPLETED => ['ar' => 'مكتمل', 'en' => 'completed'],
        ][$registration->status];

        // A program assigned to a trainee (by the center, a school or a bulk import) has its own switchable action.
        $assigned = $registration->status === Registration::STATUS_APPROVED && $registration->source !== Registration::SOURCE_SELF;
        $this->notifications->send(
            $registration->employee->user_id,
            $assigned ? 'program.assigned' : 'registration.'.$registration->status,
            $assigned ? ['ar' => 'تم إسنادك إلى برنامج تدريبي', 'en' => 'You have been assigned to a program'] : ['ar' => 'تحديث حالة التسجيل', 'en' => 'Registration update'],
            [
                'ar' => "تسجيلك في برنامج «{$program->title_ar}» {$labels['ar']}.",
                'en' => "Your registration for \"{$program->title_en}\" is {$labels['en']}.",
            ],
            ['registration_id' => $registration->id, 'program_id' => $program->id],
        );

        // The first stage: the direct manager is asked to decide.
        if ($registration->status === Registration::STATUS_PENDING_MANAGER && $registration->manager_id) {
            $name = $registration->employee->user?->displayName('ar') ?? '—';
            $this->notifications->send($registration->manager_id, 'registration.pending_manager', ['ar' => 'طلب تسجيل بانتظار موافقتك', 'en' => 'A registration awaits your approval'],
                ['ar' => "طلب {$name} التسجيل في «{$program->title_ar}». وافق أو ارفض ليصل الطلب إلى مركز التدريب.", 'en' => "{$name} asked to join \"{$program->title_en}\". Approve or reject so it can reach the training centre."],
                ['registration_id' => $registration->id, 'program_id' => $program->id, 'route' => '/admin/approvals']);
        }

        // A request that needs a decision is announced to the center's team (the people who approve registrations).
        if ($registration->status === Registration::STATUS_PENDING) {
            $name = $registration->employee->user?->displayName('ar') ?? '—';
            $staff = User::whereHas('roles', fn ($q) => $q->whereIn('slug', Role::CENTER_STAFF))->where('status', 'active')->pluck('id');
            $this->notifications->broadcast($staff, 'registration.new_pending', ['ar' => 'تسجيل جديد بانتظار اعتمادك', 'en' => 'A new registration awaits your approval'],
                ['ar' => "سجّل {$name} في برنامج «{$program->title_ar}». راجع الطلب واعتمده أو ارفضه.", 'en' => "{$name} registered for \"{$program->title_en}\". Review and approve or reject."],
                ['registration_id' => $registration->id, 'program_id' => $program->id, 'name' => $name, 'program' => $program->title_ar, 'route' => '/admin/registrations']);
        }
    }
}
