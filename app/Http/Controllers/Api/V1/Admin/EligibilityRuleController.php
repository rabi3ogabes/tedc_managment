<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\EligibilityRule;
use App\Models\Employee;
use App\Models\Program;
use App\Services\Eligibility\EligibilityEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EligibilityRuleController extends Controller
{
    public function index(Program $program): JsonResponse
    {
        return response()->json(['data' => $program->eligibilityRules, 'meta' => [
            'fields' => EligibilityRule::FIELDS,
            'operators' => EligibilityRule::OPERATORS,
        ]]);
    }

    /**
     * Replaces the full rule set (the rule builder UI saves the whole list at once).
     */
    public function sync(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.field' => ['required', Rule::in(EligibilityRule::FIELDS)],
            'rules.*.operator' => ['required', Rule::in(EligibilityRule::OPERATORS)],
            'rules.*.value' => ['present'],
            'rules.*.message_ar' => ['nullable', 'string', 'max:255'],
            'rules.*.message_en' => ['nullable', 'string', 'max:255'],
            'rules.*.is_mandatory' => ['sometimes', 'boolean'],
            'rules.*.is_generated' => ['sometimes', 'boolean'],
        ]);

        $program->eligibilityRules()->delete();
        foreach (array_values($data['rules']) as $i => $rule) {
            $program->eligibilityRules()->create($rule + ['sort_order' => $i, 'is_mandatory' => $rule['is_mandatory'] ?? true, 'is_generated' => $rule['is_generated'] ?? false]);
        }

        return $this->index($program->refresh());
    }

    /**
     * Preview the rule set against a specific employee.
     */
    public function check(Program $program, Employee $employee, EligibilityEngine $engine): JsonResponse
    {
        return response()->json(['data' => $engine->evaluate($program, $employee)]);
    }
}
