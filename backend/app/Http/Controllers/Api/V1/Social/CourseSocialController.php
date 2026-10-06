<?php

namespace App\Http\Controllers\Api\V1\Social;

use App\Http\Controllers\Controller;
use App\Models\ContentRating;
use App\Models\CourseLesson;
use App\Models\CourseQuestion;
use App\Models\LessonNote;
use App\Models\Program;
use App\Services\FeatureSettings;
use App\Social\CourseSocial;
use App\Social\RatingService;
use App\Social\SocialSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Around a lesson: private notes, questions to the trainer, and ratings of content. */
class CourseSocialController extends Controller
{
    public function __construct(private readonly CourseSocial $course, private readonly RatingService $ratings, private readonly SocialSettings $settings, private readonly FeatureSettings $features) {}

    // ---- notes ---------------------------------------------------------------------------------------

    public function notes(Request $request): JsonResponse
    {
        $d = $request->validate(['lesson_id' => ['required', 'uuid']]);
        $rows = $this->course->notes($this->user(), CourseLesson::findOrFail($d['lesson_id']))->map(fn (LessonNote $n) => ['id' => $n->id, 'body' => $n->body, 'created_at' => $n->created_at->toIso8601String()]);

        return response()->json(['data' => $rows->values()]);
    }

    public function addNote(Request $request): JsonResponse
    {
        $d = $request->validate(['lesson_id' => ['required', 'uuid'], 'body' => ['required', 'string', 'max:4000']]);
        $n = $this->course->addNote($this->user(), CourseLesson::findOrFail($d['lesson_id']), $d['body']);

        return response()->json(['data' => ['id' => $n->id, 'body' => $n->body, 'created_at' => $n->created_at->toIso8601String()]], 201);
    }

    public function deleteNote(LessonNote $lessonNote): JsonResponse
    {
        $this->course->deleteNote($this->user(), $lessonNote);

        return response()->json(['message' => 'ok']);
    }

    // ---- ask the trainer -----------------------------------------------------------------------------

    private function forums(): void
    {
        abort_unless($this->features->enabled('forums'), 403);
    }

    public function ask(Request $request): JsonResponse
    {
        $this->forums();
        $d = $request->validate(['program_id' => ['required', 'uuid'], 'subject' => ['required', 'string', 'max:190'], 'body' => ['required', 'string', 'max:6000'], 'visibility' => ['nullable', Rule::in(['private', 'group'])], 'lesson_id' => ['nullable', 'uuid']]);
        $q = $this->course->ask($this->user(), Program::findOrFail($d['program_id']), $d);

        return response()->json(['data' => $this->question($q)], 201);
    }

    public function myQuestions(): JsonResponse
    {
        $this->forums();
        $rows = CourseQuestion::with('program:id,title_ar,title_en')->where('asker_id', $this->user()->id)->latest()->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (CourseQuestion $q) => $this->question($q))->values()]);
    }

    public function inbox(Request $request): JsonResponse
    {
        $this->forums();
        $rows = $this->course->inbox($this->user(), $request->only(['status', 'overdue']))->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (CourseQuestion $q) => $this->question($q) + ['asker' => $q->asker?->displayName(), 'body' => $q->body, 'overdue' => $q->status === 'open' && $q->due_at && $q->due_at->isPast()])->values()]);
    }

    public function answer(Request $request, CourseQuestion $courseQuestion): JsonResponse
    {
        $this->forums();
        $d = $request->validate(['answer' => ['required', 'string', 'max:8000']]);

        return response()->json(['data' => $this->question($this->course->answer($courseQuestion, $this->user(), $d['answer']))]);
    }

    public function close(CourseQuestion $courseQuestion): JsonResponse
    {
        $this->forums();

        return response()->json(['data' => $this->question($this->course->close($courseQuestion, $this->user()))]);
    }

    /** @return array<string, mixed> */
    private function question(CourseQuestion $q): array
    {
        return ['id' => $q->id, 'program_id' => $q->program_id, 'program' => $q->program ? ['title_ar' => $q->program->title_ar, 'title_en' => $q->program->title_en] : null, 'lesson_id' => $q->lesson_id, 'visibility' => $q->visibility, 'subject' => $q->subject,
            'body' => $q->asker_id === $this->user()->id || $this->course->staffOfProgram($this->user(), $q->program_id) ? $q->body : null, 'status' => $q->status, 'answer' => $q->answer, 'answered_at' => $q->answered_at?->toIso8601String(), 'due_at' => $q->due_at?->toIso8601String(), 'created_at' => $q->created_at->toIso8601String()];
    }

    // ---- ratings -------------------------------------------------------------------------------------

    public function ratingSummary(Request $request, string $type, string $id): JsonResponse
    {
        abort_unless(isset(RatingService::SUBJECTS[$type]) && Str::isUuid($id), 403);
        $this->ratings->subject($type, $id);
        $reviews = ContentRating::with('user:id,name,name_ar')->where(['subject_type' => $type, 'subject_id' => $id, 'status' => 'published'])->whereNotNull('review')->latest()->limit(20)->get()
            ->map(fn (ContentRating $r) => ['id' => $r->id, 'stars' => $r->stars, 'review' => $r->review, 'author' => $r->user?->displayName(), 'created_at' => $r->created_at->toIso8601String()]);

        return response()->json(['data' => $this->ratings->summary($type, $id, $this->user()) + ['reviews' => $reviews->values()]]);
    }

    public function rate(Request $request): JsonResponse
    {
        $d = $request->validate(['subject_type' => ['required', Rule::in(array_keys(RatingService::SUBJECTS))], 'subject_id' => ['required', 'uuid'], 'stars' => ['required', 'integer', 'between:1,5'], 'review' => ['nullable', 'string', 'max:1000']]);
        $this->ratings->rate($this->user(), $d['subject_type'], $d['subject_id'], (int) $d['stars'], $d['review'] ?? null);

        return response()->json(['data' => $this->ratings->summary($d['subject_type'], $d['subject_id'], $this->user())], 201);
    }

    public function moderateRating(Request $request, ContentRating $rating): JsonResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(['hidden', 'published'])]]);

        return response()->json(['data' => ['status' => $this->ratings->moderate($rating, $d['status'])->status]]);
    }

    /** Reviews waiting for a look (newest first) for the moderators. */
    public function reviews(Request $request): JsonResponse
    {
        $rows = ContentRating::with('user:id,name,name_ar')->whereNotNull('review')->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->latest()->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (ContentRating $r) => ['id' => $r->id, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'stars' => $r->stars, 'review' => $r->review, 'status' => $r->status, 'author' => $r->user?->displayName(), 'created_at' => $r->created_at->toIso8601String()])->values()]);
    }

    // ---- settings ------------------------------------------------------------------------------------

    public function settings(): JsonResponse
    {
        return response()->json(['data' => $this->settings->all()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['trainer_sla_hours' => ['integer', 'between:1,720'], 'daily_digest' => ['boolean'], 'digest_hour' => ['integer', 'between:0,23'], 'rating_reviews' => ['boolean']]);

        return response()->json(['data' => $this->settings->save($d, $this->user()->id)]);
    }
}
