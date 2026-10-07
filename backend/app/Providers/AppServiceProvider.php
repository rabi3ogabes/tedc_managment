<?php

namespace App\Providers;

use App\Ai\AiHooks;
use App\Auth\SupabaseUserResolver;
use App\Gamification\GamificationListener;
use App\Integrations\EventBus;
use App\Integrations\Ministry\SijilArchive;
use App\Integrations\Teams\TeamsService;
use App\Models\AppNotification;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CourseLesson;
use App\Models\ImpactSurvey;
use App\Models\IntegrationLog;
use App\Models\LessonProgress;
use App\Models\NeedsSurvey;
use App\Models\PdActivity;
use App\Models\ProfessionalLicence;
use App\Models\ProgramSession;
use App\Models\Registration;
use App\Models\TaskSubmission;
use App\Models\TrainingNeed;
use App\Security\SecurityEvents;
use App\Security\Siem\SiemForwarder;
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
        foreach (['room', 'program', 'session', 'registration', 'employee', 'task', 'submission', 'certificate', 'material', 'school', 'trainer', 'announcement', 'need', 'user', 'role', 'notification', 'survey', 'definition', 'run', 'schedule', 'rule', 'scheduled', 'subscription', 'delivery', 'authSession', 'assessment', 'space', 'post', 'spacePoll', 'spaceEvent', 'courseQuestion', 'challenge', 'reward', 'badge', 'redemption', 'abuseReport', 'lessonNote', 'postComment', 'rating', 'aiDraft', 'riskFlag', 'shopOrder', 'shopRefund', 'discountCode', 'entityAccount', 'dsr'] as $param) {
            Route::pattern($param, '[0-9a-fA-F-]{36}');
        }
        GamificationListener::register();
        AiHooks::register();
        // Audit records and failed calls to other systems go to the SIEM (queued first, so it never slows a request).
        AuditLog::created(fn (AuditLog $a) => app(SiemForwarder::class)->push('audit', ['action' => $a->action, 'user_id' => $a->user_id, 'subject_type' => $a->auditable_type, 'subject_id' => $a->auditable_id, 'ip' => $a->ip_address, 'url' => $a->url]));
        IntegrationLog::created(function (IntegrationLog $l) {
            if ($l->status === 'error' && $l->integration_key !== 'siem') {   // a SIEM that is down must not report itself in a loop
                SecurityEvents::record('integration_failure', null, 'error', ['integration' => $l->integration_key, 'operation' => $l->operation]);
            }
        });
        // Domain events for other systems (webhooks / outbox): written only when someone subscribes.
        Registration::updated(function (Registration $r) {
            if ($r->wasChanged('status') && in_array($r->status, ['approved', 'completed'], true)) {
                app(EventBus::class)->emit('registration.'.$r->status, ['registration_id' => $r->id, 'program_id' => $r->program_id, 'employee_id' => $r->employee_id, 'status' => $r->status]);
            }
        });
        Certificate::created(function (Certificate $c) {
            try {
                app(SijilArchive::class)->queueCertificate($c);   // sent to the central archive by the next run
            } catch (\Throwable) {
            }
        });
        Certificate::created(fn (Certificate $c) => app(EventBus::class)->emit('certificate.issued', ['certificate_id' => $c->id, 'certificate_no' => $c->certificate_no, 'employee_id' => $c->employee_id, 'program_id' => $c->program_id, 'hours' => $c->hours]));
        Attendance::created(fn (Attendance $a) => app(EventBus::class)->emit('attendance.recorded', ['attendance_id' => $a->id, 'employee_id' => $a->employee_id, 'session_id' => $a->program_session_id, 'status' => $a->status, 'minutes' => $a->minutes_attended]));
        PdActivity::updated(function (PdActivity $p) {
            if ($p->wasChanged('status') && $p->status === 'approved') {
                app(EventBus::class)->emit('pd.approved', ['activity_id' => $p->id, 'employee_id' => $p->employee_id, 'approved_hours' => $p->approved_hours]);
            }
        });
        ProfessionalLicence::saved(fn (ProfessionalLicence $l) => app(EventBus::class)->emit('licence.updated', ['licence_id' => $l->id, 'employee_id' => $l->employee_id, 'status' => $l->status, 'level' => $l->level_no]));

        // Teams meetings follow the schedule: created for online sessions, updated when the time or title changes, cancelled with the session. Never blocks the save.
        ProgramSession::saved(function (ProgramSession $session) {
            try {
                $teams = app(TeamsService::class);
                if ($session->status === 'cancelled') {
                    $teams->cancelMeeting($session);
                } elseif ($session->wasRecentlyCreated || $session->wasChanged(['starts_at', 'ends_at', 'title_ar', 'title_en', 'mode'])) {
                    $teams->ensureMeeting($session);
                }
            } catch (\Throwable) {
                // The failure is on the meeting row and in the integration log; the session itself is saved.
            }
        });

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
