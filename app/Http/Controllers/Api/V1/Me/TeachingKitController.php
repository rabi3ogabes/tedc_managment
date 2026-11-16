<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Http\Controllers\Controller;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Program;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The kit (حقيبة) of an online programme as its trainer sees it in the app: every module and lesson the learners get (videos, PowerPoint
 * presentations, quizzes, surveys, articles, interactive units, tools and external courses), with what each holds, read-only. Only the
 * programme's own trainers (and people who manage programmes) may open it.
 */
class TeachingKitController extends Controller
{
    public function __construct(private readonly FileStorage $storage) {}

    public function show(Request $request, Program $program): JsonResponse
    {
        $user = $request->user();
        $trainer = $user->trainer;
        $teaches = $trainer && ($program->trainers()->whereKey($trainer->id)->exists() || $program->sessions()->where('trainer_id', $trainer->id)->exists());
        abort_unless($teaches || $user->hasPermission('programs.manage'), 403, __('auth.forbidden'));
        abort_unless($program->has_course, 404);

        $modules = $program->courseModules()->with(['lessons' => fn ($q) => $q->where('status', 'published')->withCount(['questions', 'surveyQuestions'])])->get();
        $lessons = $modules->flatMap->lessons;

        return response()->json(['data' => [
            'program' => ['id' => $program->id, 'code' => $program->code, 'title' => $program->translate('title'), 'delivery_mode' => $program->delivery_mode],
            'modules' => $modules->map(fn (CourseModule $m) => [
                'id' => $m->id, 'title' => $m->translate('title'), 'description' => $m->translate('description'),
                'lessons' => $m->lessons->map(fn (CourseLesson $l) => $this->lesson($l))->values(),
            ])->filter(fn (array $m) => $m['lessons']->isNotEmpty())->values(),
            'totals' => [
                'modules' => $modules->filter(fn (CourseModule $m) => $m->lessons->isNotEmpty())->count(), 'lessons' => $lessons->count(),
                'minutes' => (int) ceil($lessons->sum('duration_seconds') / 60), 'by_type' => $lessons->countBy('type'),
            ],
        ]]);
    }

    private function lesson(CourseLesson $l): array
    {
        $file = $l->file_path ? $this->storage->temporaryUrl('materials', $l->file_path, 3600) : null;

        return [
            'id' => $l->id, 'type' => $l->type, 'title' => $l->translate('title'), 'description' => $l->translate('description'),
            'is_required' => $l->is_required, 'duration_seconds' => $l->duration_seconds, 'slide_count' => $l->slide_count,
            'questions' => $l->type === CourseLesson::QUIZ ? $l->questions_count : ($l->type === CourseLesson::SURVEY ? $l->survey_questions_count : null),
            'pass_percent' => $l->type === CourseLesson::QUIZ ? (int) ($l->setting('pass_percent', 70)) : null,
            // A file to look at (the video, the presentation) or the address of an external lesson: opened outside the app.
            'file_name' => $l->file_name, 'url' => $file ?? $l->external_url,
        ];
    }
}
