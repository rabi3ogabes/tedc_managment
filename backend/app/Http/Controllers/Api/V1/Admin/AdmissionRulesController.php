<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Models\ProgramEquivalence;
use App\Models\Registration;
use App\Models\RegistrationPriorityRule;
use App\Models\SiteSetting;
use App\Models\TrainingGroup;
use App\Services\RegistrationPriorityService;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Priority rules, equivalent programs, and the group candidate list with prior-benefit analytics and bulk acceptance. */
class AdmissionRulesController extends Controller
{
    public function __construct(private readonly RegistrationPriorityService $priority, private readonly RegistrationService $registrations) {}

    public function rules(): JsonResponse
    {
        return response()->json(['data' => RegistrationPriorityRule::orderBy('scope')->get()->all(), 'defaults' => ['criteria' => RegistrationPriorityService::DEFAULT_CRITERIA, 'weights' => RegistrationPriorityService::DEFAULT_WEIGHTS]]);
    }

    public function saveRule(Request $request, ?RegistrationPriorityRule $rule = null): JsonResponse
    {
        $d = $request->validate([
            'scope' => ['required', Rule::in(['global', 'program', 'group'])], 'scope_id' => ['nullable', 'uuid'], 'criteria' => ['required', 'array', 'min:1', 'max:10'],
            'criteria.*' => ['string', Rule::in(array_merge(RegistrationPriorityService::DEFAULT_CRITERIA, ['job_title_in']))], 'weights' => ['nullable', 'array'], 'is_active' => ['sometimes', 'boolean'],
        ]);
        $rule = $rule ? tap($rule)->update($d) : RegistrationPriorityRule::create($d);

        return response()->json(['data' => $rule], 201);
    }

    public function deleteRule(RegistrationPriorityRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    /** Live ranking of a group's applicants with an unsaved rule. */
    public function preview(Request $request): JsonResponse
    {
        $d = $request->validate(['group_id' => ['required', 'uuid', 'exists:training_groups,id'], 'criteria' => ['required', 'array', 'min:1'], 'weights' => ['nullable', 'array']]);
        $group = TrainingGroup::with('program')->findOrFail($d['group_id']);
        $rule = $this->priority->normalize($d['criteria'], $d['weights'] ?? null);
        $rows = $this->applicants($group)->map(function ($r) use ($group, $rule) {
            $s = $this->priority->score($r->employee, $group->program, $group, null, $rule);

            return ['registration_id' => $r->id, 'employee' => $r->employee->user?->displayName(), 'score' => $s['score'], 'explanation' => $s['explanation']];
        })->sortByDesc('score')->values();

        return response()->json(['data' => $rows->all()]);
    }

    public function equivalences(Program $program): JsonResponse
    {
        $rows = ProgramEquivalence::with(['equivalent:id,code,title_ar,title_en'])->where('program_id', $program->id)->get()->map(fn ($e) => ['id' => $e->id, 'program_id' => $e->equivalent_program_id, 'code' => $e->equivalent?->code, 'title' => $e->equivalent?->translate('title'), 'bidirectional' => $e->bidirectional, 'note' => $e->note]);

        return response()->json(['data' => ['repeat_policy' => $program->repeat_policy, 'equivalents' => $rows->all()]]);
    }

    public function syncEquivalences(Request $request, Program $program): JsonResponse
    {
        $d = $request->validate(['repeat_policy' => ['sometimes', Rule::in(['block', 'warn', 'allow'])], 'equivalents' => ['present', 'array', 'max:50'], 'equivalents.*.program_id' => ['required', 'uuid', 'exists:programs,id', 'different:'.$program->id], 'equivalents.*.bidirectional' => ['sometimes', 'boolean'], 'equivalents.*.note' => ['nullable', 'string', 'max:255']]);
        if (isset($d['repeat_policy'])) {
            $program->update(['repeat_policy' => $d['repeat_policy']]);
        }
        ProgramEquivalence::where('program_id', $program->id)->delete();
        foreach ($d['equivalents'] as $e) {
            ProgramEquivalence::create(['program_id' => $program->id, 'equivalent_program_id' => $e['program_id'], 'bidirectional' => $e['bidirectional'] ?? true, 'note' => $e['note'] ?? null]);
        }

        return $this->equivalences($program->fresh());
    }

    /** Applicants of a group (pending or waiting), best first, with what they already benefited from. */
    public function candidates(TrainingGroup $group): JsonResponse
    {
        $group->loadMissing('program');
        $minHours = (float) (SiteSetting::find('training.annual_min_hours')?->value['hours'] ?? 20);
        $rows = $this->applicants($group)->map(function (Registration $r) use ($group, $minHours) {
            $done = Registration::with('program:id,total_hours,category_id')->where('employee_id', $r->employee_id)->where('status', Registration::STATUS_COMPLETED)->get();
            $recent = $done->filter(fn ($x) => $x->completed_at && $x->completed_at->gte(now()->subMonths(12)));
            $hours = (float) $done->filter(fn ($x) => $x->completed_at && $x->completed_at->year === now()->year)->sum(fn ($x) => $x->program->total_hours);

            return [
                'registration_id' => $r->id, 'status' => $r->status, 'employee' => $r->employee->user?->displayName(), 'school' => $r->employee->school?->translate('name'), 'priority_score' => $r->priority_score, 'priority_explanation' => $r->priority_explanation,
                'history' => ['completed_12_months' => $recent->count(), 'hours_this_year' => $hours, 'annual_min_hours' => $minHours, 'same_category' => $done->where('program.category_id', $group->program->category_id)->count(), 'attendance_avg' => round((float) $done->avg('attendance_percent'), 1)],
                'warnings' => array_filter([$r->eligibility_snapshot['repeat_warning'] ?? null ? 'repeat' : null]),
            ];
        })->sortByDesc('priority_score')->values();

        return response()->json(['data' => $rows->all(), 'seats_available' => $group->seatsAvailable()]);
    }

    /** Approves the chosen applicants in the given order, only as far as the seats go. */
    public function accept(Request $request, TrainingGroup $group): JsonResponse
    {
        $d = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['uuid'], 'override_reason' => ['nullable', 'string', 'max:500']]);
        $approved = 0;
        $skipped = [];
        $regs = Registration::where('training_group_id', $group->id)->whereIn('id', $d['ids'])->get()->sortBy(fn ($r) => array_search($r->id, $d['ids']));
        foreach ($regs as $r) {
            $taken = Registration::where('training_group_id', $group->id)->where('status', Registration::STATUS_APPROVED)->count();
            if ($taken >= $group->capacity) {
                $skipped[] = ['id' => $r->id, 'reason' => 'no_seats'];

                continue;
            }
            try {
                if ($r->status === Registration::STATUS_WAITLISTED) {
                    $this->registrations->transition($r, Registration::STATUS_PENDING, $this->user());
                }
                $this->registrations->transition($r->fresh(), Registration::STATUS_APPROVED, $this->user(), null, $d['override_reason'] ?? null);
                $approved++;
            } catch (BusinessRuleException $e) {
                $skipped[] = ['id' => $r->id, 'reason' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }

        return response()->json(['data' => ['approved' => $approved, 'skipped' => $skipped]]);
    }

    private function applicants(TrainingGroup $group)
    {
        return Registration::with(['employee.user:id,name,name_ar', 'employee.school:id,name_ar,name_en'])->where('training_group_id', $group->id)
            ->whereIn('status', [Registration::STATUS_PENDING_MANAGER, Registration::STATUS_PENDING, Registration::STATUS_WAITLISTED])->get();
    }
}
