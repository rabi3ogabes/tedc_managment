<?php

namespace App\Services\Content;

use App\Models\ContentPackage;
use App\Models\CourseLesson;
use App\Models\LessonProgress;
use App\Models\OfflineSyncLog;
use App\Models\Registration;
use App\Models\User;
use App\Services\CourseService;
use App\Services\FileStorage;

/** Offline learning: what the app may download, and the idempotent sync of progress made without a connection. */
class OfflineService
{
    public function __construct(private readonly FileStorage $storage, private readonly StandardsSettings $settings, private readonly CourseService $course, private readonly XapiService $xapi) {}

    /** @return array<string, mixed> */
    public function manifest(Registration $r): array
    {
        $days = (int) $this->settings->all()['offline']['expiry_days'];
        $lessons = CourseLesson::where('program_id', $r->program_id)->where('status', 'published')->orderBy('sort_order')->get()->map(function (CourseLesson $l) {
            $item = ['id' => $l->id, 'type' => $l->type, 'title_ar' => $l->title_ar, 'title_en' => $l->title_en, 'version' => $l->version, 'downloadable' => false];
            if (in_array($l->type, [CourseLesson::VIDEO, CourseLesson::PRESENTATION], true) && $l->file_path) {
                return array_replace($item, ['downloadable' => true, 'mime' => $l->file_mime, 'size' => $l->file_size, 'url' => $this->storage->temporaryUrl('materials', $l->file_path, 86400)]);
            }
            if ($l->type === CourseLesson::ARTICLE) {
                return array_replace($item, ['downloadable' => true, 'body_ar' => $l->body_ar, 'body_en' => $l->body_en]);
            }
            $pkg = $l->type === CourseLesson::PACKAGE && $l->package_id ? ContentPackage::find($l->package_id) : null;
            // Only HTML5 and H5P packages run offline; SCORM needs the player's server round trips.
            if ($pkg && in_array($pkg->standard, ['h5p', 'html5'], true)) {
                return array_replace($item, ['downloadable' => true, 'standard' => $pkg->standard, 'size' => $pkg->size, 'base_url' => PackageToken::url($pkg->id, '', null)]);
            }

            return $item + ['reason' => 'requires_connection'];
        })->values()->all();

        return ['registration_id' => $r->id, 'expires_at' => now()->addDays($days)->toIso8601String(), 'expiry_days' => $days, 'lessons' => $lessons];
    }

    /**
     * Applies a batch of offline events once: the same idempotency key returns the stored result. The server never lowers progress.
     *
     * @param  list<array<string, mixed>>  $items  type: video_progress | slide_view | lesson_complete | xapi
     * @return array{applied: int, rejected: list<array<string, mixed>>, replayed: bool}
     */
    public function sync(User $user, string $key, array $items): array
    {
        if ($log = OfflineSyncLog::where('idempotency_key', $key)->first()) {
            return ($log->result ?? []) + ['replayed' => true];
        }
        $applied = 0;
        $rejected = [];
        $employee = $user->employee;
        foreach ($items as $i => $it) {
            $lesson = CourseLesson::find($it['lesson_id'] ?? null);
            $r = $lesson && $employee ? Registration::where('program_id', $lesson->program_id)->where('employee_id', $employee->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->first() : null;
            if (! $lesson || ! $r) {
                $rejected[] = ['index' => $i, 'reason' => 'unknown_lesson'];

                continue;
            }
            try {
                match ($it['type'] ?? '') {
                    'video_progress' => $this->video($lesson, $r, $it),
                    'slide_view' => $this->course->viewSlide($lesson, $r, max(1, (int) ($it['slide'] ?? 1)), max(1, (int) ($it['total'] ?? 1))),
                    'lesson_complete' => $this->complete($lesson, $r),
                    'xapi' => $this->xapi->native($r, $lesson, (string) ($it['verb'] ?? 'http://adlnet.gov/expapi/verbs/experienced'), (string) ($it['verb_name'] ?? 'experienced')),
                    default => throw new \InvalidArgumentException('unknown_type'),
                };
                $applied++;
            } catch (\Throwable $e) {
                $rejected[] = ['index' => $i, 'reason' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'rejected'];
            }
        }
        $result = ['applied' => $applied, 'rejected' => $rejected];
        OfflineSyncLog::create(['idempotency_key' => $key, 'user_id' => $user->id, 'result' => $result]);

        return $result + ['replayed' => false];
    }

    /** Watched stretches merge into the stored segments (the player cannot be timed offline, so the lesson's own watch percentage still decides completion). */
    private function video(CourseLesson $lesson, Registration $r, array $it): void
    {
        $p = LessonProgress::firstOrCreate(['lesson_id' => $lesson->id, 'registration_id' => $r->id], ['employee_id' => $r->employee_id, 'first_opened_at' => now(), 'lesson_version' => $lesson->version]);
        $segments = $p->segments ?? [];
        foreach ((array) ($it['segments'] ?? []) as $s) {
            if (is_array($s) && count($s) === 2 && $s[1] > $s[0]) {
                $segments = $this->course->merge($segments, [(float) $s[0], min((float) $s[1], (float) max($lesson->duration_seconds, (float) $s[1]))]);
            }
        }
        $duration = max(1, (float) $lesson->duration_seconds);
        $percent = min(100.0, round($this->course->covered($segments) / $duration * 100, 2));
        $need = (float) ($lesson->setting('min_watch_percent', 90));
        $p->update(['segments' => $segments, 'percent' => max((float) $p->percent, $percent), 'furthest_position' => max((float) $p->furthest_position, (float) ($it['position'] ?? 0)), 'last_position' => (float) ($it['position'] ?? $p->last_position), 'last_activity_at' => now()]);
        if ($percent >= $need) {
            $this->course->markFromPackage($lesson, $r, true);
        }
    }

    private function complete(CourseLesson $lesson, Registration $r): void
    {
        $this->course->complete($lesson, $r);
    }
}
