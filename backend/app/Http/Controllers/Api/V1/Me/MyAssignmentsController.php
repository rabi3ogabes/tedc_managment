<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Api\V1\Admin\TrainerAssignmentController;
use App\Http\Controllers\Controller;
use App\Models\GroupTrainer;
use App\Services\TrainerAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The trainer's side of a proposal: see own assignments and fill in the assignment form. */
class MyAssignmentsController extends Controller
{
    public function __construct(private readonly TrainerAssignmentService $assignments) {}

    public function index(): JsonResponse
    {
        $trainerId = $this->user()->trainer?->id;
        $rows = $trainerId ? GroupTrainer::with(['trainer', 'group.program'])->where('trainer_id', $trainerId)->latest()->get() : collect();

        return response()->json(['data' => $rows->map(fn ($a) => TrainerAssignmentController::present($a))->all()]);
    }

    public function form(Request $request, string $id): JsonResponse
    {
        $assignment = GroupTrainer::where('trainer_id', $this->user()->trainer?->id)->findOrFail($id);
        $data = $request->validate([
            'form' => ['required', 'array'], 'form.availability_confirmed' => ['sometimes', 'boolean'], 'form.cv_updated' => ['sometimes', 'boolean'],
            'form.notes' => ['sometimes', 'nullable', 'string', 'max:2000'], 'form.materials_needed' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => TrainerAssignmentController::present($this->assignments->submitForm($assignment, $data['form']))]);
    }
}
