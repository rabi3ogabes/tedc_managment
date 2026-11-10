<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Registration;
use App\Models\Role;
use App\Models\School;
use App\Models\TrainingGroup;
use App\Services\AnnualHoursService;
use App\Services\Eligibility\EligibilityEngine;
use App\Services\Programs\ProgramAnnouncer;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Adding trainees to a group from the program's Groups tab: by employee number, or by choosing a school and ticking from its staff.
 * Only people in the program's target audience can be added — the school list shows only them, the number check says why anyone else
 * cannot, and the add itself refuses them again (there is no override on this screen). Every person comes with the hours they trained
 * last year.
 */
class GroupTraineeController extends Controller
{
    public function __construct(private readonly RegistrationService $registrations, private readonly AnnualHoursService $hours, private readonly EligibilityEngine $eligibility) {}

    /** The school's staff who are in the target audience and not yet in the program, with how many were left out and why. */
    public function candidates(Request $request, TrainingGroup $group): JsonResponse
    {
        $data = $request->validate(['school_id' => ['required', 'uuid', 'exists:schools,id'], 'q' => ['nullable', 'string', 'max:100']]);
        $program = $group->program;
        abort_unless($this->scope()->allowsSchool($data['school_id']), 403, __('auth.forbidden'));
        $q = trim((string) ($data['q'] ?? ''));

        $all = Employee::with(['user:id,name,name_ar', 'school:id,name_ar,name_en', 'jobTitle'])->where('status', 'active')->where('school_id', $data['school_id'])
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->whereLike('employee_no', "%{$q}%")->orWhereHas('user', fn ($u) => $u->whereLike('name', "%{$q}%")->orWhereLike('name_ar', "%{$q}%"))))
            ->orderBy('employee_no')->limit(800)->get();
        $taken = $this->taken($program->id);

        $eligible = collect();
        $registered = 0;
        $outside = 0;
        foreach ($all as $e) {
            if ($taken->has($e->id)) {
                $registered++;

                continue;
            }
            if ($this->eligibility->evaluate($program, $e)->eligible) {
                $eligible->push($e);
            } else {
                $outside++;
            }
        }
        $hours = $this->hours->lastYearHours($eligible->pluck('id')->all());

        return response()->json(['data' => $eligible->map(fn (Employee $e) => $this->row($e, $hours))->values(), 'meta' => [
            'school' => School::whereKey($data['school_id'])->first()?->translate('name'), 'staff' => $all->count(), 'in_audience' => $eligible->count(), 'outside_audience' => $outside, 'already_registered' => $registered, 'year' => $hours['year'],
        ]]);
    }

    /** Looks up employee numbers: who each is, whether they are in the target audience (and if not, why not), and whether they can be added now. */
    public function check(Request $request, TrainingGroup $group): JsonResponse
    {
        $data = $request->validate(['employee_numbers' => ['required', 'array', 'min:1', 'max:200'], 'employee_numbers.*' => ['string', 'max:64']]);
        $program = $group->program;
        $numbers = collect($data['employee_numbers'])->map(fn ($n) => trim((string) $n))->filter()->unique()->values();
        $found = Employee::with(['user:id,name,name_ar', 'school:id,name_ar,name_en', 'jobTitle'])->whereIn('employee_no', $numbers)->get()->keyBy('employee_no');
        $taken = $this->taken($program->id);
        $hours = $this->hours->lastYearHours($found->pluck('id')->all());

        $rows = $numbers->map(function (string $no) use ($found, $taken, $hours, $group) {
            $e = $found->get($no);
            if (! $e || ! $this->scope()->allowsEmployee($e)) {
                return ['employee_no' => $no, 'found' => false, 'can_add' => false, 'reason' => 'not_found', 'message' => __('messages.groups.number_not_found', ['no' => $no])];
            }
            $verdict = $this->verdict($e, $group, $taken);

            return $this->row($e, $hours) + ['found' => true] + $verdict;
        });

        return response()->json(['data' => $rows->values(), 'meta' => ['year' => $hours['year']]]);
    }

    /** Adds the chosen people to the group. Nobody outside the target audience gets in, whoever asks. */
    public function store(Request $request, TrainingGroup $group): JsonResponse
    {
        $data = $request->validate(['employee_ids' => ['required', 'array', 'min:1', 'max:200'], 'employee_ids.*' => ['uuid', 'exists:employees,id']]);
        $program = $group->program;
        $user = $this->user();
        $isCenter = $this->isCenterStaff($user);
        abort_unless($isCenter || $user->hasRole(Role::SCHOOL_ADMIN), 403, __('auth.forbidden'));

        $taken = $this->taken($program->id);
        $results = [];
        $assigned = [];
        foreach (Employee::with('user:id,name,name_ar')->whereIn('id', $data['employee_ids'])->get() as $employee) {
            if (! $this->scope()->allowsEmployee($employee)) {
                $results[] = ['employee_id' => $employee->id, 'ok' => false, 'message' => __('auth.forbidden')];

                continue;
            }
            $verdict = $this->verdict($employee, $group, $taken);
            if (! $verdict['can_add']) {
                $results[] = ['employee_id' => $employee->id, 'ok' => false, 'reason' => $verdict['reason'], 'message' => $verdict['message']];

                continue;
            }
            try {
                // Direct assignment ignores the registration window for the centre; eligibility, time conflicts and equivalents still count.
                $registration = $this->registrations->nominate($program, $employee, $user, $isCenter ? 'training_center' : 'school_admin', null, false, $group, ignoreWindow: $isCenter);
                $results[] = ['employee_id' => $employee->id, 'ok' => true, 'status' => $registration->status, 'registration_id' => $registration->id];
                $assigned[] = $registration;
                $taken->put($employee->id, $group);
            } catch (BusinessRuleException $e) {
                $results[] = ['employee_id' => $employee->id, 'ok' => false, 'reason' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }
        if ($isCenter && $assigned !== []) {
            app(ProgramAnnouncer::class)->traineesAssigned($program, $assigned);
        }

        return response()->json(['data' => $results], 201);
    }

    /** Last year's hours for any list of employees (the lists on the program page show them beside every trainee). */
    public function hours(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:300'], 'ids.*' => ['uuid']]);
        $ids = Employee::whereIn('id', $data['ids'])->tap(fn ($q) => $this->scope()->constrainEmployees($q))->pluck('id')->all();
        $h = $this->hours->lastYearHours($ids);

        return response()->json(['year' => $h['year'], 'data' => $h['hours']]);
    }

    /** @return Collection<string, TrainingGroup|null> employee id => the group they already hold a place in (cancelled and rejected places do not count) */
    private function taken(string $programId): Collection
    {
        return Registration::with('trainingGroup:id,title_ar,title_en,sequence')->where('program_id', $programId)->whereNotIn('status', [Registration::STATUS_CANCELLED, Registration::STATUS_REJECTED])
            ->get()->mapWithKeys(fn (Registration $r) => [$r->employee_id => $r->trainingGroup]);
    }

    /** @return array{can_add: bool, reason: string, message: string, in_audience: bool, failures: list<string>} */
    private function verdict(Employee $e, TrainingGroup $group, Collection $taken): array
    {
        $result = $this->eligibility->evaluate($group->program, $e);
        $failures = array_column($result->failures(), 'message');
        $base = ['in_audience' => $result->eligible, 'failures' => $failures];
        if ($taken->has($e->id)) {
            $g = $taken->get($e->id);

            return $base + ['can_add' => false, 'reason' => $g?->id === $group->id ? 'already_in_group' : 'registered_elsewhere', 'message' => $g?->id === $group->id
                ? __('messages.groups.already_in_group') : __('messages.groups.registered_elsewhere', ['group' => $g?->displayTitle() ?? '—'])];
        }
        if (! $result->eligible) {
            return $base + ['can_add' => false, 'reason' => 'not_in_target_audience', 'message' => __('messages.groups.not_in_audience').($failures ? ' ('.implode(app()->getLocale() === 'ar' ? '، ' : '; ', $failures).')' : '')];
        }
        if (in_array($group->status, [TrainingGroup::CANCELLED, TrainingGroup::COMPLETED], true)) {
            return $base + ['can_add' => false, 'reason' => 'group_closed', 'message' => __('messages.groups.closed_for_move')];
        }
        if ($group->seatsAvailable() <= 0) {
            return $base + ['can_add' => false, 'reason' => 'group_full', 'message' => __('messages.groups.full', ['group' => $group->displayTitle()])];
        }

        return $base + ['can_add' => true, 'reason' => 'ok', 'message' => ''];
    }

    /** @param array{year: int, hours: array<string, float>} $hours @return array<string, mixed> */
    private function row(Employee $e, array $hours): array
    {
        return ['id' => $e->id, 'employee_no' => $e->employee_no, 'name' => $e->user?->displayName(), 'school' => $e->school?->translate('name'), 'school_id' => $e->school_id, 'job_title' => $e->jobTitle?->translate('name'),
            'hours_last_year' => (float) ($hours['hours'][$e->id] ?? 0), 'hours_year' => $hours['year']];
    }
}
