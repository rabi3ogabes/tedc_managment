<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Models\ContentPackage;
use App\Models\CourseLesson;
use App\Models\Registration;
use App\Models\SurveyResponse;
use App\Services\Content\LessonVersionService;
use App\Services\CourseService;
use App\Services\FileStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * The learner's online course: outline, lesson player data, watch tracking, quizzes and surveys.
 */
class MyCourseController extends MeController
{
    public function __construct(private readonly CourseService $course, private readonly FileStorage $storage) {}

    public function outline(Registration $registration): JsonResponse
    {
        $this->mine($registration);
        $program = $registration->program;
        abort_unless($program->has_course, 404);

        return response()->json(['data' => ['registration_id' => $registration->id, 'program' => ['id' => $program->id, 'code' => $program->code, 'title' => $program->translate('title')]] + $this->course->outline($program, $registration)]);
    }

    /** Everything the player needs for one lesson: the media address, the saved position, the questions (without answers). */
    public function lesson(CourseLesson $lesson): JsonResponse
    {
        $registration = $this->registrationFor($lesson);
        $this->course->assertOpen($lesson, $registration);
        $p = $this->course->open($lesson, $registration);
        // A learner who started under an older version of this lesson keeps seeing that version until it is completed or they are moved.
        if ($ov = app(LessonVersionService::class)->overlay($lesson, $p)) {
            $lesson->fill(Arr::only($ov['fields'], ['title_ar', 'title_en', 'description_ar', 'description_en', 'body_ar', 'body_en', 'settings', 'file_path', 'file_name', 'file_mime', 'external_url', 'duration_seconds', 'slide_count', 'package_id', 'package_item_id']));
        }
        $settings = $lesson->settings ?? [];

        $media = null;
        if ($lesson->file_path) {
            // Long-lived: the player keeps requesting byte ranges while seeking.
            $media = ['kind' => 'file', 'url' => $this->storage->temporaryUrl('materials', $lesson->file_path, 6 * 3600), 'mime' => $lesson->file_mime, 'name' => $lesson->file_name];
        } elseif ($lesson->external_url) {
            $media = ['kind' => 'link', 'url' => $lesson->external_url, 'embed' => $this->embedUrl($lesson->external_url)];
        }

        $quiz = null;
        if ($lesson->type === CourseLesson::QUIZ) {
            $questions = $lesson->questions;
            $quiz = [
                'questions' => ($settings['shuffle_questions'] ?? false ? $questions->shuffle() : $questions)->map(fn ($q) => [
                    'id' => $q->id, 'type' => $q->type, 'text' => $q->translate('text'), 'points' => $q->points,
                    'options' => collect($settings['shuffle_options'] ?? false ? collect($q->options)->shuffle() : $q->options)->map(fn ($o) => ['id' => $o['id'], 'text' => $o['text_'.(app()->getLocale() === 'en' ? 'en' : 'ar')] ?: $o['text_ar']])->values(),
                ])->values(),
                'pass_percent' => (int) ($settings['pass_percent'] ?? 70), 'max_attempts' => $settings['max_attempts'] ?? null, 'attempts' => $p->attempts,
                'time_limit_minutes' => $settings['time_limit_minutes'] ?? null,
            ];
        }
        $survey = null;
        if ($lesson->type === CourseLesson::SURVEY) {
            $survey = [
                'submitted' => SurveyResponse::where('lesson_id', $lesson->id)->where('registration_id', $registration->id)->exists(),
                'questions' => $lesson->surveyQuestions->map(fn ($q) => ['id' => $q->id, 'type' => $q->type, 'text' => $q->translate('text'), 'required' => $q->required,
                    'options' => collect($q->options ?? [])->map(fn ($o) => ['id' => $o['id'], 'text' => $o['text_'.(app()->getLocale() === 'en' ? 'en' : 'ar')] ?: $o['text_ar']])->values()])->values(),
            ];
        }

        return response()->json(['data' => [
            'id' => $lesson->id, 'type' => $lesson->type, 'title' => $lesson->translate('title'), 'description' => $lesson->translate('description'), 'body' => $lesson->translate('body'),
            'is_required' => $lesson->is_required, 'duration_seconds' => $lesson->duration_seconds, 'slide_count' => $lesson->slide_count, 'media' => $media,
            'rules' => ['allow_seeking' => (bool) ($settings['allow_seeking'] ?? true), 'max_speed' => (float) ($settings['max_speed'] ?? 2), 'pause_when_hidden' => (bool) ($settings['pause_when_hidden'] ?? true),
                'min_watch_percent' => (int) ($settings['min_watch_percent'] ?? 90), 'min_view_percent' => (int) ($settings['min_view_percent'] ?? 80), 'downloadable' => (bool) ($settings['downloadable'] ?? false)],
            'progress' => ['status' => $p->status, 'percent' => (float) $p->percent, 'position' => (float) $p->last_position, 'furthest' => (float) $p->furthest_position, 'segments' => $p->segments ?? [], 'best_score' => $p->best_score, 'attempts' => $p->attempts],
            'quiz' => $quiz, 'survey' => $survey,
            'package' => $lesson->package_id ? ($pkg = ContentPackage::find($lesson->package_id)) ? ['id' => $pkg->id, 'standard' => $pkg->standard, 'title' => $pkg->title, 'item_id' => $lesson->package_item_id, 'entry_points' => $pkg->entry_points] : null : null,
            'lti_tool_id' => $lesson->lti_tool_id, 'external_course_id' => $lesson->external_course_id,
            'registration_id' => $registration->id,
        ]]);
    }

    public function heartbeat(Request $request, CourseLesson $lesson): JsonResponse
    {
        $data = $request->validate(['from' => ['required', 'numeric', 'min:0'], 'to' => ['required', 'numeric', 'min:0'], 'duration' => ['nullable', 'numeric', 'min:0'], 'rate' => ['nullable', 'numeric', 'min:0.25', 'max:4'], 'visible' => ['nullable', 'boolean']]);
        $registration = $this->openRegistration($lesson);

        return response()->json(['data' => $this->course->heartbeat($lesson, $registration, (float) $data['from'], (float) $data['to'], isset($data['duration']) ? (float) $data['duration'] : null, (float) ($data['rate'] ?? 1), (bool) ($data['visible'] ?? true))]);
    }

    public function slide(Request $request, CourseLesson $lesson): JsonResponse
    {
        $data = $request->validate(['slide' => ['required', 'integer', 'min:1'], 'total' => ['required', 'integer', 'min:1', 'max:2000']]);

        return response()->json(['data' => $this->course->viewSlide($lesson, $this->openRegistration($lesson), $data['slide'], $data['total'])]);
    }

    public function complete(CourseLesson $lesson): JsonResponse
    {
        return response()->json(['data' => $this->course->complete($lesson, $this->openRegistration($lesson))]);
    }

    public function quiz(Request $request, CourseLesson $lesson): JsonResponse
    {
        $data = $request->validate(['answers' => ['required', 'array'], 'answers.*' => ['array'], 'answers.*.*' => ['string', 'max:40'], 'seconds' => ['nullable', 'integer', 'min:0', 'max:86400']]);

        return response()->json(['data' => $this->course->submitQuiz($lesson, $this->openRegistration($lesson), $data['answers'], (int) ($data['seconds'] ?? 0))]);
    }

    public function survey(Request $request, CourseLesson $lesson): JsonResponse
    {
        $data = $request->validate(['answers' => ['required', 'array']]);

        return response()->json(['data' => $this->course->submitSurvey($lesson, $this->openRegistration($lesson), $data['answers'])]);
    }

    // -----------------------------------------------------------------------------------------------------------

    private function mine(Registration $registration): void
    {
        abort_unless($registration->employee_id === $this->employee()->id && in_array($registration->status, [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED], true), 404);
    }

    private function registrationFor(CourseLesson $lesson): Registration
    {
        return Registration::where('program_id', $lesson->program_id)->where('employee_id', $this->employee()->id)
            ->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->firstOr(fn () => abort(404));
    }

    /** The learner's registration, after checking the lesson is published and not locked behind earlier ones. */
    private function openRegistration(CourseLesson $lesson): Registration
    {
        $registration = $this->registrationFor($lesson);
        $this->course->assertOpen($lesson, $registration);

        return $registration;
    }

    /** YouTube / Vimeo links become embeddable addresses; anything else is played as a file. */
    private function embedUrl(string $url): ?string
    {
        if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([\w-]{11})~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/'.$m[1].'?rel=0&modestbranding=1';
        }
        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return null;
    }
}
