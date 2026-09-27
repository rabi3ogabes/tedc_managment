<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\Nomination;
use App\Models\Program;
use App\Models\Registration;
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
        Registration::STATUS_PENDING => [Registration::STATUS_APPROVED, Registration::STATUS_REJECTED, Registration::STATUS_CANCELLED, Registration::STATUS_WAITLISTED],
        Registration::STATUS_WAITLISTED => [Registration::STATUS_APPROVED, Registration::STATUS_PENDING, Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED],
        Registration::STATUS_APPROVED => [Registration::STATUS_CANCELLED, Registration::STATUS_COMPLETED],
        Registration::STATUS_REJECTED => [],
        Registration::STATUS_CANCELLED => [],
        Registration::STATUS_COMPLETED => [],
    ];

    public function __construct(
        private readonly EligibilityEngine $eligibility,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  bool  $override  training-center staff may bypass the window / eligibility (recorded in the snapshot)
     */
    public function register(Program $program, Employee $employee, string $source, ?User $actor = null, ?Nomination $nomination = null, bool $override = false): Registration
    {
        if (! $program->allowsMode($source)) {
            throw new BusinessRuleException(__('messages.registration.mode_not_allowed'), 'mode_not_allowed');
        }

        if (! $override && ! $program->isRegistrationOpen()) {
            throw new BusinessRuleException(__('messages.registration.closed'), 'registration_closed');
        }

        $result = $this->eligibility->evaluate($program, $employee);
        if (! $result->eligible && ! $override) {
            throw new BusinessRuleException(__('messages.registration.not_eligible'), 'not_eligible', $result->jsonSerialize());
        }

        return DB::transaction(function () use ($program, $employee, $source, $actor, $nomination, $override, $result) {
            // Serialize seat allocation per program.
            Program::whereKey($program->id)->lockForUpdate()->first();

            $existing = Registration::where('program_id', $program->id)->where('employee_id', $employee->id)->first();
            if ($existing && ! in_array($existing->status, [Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED], true)) {
                throw new BusinessRuleException(__('messages.registration.duplicate'), 'duplicate');
            }

            $hasSeat = $program->seatsAvailable() > 0;
            $autoApprove = in_array($source, [Registration::SOURCE_CENTER, Registration::SOURCE_BULK], true);

            $status = match (true) {
                ! $hasSeat => Registration::STATUS_WAITLISTED,
                $autoApprove => Registration::STATUS_APPROVED,
                default => Registration::STATUS_PENDING,
            };

            $attributes = [
                'source' => $source,
                'status' => $status,
                'nomination_id' => $nomination?->id,
                'eligibility_snapshot' => $result->jsonSerialize() + ['override' => $override && ! $result->eligible, 'override_by' => $override ? $actor?->id : null],
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

    public function nominate(Program $program, Employee $employee, User $actor, string $nominatorType, ?string $justification = null, bool $override = false): Registration
    {
        $source = $nominatorType === 'school_admin' ? Registration::SOURCE_SCHOOL : Registration::SOURCE_CENTER;

        return DB::transaction(function () use ($program, $employee, $actor, $nominatorType, $justification, $override, $source) {
            $nomination = Nomination::create([
                'program_id' => $program->id,
                'employee_id' => $employee->id,
                'school_id' => $employee->school_id,
                'nominated_by' => $actor->id,
                'nominator_type' => $nominatorType,
                'justification' => $justification,
                'status' => 'pending',
            ]);

            $registration = $this->register($program, $employee, $source, $actor, $nomination, $override);
            $nomination->update(['status' => 'converted', 'decided_at' => now()]);

            return $registration;
        });
    }

    public function transition(Registration $registration, string $to, ?User $actor = null, ?string $notes = null): Registration
    {
        $from = $registration->status;

        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new BusinessRuleException(__('messages.registration.invalid_transition', ['from' => $from, 'to' => $to]), 'invalid_transition');
        }

        DB::transaction(function () use ($registration, $to, $actor, $notes, $from) {
            $registration->update(array_filter([
                'status' => $to,
                'notes' => $notes ?? $registration->notes,
                'approved_by' => $to === Registration::STATUS_APPROVED ? $actor?->id : $registration->approved_by,
                'approved_at' => $to === Registration::STATUS_APPROVED ? now() : $registration->approved_at,
                'completed_at' => $to === Registration::STATUS_COMPLETED ? now() : $registration->completed_at,
            ], fn ($v) => $v !== null));

            if ($from === Registration::STATUS_WAITLISTED) {
                WaitingList::where('registration_id', $registration->id)->update(['status' => $to === Registration::STATUS_CANCELLED ? 'expired' : 'promoted', 'promoted_at' => now()]);
            }

            if (in_array($from, Registration::SEAT_HOLDING, true) && in_array($to, [Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED], true)) {
                $this->promoteFromWaitingList($registration->program);
            }
        });

        $this->notifyStatus($registration->refresh());

        return $registration;
    }

    /**
     * Moves the first waiting employee into a freed seat.
     */
    public function promoteFromWaitingList(Program $program): ?Registration
    {
        if ($program->seatsAvailable() <= 0) {
            return null;
        }

        $entry = WaitingList::where('program_id', $program->id)->where('status', 'waiting')->orderBy('position')->first();
        if (! $entry) {
            return null;
        }

        $entry->update(['status' => 'promoted', 'promoted_at' => now()]);
        $registration = $entry->registration;
        $registration->update(['status' => Registration::STATUS_PENDING]);
        $this->notifyStatus($registration);

        return $registration;
    }

    private function addToWaitingList(Registration $registration): void
    {
        $position = (int) WaitingList::where('program_id', $registration->program_id)->max('position') + 1;

        WaitingList::updateOrCreate(
            ['program_id' => $registration->program_id, 'employee_id' => $registration->employee_id],
            ['registration_id' => $registration->id, 'position' => $position, 'status' => 'waiting', 'promoted_at' => null],
        );
    }

    private function notifyStatus(Registration $registration): void
    {
        $registration->loadMissing(['program', 'employee']);
        $program = $registration->program;

        $labels = [
            Registration::STATUS_PENDING => ['ar' => 'قيد المراجعة', 'en' => 'under review'],
            Registration::STATUS_APPROVED => ['ar' => 'معتمد', 'en' => 'approved'],
            Registration::STATUS_REJECTED => ['ar' => 'مرفوض', 'en' => 'rejected'],
            Registration::STATUS_WAITLISTED => ['ar' => 'في قائمة الانتظار', 'en' => 'on the waiting list'],
            Registration::STATUS_CANCELLED => ['ar' => 'ملغى', 'en' => 'cancelled'],
            Registration::STATUS_COMPLETED => ['ar' => 'مكتمل', 'en' => 'completed'],
        ][$registration->status];

        $this->notifications->send(
            $registration->employee->user_id,
            'registration.'.$registration->status,
            ['ar' => 'تحديث حالة التسجيل', 'en' => 'Registration update'],
            [
                'ar' => "تسجيلك في برنامج «{$program->title_ar}» {$labels['ar']}.",
                'en' => "Your registration for \"{$program->title_en}\" is {$labels['en']}.",
            ],
            ['registration_id' => $registration->id, 'program_id' => $program->id],
        );
    }
}
