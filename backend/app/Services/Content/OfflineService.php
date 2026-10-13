<?php

namespace App\Services\Content;

use App\Ai\Adaptive\RemedialAssignments;
use App\Models\Assessment;
use App\Models\ContentPackage;
use App\Models\CourseLesson;
use App\Models\LessonProgress;
use App\Models\OfflineDevice;
use App\Models\OfflineSyncLog;
use App\Models\Registration;
use App\Models\User;
use App\Services\CourseService;
use App\Services\FileStorage;
use Illuminate\Support\Carbon;

/** Offline learning (TYP-12): what the app may download, the devices that keep it, and the idempotent sync of progress made without a connection. */
class OfflineService
{
    public function __construct(private readonly FileStorage $storage, private readonly StandardsSettings $settings, private readonly CourseService $course, private readonly XapiService $xapi) {}

    /**
     * What the app may keep offline for one registration (manifest v2). Each file carries its size, a SHA-256 checksum
     * and an expiry; the device gets its own content key (stored encrypted, sent only to that device) to encrypt what
     * it downloads. Items the author did not allow to download are listed without a link. A withdrawn registration or a
     * revoked device gets a delete instruction instead.
     *
     * @return array<string, mixed>
     */
    public function manifest(Registration $r, ?string $deviceId = null, ?string $platform = null): array
    {
        $days = (int) $this->settings->all()['offline']['expiry_days'];
        $expires = now()->addDays($days)->toIso8601String();
        $device = $deviceId ? $this->device($r->employee->user_id, $deviceId, $platform) : null;
        $active = in_array($r->status, [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED], true);
        $base = ['manifest_version' => 2, 'registration_id' => $r->id, 'expires_at' => $expires, 'expiry_days' => $days];
        if (! $active || $device?->revoked()) {
            return $base + ['delete' => true, 'revoked' => true, 'content_key' => null, 'lessons' => [], 'assessments' => []];
        }

        $assigned = app(RemedialAssignments::class)->open($r);
        $lessons = CourseLesson::where('program_id', $r->program_id)->where('status', 'published')->orderBy('sort_order')->get()
            ->filter(fn (CourseLesson $l) => ! $l->isRemedial() || $assigned->has($l->id))->values()->map(function (CourseLesson $l) use ($expires) {
                $item = ['id' => $l->id, 'type' => $l->type, 'title_ar' => $l->title_ar, 'title_en' => $l->title_en, 'version' => $l->version, 'downloadable' => false];
                if ($l->type === CourseLesson::ARTICLE) {
                    return array_replace($item, ['downloadable' => true, 'body_ar' => $l->body_ar, 'body_en' => $l->body_en, 'expires_at' => $expires]);
                }
                if (in_array($l->type, [CourseLesson::VIDEO, CourseLesson::PRESENTATION, CourseLesson::PACKAGE], true) && $l->setting('downloadable', $l->type !== CourseLesson::PRESENTATION) === false) {
                    return $item + ['reason' => 'not_downloadable'];
                }
                if (in_array($l->type, [CourseLesson::VIDEO, CourseLesson::PRESENTATION], true) && $l->file_path) {
                    $sha = $this->checksum($l);
                    if ($sha === null) {
                        return $item + ['reason' => 'unavailable'];
                    }

                    return array_replace($item, ['downloadable' => true, 'mime' => $l->file_mime, 'size' => (int) $l->file_size, 'sha256' => $sha, 'expires_at' => $expires,
                        'url' => $this->storage->temporaryUrl('materials', $l->file_path, 86400), 'settings' => array_intersect_key($l->settings ?? [], array_flip(['min_seconds_per_slide', 'min_view_percent', 'min_watch_percent', 'require_fullscreen']))]);
                }
                $pkg = $l->type === CourseLesson::PACKAGE && $l->package_id ? ContentPackage::find($l->package_id) : null;
                // Only HTML5 and H5P packages run offline; SCORM needs the player's server round trips.
                if ($pkg && in_array($pkg->standard, ['h5p', 'html5'], true)) {
                    return array_replace($item, ['downloadable' => true, 'standard' => $pkg->standard, 'size' => $pkg->size, 'base_url' => PackageToken::url($pkg->id, '', null), 'expires_at' => $expires]);
                }

                return $item + ['reason' => 'requires_connection'];
            })->values()->all();

        $assessments = Assessment::where('program_id', $r->program_id)->where('status', 'published')->where('allow_offline', true)->orderBy('created_at')
            ->get(['id', 'title_ar', 'title_en', 'time_limit_minutes', 'offline_window_hours', 'max_attempts'])->toArray();

        return $base + ['delete' => false, 'revoked' => false, 'content_key' => $device?->content_key, 'lessons' => $lessons, 'assessments' => $assessments];
    }

    /** The device that keeps the content offline; a new device gets its own random 256-bit key. */
    public function device(string $userId, string $deviceId, ?string $platform = null): OfflineDevice
    {
        $device = OfflineDevice::firstOrCreate(['user_id' => $userId, 'device_id' => $deviceId], ['content_key' => base64_encode(random_bytes(32)), 'platform' => $platform ? mb_substr($platform, 0, 20) : null]);
        $device->update(['last_seen_at' => now()]);

        return $device;
    }

    /**
     * Registrations whose offline content the device must delete: no longer active, or the device was revoked.
     *
     * @param  list<string>  $held  registration ids the device keeps
     * @return list<string>
     */
    public function purge(User $user, ?string $deviceId, array $held): array
    {
        $held = array_values(array_filter($held, 'is_string'));
        if ($held === []) {
            return [];
        }
        if ($deviceId && OfflineDevice::where('user_id', $user->id)->where('device_id', $deviceId)->whereNotNull('revoked_at')->exists()) {
            return $held;
        }
        $active = Registration::whereIn('id', $held)->where('employee_id', $user->employee?->id)->whereIn('status', [Registration::STATUS_APPROVED, Registration::STATUS_COMPLETED])->pluck('id')->all();

        return array_values(array_diff($held, $active));
    }

    /** SHA-256 of a lesson file, worked out once and kept until the file changes. */
    private function checksum(CourseLesson $l): ?string
    {
        if ($l->file_sha256) {
            return $l->file_sha256;
        }
        try {
            $sha = hash('sha256', $this->storage->get('materials', $l->file_path));
        } catch (\Throwable) {
            return null;
        }
        $l->forceFill(['file_sha256' => $sha])->saveQuietly();

        return $sha;
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
                    'slide_view' => $this->course->viewSlide($lesson, $r, max(1, (int) ($it['slide'] ?? 1)), max(1, (int) ($it['total'] ?? 1)), $this->recordedAt($it)),
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

    /** When the app recorded an offline event (never in the future); null for events from older app versions. */
    private function recordedAt(array $it): ?Carbon
    {
        if (empty($it['at']) || ! is_string($it['at'])) {
            return null;
        }
        try {
            $at = Carbon::parse($it['at']);
        } catch (\Throwable) {
            throw new \InvalidArgumentException('bad_time');
        }

        return $at->gt(now()) ? now() : $at;
    }

    private function complete(CourseLesson $lesson, Registration $r): void
    {
        $this->course->complete($lesson, $r);
    }
}
