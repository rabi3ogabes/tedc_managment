<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\LessonProgress;
use App\Models\Program;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Models\SurveyResponse;
use App\Services\FileStorage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Builds and manages the online course of a program: modules, lessons (video, presentation, quiz, survey, article),
 * their files, questions and watch rules, plus the learning analytics.
 */
class CourseController extends Controller
{
    /** Where lesson media is stored (inside the existing private `materials` bucket). */
    private const BUCKET = 'materials';

    private const VIDEO_MIMES = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v', 'video/ogg'];

    private const SLIDE_MIMES = ['application/pdf', 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/vnd.ms-powerpoint'];

    public function __construct(private readonly FileStorage $storage) {}

    // Whole course ----------------------------------------------------------------------------------------------

    public function show(Program $program): JsonResponse
    {
        $modules = $program->courseModules()->with(['lessons' => fn ($q) => $q->with(['questions', 'surveyQuestions'])])->get();
        $lessons = $modules->flatMap->lessons;

        return response()->json(['data' => [
            'settings' => ['has_course' => $program->has_course, 'sequential' => $program->course_sequential, 'completion_percent' => $program->course_completion_percent, 'auto_certificate' => $program->course_auto_certificate],
            'modules' => $modules->map(fn (CourseModule $m) => [
                'id' => $m->id, 'title_ar' => $m->title_ar, 'title_en' => $m->title_en, 'description_ar' => $m->description_ar, 'description_en' => $m->description_en, 'sort_order' => $m->sort_order,
                'lessons' => $m->lessons->map(fn (CourseLesson $l) => $this->present($l))->values(),
            ])->values(),
            'totals' => [
                'modules' => $modules->count(), 'lessons' => $lessons->count(), 'published' => $lessons->where('status', 'published')->count(),
                'video_seconds' => (int) $lessons->where('type', 'video')->sum('duration_seconds'), 'by_type' => $lessons->countBy('type'),
            ],
        ]]);
    }

    public function updateSettings(Request $request, Program $program): JsonResponse
    {
        $program->update($request->validate([
            'has_course' => ['sometimes', 'boolean'], 'course_sequential' => ['sometimes', 'boolean'], 'course_completion_percent' => ['sometimes', 'integer', 'between:1,100'], 'course_auto_certificate' => ['sometimes', 'boolean'],
        ]));

        return $this->show($program->refresh());
    }

    // Modules ---------------------------------------------------------------------------------------------------

    public function storeModule(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['title_ar' => ['required', 'string', 'max:255'], 'title_en' => ['required', 'string', 'max:255'], 'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000']]);
        $module = $program->courseModules()->create($data + ['sort_order' => (int) $program->courseModules()->max('sort_order') + 1]);
        $program->update(['has_course' => true]);

        return response()->json(['data' => $module], 201);
    }

    public function updateModule(Request $request, CourseModule $module): JsonResponse
    {
        $module->update($request->validate(['title_ar' => ['sometimes', 'string', 'max:255'], 'title_en' => ['sometimes', 'string', 'max:255'], 'description_ar' => ['nullable', 'string', 'max:2000'], 'description_en' => ['nullable', 'string', 'max:2000']]));

        return response()->json(['data' => $module]);
    }

    public function destroyModule(CourseModule $module): JsonResponse
    {
        $module->lessons->each(fn (CourseLesson $l) => $this->forgetFile($l));
        $module->delete();

        return response()->json(['message' => 'ok']);
    }

    /** New order: [{id: moduleId, lessons: [lessonId, ...]}, ...] — lessons can move between modules. */
    public function reorder(Request $request, Program $program): JsonResponse
    {
        $data = $request->validate(['modules' => ['required', 'array'], 'modules.*.id' => ['required', 'uuid'], 'modules.*.lessons' => ['array'], 'modules.*.lessons.*' => ['uuid']]);
        DB::transaction(function () use ($data, $program) {
            foreach ($data['modules'] as $i => $m) {
                CourseModule::where('program_id', $program->id)->where('id', $m['id'])->update(['sort_order' => $i + 1]);
                foreach ($m['lessons'] ?? [] as $j => $lessonId) {
                    CourseLesson::where('program_id', $program->id)->where('id', $lessonId)->update(['module_id' => $m['id'], 'sort_order' => $j + 1]);
                }
            }
        });

        return $this->show($program);
    }

    // Lessons ---------------------------------------------------------------------------------------------------

    public function storeLesson(Request $request, CourseModule $module): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(CourseLesson::TYPES)]] + $this->lessonRules());
        $lesson = $module->lessons()->create($data + [
            'program_id' => $module->program_id, 'sort_order' => (int) $module->lessons()->max('sort_order') + 1,
            'settings' => $this->defaultSettings($data['type']) + ($data['settings'] ?? []),
        ]);
        $module->program->update(['has_course' => true]);

        return response()->json(['data' => $this->present($lesson->load(['questions', 'surveyQuestions']))], 201);
    }

    public function updateLesson(Request $request, CourseLesson $lesson): JsonResponse
    {
        $data = $request->validate($this->lessonRules(true));
        if (isset($data['settings'])) {
            $data['settings'] = array_replace($lesson->settings ?? [], $data['settings']);
        }
        $lesson->update($data);

        return response()->json(['data' => $this->present($lesson->refresh()->load(['questions', 'surveyQuestions']))]);
    }

    public function destroyLesson(CourseLesson $lesson): JsonResponse
    {
        $this->forgetFile($lesson);
        $lesson->delete();

        return response()->json(['message' => 'ok']);
    }

    // Files (uploaded straight to storage; the API only signs and records) --------------------------------------

    public function uploadUrl(Request $request, CourseLesson $lesson): JsonResponse
    {
        $data = $request->validate(['filename' => ['required', 'string', 'max:255'], 'mime' => ['required', 'string', 'max:120'], 'size' => ['required', 'integer', 'min:1', 'max:'.(2 * 1024 ** 3)]]);
        $allowed = match ($lesson->type) {
            CourseLesson::VIDEO => self::VIDEO_MIMES,
            CourseLesson::PRESENTATION => self::SLIDE_MIMES,
            default => throw new BusinessRuleException(__('messages.course.no_file'), 'no_file'),
        };
        if (! in_array($data['mime'], $allowed, true)) {
            throw new BusinessRuleException(__('messages.course.bad_file'), 'bad_file');
        }

        $extension = strtolower(pathinfo($data['filename'], PATHINFO_EXTENSION)) ?: 'bin';
        $path = "course/{$lesson->program_id}/{$lesson->id}/".Str::uuid().'.'.preg_replace('/[^a-z0-9]/', '', $extension);

        return response()->json(['data' => ['path' => $path] + $this->storage->signedUpload(self::BUCKET, $path, $data['mime'])]);
    }

    public function fileComplete(Request $request, CourseLesson $lesson): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:300'], 'name' => ['required', 'string', 'max:255'], 'mime' => ['required', 'string', 'max:120'], 'size' => ['required', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'], 'slide_count' => ['nullable', 'integer', 'min:0', 'max:2000'],
        ]);
        // Only a file this lesson was given a signed address for can be attached to it.
        abort_unless(str_starts_with($data['path'], "course/{$lesson->program_id}/{$lesson->id}/") && ! str_contains($data['path'], '..'), 422);

        if ($lesson->file_path && $lesson->file_path !== $data['path']) {
            $this->forgetFile($lesson);
        }
        $lesson->update([
            'source' => 'upload', 'file_path' => $data['path'], 'file_name' => $data['name'], 'file_mime' => $data['mime'], 'file_size' => $data['size'], 'external_url' => null,
            'duration_seconds' => $data['duration_seconds'] ?? $lesson->duration_seconds, 'slide_count' => $data['slide_count'] ?? $lesson->slide_count,
        ]);

        return response()->json(['data' => $this->present($lesson->refresh())]);
    }

    /** A video or presentation that lives elsewhere (YouTube, Vimeo, a direct file link). */
    public function setLink(Request $request, CourseLesson $lesson): JsonResponse
    {
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:500'], 'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400']]);
        $this->forgetFile($lesson);
        $lesson->update(['source' => 'url', 'external_url' => $data['url'], 'file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => 0, 'duration_seconds' => $data['duration_seconds'] ?? $lesson->duration_seconds]);

        return response()->json(['data' => $this->present($lesson->refresh())]);
    }

    public function removeFile(CourseLesson $lesson): JsonResponse
    {
        $this->forgetFile($lesson);
        $lesson->update(['source' => null, 'file_path' => null, 'file_name' => null, 'file_mime' => null, 'file_size' => 0, 'external_url' => null]);

        return response()->json(['data' => $this->present($lesson->refresh())]);
    }

    // Questions -------------------------------------------------------------------------------------------------

    public function saveQuestions(Request $request, CourseLesson $lesson): JsonResponse
    {
        abort_unless($lesson->type === CourseLesson::QUIZ, 422);
        $data = $request->validate([
            'questions' => ['present', 'array', 'max:200'],
            'questions.*.type' => ['required', Rule::in(['single', 'multiple', 'true_false'])],
            'questions.*.text_ar' => ['required', 'string', 'max:2000'], 'questions.*.text_en' => ['nullable', 'string', 'max:2000'],
            'questions.*.points' => ['nullable', 'numeric', 'between:0,100'],
            'questions.*.explanation_ar' => ['nullable', 'string', 'max:2000'], 'questions.*.explanation_en' => ['nullable', 'string', 'max:2000'],
            'questions.*.options' => ['required', 'array', 'min:2', 'max:10'],
            'questions.*.options.*.id' => ['required', 'string', 'max:40'], 'questions.*.options.*.text_ar' => ['required', 'string', 'max:500'],
            'questions.*.options.*.text_en' => ['nullable', 'string', 'max:500'], 'questions.*.options.*.correct' => ['required', 'boolean'],
        ]);
        foreach ($data['questions'] as $i => $q) {
            $correct = collect($q['options'])->where('correct', true)->count();
            if ($correct < 1 || ($q['type'] !== 'multiple' && $correct !== 1)) {
                throw new BusinessRuleException(__('messages.course.one_correct', ['n' => $i + 1]), 'bad_question');
            }
        }

        DB::transaction(function () use ($lesson, $data) {
            $lesson->questions()->delete();
            foreach ($data['questions'] as $i => $q) {
                $lesson->questions()->create($q + ['points' => $q['points'] ?? 1, 'sort_order' => $i + 1]);
            }
        });

        return response()->json(['data' => $this->present($lesson->refresh()->load('questions'))]);
    }

    public function saveSurveyQuestions(Request $request, CourseLesson $lesson): JsonResponse
    {
        abort_unless($lesson->type === CourseLesson::SURVEY, 422);
        $data = $request->validate([
            'questions' => ['present', 'array', 'max:60'],
            'questions.*.type' => ['required', Rule::in(['rating', 'nps', 'choice', 'multiple', 'text'])],
            'questions.*.text_ar' => ['required', 'string', 'max:1000'], 'questions.*.text_en' => ['nullable', 'string', 'max:1000'], 'questions.*.required' => ['nullable', 'boolean'],
            'questions.*.options' => ['nullable', 'array', 'max:12'], 'questions.*.options.*.id' => ['required', 'string', 'max:40'],
            'questions.*.options.*.text_ar' => ['required', 'string', 'max:500'], 'questions.*.options.*.text_en' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($lesson, $data) {
            $lesson->surveyQuestions()->delete();
            foreach ($data['questions'] as $i => $q) {
                $lesson->surveyQuestions()->create($q + ['required' => $q['required'] ?? true, 'sort_order' => $i + 1]);
            }
        });

        return response()->json(['data' => $this->present($lesson->refresh()->load('surveyQuestions'))]);
    }

    // Analytics -------------------------------------------------------------------------------------------------

    public function analytics(Program $program): JsonResponse
    {
        $lessons = $program->courseLessons()->where('status', 'published')->with(['questions', 'surveyQuestions'])->get();
        $registrations = Registration::with('employee.user:id,name,name_ar')->where('program_id', $program->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->get();
        $progress = LessonProgress::whereIn('lesson_id', $lessons->pluck('id'))->get()->groupBy('lesson_id');
        $attempts = QuizAttempt::whereIn('lesson_id', $lessons->pluck('id'))->get()->groupBy('lesson_id');
        $surveys = SurveyResponse::whereIn('lesson_id', $lessons->pluck('id'))->get()->groupBy('lesson_id');
        $learners = $registrations->count();

        $rows = $lessons->map(function (CourseLesson $l) use ($progress, $attempts, $surveys, $learners) {
            $p = $progress->get($l->id, collect());
            $row = [
                'id' => $l->id, 'type' => $l->type, 'title' => $l->translate('title'), 'is_required' => $l->is_required, 'duration_seconds' => $l->duration_seconds,
                'started' => $p->count(), 'completed' => $p->where('status', 'completed')->count(), 'completion_rate' => $learners ? round($p->where('status', 'completed')->count() / $learners * 100, 1) : 0,
                'avg_percent' => $p->count() ? round($p->avg('percent'), 1) : 0, 'avg_minutes' => $p->count() ? round($p->avg('watched_seconds') / 60, 1) : 0,
            ];
            if ($l->type === CourseLesson::VIDEO && $l->duration_seconds > 0) {
                $row['retention'] = $this->retention($p, $l->duration_seconds);
            }
            if ($l->type === CourseLesson::QUIZ) {
                $a = $attempts->get($l->id, collect());
                $row['quiz'] = [
                    'attempts' => $a->count(), 'avg_score' => $a->count() ? round($a->avg('score_percent'), 1) : null, 'pass_rate' => $a->count() ? round($a->where('passed', true)->count() / $a->count() * 100, 1) : null,
                    // How hard each question is: the share of attempts that got it right.
                    'questions' => $l->questions->map(fn ($q) => [
                        'id' => $q->id, 'text' => $q->translate('text'),
                        'correct_rate' => $a->count() ? round($a->filter(function ($x) use ($q) {
                            $correct = collect($q->options)->where('correct', true)->pluck('id')->sort()->values()->all();

                            return collect($x->answers[$q->id] ?? [])->map('strval')->sort()->values()->all() === $correct;
                        })->count() / $a->count() * 100, 1) : null,
                    ])->values(),
                ];
            }
            if ($l->type === CourseLesson::SURVEY) {
                $r = $surveys->get($l->id, collect());
                $row['survey'] = ['responses' => $r->count(), 'questions' => $l->surveyQuestions->map(fn ($q) => $this->surveyStats($q, $r))->values()];
            }

            return $row;
        })->values();

        $people = $registrations->map(function (Registration $r) {
            $last = LessonProgress::where('registration_id', $r->id)->max('last_activity_at');

            return ['registration_id' => $r->id, 'name' => $r->employee->user->displayName(), 'percent' => (float) $r->course_percent, 'completed' => $r->course_completed, 'last_activity_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
                'watch_minutes' => round(LessonProgress::where('registration_id', $r->id)->sum('watched_seconds') / 60, 1)];
        })->sortBy('percent')->values();

        return response()->json(['data' => [
            'summary' => [
                'learners' => $learners, 'started' => $people->where('percent', '>', 0)->count(), 'completed' => $registrations->where('course_completed', true)->count(),
                'avg_percent' => $learners ? round($registrations->avg('course_percent'), 1) : 0, 'watch_hours' => round(LessonProgress::whereIn('lesson_id', $lessons->pluck('id'))->sum('watched_seconds') / 3600, 1),
                'stalled' => $people->filter(fn ($p) => ! $p['completed'] && $p['last_activity_at'] && now()->diffInDays($p['last_activity_at']) >= 5)->count(),
            ],
            'lessons' => $rows, 'learners' => $people,
        ]]);
    }

    // -----------------------------------------------------------------------------------------------------------

    /** Share of learners who saw each tenth of the video — shows where people drop off. */
    private function retention($progress, int $duration): array
    {
        $buckets = [];
        $total = max(1, $progress->count());
        for ($i = 0; $i < 20; $i++) {
            $from = $duration * $i / 20;
            $to = $duration * ($i + 1) / 20;
            $mid = ($from + $to) / 2;
            $buckets[] = round($progress->filter(fn ($p) => collect($p->segments ?? [])->contains(fn ($s) => $s[0] <= $mid && $s[1] >= $mid))->count() / $total * 100);
        }

        return $buckets;
    }

    private function surveyStats($q, $responses): array
    {
        $values = $responses->map(fn ($r) => $r->answers[$q->id] ?? null)->filter(fn ($v) => $v !== null && $v !== '' && $v !== []);
        $stat = ['id' => $q->id, 'type' => $q->type, 'text' => $q->translate('text'), 'answered' => $values->count()];
        if (in_array($q->type, ['rating', 'nps'], true)) {
            $stat['average'] = $values->count() ? round($values->avg(), 2) : null;
            $stat['distribution'] = $values->countBy()->sortKeys();
        } elseif (in_array($q->type, ['choice', 'multiple'], true)) {
            $counts = $values->flatten()->countBy();
            $stat['options'] = collect($q->options)->map(fn ($o) => ['id' => $o['id'], 'text' => $o['text_'.(app()->getLocale() === 'en' ? 'en' : 'ar')] ?? $o['text_ar'], 'count' => $counts[$o['id']] ?? 0])->values();
        } else {
            $stat['texts'] = $values->take(30)->values();
        }

        return $stat;
    }

    private function present(CourseLesson $l): array
    {
        return [
            'id' => $l->id, 'module_id' => $l->module_id, 'type' => $l->type, 'title_ar' => $l->title_ar, 'title_en' => $l->title_en, 'description_ar' => $l->description_ar, 'description_en' => $l->description_en,
            'body_ar' => $l->body_ar, 'body_en' => $l->body_en, 'sort_order' => $l->sort_order, 'is_required' => $l->is_required, 'status' => $l->status, 'duration_seconds' => $l->duration_seconds,
            'source' => $l->source, 'file_name' => $l->file_name, 'file_mime' => $l->file_mime, 'file_size' => $l->file_size, 'external_url' => $l->external_url, 'slide_count' => $l->slide_count,
            'has_file' => (bool) ($l->file_path || $l->external_url), 'settings' => $l->settings ?? [],
            'questions' => $l->relationLoaded('questions') ? $l->questions->map(fn ($q) => $q->only(['id', 'type', 'text_ar', 'text_en', 'options', 'points', 'explanation_ar', 'explanation_en']))->values() : [],
            'survey_questions' => $l->relationLoaded('surveyQuestions') ? $l->surveyQuestions->map(fn ($q) => $q->only(['id', 'type', 'text_ar', 'text_en', 'options', 'required']))->values() : [],
        ];
    }

    /** @return array<string, mixed> */
    private function lessonRules(bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'title_ar' => [$req, 'string', 'max:255'], 'title_en' => [$req, 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:3000'], 'description_en' => ['nullable', 'string', 'max:3000'],
            'body_ar' => ['nullable', 'string', 'max:100000'], 'body_en' => ['nullable', 'string', 'max:100000'],
            'is_required' => ['sometimes', 'boolean'], 'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'duration_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'settings' => ['nullable', 'array'],
            'settings.allow_seeking' => ['sometimes', 'boolean'], 'settings.min_watch_percent' => ['sometimes', 'integer', 'between:1,100'],
            'settings.max_speed' => ['sometimes', 'numeric', 'between:1,3'], 'settings.pause_when_hidden' => ['sometimes', 'boolean'],
            'settings.min_view_percent' => ['sometimes', 'integer', 'between:1,100'], 'settings.downloadable' => ['sometimes', 'boolean'],
            'settings.pass_percent' => ['sometimes', 'integer', 'between:1,100'], 'settings.max_attempts' => ['nullable', 'integer', 'between:1,50'],
            'settings.shuffle_questions' => ['sometimes', 'boolean'], 'settings.shuffle_options' => ['sometimes', 'boolean'],
            'settings.show_answers' => ['sometimes', Rule::in(['after_submit', 'after_pass', 'never'])], 'settings.time_limit_minutes' => ['nullable', 'integer', 'between:1,300'],
        ];
    }

    /** @return array<string, mixed> */
    private function defaultSettings(string $type): array
    {
        return match ($type) {
            CourseLesson::VIDEO => ['allow_seeking' => true, 'min_watch_percent' => 90, 'max_speed' => 2, 'pause_when_hidden' => true],
            CourseLesson::PRESENTATION => ['min_view_percent' => 80, 'downloadable' => false],
            CourseLesson::QUIZ => ['pass_percent' => 70, 'max_attempts' => null, 'shuffle_questions' => true, 'shuffle_options' => true, 'show_answers' => 'after_submit'],
            default => [],
        };
    }

    private function forgetFile(CourseLesson $lesson): void
    {
        if ($lesson->file_path) {
            try {
                $this->storage->delete(self::BUCKET, $lesson->file_path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
