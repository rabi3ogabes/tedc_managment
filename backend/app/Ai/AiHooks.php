<?php

namespace App\Ai;

use App\Ai\Adaptive\AdaptivePath;
use App\Ai\Adaptive\MasteryService;
use App\Ai\Feedback\SmartFeedback;
use App\Ai\Rag\Indexer;
use App\Ai\Recommend\HybridRecommender;
use App\Models\AssessmentAttempt;
use App\Models\CourseLesson;
use App\Models\LibraryItem;
use App\Models\Program;
use App\Models\Registration;
use Throwable;

/** Connects the AI features to what happens on the platform. A failure here never breaks the action that caused it. */
class AiHooks
{
    public static function register(): void
    {
        AssessmentAttempt::updated(fn (AssessmentAttempt $a) => self::safely(function () use ($a) {
            if (! $a->wasChanged('status')) {
                return;
            }
            if ($a->status === 'grading') {
                app(SmartFeedback::class)->draftEssays($a);   // a draft waits for the grader; nothing is graded
            }
            if ($a->status === 'graded') {
                app(MasteryService::class)->record($a);
                if ($a->registration) {
                    app(AdaptivePath::class)->apply($a->registration);
                }
            }
        }));
        CourseLesson::saved(fn (CourseLesson $l) => self::safely(fn () => app(Indexer::class)->lesson($l)));
        CourseLesson::deleted(fn (CourseLesson $l) => self::safely(fn () => app(Indexer::class)->forget('lesson', $l->id)));
        Program::saved(fn (Program $p) => self::safely(fn () => app(Indexer::class)->program($p)));
        LibraryItem::saved(fn (LibraryItem $i) => self::safely(fn () => app(Indexer::class)->library($i)));
        LibraryItem::deleted(fn (LibraryItem $i) => self::safely(fn () => app(Indexer::class)->forget('library', $i->id)));
        Registration::created(fn (Registration $r) => self::safely(fn () => app(HybridRecommender::class)->registered($r)));
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
