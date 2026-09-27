<?php

namespace App\Providers;

use App\Auth\SupabaseUserResolver;
use App\Models\AppNotification;
use App\Models\ImpactSurvey;
use App\Models\ProgramSession;
use App\Models\TaskSubmission;
use App\Models\TrainingNeed;
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
        //
    }

    public function boot(): void
    {
        // Stateless bearer-token guard backed by Supabase Auth JWTs.
        Auth::viaRequest('supabase-jwt', app(SupabaseUserResolver::class));

        // Route-model binding parameters are UUIDs; reject anything else early.
        foreach (['program', 'session', 'registration', 'employee', 'task', 'submission', 'certificate', 'material', 'school', 'trainer', 'announcement', 'need', 'user', 'role', 'notification', 'survey'] as $param) {
            Route::pattern($param, '[0-9a-fA-F-]{36}');
        }
        Route::bind('need', fn ($id) => TrainingNeed::findOrFail($id));
        Route::bind('survey', fn ($id) => ImpactSurvey::findOrFail($id));
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
        RateLimiter::for('verify', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('contact', fn (Request $request) => Limit::perHour(10)->by($request->ip()));
        RateLimiter::for('scan', fn (Request $request) => Limit::perMinute(20)->by($key($request)));
        RateLimiter::for('ai', fn (Request $request) => Limit::perMinute(10)->by($key($request)));
    }
}
