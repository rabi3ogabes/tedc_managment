<?php

namespace App\Providers;

use App\Auth\SupabaseUserResolver;
use App\Models\AppNotification;
use App\Models\CourseLesson;
use App\Models\ImpactSurvey;
use App\Models\LessonProgress;
use App\Models\NeedsSurvey;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TaskSubmission;
use App\Models\TrainingNeed;
use App\Services\Channels\ChannelSettings;
use App\Services\Channels\NotificationChannels;
use App\Services\Content\CaliperService;
use App\Services\Content\XapiService;
use App\Support\ActiveRole;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(ActiveRole::class);
        // One instance per request: it remembers the channels the administrator picked for the task in progress.
        $this->app->singleton(NotificationChannels::class);
        $this->app->singleton(ChannelSettings::class);
    }

    public function boot(): void
    {
        // Stateless bearer-token guard backed by Supabase Auth JWTs.
        Auth::viaRequest('supabase-jwt', app(SupabaseUserResolver::class));

        // Route-model binding parameters are UUIDs; reject anything else early.
        foreach (['program', 'session', 'registration', 'employee', 'task', 'submission', 'certificate', 'material', 'school', 'trainer', 'announcement', 'need', 'user', 'role', 'notification', 'survey'] as $param) {
            Route::pattern($param, '[0-9a-fA-F-]{36}');
        }
        // Native learning activity is recorded as xAPI statements and Caliper events too (packages report their own).
        LessonProgress::saved(function (LessonProgress $p) {
            if (! $p->wasChanged('status') || $p->status !== 'completed') {
                return;
            }
            $lesson = CourseLesson::find($p->lesson_id);
            $registration = Registration::find($p->registration_id);
            if (! $lesson || ! $registration || $lesson->type === CourseLesson::PACKAGE) {
                return;
            }
            try {
                app(XapiService::class)->native($registration, $lesson, 'http://adlnet.gov/expapi/verbs/completed', 'completed', $p->best_score !== null ? ['result' => ['completion' => true, 'score' => ['scaled' => min(1, max(0, (float) $p->best_score / 100))]]] : ['result' => ['completion' => true]]);
                app(CaliperService::class)->emit($lesson->type === 'video' ? 'MediaEvent' : 'GradeEvent', $registration, $lesson, $lesson->type === 'video' ? 'Ended' : 'Graded');
            } catch (\Throwable $e) {
                report($e);
            }
        });
        Route::bind('need', fn ($id) => TrainingNeed::findOrFail($id));
        Route::bind('survey', fn ($id) => ImpactSurvey::findOrFail($id));
        Route::bind('needsSurvey', fn ($id) => NeedsSurvey::findOrFail($id));
        Route::bind('submission', fn ($id) => TaskSubmission::findOrFail($id));
        Route::bind('session', fn ($id) => ProgramSession::findOrFail($id));
        Route::bind('notification', fn ($id) => AppNotification::findOrFail($id));

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $key = fn (Request $request) => $request->user()?->id ?: $request->ip();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($key($request)));
        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
        ]);
        // E-mail codes: few per address and per IP, so the form cannot be used to flood someone's inbox.
        RateLimiter::for('external-code', fn (Request $request) => [Limit::perMinute(6)->by('code-ip:'.$request->ip()), Limit::perHour(5)->by('code-mail:'.strtolower((string) $request->input('email')))]);
        RateLimiter::for('external-submit', fn (Request $request) => Limit::perMinute(10)->by('submit:'.$request->ip()));
        RateLimiter::for('verify', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
        RateLimiter::for('scan', fn (Request $request) => Limit::perMinute(20)->by($key($request)));
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
    }
}
