<?php

namespace App\Services\Content;

use App\Exceptions\BusinessRuleException;
use App\Models\ContentPackage;
use App\Models\CourseLesson;
use App\Models\Registration;
use App\Models\ScormAttempt;
use App\Services\CourseService;
use Illuminate\Support\Arr;

/** SCORM 1.2 and 2004 runtime data: the player (scorm-again) commits the CMI tree, the server derives completion, score and resume data. */
class ScormService
{
    public function __construct(private readonly CourseService $course, private readonly XapiService $xapi, private readonly CaliperService $caliper) {}

    /** The open attempt (resume with suspend data) or a new one. @return array<string, mixed> */
    public function start(CourseLesson $lesson, Registration $registration, ?string $itemId = null): array
    {
        $package = $this->packageOf($lesson);
        abort_unless(in_array($package->standard, ['scorm12', 'scorm2004'], true), 422);
        $this->course->assertOpen($lesson, $registration);
        $this->course->open($lesson, $registration);

        $attempt = ScormAttempt::where('registration_id', $registration->id)->where('lesson_id', $lesson->id)->orderByDesc('attempt_no')->first();
        // An unfinished attempt resumes (with its suspend data); a finished one starts a new attempt (a retake).
        if (! $attempt || $attempt->completion_status === 'completed') {
            $attempt = ScormAttempt::create(['registration_id' => $registration->id, 'lesson_id' => $lesson->id, 'package_id' => $package->id, 'attempt_no' => (int) ($attempt?->attempt_no ?? 0) + 1, 'started_at' => now(), 'completion_status' => 'incomplete']);
        }

        $item = collect($package->entry_points)->firstWhere('id', $itemId ?? $lesson->package_item_id) ?? collect($package->entry_points)->first(fn ($e) => ! empty($e['href']));
        $this->caliper->emit('ToolUseEvent', $registration, $lesson, 'Used', ['package' => $package->title]);
        $this->xapi->native($registration, $lesson, 'http://adlnet.gov/expapi/verbs/launched', 'launched');

        return ['attempt_id' => $attempt->id, 'standard' => $package->standard, 'entry' => $item, 'launch_url' => PackageToken::url($package->id, $item['href'] ?? '', $registration->employee?->user_id), 'cmi' => $attempt->cmi ?? [], 'suspend_data' => $attempt->suspend_data, 'location' => $attempt->location, 'completion_status' => $attempt->completion_status, 'score_raw' => $attempt->score_raw];
    }

    /** @param  array<string, mixed>  $cmi  the committed CMI tree (nested) */
    public function commit(ScormAttempt $attempt, array $cmi): ScormAttempt
    {
        $attempt->loadMissing('package');
        $is2004 = $this->is2004($attempt, $cmi);
        $get = fn (string $a, string $b) => Arr::get($cmi, $is2004 ? $a : $b);

        $completion = $is2004 ? (string) $get('completion_status', '') : (string) $get('', 'core.lesson_status');
        $success = $is2004 ? (string) $get('success_status', '') : '';
        if (! $is2004) {
            // 1.2 has one lesson_status: passed / failed / completed / incomplete / browsed / not attempted.
            $status = $completion;
            $completion = in_array($status, ['completed', 'passed', 'failed'], true) ? 'completed' : (in_array($status, ['incomplete', 'browsed'], true) ? 'incomplete' : 'not attempted');
            $success = $status === 'passed' ? 'passed' : ($status === 'failed' ? 'failed' : 'unknown');
        }
        $raw = $get('score.raw', 'core.score.raw');
        $min = $get('score.min', 'core.score.min');
        $max = $get('score.max', 'core.score.max');
        $scaled = $is2004 ? $get('score.scaled', '') : null;
        if ($scaled === null && is_numeric($raw)) {
            $span = (is_numeric($max) ? (float) $max : 100.0) - (is_numeric($min) ? (float) $min : 0.0);
            $scaled = $span > 0 ? ((float) $raw - (is_numeric($min) ? (float) $min : 0.0)) / $span : null;
        }

        $attempt->update([
            'cmi' => $cmi, 'completion_status' => $completion ?: $attempt->completion_status, 'success_status' => $success ?: $attempt->success_status, 'score_raw' => is_numeric($raw) ? $raw : null, 'score_min' => is_numeric($min) ? $min : null, 'score_max' => is_numeric($max) ? $max : null,
            'score_scaled' => is_numeric($scaled) ? max(-1, min(1, (float) $scaled)) : null, 'suspend_data' => $get('suspend_data', 'suspend_data') ?? $attempt->suspend_data, 'location' => $get('location', 'core.lesson_location') ?? $attempt->location,
            'total_time' => $this->seconds((string) ($get('total_time', 'core.total_time') ?? '')) ?: $attempt->total_time, 'committed_at' => now(),
        ]);

        $this->sync($attempt->fresh());

        return $attempt->fresh();
    }

    /** Maps the attempt onto the lesson: completed (and passed, when the lesson asks for it) → the lesson is done. */
    private function sync(ScormAttempt $a): void
    {
        $a->loadMissing('registration.employee.user');
        $lesson = CourseLesson::findOrFail($a->lesson_id);
        $needsPass = (bool) $lesson->setting('require_pass', false);
        $done = ($a->completion_status === 'completed' || $a->success_status === 'passed') && (! $needsPass || $a->success_status === 'passed') && $a->success_status !== 'failed';
        $was = \App\Models\LessonProgress::where('lesson_id', $lesson->id)->where('registration_id', $a->registration_id)->value('status');
        $progress = $a->cmi['progress_measure'] ?? null;
        $this->course->markFromPackage($lesson, $a->registration, $done, is_numeric($progress) ? (float) $progress * 100 : null, $a->score_scaled !== null ? round((float) $a->score_scaled * 100, 2) : null);

        if ($done && $was !== 'completed') {
            $this->xapi->native($a->registration, $lesson, 'http://adlnet.gov/expapi/verbs/completed', 'completed', $a->score_scaled !== null ? ['result' => ['completion' => true, 'score' => ['scaled' => (float) $a->score_scaled]]] : ['result' => ['completion' => true]]);
            $this->caliper->emit('GradeEvent', $a->registration, $lesson, 'Graded', ['score' => $a->score_scaled]);
        }
    }

    public function finish(ScormAttempt $attempt): ScormAttempt
    {
        $attempt->update(['committed_at' => now()]);

        return $attempt;
    }

    private function packageOf(CourseLesson $lesson): ContentPackage
    {
        if ($lesson->type !== CourseLesson::PACKAGE || ! $lesson->package_id) {
            throw new BusinessRuleException(__('messages.package.no_package'), 'no_package');
        }

        return ContentPackage::where('status', 'ready')->findOrFail($lesson->package_id);
    }

    private function is2004(ScormAttempt $a, array $cmi): bool
    {
        return $a->package?->standard === 'scorm2004' || isset($cmi['completion_status']) || isset($cmi['success_status']);
    }

    /** SCORM time: "hh:mm:ss.ss" (1.2) or ISO 8601 "PT1H2M3S" (2004). */
    public function seconds(string $t): int
    {
        if ($t === '') {
            return 0;
        }
        if (preg_match('/^(\d+):(\d{2}):(\d{2})(?:\.\d+)?$/', $t, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }
        if (preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $t, $m)) {
            return (int) ($m[1] ?? 0) * 86400 + (int) ($m[2] ?? 0) * 3600 + (int) ($m[3] ?? 0) * 60 + (int) round((float) ($m[4] ?? 0));
        }

        return 0;
    }
}
