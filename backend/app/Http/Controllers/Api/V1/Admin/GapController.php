<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\TrainingPlan;
use App\Services\GapAnalysisService;
use App\Support\AccessScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Gap analysis: required vs current competency level, ranked and sent to the plan. */
class GapController extends Controller
{
    public function __construct(private readonly GapAnalysisService $gaps) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['group_by' => ['sometimes', Rule::in(GapAnalysisService::GROUPS)], 'school_id' => ['sometimes', 'uuid'], 'job_title_id' => ['sometimes', 'uuid'], 'skill_id' => ['sometimes', 'uuid']]);

        return response()->json(['data' => $this->gaps->analyse($this->user(), $q['group_by'] ?? 'skill', array_diff_key($q, ['group_by' => 1]))]);
    }

    public function employee(Employee $employee): JsonResponse
    {
        abort_unless(AccessScope::current($this->user())->allowsEmployee($employee), 403);

        return response()->json(['data' => $this->gaps->employeeDetail($employee)]);
    }

    public function toPlan(Request $request): JsonResponse
    {
        $d = $request->validate(['plan_id' => ['required', 'uuid', 'exists:training_plans,id'], 'skill_ids' => ['required', 'array', 'min:1', 'max:100'], 'skill_ids.*' => ['uuid']]);

        return response()->json(['data' => $this->gaps->toPlan($this->user(), TrainingPlan::findOrFail($d['plan_id']), $d['skill_ids'])]);
    }
}
