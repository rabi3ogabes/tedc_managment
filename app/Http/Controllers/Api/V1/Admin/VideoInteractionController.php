<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Me\MeController;
use App\Models\CourseLesson;
use App\Models\Registration;
use App\Models\VideoInteraction;
use App\Services\Assessment\InteractionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Interactive video: the author's timeline and the trainee's answers. */
class VideoInteractionController extends MeController
{
    public function __construct(private readonly InteractionService $interactions) {}

    public function index(CourseLesson $lesson): JsonResponse
    {
        return response()->json(['data' => VideoInteraction::with('question:id,type,stem_ar,stem_en')->where('lesson_id', $lesson->id)->orderBy('at_seconds')->get()->all()]);
    }

    public function save(Request $request, CourseLesson $lesson): JsonResponse
    {
        $d = $request->validate(['interactions' => ['present', 'array', 'max:60'], 'interactions.*.at_seconds' => ['required', 'numeric', 'min:0', 'max:86400'], 'interactions.*.type' => ['sometimes', Rule::in(['question', 'reflection', 'note', 'checkpoint'])],
            'interactions.*.question_id' => ['nullable', 'uuid', 'exists:questions,id'], 'interactions.*.question' => ['nullable', 'array'], 'interactions.*.prompt_ar' => ['nullable', 'string', 'max:2000'], 'interactions.*.prompt_en' => ['nullable', 'string', 'max:2000'],
            'interactions.*.required' => ['sometimes', 'boolean'], 'interactions.*.blocks_progress' => ['sometimes', 'boolean'], 'interactions.*.require_correct' => ['sometimes', 'boolean'], 'interactions.*.allow_skip' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->interactions->replace($lesson, $d['interactions'], $this->user())->all()]);
    }

    public function mine(CourseLesson $lesson): JsonResponse
    {
        return response()->json(['data' => $this->interactions->forTrainee($lesson, $this->registrationFor($lesson))]);
    }

    public function answer(Request $request, CourseLesson $lesson, VideoInteraction $interaction): JsonResponse
    {
        abort_unless($interaction->lesson_id === $lesson->id, 404);

        return response()->json(['data' => $this->interactions->answer($interaction, $this->registrationFor($lesson), $request->input('answer'))]);
    }

    private function registrationFor(CourseLesson $lesson): Registration
    {
        return Registration::where('program_id', $lesson->program_id)->where('employee_id', $this->employee()->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->firstOrFail();
    }
}
