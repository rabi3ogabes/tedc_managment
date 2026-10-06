<?php

namespace App\Gamification;

use App\Models\AssessmentAttempt;
use App\Models\Comment;
use App\Models\Employee;
use App\Models\LessonProgress;
use App\Models\PdActivity;
use App\Models\Post;
use App\Models\Registration;
use App\Models\User;
use App\Social\Events\AnswerAccepted;
use App\Social\Events\CommentPosted;
use App\Social\Events\PostPublished;
use App\Social\Events\ReactionReceived;
use Illuminate\Support\Facades\Event;
use Throwable;

/** Connects what people do on the platform to the points rules. Never lets a failure here break the action that caused it. */
class GamificationListener
{
    public static function register(): void
    {
        LessonProgress::saved(fn (LessonProgress $p) => self::safely(function () use ($p) {
            if ($p->wasChanged('status') && $p->status === 'completed') {
                $reg = Registration::find($p->registration_id);
                self::pay(self::userOfEmployee($p->employee_id), 'lesson_completed', 'lesson_progress', $p->id, ['program_id' => $reg?->program_id]);
            }
        }));
        Registration::updated(fn (Registration $r) => self::safely(function () use ($r) {
            if ($r->wasChanged('status') && $r->status === Registration::STATUS_COMPLETED) {
                self::pay(self::userOfEmployee($r->employee_id), 'course_completed', 'registration', $r->id, ['program_id' => $r->program_id]);
            }
        }));
        AssessmentAttempt::updated(fn (AssessmentAttempt $a) => self::safely(function () use ($a) {
            if ($a->wasChanged('passed') && $a->passed) {
                $reg = Registration::find($a->registration_id);
                self::pay(self::userOfEmployee($reg?->employee_id), 'assessment_passed', 'attempt', $a->id, ['program_id' => $reg?->program_id, 'value' => $a->score_percent]);
            }
        }));
        PdActivity::updated(fn (PdActivity $p) => self::safely(function () use ($p) {
            if ($p->wasChanged('status') && $p->status === 'approved') {
                self::pay(self::userOfEmployee($p->employee_id), 'pd_approved', 'pd_activity', $p->id);
            }
        }));
        Event::listen(PostPublished::class, fn (PostPublished $e) => self::safely(fn () => $e->post->status === 'published' && self::pay($e->post->author_id, 'post_created', 'post', $e->post->id)));
        Event::listen(CommentPosted::class, fn (CommentPosted $e) => self::safely(fn () => self::pay($e->comment->author_id, 'comment_posted', 'comment', $e->comment->id)));
        Event::listen(AnswerAccepted::class, fn (AnswerAccepted $e) => self::safely(fn () => self::pay($e->comment->author_id, 'answer_accepted', 'comment', $e->comment->id)));
        Event::listen(ReactionReceived::class, fn (ReactionReceived $e) => self::safely(fn () => self::pay($e->target->author_id, 'reaction_received', $e->targetType.'_reaction', $e->target->id.':'.now()->timestamp)));
        Post::updated(fn (Post $p) => self::safely(function () use ($p) {
            if ($p->wasChanged('status') && in_array($p->status, ['hidden', 'deleted'], true)) {
                app(GamificationService::class)->reverse($p->author_id, 'post_created', 'post', $p->id);
            }
        }));
        Comment::updated(fn (Comment $c) => self::safely(function () use ($c) {
            if ($c->wasChanged('status') && in_array($c->status, ['hidden', 'deleted'], true)) {
                app(GamificationService::class)->reverse($c->author_id, 'comment_posted', 'comment', $c->id);
            }
        }));
    }

    /** One visit a day: called from sign-in. */
    public static function login(User $user): void
    {
        self::safely(fn () => self::pay($user->id, 'daily_login', 'day', now()->toDateString()));
    }

    private static function pay(?string $userId, string $event, ?string $type, ?string $id, array $ctx = []): int
    {
        return $userId ? app(GamificationService::class)->award($userId, $event, $type, $id, $ctx) : 0;
    }

    private static function userOfEmployee(?string $employeeId): ?string
    {
        return $employeeId ? Employee::whereKey($employeeId)->value('user_id') : null;
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
