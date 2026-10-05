<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Program;
use App\Models\Registration;
use App\Models\User;
use App\Support\AccessScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** School internal workshops: submitted by the school, approved by the centre, then run by the school for its own staff. */
class InternalWorkshopService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly ProgramGrantService $grants,
        private readonly RegistrationService $registrations,
    ) {}

    public function query(User $user): Builder
    {
        $query = Program::where('owner_type', 'school');

        return AccessScope::current($user)->constrainSchoolColumn($query, 'owner_school_id');
    }

    public function submit(User $user, array $data): Program
    {
        $scope = AccessScope::current($user);
        $schoolId = $data['school_id'] ?? $scope->singleSchoolId() ?? $user->employee?->school_id;
        if (! $schoolId || ! $scope->allowsSchool($schoolId)) {
            throw new BusinessRuleException(__('messages.workshops.school_required'), 'school_required');
        }

        $program = Program::create([
            'code' => 'WS-'.now()->format('y').'-'.Str::upper(Str::random(5)),
            'title_ar' => $data['title_ar'], 'title_en' => $data['title_en'], 'summary_ar' => $data['summary_ar'] ?? null, 'summary_en' => $data['summary_en'] ?? null,
            'objectives' => $data['objectives'] ?? null, 'delivery_mode' => $data['delivery_mode'] ?? 'in_person', 'total_hours' => $data['total_hours'], 'capacity' => $data['capacity'],
            'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'min_attendance_percent' => $data['min_attendance_percent'] ?? 80,
            'status' => Program::STATUS_DRAFT, 'owner_type' => 'school', 'owner_school_id' => $schoolId, 'approval_status' => 'pending',
            'registration_modes' => ['school_nomination'], 'audience' => ['school_ids' => [$schoolId]], 'created_by' => $user->id,
            'requires_tasks' => false, 'requires_evaluation' => false,
        ]);

        $approvers = User::whereHas('roles.permissions', fn ($q) => $q->where('slug', 'workshops.approve'))->pluck('id')->all();
        if ($approvers) {
            $this->notifications->broadcast($approvers, 'internal_workshop.submitted',
                ['ar' => 'ورشة داخلية بانتظار الاعتماد', 'en' => 'An internal workshop awaits approval'],
                ['ar' => "«{$program->title_ar}» — {$program->ownerSchool?->name_ar}", 'en' => "\"{$program->title_en}\" — {$program->ownerSchool?->name_en}"], ['program_id' => $program->id, 'route' => '/admin/internal-workshops']);
        }

        return $program;
    }

    public function decide(Program $program, string $decision, ?string $note, User $by): Program
    {
        if ($program->owner_type !== 'school' || $program->approval_status !== 'pending') {
            throw new BusinessRuleException(__('messages.workshops.not_pending'), 'workshop_not_pending');
        }
        $approve = $decision === 'approved';
        if (! $approve && ! filled($note)) {
            throw new BusinessRuleException(__('messages.assignment.reason_required'), 'reason_required');
        }

        $program->update(['approval_status' => $approve ? 'approved' : 'rejected', 'status' => $approve ? Program::STATUS_REGISTRATION_OPEN : Program::STATUS_DRAFT]);
        AuditLog::create(['user_id' => $by->id, 'action' => 'internal_workshop_'.$decision, 'auditable_type' => Program::class, 'auditable_id' => $program->id, 'new_values' => ['note' => $note]]);

        if ($approve && $program->created_by) {
            $owner = User::find($program->created_by);
            foreach (['attendance.mark', 'notifications.send'] as $ability) {
                $owner && $this->grants->grant($program, $owner, $ability, $by, null);
            }
        }
        if ($program->created_by) {
            [$ar, $en] = $approve ? ['اعتُمدت', 'approved'] : ['لم تُعتمد', 'not approved'];
            $this->notifications->send($program->created_by, 'internal_workshop.decided',
                ['ar' => 'قرار بشأن ورشتك الداخلية', 'en' => 'Decision on your internal workshop'],
                ['ar' => "ورشة «{$program->title_ar}» {$ar}".($note ? " — {$note}" : '').'.', 'en' => "Workshop \"{$program->title_en}\" {$en}".($note ? " — {$note}" : '').'.'],
                ['program_id' => $program->id, 'route' => '/admin/internal-workshops']);
        }

        return $program;
    }

    /** @param  list<string>  $employeeIds
     * @return array{registered: int, skipped: int, errors: list<string>} */
    public function registerStaff(Program $program, array $employeeIds, User $actor, array $employeeNos = []): array
    {
        if (in_array($program->approval_status, ['pending', 'rejected'], true)) {
            throw new BusinessRuleException(__('messages.workshops.not_approved'), 'workshop_not_approved');
        }
        $registered = 0;
        $errors = [];
        $employees = Employee::where('school_id', $program->owner_school_id)->where(fn ($q) => $q->whereIn('id', $employeeIds)->orWhereIn('employee_no', $employeeNos))->get();
        foreach ($employees as $employee) {
            try {
                $this->registrations->register($program, $employee, Registration::SOURCE_SCHOOL, $actor);
                $registered++;
            } catch (BusinessRuleException $e) {
                $errors[] = $e->getMessage();
            }
        }

        return ['registered' => $registered, 'skipped' => max(0, count($employeeIds) + count($employeeNos) - $registered), 'errors' => $errors];
    }
}
