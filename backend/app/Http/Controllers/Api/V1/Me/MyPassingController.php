<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Exceptions\BusinessRuleException;
use App\Models\Assessment;
use App\Models\Program;
use App\Models\Registration;
use App\Services\Assessment\AttemptService;
use App\Services\PassingPolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The trainee's progress towards passing, and the test-out entry point. */
class MyPassingController extends MeController
{
    public function __construct(private readonly PassingPolicyService $passing, private readonly AttemptService $attempts) {}

    public function progress(Registration $registration): JsonResponse
    {
        abort_unless($registration->employee_id === $this->employee()->id, 404);
        $registration->loadMissing('program');
        $e = $this->passing->evaluate($registration);
        $hours = $this->passing->hours($registration, $e['policy']);

        return response()->json(['data' => [
            'program_id' => $registration->program_id, 'program' => $registration->program->translate('title'), 'pass_status' => $registration->pass_status, 'passed_via' => $registration->passed_via, 'mode' => $e['policy']['mode'],
            'weighted_score' => $e['weighted_score'], 'pass_threshold' => $e['policy']['pass_threshold'], 'hours' => $hours,
            'criteria' => array_map(fn ($c) => ['key' => $c['key'], 'value' => $c['value'], 'min' => $c['min'], 'weight' => $c['weight'], 'required' => $c['required'], 'met' => $c['met'], 'applicable' => $c['applicable'], 'exempted' => $c['exempted'], 'evidence' => $c['evidence']], $e['criteria']),
            'can_test_out' => $e['policy']['allow_test_out'] && $e['policy']['test_out_assessment_id'] && ! $e['passed'],
            'test_out_assessment_id' => $e['policy']['allow_test_out'] ? $e['policy']['test_out_assessment_id'] : null,
            'certificates' => $registration->certificates()->get(['id', 'type', 'certificate_no', 'status', 'hours', 'issued_at']),
        ]]);
    }

    /** Starts the program's comprehensive skills test (Phase 06 attempt) for someone who may pass without attending. */
    public function testOut(Request $request, Program $program): JsonResponse
    {
        $registration = Registration::where('program_id', $program->id)->where('employee_id', $this->employee()->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first();
        if (! $registration) {
            throw new BusinessRuleException(__('messages.passing.not_registered'), 'not_registered');
        }
        $policy = $this->passing->policy($registration);
        if (! $policy['allow_test_out'] || ! $policy['test_out_assessment_id']) {
            throw new BusinessRuleException(__('messages.passing.test_out_off'), 'test_out_off');
        }
        $assessment = Assessment::where('status', 'published')->findOrFail($policy['test_out_assessment_id']);
        $attempt = $this->attempts->start($registration, $assessment, $request->validate(['access_code' => ['nullable', 'string', 'max:20']]) + ['ip' => $request->ip(), 'device' => $request->userAgent()]);

        return response()->json(['data' => $this->attempts->state($attempt->load('assessment')) + ['assessment_id' => $assessment->id]]);
    }
}
