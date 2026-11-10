<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Services\Programs\ProgramInstruments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A programme's training kits and the evaluation instruments generated from them. */
class ProgramInstrumentController extends Controller
{
    public function __construct(private readonly ProgramInstruments $instruments) {}

    /** The kits a programme may use, with what their assessment tools offer (for the picker). */
    public function kits(Request $request): JsonResponse
    {
        $d = $request->validate(['delivery_mode' => ['nullable', 'in:in_person,online,hybrid'], 'q' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $this->instruments->kitOptions($d['delivery_mode'] ?? null, $d['q'] ?? null)]);
    }

    public function show(Program $program): JsonResponse
    {
        return response()->json(['data' => $this->instruments->overview($program)]);
    }

    /** Sets the programme's kits and regenerates the instruments from them. */
    public function update(Request $request, Program $program): JsonResponse
    {
        $d = $request->validate(['kit_ids' => ['present', 'array', 'max:20'], 'kit_ids.*' => ['uuid', 'exists:training_kits,id'], 'instruments' => ['sometimes', 'array'],
            'instruments.trainee_survey' => ['sometimes', 'boolean'], 'instruments.trainer_reflection' => ['sometimes', 'boolean'], 'instruments.pre_test' => ['sometimes', 'boolean'], 'instruments.post_test' => ['sometimes', 'boolean'], 'instruments.survey_auto_hours' => ['sometimes', 'integer', 'min:0', 'max:720']]);
        $this->instruments->attachKits($program, $d['kit_ids']);

        return response()->json(['data' => $this->instruments->generate($program, $d['instruments'] ?? [], $this->user())]);
    }
}
