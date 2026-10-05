<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\GroupTrainer;
use App\Models\Program;
use App\Models\Trainer;
use App\Models\TrainingGroup;
use App\Services\TrainerAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Trainer proposals per group (staff side) and kit-developer assignment. */
class TrainerAssignmentController extends Controller
{
    public function __construct(private readonly TrainerAssignmentService $assignments) {}

    public function store(Request $request, TrainingGroup $group): JsonResponse
    {
        $data = $request->validate(['trainer_id' => ['required', 'uuid', 'exists:trainers,id'], 'role' => ['sometimes', Rule::in(['lead', 'assistant'])], 'hours' => ['sometimes', 'numeric', 'min:0', 'max:1000']]);
        $assignment = $this->assignments->propose($group, Trainer::findOrFail($data['trainer_id']), $data['role'] ?? 'lead', (float) ($data['hours'] ?? 0), $this->user());

        return response()->json(['data' => $this->present($assignment)], 201);
    }

    public function decision(Request $request, GroupTrainer $groupTrainer): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in([GroupTrainer::APPROVED, GroupTrainer::REJECTED])], 'external_approval_ref' => ['nullable', 'string', 'max:120'], 'note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->present($this->assignments->decide($groupTrainer, $data['decision'], $data['external_approval_ref'] ?? null, $data['note'] ?? null, $this->user()))]);
    }

    public function destroy(GroupTrainer $groupTrainer): JsonResponse
    {
        $groupTrainer->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function kitDevelopers(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['user_ids' => ['required', 'array', 'min:1', 'max:20'], 'user_ids.*' => ['uuid', 'exists:users,id'], 'due_at' => ['nullable', 'date', 'after_or_equal:today']]);
        $result = $this->assignments->assignKitDevelopers($program, $data['user_ids'], $data['due_at'] ?? null, $this->user());

        return response()->json(['data' => ['kit_id' => $result['kit']->id, 'code' => $result['kit']->code, 'added' => $result['added'], 'due_at' => $result['kit']->due_at?->toDateString()]], 201);
    }

    public static function present(GroupTrainer $a): array
    {
        $a->loadMissing(['trainer', 'group.program']);

        return [
            'id' => $a->id, 'group_id' => $a->group_id, 'role' => $a->role, 'hours' => $a->hours, 'status' => $a->status,
            'trainer' => ['id' => $a->trainer_id, 'name_ar' => $a->trainer?->name_ar, 'name_en' => $a->trainer?->name_en],
            'group' => $a->group ? ['id' => $a->group->id, 'code' => $a->group->code, 'title_ar' => $a->group->displayTitle('ar'), 'title_en' => $a->group->displayTitle('en'), 'program_title_ar' => $a->group->program?->title_ar, 'program_title_en' => $a->group->program?->title_en, 'start_date' => $a->group->start_date?->toDateString()] : null,
            'form' => $a->form, 'form_submitted_at' => $a->form_submitted_at?->toIso8601String(),
            'decided_at' => $a->decided_at?->toIso8601String(), 'decision_note' => $a->decision_note, 'external_approval_ref' => $a->external_approval_ref,
        ];
    }
}
