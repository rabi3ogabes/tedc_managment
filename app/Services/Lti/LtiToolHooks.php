<?php

namespace App\Services\Lti;

use App\Models\LessonProgress;
use App\Models\Registration;
use Throwable;

/** Sends grades back to the platforms that launched TEDC when a lesson or a course is finished. A failure never stops the learner. */
class LtiToolHooks
{
    public static function register(): void
    {
        LessonProgress::saved(function (LessonProgress $p) {
            if ($p->status === 'completed' && ($p->wasRecentlyCreated || $p->wasChanged('status'))) {
                self::safely(fn () => app(LtiToolService::class)->lessonFinished($p));
            }
        });
        Registration::updated(function (Registration $r) {
            if ($r->course_completed && $r->wasChanged('course_completed')) {
                self::safely(fn () => app(LtiToolService::class)->courseFinished($r));
            }
        });
    }

    private static function safely(callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
