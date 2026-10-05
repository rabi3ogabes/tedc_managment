<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Program;
use App\Services\ProgramStructureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Sub-programs, the program tree with its roll-ups, and units (axes, objectives, competencies). */
class ProgramStructureController extends Controller
{
    public function __construct(private readonly ProgramStructureService $structure) {}

    public function storeSub(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate([
            'title_ar' => ['required', 'string', 'max:255'], 'title_en' => ['required', 'string', 'max:255'], 'code' => ['nullable', 'alpha_dash', 'max:32', 'unique:programs,code'],
            'summary_ar' => ['nullable', 'string', 'max:500'], 'summary_en' => ['nullable', 'string', 'max:500'], 'description_ar' => ['nullable', 'string'], 'description_en' => ['nullable', 'string'],
            'total_hours' => ['sometimes', 'numeric', 'min:0', 'max:1000'], 'capacity' => ['sometimes', 'integer', 'min:1', 'max:5000'],
            'objectives' => ['nullable', 'array'], 'objectives.*' => ['string', 'max:500'], 'axes' => ['nullable', 'array'],
        ]);

        return response()->json(['data' => $this->structure->createSub($program, array_filter($data, fn ($v) => $v !== null), $this->user())], 201);
    }

    public function tree(Program $program): JsonResponse
    {
        // A sub-program shows the whole tree of its main program.
        return response()->json(['data' => $this->structure->tree($program->parent ?? $program)]);
    }

    public function syncUnits(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate([
            'units' => ['present', 'array', 'max:60'], 'units.*.title_ar' => ['required', 'string', 'max:255'], 'units.*.title_en' => ['required', 'string', 'max:255'],
            'units.*.hours' => ['nullable', 'numeric', 'min:0', 'max:1000'], 'units.*.objectives' => ['nullable', 'array'], 'units.*.objectives.*' => ['string', 'max:500'],
            'units.*.summary_ar' => ['nullable', 'string', 'max:1000'], 'units.*.summary_en' => ['nullable', 'string', 'max:1000'],
            'units.*.skill_ids' => ['nullable', 'array'], 'units.*.skill_ids.*' => ['uuid', 'exists:skills,id'],
        ]);

        return response()->json(['data' => $this->structure->syncUnits($program, $data['units'])]);
    }
}
