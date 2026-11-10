<?php

namespace App\Services\Plc;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\GroupTrainer;
use App\Models\PlcNomination;
use App\Models\PlcWorkshop;
use App\Models\Registration;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use App\Services\TrainerService;
use App\Support\AccessScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The people of a workshop. Trainees are nominated explicitly from the participating schools (never by adding a
 * school), with seats per school, the waiting list, duplicates and clashes checked on the server, and an auditable
 * state machine; approved nominations become registrations on the programme engine once the workshop is approved.
 * Trainers are proposed as lead or co-trainer on the workshop's group, checked for double booking, confirmed, and
 * replaced through a recorded change that notifies everyone concerned.
 */
class WorkshopPeople
{
    public function __construct(
        private readonly WorkshopWorkflow $workflow,
        private readonly RegistrationService $registrations,
        private readonly TrainerService $trainers,
        private readonly NotificationService $notifications,
    ) {}

    // Group ---------------------------------------------------------------------------------------------------

    /** The workshop's training group (its sessions, trainers and registrations live there); created on first use. */
    public function group(PlcWorkshop $w): TrainingGroup
    {
        $program = $w->program;
        $group = $program->groups()->orderBy('sequence')->first();
        if ($group) {
            return $group;
        }

        return TrainingGroup::create([
            'program_id' => $program->id, 'code' => $program->code.'-G1', 'sequence' => 1, 'title_ar' => $program->title_ar, 'title_en' => $program->title_en,
            'delivery_mode' => TrainingGroup::modeFor($program->delivery_mode), 'start_date' => $program->start_date, 'end_date' => $program->end_date,
            'capacity' => $program->capacity, 'min_attendance_percent' => $program->min_attendance_percent, 'supervisor_id' => $program->coordinator_id,
            'status' => TrainingGroup::PLANNED, 'published_at' => now(),
        ]);
    }

    // Trainees --------------------------------------------------------------------------------------------------

    /** Staff of the participating schools the person may nominate from, with what the officer needs to decide. */
    public function eligible(PlcWorkshop $w, User $user, array $filters, int $limit = 100): array
    {
        $schoolIds = $this->participatingSchoolIds($w);
        $scope = AccessScope::current($user);
        if (! $scope->isMinistryWide()) {
            $schoolIds = array_values(array_intersect($schoolIds, $scope->schoolIds() ?? []));
        }
        if (! empty($filters['school_id'])) {
            $schoolIds = in_array($filters['school_id'], $schoolIds, true) ? [$filters['school_id']] : [];
        }
        if (! $schoolIds) {
            return [];
        }
        $rows = Employee::query()->with(['user:id,name,name_ar,email', 'jobTitle:id,name_ar,name_en', 'school:id,name_ar,name_en'])
            ->whereIn('school_id', $schoolIds)->where('status', 'active')->whereNotNull('user_id')
            ->when($filters['job_title_id'] ?? null, fn ($q, $v) => $q->where('job_title_id', $v))
            ->when($filters['q'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('employee_no', 'like', "%$v%")->orWhere('specialization', 'like', "%$v%")->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%$v%")->orWhere('name_ar', 'like', "%$v%"))))
            ->orderBy('school_id')->limit($limit)->get();
        $nominated = PlcNomination::where('program_id', $w->program_id)->whereIn('employee_id', $rows->pluck('id'))->pluck('status', 'employee_id');
        $clashes = $this->clashesFor($w, $rows->pluck('id')->all());

        return $rows->map(fn (Employee $e) => $this->person($e) + ['nomination' => $nominated[$e->id] ?? null, 'clashes' => $clashes[$e->id] ?? []])->values()->all();
    }

    /** The nominations with per-school seat usage. */
    public function nominations(PlcWorkshop $w): array
    {
        $rows = PlcNomination::where('program_id', $w->program_id)->with(['employee.user:id,name,name_ar,email', 'employee.jobTitle:id,name_ar,name_en', 'school:id,name_ar,name_en', 'registration:id,status'])
            ->orderBy('school_id')->orderBy('created_at')->get();
        $seats = $w->schools()->where('status', '!=', 'excluded')->get()->keyBy('school_id');
        $bySchool = $rows->groupBy('school_id')->map(fn (Collection $g, $schoolId) => [
            'school_id' => $schoolId, 'school' => $g->first()->school?->name_ar, 'school_en' => $g->first()->school?->name_en,
            'seats' => $seats[$schoolId]->seats ?? null, 'seated' => $g->whereIn('status', PlcNomination::SEATED)->count(), 'waitlisted' => $g->where('status', 'waitlisted')->count(),
        ])->values()->all();

        return [
            'capacity' => $w->program->capacity, 'seated' => $rows->whereIn('status', PlcNomination::SEATED)->count(), 'waitlisted' => $rows->where('status', 'waitlisted')->count(),
            'by_status' => $rows->countBy('status')->all(), 'schools' => $bySchool,
            'rows' => $rows->map(fn (PlcNomination $n) => $this->nominationRow($n))->values()->all(),
            'transitions' => PlcNomination::TRANSITIONS, 'reason_required' => PlcNomination::REASON_REQUIRED,
        ];
    }

    /**
     * Nominates people. Each one must belong to a participating school inside the nominator's scope; a second nomination
     * of the same person is refused; a clash with another programme is refused unless forced with a reason (recorded);
     * when the school's seats or the workshop's capacity are used up the nomination waits.
     *
     * @param  list<string>  $employeeIds
     * @return array{added: list<array>, skipped: list<array{employee_id: string, code: string, message: string}>}
     */
    public function nominate(PlcWorkshop $w, User $by, array $employeeIds, ?string $note = null, bool $force = false, ?string $forceReason = null): array
    {
        $this->assertCanNominate($w, $by);
        if ($force && (! filled($forceReason) || ! ($by->hasPermission('plc.manage') || $by->hasPermission('plc.exceptions')))) {
            throw new BusinessRuleException(__('messages.plc.force_reason'), 'plc_force_reason');
        }
        $participating = $this->participatingSchoolIds($w);
        $scope = AccessScope::current($by);
        $employees = Employee::with('user:id,name,name_ar')->whereIn('id', array_values(array_unique($employeeIds)))->get();
        $clashes = $this->clashesFor($w, $employees->pluck('id')->all());
        $added = [];
        $skipped = [];

        foreach ($employees as $e) {
            try {
                if (! in_array($e->school_id, $participating, true)) {
                    throw new BusinessRuleException(__('messages.plc.school_not_participating'), 'plc_school_not_participating');
                }
                if (! $scope->isMinistryWide() && ! $scope->allowsSchool($e->school_id) && ! $by->hasPermission('plc.manage')) {
                    throw new BusinessRuleException(__('messages.plc.outside_scope'), 'plc_outside_scope');
                }
                if ($e->status !== 'active' || ! $e->user_id) {
                    throw new BusinessRuleException(__('messages.plc.employee_inactive'), 'plc_employee_inactive');
                }
                $existing = PlcNomination::where('program_id', $w->program_id)->where('employee_id', $e->id)->first();
                if ($existing && ! in_array('nominated', PlcNomination::TRANSITIONS[$existing->status], true)) {
                    throw new BusinessRuleException(__('messages.plc.duplicate_nomination'), 'plc_duplicate_nomination');
                }
                if (($clashes[$e->id] ?? []) && ! $force) {
                    throw new BusinessRuleException(__('messages.plc.trainee_clash', ['program' => $clashes[$e->id][0]['program']]), 'plc_trainee_clash', ['conflicts' => $clashes[$e->id]]);
                }
                $status = $this->seatFor($w, $e->school_id) ? 'nominated' : 'waitlisted';
                $row = DB::transaction(function () use ($w, $by, $e, $existing, $note, $status, $clashes, $force, $forceReason) {
                    $data = ['status' => $status, 'source' => $by->hasPermission('plc.manage') ? 'centre' : 'school', 'nominated_by' => $by->id, 'decided_by' => null, 'decided_at' => null,
                        'reason' => null, 'note' => $note, 'clashes' => $clashes[$e->id] ?? null, 'registration_id' => null];
                    $row = $existing ? tap($existing)->update($data) : PlcNomination::create($data + ['program_id' => $w->program_id, 'employee_id' => $e->id, 'school_id' => $e->school_id]);
                    if ($force && ($clashes[$e->id] ?? [])) {
                        $this->workflow->decision($w, 'exception', 'clash_overridden', $forceReason, ['employee_id' => $e->id, 'conflicts' => $clashes[$e->id]], $by);
                    }

                    return $row;
                });
                if ($e->user_id) {
                    $this->notifications->send($e->user_id, 'plc.nominated', ['ar' => 'رُشّحت لورشة', 'en' => 'You were nominated for a workshop'],
                        ['ar' => '«'.$w->program->title_ar.'»'.($status === 'waitlisted' ? ' — على قائمة الانتظار' : ''), 'en' => '"'.($w->program->title_en ?: $w->program->title_ar).'"'.($status === 'waitlisted' ? ' — on the waiting list' : '')],
                        ['program_id' => $w->program_id, 'link' => '/portal/training']);
                }
                $added[] = $this->nominationRow($row->load(['employee.user', 'employee.jobTitle', 'school']));
            } catch (BusinessRuleException $ex) {
                $skipped[] = ['employee_id' => $e->id, 'name' => $e->user?->displayName(), 'code' => $ex->errorCode, 'message' => $ex->getMessage(), 'details' => $ex->details];
            }
        }
        foreach (array_diff($employeeIds, $employees->pluck('id')->all()) as $missing) {
            $skipped[] = ['employee_id' => $missing, 'name' => null, 'code' => 'plc_employee_unknown', 'message' => __('messages.plc.employee_unknown'), 'details' => []];
        }
        if ($added) {
            $this->workflow->decision($w, 'nomination', 'nominated', $note, ['count' => count($added), 'skipped' => count($skipped)], $by);
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /** Moves a nomination along its state machine; approving materialises a registration when the workshop is already approved. */
    public function transition(PlcWorkshop $w, User $by, PlcNomination $n, string $to, ?string $reason = null, bool $force = false): PlcNomination
    {
        if ($n->program_id !== $w->program_id) {
            abort(404);
        }
        $this->assertCanDecide($w, $by, $n);
        if (! in_array($to, PlcNomination::TRANSITIONS[$n->status] ?? [], true)) {
            throw new BusinessRuleException(__('messages.plc.nomination_bad_transition', ['from' => $n->status, 'to' => $to]), 'plc_nomination_bad_transition', ['from' => $n->status, 'to' => $to]);
        }
        if (in_array($to, PlcNomination::REASON_REQUIRED, true) && ! filled($reason)) {
            throw new BusinessRuleException(__('messages.plc.reason_required'), 'plc_reason_required');
        }
        if ($to === 'approved' && ! $this->seatFor($w, $n->school_id, $n)) {
            if (! $force || ! $by->hasPermission('plc.manage')) {
                throw new BusinessRuleException(__('messages.plc.seats_full'), 'plc_seats_full');
            }
            $this->workflow->decision($w, 'exception', 'seats_exceeded', $reason, ['employee_id' => $n->employee_id, 'school_id' => $n->school_id], $by);
        }
        $from = $n->status;
        DB::transaction(function () use ($w, $by, $n, $to, $reason, $from) {
            $n->update(['status' => $to, 'decided_by' => $by->id, 'decided_at' => now(), 'reason' => $reason]);
            $this->workflow->decision($w, 'nomination', $to, $reason, ['employee_id' => $n->employee_id, 'from' => $from], $by);
            if (in_array($to, ['withdrawn', 'declined', 'rejected'], true) && $n->registration_id && $n->registration) {
                $target = $to === 'rejected' ? Registration::STATUS_REJECTED : Registration::STATUS_WITHDRAWN;
                rescue(fn () => $this->registrations->transition($n->registration, $target, $by, $reason), report: false);
            }
        });
        if ($to === 'approved' && in_array($w->workflow_state, ['approved', 'scheduled', 'ready', 'in_progress'], true)) {
            $this->materialise($w, $by, [$n->id]);
        }
        if (in_array($from, PlcNomination::SEATED, true) && in_array($to, ['withdrawn', 'declined', 'rejected'], true)) {
            $this->promote($w, $by, $n->school_id);
        }
        $employee = $n->employee;
        if ($employee?->user_id && in_array($to, ['approved', 'rejected', 'waitlisted'], true)) {
            $labels = ['approved' => ['ar' => 'اعتُمد ترشيحك', 'en' => 'Your nomination was approved'], 'rejected' => ['ar' => 'لم يُعتمد ترشيحك', 'en' => 'Your nomination was not approved'], 'waitlisted' => ['ar' => 'ترشيحك على قائمة الانتظار', 'en' => 'Your nomination is on the waiting list']];
            $this->notifications->send($employee->user_id, 'plc.nomination_decided', $labels[$to], ['ar' => '«'.$w->program->title_ar.'»'.($reason ? ' — '.$reason : ''), 'en' => '"'.($w->program->title_en ?: $w->program->title_ar).'"'.($reason ? ' — '.$reason : '')], ['program_id' => $w->program_id, 'link' => '/portal/training']);
        }

        return $n->refresh();
    }

    /** The first waiting person of the school (or any school) takes a freed seat and returns to "nominated". */
    public function promote(PlcWorkshop $w, User $by, ?string $schoolId = null): ?PlcNomination
    {
        $next = PlcNomination::where('program_id', $w->program_id)->where('status', 'waitlisted')->when($schoolId, fn ($q) => $q->where('school_id', $schoolId))->orderBy('created_at')->first()
            ?? PlcNomination::where('program_id', $w->program_id)->where('status', 'waitlisted')->orderBy('created_at')->first();
        if (! $next || ! $this->seatFor($w, $next->school_id, $next)) {
            return null;
        }
        $next->update(['status' => 'nominated', 'decided_by' => $by->id, 'decided_at' => now()]);
        $this->workflow->decision($w, 'nomination', 'promoted', null, ['employee_id' => $next->employee_id], $by);

        return $next;
    }

    /**
     * Turns approved nominations into registrations on the programme engine (seats, waiting list, attendance,
     * certificates). Called when the workshop is approved and whenever a nomination is approved afterwards.
     * Failures are recorded per person and never abort the others.
     *
     * @return array{registered: int, failed: list<array{employee_id: string, code: string, message: string}>}
     */
    public function materialise(PlcWorkshop $w, User $by, ?array $onlyIds = null): array
    {
        $program = $w->program;
        if (! in_array($program->approval_status, ['approved'], true)) {
            return ['registered' => 0, 'failed' => []];
        }
        $group = $this->group($w);
        $source = $w->isInternal() ? Registration::SOURCE_SCHOOL : Registration::SOURCE_CENTER;
        $rows = PlcNomination::where('program_id', $w->program_id)->where('status', 'approved')->whereNull('registration_id')->when($onlyIds, fn ($q) => $q->whereIn('id', $onlyIds))->with('employee')->get();
        $registered = 0;
        $failed = [];
        foreach ($rows as $n) {
            try {
                $reg = $this->registrations->register($program, $n->employee, $source, $by, null, false, $group);
                if (in_array($reg->status, [Registration::STATUS_PENDING_MANAGER], true)) {
                    $reg = $this->registrations->transition($reg, Registration::STATUS_PENDING, $by, 'PLC');
                }
                if ($reg->status === Registration::STATUS_PENDING) {
                    $reg = $this->registrations->transition($reg, Registration::STATUS_APPROVED, $by, 'PLC');
                }
                $n->update(['registration_id' => $reg->id]);
                $registered++;
            } catch (BusinessRuleException $ex) {
                $failed[] = ['employee_id' => $n->employee_id, 'code' => $ex->errorCode, 'message' => $ex->getMessage()];
            }
        }
        if ($rows->isNotEmpty()) {
            $this->workflow->decision($w, 'nomination', 'materialised', null, ['registered' => $registered, 'failed' => $failed], $by);
        }

        return ['registered' => $registered, 'failed' => $failed];
    }

    // Trainers --------------------------------------------------------------------------------------------------

    public function trainerRows(PlcWorkshop $w): array
    {
        $group = $this->group($w);

        return GroupTrainer::where('group_id', $group->id)->with(['trainer:id,name_ar,name_en,title_ar,title_en,specializations,is_external,organization,rating,status,user_id'])->orderBy('created_at')->get()
            ->map(fn (GroupTrainer $a) => $this->assignmentRow($a))->values()->all();
    }

    /** Trainers ranked by specialisation match with the workshop's domain and availability over its dates. */
    public function suggestTrainers(PlcWorkshop $w, ?string $q = null, int $limit = 20): array
    {
        [$start, $end] = $this->window($w);
        $need = ['starts_at' => $start, 'ends_at' => $end];
        if ($w->domain) {
            $need['specializations'] = [$w->domain];
        }
        $rows = $this->trainers->suggest($need, 200);
        if (! $w->domain || $rows->isEmpty()) {
            $rows = $this->trainers->suggest(['starts_at' => $start, 'ends_at' => $end], 200);
        }
        $assigned = GroupTrainer::where('group_id', $this->group($w)->id)->whereIn('status', [GroupTrainer::PROPOSED, GroupTrainer::APPROVED])->pluck('role', 'trainer_id');

        return $rows->filter(fn ($r) => ! $q || mb_stripos($r['trainer']->name_ar.' '.$r['trainer']->name_en, $q) !== false)
            ->take($limit)->map(fn ($r) => $this->trainerRow($r['trainer']) + ['score' => $r['score'], 'matched' => $r['matched_specializations'], 'available' => $r['available'], 'assigned_role' => $assigned[$r['trainer']->id] ?? null])->values()->all();
    }

    /** Proposes a trainer as lead or co-trainer; double booking over the workshop's dates is refused unless forced with a reason. */
    public function propose(PlcWorkshop $w, User $by, string $trainerId, string $role, ?float $hours, bool $force = false, ?string $forceReason = null): array
    {
        $this->assertCanAssign($w, $by);
        $trainer = Trainer::where('status', 'active')->find($trainerId) ?: throw new BusinessRuleException(__('messages.plc.trainer_unknown'), 'plc_trainer_unknown');
        $group = $this->group($w);
        $role = $role === 'assistant' || $role === 'co' ? 'assistant' : 'lead';
        if (GroupTrainer::where('group_id', $group->id)->where('trainer_id', $trainer->id)->whereIn('status', [GroupTrainer::PROPOSED, GroupTrainer::APPROVED])->exists()) {
            throw new BusinessRuleException(__('messages.assignment.duplicate'), 'assignment_duplicate');
        }
        [$start, $end] = $this->window($w);
        $busy = $start && $end ? $this->trainers->conflicts($trainer->id, $start, $end) : collect();
        if ($busy->isNotEmpty() && ! $force) {
            throw new BusinessRuleException(__('messages.trainer.busy', ['trainer' => $trainer->translate('name')]), 'plc_trainer_busy', ['sessions' => $busy->map(fn ($s) => ['id' => $s->id, 'title' => $s->translate('title'), 'program' => $s->program?->translate('title'), 'starts_at' => $s->starts_at, 'ends_at' => $s->ends_at])->values()->all()]);
        }
        if ($force && (! filled($forceReason) || ! $by->hasPermission('plc.manage'))) {
            throw new BusinessRuleException(__('messages.plc.force_reason'), 'plc_force_reason');
        }
        $a = GroupTrainer::create(['group_id' => $group->id, 'trainer_id' => $trainer->id, 'role' => $role, 'hours' => $hours ?? (float) $w->program->total_hours, 'status' => GroupTrainer::PROPOSED, 'proposed_by' => $by->id]);
        if ($busy->isNotEmpty()) {
            $this->workflow->decision($w, 'exception', 'trainer_busy_overridden', $forceReason, ['trainer_id' => $trainer->id], $by);
        }
        $this->workflow->decision($w, 'trainer', 'proposed', null, ['trainer_id' => $trainer->id, 'trainer' => $trainer->name_ar, 'role' => $role], $by);
        if ($trainer->user_id) {
            $this->notifications->send($trainer->user_id, 'trainer.assignment_proposed', ['ar' => 'رُشّحت لتدريب ورشة', 'en' => 'You were proposed for a workshop'],
                ['ar' => '«'.$w->program->title_ar.'»', 'en' => '"'.($w->program->title_en ?: $w->program->title_ar).'"'], ['assignment_id' => $a->id, 'group_id' => $group->id, 'route' => '/assignments']);
        }

        return $this->assignmentRow($a->load('trainer'));
    }

    /** Confirms a proposed trainer (the assignment is not real until it is confirmed). */
    public function confirm(PlcWorkshop $w, User $by, GroupTrainer $a, ?string $note = null): array
    {
        $this->assertCanAssign($w, $by);
        $this->assertOfWorkshop($w, $a);
        if ($a->status !== GroupTrainer::PROPOSED) {
            throw new BusinessRuleException(__('messages.assignment.decided'), 'assignment_decided');
        }
        $a->update(['status' => GroupTrainer::APPROVED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note]);
        $this->workflow->decision($w, 'trainer', 'confirmed', $note, ['trainer_id' => $a->trainer_id, 'role' => $a->role], $by);
        $this->notifyTrainer($w, $a, ['ar' => 'تأكّد تكليفك', 'en' => 'Your assignment is confirmed']);

        return $this->assignmentRow($a->load('trainer'));
    }

    /** Replaces a trainer (even after approval) through a recorded change: the old row is marked replaced, both trainers and the coordinator are told. */
    public function replace(PlcWorkshop $w, User $by, GroupTrainer $old, string $newTrainerId, string $reason, bool $force = false): array
    {
        $this->assertCanAssign($w, $by);
        $this->assertOfWorkshop($w, $old);
        if (! filled($reason)) {
            throw new BusinessRuleException(__('messages.plc.reason_required'), 'plc_reason_required');
        }
        if (! in_array($old->status, [GroupTrainer::PROPOSED, GroupTrainer::APPROVED], true)) {
            throw new BusinessRuleException(__('messages.assignment.decided'), 'assignment_decided');
        }
        $new = DB::transaction(function () use ($w, $by, $old, $newTrainerId, $reason, $force) {
            $old->update(['status' => 'replaced', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $reason]);
            $row = $this->propose($w, $by, $newTrainerId, $old->role, $old->hours, $force, $force ? $reason : null);
            $this->workflow->decision($w, 'trainer', 'replaced', $reason, ['from_trainer_id' => $old->trainer_id, 'to_trainer_id' => $newTrainerId, 'role' => $old->role], $by);

            return $row;
        });
        $this->notifyTrainer($w, $old, ['ar' => 'أُلغي تكليفك في الورشة', 'en' => 'Your workshop assignment was withdrawn'], $reason);
        if ($w->coordinator_user_id && $w->coordinator_user_id !== $by->id) {
            $this->notifications->send($w->coordinator_user_id, 'plc.trainer_changed', ['ar' => 'تغيّر مدرب الورشة', 'en' => 'Workshop trainer changed'], ['ar' => '«'.$w->program->title_ar.'» — '.$reason, 'en' => '"'.($w->program->title_en ?: $w->program->title_ar).'" — '.$reason], ['program_id' => $w->program_id, 'link' => '/admin/communities/workshops/'.$w->program_id]);
        }

        return $new;
    }

    /** Withdraws a proposal (no reason) or an approved assignment (reason required, recorded, trainer told). */
    public function withdraw(PlcWorkshop $w, User $by, GroupTrainer $a, ?string $reason = null): void
    {
        $this->assertCanAssign($w, $by);
        $this->assertOfWorkshop($w, $a);
        if ($a->status === GroupTrainer::APPROVED && ! filled($reason)) {
            throw new BusinessRuleException(__('messages.plc.reason_required'), 'plc_reason_required');
        }
        $was = $a->status;
        $a->update(['status' => GroupTrainer::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $reason]);
        $this->workflow->decision($w, 'trainer', 'withdrawn', $reason, ['trainer_id' => $a->trainer_id, 'was' => $was], $by);
        if ($was === GroupTrainer::APPROVED) {
            $this->notifyTrainer($w, $a, ['ar' => 'أُلغي تكليفك في الورشة', 'en' => 'Your workshop assignment was withdrawn'], $reason);
        }
    }

    // Helpers ---------------------------------------------------------------------------------------------------

    private function participatingSchoolIds(PlcWorkshop $w): array
    {
        return $w->schools()->where('status', '!=', 'excluded')->pluck('school_id')->all();
    }

    /** Whether a seat is free for the school (its seats when set) and for the workshop (its capacity). */
    private function seatFor(PlcWorkshop $w, string $schoolId, ?PlcNomination $except = null): bool
    {
        $seated = PlcNomination::where('program_id', $w->program_id)->whereIn('status', PlcNomination::SEATED)->when($except, fn ($q) => $q->where('id', '!=', $except->id));
        if ((clone $seated)->count() >= (int) $w->program->capacity) {
            return false;
        }
        $seats = $w->schools()->where('school_id', $schoolId)->value('seats');

        return $seats === null || (clone $seated)->where('school_id', $schoolId)->count() < (int) $seats;
    }

    /** Other programmes each employee holds a seat in that overlap the workshop's dates. @return array<string, list<array>> */
    private function clashesFor(PlcWorkshop $w, array $employeeIds): array
    {
        $p = $w->program;
        if (! $employeeIds || ! $p->start_date) {
            return [];
        }
        $end = $p->end_date ?? $p->start_date;
        $rows = Registration::query()->join('programs', 'programs.id', '=', 'registrations.program_id')
            ->whereIn('registrations.employee_id', $employeeIds)->where('registrations.program_id', '!=', $p->id)
            ->whereIn('registrations.status', Registration::SEAT_HOLDING)
            ->whereNotNull('programs.start_date')->whereDate('programs.start_date', '<=', $end)->whereDate(DB::raw('coalesce(programs.end_date, programs.start_date)'), '>=', $p->start_date)
            ->get(['registrations.employee_id', 'programs.id as program_id', 'programs.title_ar', 'programs.title_en', 'programs.start_date', 'programs.end_date', 'registrations.status']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->employee_id][] = ['program_id' => $r->program_id, 'program' => $r->title_ar, 'program_en' => $r->title_en, 'start_date' => $r->start_date, 'end_date' => $r->end_date, 'status' => $r->status];
        }

        return $out;
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} the workshop's dates as a day-level window */
    private function window(PlcWorkshop $w): array
    {
        $p = $w->program;
        if (! $p->start_date) {
            return [null, null];
        }

        return [Carbon::parse($p->start_date)->startOfDay(), Carbon::parse($p->end_date ?? $p->start_date)->endOfDay()];
    }

    private function assertCanNominate(PlcWorkshop $w, User $by): void
    {
        if (! $by->hasPermission('plc.nominate') && ! $by->hasPermission('plc.manage')) {
            abort(403);
        }
        if (in_array($w->workflow_state, ['rejected', 'cancelled', 'closed', 'archived', 'completed', 'awaiting_evaluation'], true)) {
            throw new BusinessRuleException(__('messages.plc.locked', ['state' => $w->workflow_state]), 'plc_locked');
        }
    }

    private function assertCanDecide(PlcWorkshop $w, User $by, PlcNomination $n): void
    {
        $this->assertCanNominate($w, $by);
        $scope = AccessScope::current($by);
        if (! $scope->isMinistryWide() && ! $scope->allowsSchool($n->school_id) && ! $by->hasPermission('plc.manage')) {
            abort(403, __('messages.plc.outside_scope'));
        }
    }

    private function assertCanAssign(PlcWorkshop $w, User $by): void
    {
        if (! $by->hasPermission('plc.trainers.assign') && ! $by->hasPermission('plc.manage')) {
            abort(403);
        }
        if (in_array($w->workflow_state, ['rejected', 'cancelled', 'closed', 'archived'], true)) {
            throw new BusinessRuleException(__('messages.plc.locked', ['state' => $w->workflow_state]), 'plc_locked');
        }
    }

    private function assertOfWorkshop(PlcWorkshop $w, GroupTrainer $a): void
    {
        if ($a->group_id !== $this->group($w)->id) {
            abort(404);
        }
    }

    private function notifyTrainer(PlcWorkshop $w, GroupTrainer $a, array $title, ?string $reason = null): void
    {
        $userId = $a->trainer?->user_id ?? Trainer::whereKey($a->trainer_id)->value('user_id');
        if ($userId) {
            $this->notifications->send($userId, 'trainer.assignment_decided', $title, ['ar' => '«'.$w->program->title_ar.'»'.($reason ? ' — '.$reason : ''), 'en' => '"'.($w->program->title_en ?: $w->program->title_ar).'"'.($reason ? ' — '.$reason : '')], ['assignment_id' => $a->id, 'program_id' => $w->program_id, 'route' => '/assignments']);
        }
    }

    private function person(Employee $e): array
    {
        return ['employee_id' => $e->id, 'employee_no' => $e->employee_no, 'name' => $e->user?->displayName('ar') ?? $e->user?->name, 'name_en' => $e->user?->name, 'email' => $e->user?->email,
            'job_title' => $e->jobTitle?->name_ar, 'job_title_en' => $e->jobTitle?->name_en, 'specialization' => $e->specialization, 'school_id' => $e->school_id, 'school' => $e->school?->name_ar, 'school_en' => $e->school?->name_en];
    }

    private function nominationRow(PlcNomination $n): array
    {
        return ['id' => $n->id, 'status' => $n->status, 'source' => $n->source, 'note' => $n->note, 'reason' => $n->reason, 'clashes' => $n->clashes ?? [], 'decided_at' => $n->decided_at, 'created_at' => $n->created_at,
            'registration' => $n->registration ? ['id' => $n->registration->id, 'status' => $n->registration->status] : null,
            'transitions' => PlcNomination::TRANSITIONS[$n->status] ?? []] + ($n->employee ? $this->person($n->employee) : ['employee_id' => $n->employee_id, 'school_id' => $n->school_id]);
    }

    private function trainerRow(Trainer $t): array
    {
        return ['trainer_id' => $t->id, 'name' => $t->name_ar, 'name_en' => $t->name_en, 'title' => $t->title_ar, 'specializations' => $t->specializations ?? [], 'external' => (bool) $t->is_external, 'organization' => $t->organization, 'rating' => $t->rating];
    }

    private function assignmentRow(GroupTrainer $a): array
    {
        return ['id' => $a->id, 'role' => $a->role === 'assistant' ? 'co' : 'lead', 'status' => $a->status, 'hours' => (float) $a->hours, 'note' => $a->decision_note, 'decided_at' => $a->decided_at, 'created_at' => $a->created_at]
            + ($a->trainer ? $this->trainerRow($a->trainer) : ['trainer_id' => $a->trainer_id]);
    }
}
