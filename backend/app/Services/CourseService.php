<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\CourseLesson;
use App\Models\LessonProgress;
use App\Models\Program;
use App\Models\QuizAttempt;
use App\Models\Registration;
use App\Models\SurveyResponse;
use Illuminate\Support\Collection;

/**
 * The learner side of an online course: the outline with locks, trustworthy video-watch tracking, presentation
 * views, quizzes graded on the server, surveys, and the course completion that unlocks the certificate.
 */
class CourseService
{
    /** Longest stretch of video a single heartbeat can claim, and the slack allowed for network jitter. */
    private const MAX_BEAT_SECONDS = 45;

    private const JITTER_SECONDS = 4;

    public function __construct(private readonly NotificationService $notifications) {}

    // Outline ---------------------------------------------------------------------------------------------------

    /** Published lessons in course order, with the learner's progress and whether each is still locked. */
    public function outline(Program $program, Registration $registration): array
    {
        $modules = $program->courseModules()->with(['lessons' => fn ($q) => $q->where('status', 'published')])->get();
        $progress = LessonProgress::where('registration_id', $registration->id)->get()->keyBy('lesson_id');

        $blocked = false;
        $required = 0;
        $done = 0;
        $out = [];

        foreach ($modules as $module) {
            $lessons = [];
            foreach ($module->lessons as $lesson) {
                $p = $progress->get($lesson->id);
                $completed = $p?->status === 'completed';
                $locked = $program->course_sequential && $blocked;
                if ($lesson->is_required) {
                    $required++;
                    $done += $completed ? 1 : 0;
                    // The next required lesson stays locked until this one is done.
                    $blocked = $blocked || ! $completed;
                }
                $lessons[] = [
                    'id' => $lesson->id, 'type' => $lesson->type, 'title' => $lesson->translate('title'), 'description' => $lesson->translate('description'),
                    'duration_seconds' => $lesson->duration_seconds, 'is_required' => $lesson->is_required, 'locked' => $locked,
                    'status' => $completed ? 'completed' : ($p ? 'in_progress' : 'not_started'), 'percent' => (float) ($p?->percent ?? 0),
                    'score' => $p?->best_score, 'last_activity_at' => $p?->last_activity_at?->toIso8601String(),
                ];
            }
            if ($lessons) {
                $out[] = ['id' => $module->id, 'title' => $module->translate('title'), 'description' => $module->translate('description'), 'lessons' => $lessons];
            }
        }

        $flat = collect($out)->flatMap(fn ($m) => $m['lessons']);
        $next = $flat->first(fn ($l) => ! $l['locked'] && $l['status'] !== 'completed');

        return [
            'modules' => $out,
            'summary' => [
                'lessons' => $flat->count(), 'required' => $required, 'completed' => $done,
                'percent' => $required ? round($done / $required * 100, 1) : 0.0,
                'completed_course' => (bool) $registration->course_completed,
                'required_percent' => $program->course_completion_percent,
                'sequential' => $program->course_sequential,
                'duration_seconds' => (int) $flat->sum('duration_seconds'),
                'next_lesson_id' => $next['id'] ?? null,
                'certificate' => $this->certificateInfo($registration),
                'resume_lesson_id' => $flat->sortByDesc('last_activity_at')->first(fn ($l) => $l['last_activity_at'] && $l['status'] !== 'completed' && ! $l['locked'])['id'] ?? null,
            ],
        ];
    }

    /** Where the learner stands on the certificate: issued, ready, or what is still missing. */
    private function certificateInfo(Registration $registration): array
    {
        $certificates = app(CertificateService::class);
        $issued = $registration->certificate()->first();
        $check = $certificates->requirements($registration);

        return [
            'issued' => (bool) $issued, 'downloadable' => $issued ? $certificates->downloadable($issued) : false, 'eligible' => $check['eligible'],
            'missing' => collect($check['checks'])->where('passed', false)->pluck('label')->values(),
        ];
    }

    public function assertOpen(CourseLesson $lesson, Registration $registration): void
    {
        if ($lesson->status !== 'published' || ! $lesson->program->has_course) {
            abort(404);
        }
        if (! $lesson->program->course_sequential) {
            return;
        }
        $order = $this->orderedLessons($lesson->program)->pluck('id')->all();
        $done = LessonProgress::where('registration_id', $registration->id)->where('status', 'completed')->pluck('lesson_id')->all();
        foreach ($this->orderedLessons($lesson->program) as $earlier) {
            if ($earlier->id === $lesson->id) {
                return;
            }
            if ($earlier->is_required && ! in_array($earlier->id, $done, true)) {
                throw new BusinessRuleException(__('messages.course.locked'), 'lesson_locked', ['blocked_by' => $earlier->id, 'position' => array_search($lesson->id, $order, true)]);
            }
        }
    }

    /** @return Collection<int, CourseLesson> */
    private function orderedLessons(Program $program): Collection
    {
        return $program->courseModules()->with(['lessons' => fn ($q) => $q->where('status', 'published')])->get()->flatMap->lessons->values();
    }

    public function open(CourseLesson $lesson, Registration $registration): LessonProgress
    {
        $p = LessonProgress::firstOrCreate(['lesson_id' => $lesson->id, 'registration_id' => $registration->id], ['employee_id' => $registration->employee_id, 'first_opened_at' => now()]);
        $p->increment('sessions');
        $p->update(['last_activity_at' => now()]);

        return $p->refresh();
    }

    // Video -----------------------------------------------------------------------------------------------------

    /**
     * Records a stretch of video the learner watched. The server credits only what wall-clock time allows (so a
     * script cannot claim a whole video in a second), and, when seeking is switched off, only continuous watching.
     *
     * @return array{percent: float, status: string, position: float, furthest: float, completed: bool, credited: float}
     */
    public function heartbeat(CourseLesson $lesson, Registration $registration, float $from, float $to, ?float $duration = null, float $rate = 1.0): array
    {
        $p = $this->progressFor($lesson, $registration);
        if ($lesson->duration_seconds <= 0 && $duration && $duration > 1 && $duration < 86400) {
            $lesson->update(['duration_seconds' => (int) round($duration)]);
        }
        $length = max(1, (int) $lesson->duration_seconds);
        $maxSpeed = (float) $lesson->setting('max_speed', 2.0);

        $from = max(0, min($from, $length));
        $to = max($from, min($to, $length));
        $span = min($to - $from, self::MAX_BEAT_SECONDS);
        $elapsed = $p->last_activity_at ? max(0, $p->last_activity_at->diffInSeconds(now(), true)) : self::MAX_BEAT_SECONDS;
        $allowed = min($elapsed, self::MAX_BEAT_SECONDS) * min(max($rate, 0.25), $maxSpeed) + self::JITTER_SECONDS;
        $credited = min($span, $allowed);

        // With seeking off, a stretch that starts beyond what was already reached is a skip: it earns nothing.
        $skipped = ! $lesson->setting('allow_seeking', true) && $from > $p->furthest_position + self::JITTER_SECONDS * 2;
        if ($skipped) {
            $credited = 0;
        }

        $segments = $p->segments ?? [];
        if ($credited > 0) {
            $segments = $this->merge($segments, [round($from, 1), round($from + $credited, 1)]);
        }
        $watched = (int) round($this->covered($segments));
        $percent = min(100, round($watched / $length * 100, 2));
        $complete = $percent >= (float) $lesson->setting('min_watch_percent', 90);

        // Only what was credited counts as reached; a skip sends the player back to where the learner really got to.
        $reached = $from + $credited;
        $position = $skipped ? min($p->furthest_position, $length) : $reached;
        $p->fill([
            'segments' => $segments, 'watched_seconds' => $watched, 'percent' => max($p->percent, $percent), 'last_position' => $position,
            'furthest_position' => max($p->furthest_position, $skipped ? 0 : $reached), 'last_activity_at' => now(),
        ]);
        if ($complete && $p->status !== 'completed') {
            $p->fill(['status' => 'completed', 'completed_at' => now(), 'percent' => max($p->percent, $percent)]);
        }
        $p->save();
        $this->recompute($registration);

        return ['percent' => (float) $p->percent, 'status' => $p->status, 'position' => (float) $p->last_position, 'furthest' => (float) $p->furthest_position, 'completed' => $p->status === 'completed', 'credited' => round($credited, 1)];
    }

    // Presentations & articles ----------------------------------------------------------------------------------

    /** A slide was shown. Progress is the share of distinct slides seen. */
    public function viewSlide(CourseLesson $lesson, Registration $registration, int $slide, int $total): array
    {
        $p = $this->progressFor($lesson, $registration);
        $total = max(1, min($total, 2000));
        if ($lesson->slide_count !== $total) {
            $lesson->update(['slide_count' => $total]);
        }
        $slide = max(1, min($slide, $total));
        $segments = $this->merge($p->segments ?? [], [$slide, $slide]);
        $seen = (int) $this->covered($segments, 1);
        $percent = min(100, round($seen / $total * 100, 2));

        $p->fill(['segments' => $segments, 'percent' => max($p->percent, $percent), 'last_position' => $slide, 'furthest_position' => max($p->furthest_position, $slide), 'last_activity_at' => now()]);
        if ($percent >= (float) $lesson->setting('min_view_percent', 80) && $p->status !== 'completed') {
            $p->fill(['status' => 'completed', 'completed_at' => now()]);
        }
        $p->save();
        $this->recompute($registration);

        return ['percent' => (float) $p->percent, 'status' => $p->status, 'completed' => $p->status === 'completed'];
    }

    /** "Mark as done" for articles and for presentations that cannot be shown in the page (a downloaded file). */
    public function complete(CourseLesson $lesson, Registration $registration): array
    {
        if (! in_array($lesson->type, [CourseLesson::ARTICLE, CourseLesson::PRESENTATION], true)) {
            throw new BusinessRuleException(__('messages.course.cannot_complete'), 'cannot_complete');
        }
        $p = $this->progressFor($lesson, $registration);
        $p->fill(['status' => 'completed', 'percent' => 100, 'completed_at' => $p->completed_at ?? now(), 'last_activity_at' => now()])->save();
        $this->recompute($registration);

        return ['percent' => 100.0, 'status' => 'completed', 'completed' => true];
    }

    // Quiz ------------------------------------------------------------------------------------------------------

    /**
     * Grades a quiz on the server. `answers` maps a question id to the chosen option ids; a question is right only
     * when the chosen set equals the correct set.
     *
     * @param  array<string, array<int, string>>  $answers
     */
    public function submitQuiz(CourseLesson $lesson, Registration $registration, array $answers, int $seconds = 0): array
    {
        $p = $this->progressFor($lesson, $registration);
        $max = $lesson->setting('max_attempts');
        if ($max && $p->attempts >= (int) $max) {
            throw new BusinessRuleException(__('messages.course.no_attempts'), 'no_attempts');
        }
        $questions = $lesson->questions;
        if ($questions->isEmpty()) {
            throw new BusinessRuleException(__('messages.course.empty_quiz'), 'empty_quiz');
        }

        $total = (float) $questions->sum('points');
        $earned = 0.0;
        $review = [];
        foreach ($questions as $q) {
            $correct = collect($q->options)->where('correct', true)->pluck('id')->sort()->values()->all();
            $given = collect($answers[$q->id] ?? [])->map('strval')->unique()->sort()->values()->all();
            $right = $given === $correct && $correct !== [];
            $earned += $right ? (float) $q->points : 0;
            $review[$q->id] = ['correct' => $right, 'correct_options' => $correct, 'chosen' => $given, 'explanation' => $q->translate('explanation')];
        }

        $percent = $total > 0 ? round($earned / $total * 100, 2) : 0.0;
        $passed = $percent >= (float) $lesson->setting('pass_percent', 70);
        QuizAttempt::create(['lesson_id' => $lesson->id, 'registration_id' => $registration->id, 'answers' => $answers, 'score_percent' => $percent, 'passed' => $passed, 'duration_seconds' => $seconds, 'started_at' => now()->subSeconds($seconds), 'submitted_at' => now()]);

        $p->attempts++;
        $p->fill(['best_score' => max((float) $p->best_score, $percent), 'percent' => max($p->percent, $percent), 'last_activity_at' => now()]);
        if ($passed && $p->status !== 'completed') {
            $p->fill(['status' => 'completed', 'completed_at' => now(), 'percent' => 100]);
        }
        $p->save();
        $this->recompute($registration);

        $left = $max ? max(0, (int) $max - $p->attempts) : null;
        $reveal = match ($lesson->setting('show_answers', 'after_submit')) {
            'never' => false,
            'after_pass' => $passed || $left === 0,
            default => true,
        };

        return [
            'score_percent' => $percent, 'points' => $earned, 'total_points' => $total, 'passed' => $passed, 'pass_percent' => (float) $lesson->setting('pass_percent', 70),
            'attempts' => $p->attempts, 'attempts_left' => $left, 'status' => $p->status,
            'review' => $reveal ? $review : collect($review)->map(fn ($r) => ['correct' => $r['correct']])->all(),
        ];
    }

    // Survey ----------------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $answers */
    public function submitSurvey(CourseLesson $lesson, Registration $registration, array $answers): array
    {
        foreach ($lesson->surveyQuestions as $q) {
            $a = $answers[$q->id] ?? null;
            if ($q->required && ($a === null || $a === '' || $a === [])) {
                throw new BusinessRuleException(__('messages.course.survey_required'), 'survey_required', ['question_id' => $q->id]);
            }
        }
        $clean = collect($lesson->surveyQuestions)->mapWithKeys(fn ($q) => [$q->id => $answers[$q->id] ?? null])->filter(fn ($v) => $v !== null && $v !== '')->all();
        SurveyResponse::updateOrCreate(['lesson_id' => $lesson->id, 'registration_id' => $registration->id], ['answers' => $clean, 'submitted_at' => now()]);

        $p = $this->progressFor($lesson, $registration);
        $p->fill(['status' => 'completed', 'percent' => 100, 'completed_at' => $p->completed_at ?? now(), 'last_activity_at' => now()])->save();
        $this->recompute($registration);

        return ['status' => 'completed', 'percent' => 100.0, 'completed' => true];
    }

    // Completion ------------------------------------------------------------------------------------------------

    /** Updates the course percentage of the registration; completing the course tells the learner and re-checks the certificate. */
    public function recompute(Registration $registration): Registration
    {
        $program = $registration->program()->first();
        $required = CourseLesson::where('program_id', $program->id)->where('status', 'published')->where('is_required', true)->pluck('id');
        $done = $required->isEmpty() ? 0 : LessonProgress::where('registration_id', $registration->id)->whereIn('lesson_id', $required)->where('status', 'completed')->count();
        $percent = $required->isEmpty() ? 0.0 : round($done / $required->count() * 100, 2);
        $completed = $required->isNotEmpty() && $percent >= $program->course_completion_percent;
        $was = (bool) $registration->course_completed;

        $registration->update(['course_percent' => $percent, 'course_completed' => $completed]);

        if ($completed && ! $was && $registration->employee?->user_id) {
            $this->notifications->send(
                $registration->employee->user_id, 'course.completed',
                ['ar' => 'أتممت المحتوى التدريبي 🎉', 'en' => 'You completed the course 🎉'],
                ['ar' => "أنهيت كل دروس برنامج «{$program->title_ar}».", 'en' => "You finished every lesson of \"{$program->title_en}\"."],
                ['registration_id' => $registration->id, 'program_id' => $program->id, 'route' => '/courses/'.$registration->id],
            );
        }
        $certificates = app(CertificateService::class);
        $certificates->refreshStatus($registration->refresh());

        // Completing the course (with every other requirement met) issues the certificate by itself.
        if ($completed && $program->course_auto_certificate && ! $registration->certificate()->exists()) {
            try {
                $certificates->issue($registration->load('program', 'employee.user'));
            } catch (BusinessRuleException) {
                // Something else is still missing (a task, the survey ...): it is issued once that is done.
            }
        }

        return $registration;
    }

    private function progressFor(CourseLesson $lesson, Registration $registration): LessonProgress
    {
        return LessonProgress::firstOrCreate(['lesson_id' => $lesson->id, 'registration_id' => $registration->id], ['employee_id' => $registration->employee_id, 'first_opened_at' => now()]);
    }

    // Ranges ----------------------------------------------------------------------------------------------------

    /**
     * @param  list<array{0: float|int, 1: float|int}>  $segments
     * @param  array{0: float|int, 1: float|int}  $add
     * @return list<array{0: float|int, 1: float|int}>
     */
    public function merge(array $segments, array $add): array
    {
        $segments[] = $add;
        usort($segments, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($segments as $s) {
            if ($out && $s[0] <= $out[count($out) - 1][1] + 0.5) {
                $out[count($out) - 1][1] = max($out[count($out) - 1][1], $s[1]);
            } else {
                $out[] = [$s[0], $s[1]];
            }
        }

        return $out;
    }

    /** Total length covered by the ranges (`$unit` 1 counts integer slides inclusively). */
    public function covered(array $segments, float $unit = 0): float
    {
        return array_sum(array_map(fn ($s) => $s[1] - $s[0] + $unit, $segments));
    }
}
