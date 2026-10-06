<?php

use App\Http\Controllers\Api\V1\Admin\AbsenceController;
use App\Http\Controllers\Api\V1\Admin\AdmissionController;
use App\Http\Controllers\Api\V1\Admin\AdmissionRulesController;
use App\Http\Controllers\Api\V1\Admin\AiAssistantController;
use App\Http\Controllers\Api\V1\Admin\AiModelsController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\AnnouncementController;
use App\Http\Controllers\Api\V1\Admin\AnnualPlanController;
use App\Http\Controllers\Api\V1\Admin\AssessmentController;
use App\Http\Controllers\Api\V1\Admin\AttendanceAttemptsController;
use App\Http\Controllers\Api\V1\Admin\AttendanceDeviceController;
use App\Http\Controllers\Api\V1\Admin\AttendanceMethodsController;
use App\Http\Controllers\Api\V1\Admin\AttendanceSettingsController;
use App\Http\Controllers\Api\V1\Admin\CalendarController;
use App\Http\Controllers\Api\V1\Admin\CareerAdminController;
use App\Http\Controllers\Api\V1\Admin\CatalogController;
use App\Http\Controllers\Api\V1\Admin\CertificateController;
use App\Http\Controllers\Api\V1\Admin\CertificateTemplateController;
use App\Http\Controllers\Api\V1\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Api\V1\Admin\CmsController;
use App\Http\Controllers\Api\V1\Admin\CompetencyController;
use App\Http\Controllers\Api\V1\Admin\ContentImportController;
use App\Http\Controllers\Api\V1\Admin\ContentLifecycleController;
use App\Http\Controllers\Api\V1\Admin\CourseController;
use App\Http\Controllers\Api\V1\Admin\EligibilityRuleController;
use App\Http\Controllers\Api\V1\Admin\EmployeeController;
use App\Http\Controllers\Api\V1\Admin\ErrorLogController;
use App\Http\Controllers\Api\V1\Admin\EvaluationFormController;
use App\Http\Controllers\Api\V1\Admin\EvaluationInsightsController;
use App\Http\Controllers\Api\V1\Admin\EvaluationReportController;
use App\Http\Controllers\Api\V1\Admin\ExternalLearningController;
use App\Http\Controllers\Api\V1\Admin\ExternalRequestController;
use App\Http\Controllers\Api\V1\Admin\GapController;
use App\Http\Controllers\Api\V1\Admin\GroupEvaluationController;
use App\Http\Controllers\Api\V1\Admin\ImpersonationController;
use App\Http\Controllers\Api\V1\Admin\IntegrationsController;
use App\Http\Controllers\Api\V1\Admin\InternalWorkshopController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitAiController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitAssetController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitCommentController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitFileController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitSampleController;
use App\Http\Controllers\Api\V1\Admin\KpiController;
use App\Http\Controllers\Api\V1\Admin\LabelController;
use App\Http\Controllers\Api\V1\Admin\LobbyScreenController;
use App\Http\Controllers\Api\V1\Admin\LtiToolController;
use App\Http\Controllers\Api\V1\Admin\MaterialController;
use App\Http\Controllers\Api\V1\Admin\MinistrySiteController;
use App\Http\Controllers\Api\V1\Admin\NeedsCycleController;
use App\Http\Controllers\Api\V1\Admin\NeedsSurveyController;
use App\Http\Controllers\Api\V1\Admin\NeedsToolsController;
use App\Http\Controllers\Api\V1\Admin\NotificationChannelsController;
use App\Http\Controllers\Api\V1\Admin\NotificationDeliveriesController;
use App\Http\Controllers\Api\V1\Admin\NotificationRulesController;
use App\Http\Controllers\Api\V1\Admin\NotificationTemplateController;
use App\Http\Controllers\Api\V1\Admin\NotificationTrackingController;
use App\Http\Controllers\Api\V1\Admin\PackageController;
use App\Http\Controllers\Api\V1\Admin\PartnerOrganizationController;
use App\Http\Controllers\Api\V1\Admin\PassingController;
use App\Http\Controllers\Api\V1\Admin\PdAdminController;
use App\Http\Controllers\Api\V1\Admin\PresenceController;
use App\Http\Controllers\Api\V1\Admin\ProcessController;
use App\Http\Controllers\Api\V1\Admin\ProfileRequestController;
use App\Http\Controllers\Api\V1\Admin\ProgramBuilderController;
use App\Http\Controllers\Api\V1\Admin\ProgramController;
use App\Http\Controllers\Api\V1\Admin\ProgramGrantController;
use App\Http\Controllers\Api\V1\Admin\ProgramStructureController;
use App\Http\Controllers\Api\V1\Admin\ProgramSurveyController;
use App\Http\Controllers\Api\V1\Admin\PushSettingsController;
use App\Http\Controllers\Api\V1\Admin\QuestionBankController;
use App\Http\Controllers\Api\V1\Admin\RegistrationController;
use App\Http\Controllers\Api\V1\Admin\RemoteProgramController;
use App\Http\Controllers\Api\V1\Admin\ReportBuilderController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\RfpStatusController;
use App\Http\Controllers\Api\V1\Admin\RoleAdminController;
use App\Http\Controllers\Api\V1\Admin\RoomController;
use App\Http\Controllers\Api\V1\Admin\RoomScreenSettingsController;
use App\Http\Controllers\Api\V1\Admin\RoomsOpsController;
use App\Http\Controllers\Api\V1\Admin\ScheduledNotificationsController;
use App\Http\Controllers\Api\V1\Admin\SchoolController;
use App\Http\Controllers\Api\V1\Admin\SchoolGroupController;
use App\Http\Controllers\Api\V1\Admin\SecurityPolicyController;
use App\Http\Controllers\Api\V1\Admin\SecuritySettingsController;
use App\Http\Controllers\Api\V1\Admin\SessionController;
use App\Http\Controllers\Api\V1\Admin\StandardsController;
use App\Http\Controllers\Api\V1\Admin\SurveyExportController;
use App\Http\Controllers\Api\V1\Admin\TaskController;
use App\Http\Controllers\Api\V1\Admin\TeamsController;
use App\Http\Controllers\Api\V1\Admin\TestAccountsController;
use App\Http\Controllers\Api\V1\Admin\ThemeController;
use App\Http\Controllers\Api\V1\Admin\TrainerAssignmentController;
use App\Http\Controllers\Api\V1\Admin\TrainerController;
use App\Http\Controllers\Api\V1\Admin\TrainingDaySettingsController;
use App\Http\Controllers\Api\V1\Admin\TrainingGroupController;
use App\Http\Controllers\Api\V1\Admin\TrainingNeedController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\UserRoleController;
use App\Http\Controllers\Api\V1\Admin\VideoInteractionController;
use App\Http\Controllers\Api\V1\Admin\WebhooksController;
use App\Http\Controllers\Api\V1\Admin\WithdrawalController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuthSecurityController;
use App\Http\Controllers\Api\V1\ClientErrorController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\FeaturesController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HudhudReceiptController;
use App\Http\Controllers\Api\V1\InboundWebhookController;
use App\Http\Controllers\Api\V1\LibraryController;
use App\Http\Controllers\Api\V1\LtiController;
use App\Http\Controllers\Api\V1\Me\AccountController;
use App\Http\Controllers\Api\V1\Me\DeviceController;
use App\Http\Controllers\Api\V1\Me\MeController;
use App\Http\Controllers\Api\V1\Me\MyAssessmentController;
use App\Http\Controllers\Api\V1\Me\MyAssignmentsController;
use App\Http\Controllers\Api\V1\Me\MyCareerController;
use App\Http\Controllers\Api\V1\Me\MyCourseController;
use App\Http\Controllers\Api\V1\Me\MyEvaluationController;
use App\Http\Controllers\Api\V1\Me\MyEventsController;
use App\Http\Controllers\Api\V1\Me\MyNeedsSurveyController;
use App\Http\Controllers\Api\V1\Me\MyNotificationPreferencesController;
use App\Http\Controllers\Api\V1\Me\MyOfflineController;
use App\Http\Controllers\Api\V1\Me\MyOutcomesController;
use App\Http\Controllers\Api\V1\Me\MyPackageController;
use App\Http\Controllers\Api\V1\Me\MyPassingController;
use App\Http\Controllers\Api\V1\Me\MyReportsController;
use App\Http\Controllers\Api\V1\Me\MyTrainingController;
use App\Http\Controllers\Api\V1\MobileConfigController;
use App\Http\Controllers\Api\V1\Public\ChatController as PublicChatController;
use App\Http\Controllers\Api\V1\Public\ExternalFormController;
use App\Http\Controllers\Api\V1\Public\PublicContentController;
use App\Http\Controllers\Api\V1\Public\PublicController;
use App\Http\Controllers\Api\V1\ReportSignedDownloadController;
use App\Http\Controllers\Api\V1\SearchController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Http\Controllers\Api\V1\TicketsController;
use App\Http\Controllers\Api\V1\XapiController;
use App\Models\TrainingRoom;
use App\Services\FileStorage;
use App\Services\LobbyScreenService;
use App\Services\LobbyScreenSettings;
use App\Services\RoomScreenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| TEDC REST API — v1
|--------------------------------------------------------------------------
| Public website  : /api/v1/public/*
| Authentication  : /api/v1/auth/*
| Self-service    : /api/v1/me/*          (employees, supervisors — web & mobile)
| Administration  : /api/v1/admin/*       (RBAC protected via `permission:` middleware)
*/

Route::prefix('v1')->group(function () {
    // LTI platform endpoints (the tool authenticates itself: signed JWTs, bearer tokens, OAuth 1.0a).
    Route::prefix('lti')->group(function () {
        Route::get('jwks', [LtiController::class, 'jwks']);
        Route::match(['GET', 'POST'], 'auth', [LtiController::class, 'auth'])->middleware('throttle:120,1');
        Route::post('token', [LtiController::class, 'token'])->middleware('throttle:120,1');
        Route::get('ags/{lesson}/lineitems', [LtiController::class, 'lineItems']);
        Route::get('ags/{lesson}/lineitems/{id}', [LtiController::class, 'lineItem']);
        Route::post('ags/{lesson}/lineitems/{id}/scores', [LtiController::class, 'score']);
        Route::get('nrps/{program}/memberships', [LtiController::class, 'memberships']);
        Route::post('deep-link/return', [LtiController::class, 'deepLinkReturn']);
        Route::post('outcomes', [LtiController::class, 'outcomes']);
    });
    // The xAPI learning record store (its own Basic authentication).
    Route::prefix('xapi')->group(function () {
        Route::get('about', [XapiController::class, 'about']);
        Route::match(['GET', 'POST', 'PUT'], 'statements', [XapiController::class, 'statements']);
        Route::match(['GET', 'PUT', 'POST', 'DELETE'], 'activities/state', [XapiController::class, 'documents'])->defaults('kind', 'state');
        Route::match(['GET', 'PUT', 'POST', 'DELETE'], 'activities/profile', [XapiController::class, 'documents'])->defaults('kind', 'profile');
        Route::match(['GET', 'PUT', 'POST', 'DELETE'], 'agents/profile', [XapiController::class, 'documents'])->defaults('kind', 'agent-profile');
        Route::post('cmi5/fetch/{cmi5Session}', [XapiController::class, 'cmi5Fetch']);
    });

    // Events, feeds for the Ministry website, published pages and the centre's statistics (not edge-cached: pages differ for signed-in visitors).
    Route::prefix('public')->middleware('throttle:public')->controller(PublicContentController::class)->group(function () {
        Route::get('events', 'events');
        Route::get('events/{id}', 'event')->whereUuid('id');
        Route::get('events/{id}/event.ics', 'eventIcs')->whereUuid('id');
        Route::get('calendar.ics', 'calendarIcs');
        Route::get('feeds/{name}', 'feed')->where('name', '[a-z-]+\.(json|xml|csv)');
        Route::get('pages/{page}', 'page');
        Route::get('defined-stats', 'stats');
    });
    // The link in a scheduled report's e-mail: signed and expiring.
    Route::get('report-runs/{run}/download', ReportSignedDownloadController::class)->name('report-runs.signed')->middleware(['signed', 'throttle:30,1']);
    // Messages from the Ministry systems: signed, idempotent.
    Route::post('integrations/{key}/inbound', InboundWebhookController::class)->where('key', '[a-z_]+')->middleware('throttle:120,1');
    // Hudhud posts delivery receipts here; the body is signed with the shared secret.
    Route::post('integrations/sms/hudhud/receipt', HudhudReceiptController::class)->middleware('throttle:public');

    // Deployment diagnostics: no rate limiter here, because it needs the (possibly broken) database cache.
    Route::get('public/health', HealthController::class);

    // The screen at a classroom door: reached by its secret token, no sign-in.
    Route::get('public/room-screen/{token}', function (string $token, Request $request, RoomScreenService $screen) {
        $room = TrainingRoom::where('display_token', $token)->firstOrFail();
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $screen->day($room, $data['date'] ?? null)]);
    })->middleware('throttle:120,1');

    // The portrait lobby screen: today's programs and the administrator's slides, reached by a secret token.
    Route::get('public/lobby-screen/{token}', function (string $token, LobbyScreenSettings $settings, LobbyScreenService $screen) {
        abort_unless($settings->validToken($token) && $settings->all()['enabled'], 404);

        return response()->json(['data' => $screen->payload()]);
    })->middleware('throttle:120,1');

    // Browser uploads of big course files with the local storage driver (Supabase has its own signed upload URLs).
    Route::put('uploads/{bucket}/{path}', function (string $bucket, string $path, FileStorage $files) {
        abort_if(str_contains($path, '..'), 422);
        $files->putStream($bucket, $path, fopen('php://input', 'rb'));

        return response()->json(['message' => 'ok']);
    })->where('path', '.*')->middleware('signed')->name('uploads.local');

    // Errors reported by the website and the app themselves.
    Route::post('client-errors', [ClientErrorController::class, 'store'])->middleware('throttle:30,1');

    // Serverless operations (Vercel Cron / one-time setup), protected by CRON_SECRET ---
    Route::prefix('system')->controller(SystemController::class)->middleware('throttle:10,1')->group(function () {
        Route::get('cron', 'cron');
        Route::post('setup', 'setup');
    });

    // Website chat assistant (never cached; the visitor's secret token is the credential) --------
    Route::prefix('public/chat')->middleware('throttle:40,1')->group(function () {
        Route::post('/', [PublicChatController::class, 'start']);
        Route::get('{conversation}', [PublicChatController::class, 'show']);
        Route::post('{conversation}/messages', [PublicChatController::class, 'send']);
        Route::post('{conversation}/human', [PublicChatController::class, 'human']);
    });

    // Public website ----------------------------------------------------------
    Route::prefix('public')->middleware(['throttle:public', 'edge.cache:60'])->controller(PublicController::class)->group(function () {
        Route::get('home', 'home');
        Route::get('stats', 'stats');
        Route::get('theme', [ThemeController::class, 'show']);
        Route::get('mobile-config', MobileConfigController::class);
        Route::get('labels', [LabelController::class, 'publicIndex']);
        Route::get('programs', 'programs');
        Route::get('programs/{idOrCode}', 'program');
        Route::get('categories', 'categories');
        Route::get('trainers', 'trainers');
        Route::get('calendar', 'calendar');
        Route::get('news', 'news');
        Route::get('news/{id}', 'newsShow');
        Route::get('certificates/verify/{code}', 'verifyCertificate')->middleware('throttle:verify');
        Route::post('contact', 'contact')->middleware('throttle:contact');
    });

    // External registration forms (public, never cached) ----------------------
    Route::prefix('public/forms')->middleware('throttle:public')->controller(ExternalFormController::class)->group(function () {
        Route::get('{slug}', 'show');
        Route::post('{slug}/verify-email', 'verifyEmail')->middleware('throttle:external-code');
        Route::post('{slug}/submit', 'submit')->middleware('throttle:external-submit');
    });

    // Fingerprint devices push signed punches (no user session).
    Route::post('integrations/fingerprint/{device}/punches', [AttendanceDeviceController::class, 'webhook'])->middleware('throttle:public');

    // Authentication ----------------------------------------------------------
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('login', 'login')->middleware('throttle:login');
        Route::post('refresh', 'refresh')->middleware('throttle:login');
        Route::post('activate', 'activate')->middleware('throttle:10,1');
        // Second factor, single sign-on and directory sign-in (before there is a session).
        Route::controller(AuthSecurityController::class)->group(function () {
            Route::get('options', 'options')->middleware('throttle:public');
            Route::post('mfa/send', 'mfaSend')->middleware('throttle:login');
            Route::post('mfa/verify', 'mfaVerify')->middleware('throttle:login');
            Route::post('mfa/totp/setup', 'mfaSetupPending')->middleware('throttle:login');
            Route::post('mfa/totp/confirm', 'mfaConfirmPending')->middleware('throttle:login');
            Route::get('sso/start', 'ssoStart')->middleware('throttle:login');
            Route::get('sso/callback', 'ssoCallback')->middleware('throttle:login');
            Route::post('sso/exchange', 'ssoExchange')->middleware('throttle:login');
            Route::post('ldap/login', 'ldapLogin')->middleware('throttle:login');
        });
        Route::middleware(['auth:api', 'active.role'])->group(function () {
            Route::post('logout', [AuthSecurityController::class, 'logout']);
            Route::post('step-up', [AuthSecurityController::class, 'stepUp'])->middleware('throttle:10,1');
            Route::get('me', 'me');
            Route::post('active-role', 'switchRole');
            Route::patch('me', 'updateProfile');
            Route::post('lock', 'lock');
            Route::post('unlock', 'unlock')->middleware('throttle:10,1');
        });
    });

    Route::middleware(['auth:api', 'active.role', 'throttle:api'])->group(function () {

        Route::get('search', SearchController::class)->middleware('throttle:60,1');

        // Role dashboards: layout, widget data, personal order.
        Route::get('dashboard', [DashboardController::class, 'layout']);
        Route::put('dashboard/layout', [DashboardController::class, 'saveLayout']);
        Route::get('dashboard/widgets/{key}', [DashboardController::class, 'widget'])->where('key', '[a-z_]+');

        // Which features are on (web and app read this once and cache it).
        Route::get('features', [FeaturesController::class, 'map']);

        // Employee self-service -----------------------------------------------
        Route::prefix('me')->group(function () {
            Route::get('home', [MeController::class, 'home']);
            Route::get('recommendations', [MeController::class, 'recommendations']);
            Route::get('passport', [MeController::class, 'passport']);
            Route::put('skills', [MeController::class, 'updateSkills']);
            Route::post('presence', [PresenceController::class, 'heartbeat'])->middleware('throttle:60,1');
            // My account: read-only profile + change requests
            Route::get('account', [AccountController::class, 'show']);
            Route::get('account/requests', [AccountController::class, 'requests']);
            Route::post('account/requests', [AccountController::class, 'store'])->middleware('throttle:20,1');
            Route::delete('account/requests/{changeRequest}', [AccountController::class, 'cancel']);
            Route::post('devices', [DeviceController::class, 'store']);
            Route::delete('devices', [DeviceController::class, 'destroy']);
            Route::get('tickets', [TicketsController::class, 'mine']);
            Route::post('tickets', [TicketsController::class, 'store'])->middleware('throttle:10,1');
            Route::get('mfa', [AuthSecurityController::class, 'mfaStatus']);
            Route::post('mfa/totp/setup', [AuthSecurityController::class, 'mfaSetup'])->middleware('throttle:10,1');
            Route::post('mfa/totp/confirm', [AuthSecurityController::class, 'mfaConfirm'])->middleware('throttle:10,1');
            Route::post('mfa/disable', [AuthSecurityController::class, 'mfaDisable'])->middleware('throttle:10,1');
            Route::post('mfa/recovery-codes', [AuthSecurityController::class, 'mfaRecovery'])->middleware('throttle:10,1');
            Route::get('sessions', [AuthSecurityController::class, 'sessions']);
            Route::delete('sessions/{sid}', [AuthSecurityController::class, 'endSession'])->whereUuid('sid');
            Route::post('password', [AuthSecurityController::class, 'changePassword'])->middleware('throttle:10,1');
            Route::get('reports', [MyReportsController::class, 'index']);
            Route::get('reports/{key}', [MyReportsController::class, 'show']);
            Route::get('reports/{key}/export', [MyReportsController::class, 'export']);
            Route::get('notification-preferences', [MyNotificationPreferencesController::class, 'show']);
            Route::put('notification-preferences', [MyNotificationPreferencesController::class, 'update']);
            Route::get('events', [MyEventsController::class, 'events']);
            Route::get('announcements', [MyEventsController::class, 'announcements']);
            Route::post('events/{announcement}/rsvp', [MyEventsController::class, 'rsvp']);
            Route::get('notifications', [MeController::class, 'notifications']);
            Route::post('notifications/read-all', [MeController::class, 'readAllNotifications']);
            Route::post('notifications/seen', [MeController::class, 'seenNotifications']);
            Route::post('notifications/{notification}/read', [MeController::class, 'readNotification']);

            Route::get('competencies', [NeedsToolsController::class, 'catalog']);
            Route::get('needs', [NeedsToolsController::class, 'mine']);
            Route::post('needs', [NeedsToolsController::class, 'declare']);
            Route::post('registrations/{registration}/withdraw', [WithdrawalController::class, 'withdraw']);
            Route::get('withdrawals', [WithdrawalController::class, 'mine']);
            Route::get('withdrawal-reasons', [WithdrawalController::class, 'activeReasons']);
            Route::post('registrations/{registration}/excuses', [AbsenceController::class, 'submitExcuse']);
            Route::get('excuses', [AbsenceController::class, 'myExcuses']);
            Route::get('registrations/{registration}/progress', [MyPassingController::class, 'progress']);
            Route::post('programs/{program}/test-out/start', [MyPassingController::class, 'testOut'])->middleware('throttle:30,1');
            Route::post('packages/{lesson}/scorm/start', [MyPackageController::class, 'scormStart'])->middleware('throttle:60,1');
            Route::match(['PUT', 'POST'], 'scorm/{attempt}/commit', [MyPackageController::class, 'scormCommit'])->middleware('throttle:240,1');
            Route::post('scorm/{attempt}/finish', [MyPackageController::class, 'scormFinish']);
            Route::post('packages/{lesson}/cmi5/launch', [MyPackageController::class, 'cmi5Launch'])->middleware('throttle:60,1');
            Route::post('packages/{lesson}/launch', [MyPackageController::class, 'launch'])->middleware('throttle:60,1');
            Route::post('packages/{lesson}/complete', [MyPackageController::class, 'complete']);
            Route::post('packages/{lesson}/xapi', [MyPackageController::class, 'xapi'])->middleware('throttle:240,1');
            Route::post('lti/{lesson}/launch', [MyPackageController::class, 'ltiLaunch'])->middleware('throttle:60,1');
            Route::get('courses/{registration}/offline-manifest', [MyOfflineController::class, 'manifest'])->middleware('feature:offline_mobile');
            Route::post('sync', [MyOfflineController::class, 'sync'])->middleware(['feature:offline_mobile', 'throttle:60,1']);
            Route::post('registrations/{registration}/external-launch', [MyOfflineController::class, 'launch']);
            Route::post('registrations/{registration}/external-completion', [MyOfflineController::class, 'evidence'])->middleware('throttle:20,1');
            Route::get('library', [LibraryController::class, 'index']);
            Route::get('library/shelf', [LibraryController::class, 'myShelf']);
            Route::get('library/{item}', [LibraryController::class, 'show']);
            Route::get('library/{item}/read', [LibraryController::class, 'read']);
            Route::get('library/{item}/download', [LibraryController::class, 'download'])->middleware('throttle:30,1');
            Route::post('library/{item}/review', [LibraryController::class, 'review']);
            Route::put('library/{item}/shelf', [LibraryController::class, 'shelf']);
            Route::get('shared', [LibraryController::class, 'sharedWithMe']);
            Route::get('paths', [MyCareerController::class, 'paths']);
            Route::get('paths/{path}', [MyCareerController::class, 'showPath']);
            Route::get('licences', [MyCareerController::class, 'licences']);
            Route::get('pd-hours', [MyCareerController::class, 'hoursSummary']);
            Route::get('pd-activity-types', [MyCareerController::class, 'types']);
            Route::get('pd-activities', [MyCareerController::class, 'activities']);
            Route::post('pd-activities/preview', [MyCareerController::class, 'preview']);
            Route::post('pd-activities', [MyCareerController::class, 'saveActivity']);
            Route::post('pd-activities/{activity}', [MyCareerController::class, 'saveActivity']);
            Route::post('pd-activities/{activity}/submit', [MyCareerController::class, 'submitActivity']);
            Route::get('knowledge-transfers', [MyCareerController::class, 'transfers']);
            Route::post('knowledge-transfers/{transfer}', [MyCareerController::class, 'submitTransfer'])->middleware('throttle:30,1');
            Route::get('colleagues', [MyCareerController::class, 'colleagues'])->middleware('throttle:60,1');
            Route::get('team/pd-activities', [MyCareerController::class, 'teamActivities'])->middleware('permission:impact.supervise');
            Route::post('team/pd-activities/{activity}/decision', [PdAdminController::class, 'decide'])->middleware('permission:impact.supervise');
            Route::get('evaluations', [MyEvaluationController::class, 'index']);
            Route::get('evaluations/{assignment}', [MyEvaluationController::class, 'show']);
            Route::post('evaluations/{assignment}', [MyEvaluationController::class, 'submit'])->middleware('throttle:30,1');
            Route::get('assessments', [MyAssessmentController::class, 'index']);
            Route::post('assessments/{assessment}/start', [MyAssessmentController::class, 'start'])->middleware('throttle:30,1');
            Route::put('attempts/{attempt}/answers', [MyAssessmentController::class, 'answers'])->middleware('throttle:120,1');
            Route::post('attempts/{attempt}/events', [MyAssessmentController::class, 'event'])->middleware('throttle:120,1');
            Route::post('attempts/{attempt}/snapshot', [MyAssessmentController::class, 'snapshot'])->middleware('throttle:60,1');
            Route::post('attempts/{attempt}/submit', [MyAssessmentController::class, 'submit']);
            Route::get('attempts/{attempt}/result', [MyAssessmentController::class, 'result']);
            Route::get('lessons/{lesson}/interactions', [VideoInteractionController::class, 'mine']);
            Route::post('lessons/{lesson}/interactions/{interaction}/answer', [VideoInteractionController::class, 'answer']);
            Route::get('assignments', [MyAssignmentsController::class, 'index']);
            Route::put('assignments/{id}/form', [MyAssignmentsController::class, 'form']);
            Route::get('programs/{program}/eligibility', [MyTrainingController::class, 'eligibility']);
            Route::post('programs/{program}/register', [MyTrainingController::class, 'register']);
            Route::get('registrations', [MyTrainingController::class, 'registrations']);
            Route::get('registrations/{registration}', [MyTrainingController::class, 'registration']);
            Route::post('registrations/{registration}/cancel', [MyTrainingController::class, 'cancel']);
            Route::get('registrations/{registration}/materials', [MyTrainingController::class, 'materials']);
            Route::get('materials/{material}/download', [MyTrainingController::class, 'downloadMaterial']);
            Route::get('calendar', [MyTrainingController::class, 'calendar']);
            Route::get('calendar.ics', [MyTrainingController::class, 'ics']);
            Route::get('registrations/{registration}/course', [MyCourseController::class, 'outline']);
            Route::get('lessons/{lesson}', [MyCourseController::class, 'lesson']);
            Route::post('lessons/{lesson}/heartbeat', [MyCourseController::class, 'heartbeat'])->middleware('throttle:120,1');
            Route::post('lessons/{lesson}/slide', [MyCourseController::class, 'slide'])->middleware('throttle:240,1');
            Route::post('lessons/{lesson}/complete', [MyCourseController::class, 'complete']);
            Route::post('lessons/{lesson}/quiz', [MyCourseController::class, 'quiz'])->middleware('throttle:30,1');
            Route::post('lessons/{lesson}/survey', [MyCourseController::class, 'survey']);
            Route::get('sessions/{session}', [MyTrainingController::class, 'session']);
            Route::post('sessions/{session}/join', [MyTrainingController::class, 'joinSession'])->middleware('throttle:scan');
            Route::post('sessions/{session}/leave', [MyTrainingController::class, 'leaveSession'])->middleware('throttle:scan');
            Route::post('attendance/scan', [MyTrainingController::class, 'scan'])->middleware('throttle:scan');
            Route::get('attendance-qr', [AttendanceMethodsController::class, 'myQr']);
            Route::post('trainer-attendance/scan', [AttendanceMethodsController::class, 'trainerScan'])->middleware('throttle:scan');

            Route::get('tasks', [MyOutcomesController::class, 'tasks']);
            Route::post('tasks/{task}/submit', [MyOutcomesController::class, 'submitTask']);
            Route::post('registrations/{registration}/evaluation', [MyOutcomesController::class, 'submitEvaluation']);
            Route::get('certificates', [MyOutcomesController::class, 'certificates']);
            Route::get('trainer-certificates', [MyOutcomesController::class, 'trainerCertificates']);
            Route::get('surveys', [MyOutcomesController::class, 'surveys']);
            Route::post('surveys/{survey}', [MyOutcomesController::class, 'submitSurvey']);
            Route::get('needs-surveys', [MyNeedsSurveyController::class, 'index']);
            Route::get('needs-surveys/{needsSurvey}', [MyNeedsSurveyController::class, 'show']);
            Route::post('needs-surveys/{needsSurvey}', [MyNeedsSurveyController::class, 'submit']);

            Route::get('team', [MyOutcomesController::class, 'team'])->middleware('permission:impact.supervise');
            Route::post('team/registrations/{registration}/evaluation', [MyOutcomesController::class, 'supervisorEvaluation'])->middleware('permission:impact.supervise');
        });

        Route::get('certificates/{certificate}/download', [MyOutcomesController::class, 'downloadCertificate'])->name('api.certificates.download');
        Route::get('trainer-certificates/{certificate}/download', [MyOutcomesController::class, 'downloadTrainerCertificate'])->name('api.trainer-certificates.download');

        // Administration --------------------------------------------------------
        Route::prefix('admin')->middleware('unlocked')->group(function () {
            // Reference lists for the admin forms (schools, trainers, coordinators): staff only, never an ordinary trainee.
            Route::get('lookups', [CatalogController::class, 'lookups'])->middleware('permission:programs.view|schools.view|employees.view|registrations.view|kits.view|announcements.manage|settings.manage|reports.view');

            Route::get('dashboard', [AnalyticsController::class, 'dashboard'])->middleware('permission:dashboard.view');
            Route::get('process', ProcessController::class)->middleware('permission:dashboard.view');
            Route::get('analytics/executive', [AnalyticsController::class, 'executive'])->middleware('permission:analytics.executive');
            // Chat inbox (conversations with the website assistant)
            Route::middleware('permission:announcements.manage')->prefix('chats')->group(function () {
                Route::get('/', [AdminChatController::class, 'index']);
                Route::get('badge', [AdminChatController::class, 'badge']);
                Route::get('{conversation}', [AdminChatController::class, 'show']);
                Route::post('{conversation}/messages', [AdminChatController::class, 'reply']);
                Route::post('{conversation}/mode', [AdminChatController::class, 'mode']);
                Route::post('{conversation}/status', [AdminChatController::class, 'status']);
                Route::get('{conversation}/export', [AdminChatController::class, 'export']);
            });

            // Data-change requests from users
            Route::middleware('permission:employees.manage')->prefix('profile-requests')->group(function () {
                Route::get('/', [ProfileRequestController::class, 'index']);
                Route::get('summary', [ProfileRequestController::class, 'summary']);
                Route::post('{changeRequest}/approve', [ProfileRequestController::class, 'approve']);
                Route::post('{changeRequest}/reject', [ProfileRequestController::class, 'reject']);
            });

            // Notification templates, sending to a program's trainees, tracking
            // E-mail / SMS channels: anyone who sends notifications may see what is usable; only settings managers change it.
            Route::get('notification-channels/status', [NotificationChannelsController::class, 'status'])->middleware('permission:announcements.manage|registrations.manage|programs.manage');
            Route::middleware('permission:settings.manage')->prefix('notification-channels')->group(function () {
                Route::get('/', [NotificationChannelsController::class, 'show']);
                Route::put('/', [NotificationChannelsController::class, 'update']);
                Route::post('test', [NotificationChannelsController::class, 'test'])->middleware('throttle:10,1');
            });
            // Notifying one program's trainees is also open to people the head of training granted that right on the program.
            Route::middleware('can_or_grant:announcements.manage,notifications.send')->prefix('notifications')->group(function () {
                Route::post('send', [NotificationTrackingController::class, 'send']);
                Route::get('audience', [NotificationTrackingController::class, 'audience']);
            });
            Route::middleware('permission:announcements.manage')->prefix('notifications')->group(function () {
                Route::get('templates', [NotificationTemplateController::class, 'index']);
                Route::post('templates', [NotificationTemplateController::class, 'store']);
                Route::post('templates/preview', [NotificationTemplateController::class, 'preview']);
                Route::put('templates/{template}', [NotificationTemplateController::class, 'update']);
                Route::delete('templates/{template}', [NotificationTemplateController::class, 'destroy']);
                Route::post('templates/{template}/reset', [NotificationTemplateController::class, 'reset']);
                Route::get('campaigns', [NotificationTrackingController::class, 'campaigns']);
                Route::get('campaigns/{campaign}', [NotificationTrackingController::class, 'campaign']);
                Route::get('campaigns/{campaign}/export', [NotificationTrackingController::class, 'exportCampaign']);
                Route::get('tracking', [NotificationTrackingController::class, 'tracking']);
                Route::get('upcoming', [NotificationTrackingController::class, 'upcoming']);
            });
            Route::middleware('permission:programs.manage')->prefix('programs/{program}/survey')->group(function () {
                Route::get('/', [ProgramSurveyController::class, 'show']);
                Route::put('/', [ProgramSurveyController::class, 'update']);
                Route::post('open', [ProgramSurveyController::class, 'open']);
                Route::post('close', [ProgramSurveyController::class, 'close']);
                Route::post('notify', [ProgramSurveyController::class, 'notify']);
            });
            Route::get('presence/live', [PresenceController::class, 'live'])->middleware('permission:analytics.view');
            Route::put('presence/settings', [PresenceController::class, 'updateSettings'])->middleware('permission:settings.manage');
            Route::get('presence/report', [PresenceController::class, 'report'])->middleware('permission:analytics.view');
            Route::get('presence/export', [PresenceController::class, 'export'])->middleware('permission:analytics.view');
            Route::get('analytics/geographic', [AnalyticsController::class, 'geographic'])->middleware('permission:analytics.view');

            // Training calendar (working / off days, vacations, exam days, approvals)
            Route::prefix('calendar')->controller(CalendarController::class)->group(function () {
                Route::get('/', 'index')->middleware('permission:calendar.view|calendar.manage');
                Route::middleware('permission:calendar.manage')->group(function () {
                    Route::post('days', 'store');
                    Route::put('days/{day}', 'update');
                    Route::delete('days/{day}', 'destroy');
                });
                Route::middleware('permission:calendar.approve')->group(function () {
                    Route::post('approvals', 'approve');
                    Route::delete('approvals/{date}', 'revoke');
                });
            });

            // Training Kit Studio (الحقيبة التدريبية)
            Route::prefix('kits')->middleware('permission:kits.view')->scopeBindings()->group(function () {
                Route::get('/', [KitController::class, 'index']);
                Route::get('board', [KitController::class, 'board']);
                Route::get('stats', [KitController::class, 'stats']);
                Route::get('samples', [KitSampleController::class, 'index']);
                Route::post('samples', [KitSampleController::class, 'store'])->middleware(['permission:kits.manage', 'throttle:60,1']);
                Route::get('people', [KitController::class, 'people']);
                Route::get('ai/status', [KitAiController::class, 'status']);
                Route::post('/', [KitController::class, 'store']);

                Route::prefix('{kit}')->group(function () {
                    Route::get('/', [KitController::class, 'show']);
                    Route::put('/', [KitController::class, 'update']);
                    Route::delete('/', [KitController::class, 'destroy']);
                    Route::put('members', [KitController::class, 'updateMembers']);
                    Route::get('activity', [KitController::class, 'activity']);
                    Route::get('reviews', [KitController::class, 'reviews']);
                    Route::get('suggestions', [KitController::class, 'suggestions']);
                    Route::post('submit', [KitController::class, 'submit']);
                    Route::post('request-changes', [KitController::class, 'requestChanges']);
                    Route::post('approve', [KitController::class, 'approve']);
                    Route::post('publish', [KitController::class, 'publish']);
                    Route::post('reopen', [KitController::class, 'reopen']);
                    Route::post('archive', [KitController::class, 'archive']);

                    Route::get('files', [KitFileController::class, 'index']);
                    Route::post('files', [KitFileController::class, 'store']);
                    Route::post('files/create', [KitFileController::class, 'create']);
                    Route::prefix('files/{file}')->group(function () {
                        Route::get('/', [KitFileController::class, 'show']);
                        Route::put('/', [KitFileController::class, 'update']);
                        Route::delete('/', [KitFileController::class, 'destroy']);
                        Route::get('download', [KitFileController::class, 'download']);
                        Route::get('deck', [KitFileController::class, 'deck']);
                        Route::put('deck', [KitFileController::class, 'saveDeck']);
                        Route::post('deck/import', [KitFileController::class, 'importDeck']);
                        Route::post('export', [KitFileController::class, 'exportDeck']);
                        Route::get('versions', [KitFileController::class, 'versions']);
                        Route::post('versions', [KitFileController::class, 'snapshot']);
                        Route::get('versions/{version}', [KitFileController::class, 'showVersion']);
                        Route::post('versions/{version}/restore', [KitFileController::class, 'restore']);
                        Route::post('heartbeat', [KitFileController::class, 'heartbeat']);
                        Route::delete('presence', [KitFileController::class, 'leave']);
                        Route::post('analyze', [KitFileController::class, 'analyze']);
                        Route::post('ai/slides', [KitAiController::class, 'slides'])->middleware('permission:kits.generate');
                    });

                    Route::get('comments', [KitCommentController::class, 'index']);
                    Route::post('comments', [KitCommentController::class, 'store']);
                    Route::post('comments/bulk', [KitCommentController::class, 'bulk']);
                    Route::put('comments/{comment}', [KitCommentController::class, 'update']);
                    Route::post('comments/{comment}/status', [KitCommentController::class, 'setStatus']);
                    Route::delete('comments/{comment}', [KitCommentController::class, 'destroy']);

                    Route::get('assets', [KitAssetController::class, 'index']);
                    Route::post('assets', [KitAssetController::class, 'store']);
                    Route::post('assets/upload-url', [KitAssetController::class, 'uploadUrl'])->middleware('throttle:60,1');
                    Route::post('assets/complete', [KitAssetController::class, 'complete']);
                    Route::post('assets/from-file', [KitAssetController::class, 'fromFile']);

                    Route::middleware('throttle:ai')->group(function () {
                        Route::post('ai/deck', [KitAiController::class, 'deck'])->middleware('permission:kits.generate');
                        Route::post('ai/image', [KitAiController::class, 'image'])->middleware('permission:kits.generate');
                        Route::post('ai/audio', [KitAiController::class, 'audio'])->middleware(['permission:kits.generate', 'throttle:20,1']);
                        Route::post('ai/storyboard', [KitAiController::class, 'storyboard'])->middleware('permission:kits.generate');
                        Route::post('ai/rewrite', [KitAiController::class, 'rewrite']);
                    });
                });
            });

            // Smart program creation (from needs or audience filters)
            Route::prefix('program-builder')->controller(ProgramBuilderController::class)->middleware('permission:programs.manage')->group(function () {
                Route::get('options', 'options');
                Route::post('draft', 'draft');
                Route::post('schedule', 'schedule');
                Route::post('audience/preview', 'preview');
                Route::post('/', 'store');
            });
            Route::get('programs/{program}/audience', [ProgramBuilderController::class, 'showAudience'])->middleware('permission:programs.view|programs.manage');
            Route::put('programs/{program}/audience', [ProgramBuilderController::class, 'updateAudience'])->middleware('permission:programs.manage');
            Route::post('programs/{program}/audience/nominate', [ProgramBuilderController::class, 'nominateAudience'])->middleware('permission:nominations.center');

            // Training rooms (locations, layouts, equipment, availability)
            Route::prefix('rooms')->controller(RoomController::class)->group(function () {
                Route::middleware('permission:programs.view|rooms.manage')->group(function () {
                    Route::get('/', 'index');
                    Route::get('options', 'options');
                    Route::get('availability', 'availability');
                    Route::get('wall', 'wall');
                    Route::get('{room}', 'show');
                    Route::get('{room}/schedule', 'schedule');
                    Route::get('{room}/screen', 'screen');
                });
                Route::middleware('permission:rooms.manage')->group(function () {
                    Route::post('/', 'store');
                    Route::put('{room}', 'update');
                    Route::post('{room}/screen-link', 'screenLink');
                    Route::delete('{room}', 'destroy');
                });
            });

            // Programs & sessions
            Route::middleware('permission:programs.view|programs.manage')->group(function () {
                Route::get('programs', [ProgramController::class, 'index']);
                Route::get('programs/{program}', [ProgramController::class, 'show']);
                Route::get('programs/{program}/sessions', [SessionController::class, 'index']);
                Route::get('programs/{program}/materials', [MaterialController::class, 'index']);
                Route::get('programs/{program}/tasks', [TaskController::class, 'index']);
                Route::get('programs/{program}/eligibility-rules', [EligibilityRuleController::class, 'index']);
                Route::get('programs/{program}/impact', [ProgramController::class, 'impact']);
            });
            Route::middleware('permission:programs.manage')->group(function () {
                Route::post('programs', [ProgramController::class, 'store']);
                Route::put('programs/{program}', [ProgramController::class, 'update']);
                Route::patch('programs/{program}/status', [ProgramController::class, 'updateStatus']);
                Route::post('programs/{program}/cover', [ProgramController::class, 'uploadCover']);
                Route::delete('programs/{program}', [ProgramController::class, 'destroy']);
                Route::post('programs/{program}/sessions', [SessionController::class, 'store']);
                Route::put('sessions/{session}', [SessionController::class, 'update']);
                Route::delete('sessions/{session}', [SessionController::class, 'destroy']);
                Route::put('programs/{program}/eligibility-rules', [EligibilityRuleController::class, 'sync']);
            });
            Route::get('programs/{program}/eligibility/{employee}', [EligibilityRuleController::class, 'check'])->middleware('permission:registrations.view');

            Route::middleware('permission:materials.manage')->group(function () {
                Route::post('programs/{program}/materials', [MaterialController::class, 'store']);
                Route::delete('materials/{material}', [MaterialController::class, 'destroy']);
            });

            // Attendance (trainers & coordinators)
            // Online course builder (video, presentations, quizzes, surveys, articles) and learning analytics
            Route::middleware('permission:programs.view')->group(function () {
                Route::get('programs/{program}/course', [CourseController::class, 'show']);
                Route::get('programs/{program}/course/analytics', [CourseController::class, 'analytics']);
                Route::get('programs/{program}/course/starters', [CourseController::class, 'starters']);
                Route::get('course-starters', [CourseController::class, 'starters']);
            });
            Route::middleware('permission:programs.manage')->group(function () {
                Route::put('programs/{program}/course/settings', [CourseController::class, 'updateSettings']);
                Route::post('programs/{program}/course/blueprint', [CourseController::class, 'applyBlueprint']);
                Route::post('programs/{program}/course/import-kit', [CourseController::class, 'importKit']);
                Route::post('programs/{program}/course/modules', [CourseController::class, 'storeModule']);
                Route::put('programs/{program}/course/reorder', [CourseController::class, 'reorder']);
                Route::put('course/modules/{module}', [CourseController::class, 'updateModule']);
                Route::delete('course/modules/{module}', [CourseController::class, 'destroyModule']);
                Route::post('course/modules/{module}/lessons', [CourseController::class, 'storeLesson']);
                Route::put('course/lessons/{lesson}', [CourseController::class, 'updateLesson']);
                Route::delete('course/lessons/{lesson}', [CourseController::class, 'destroyLesson']);
                Route::post('course/lessons/{lesson}/upload-url', [CourseController::class, 'uploadUrl'])->middleware('throttle:60,1');
                Route::post('course/lessons/{lesson}/file', [CourseController::class, 'fileComplete']);
                Route::put('course/lessons/{lesson}/link', [CourseController::class, 'setLink']);
                Route::delete('course/lessons/{lesson}/file', [CourseController::class, 'removeFile']);
                Route::put('course/lessons/{lesson}/questions', [CourseController::class, 'saveQuestions']);
                Route::put('course/lessons/{lesson}/survey-questions', [CourseController::class, 'saveSurveyQuestions']);
            });
            Route::get('programs/{program}/remote-tracking', [RemoteProgramController::class, 'tracking'])->middleware('permission:programs.view');
            Route::post('program-builder/slots', [RemoteProgramController::class, 'slots'])->middleware('permission:programs.manage');
            Route::post('sessions/{session}/remind', [RemoteProgramController::class, 'remind'])->middleware('can_or_grant:attendance.manage,attendance.mark');
            Route::middleware('can_or_grant:attendance.manage,attendance.mark')->group(function () {
                Route::get('sessions/{session}/qr', [SessionController::class, 'qr']);
                Route::get('sessions/{session}/attendance', [SessionController::class, 'attendance']);
                Route::get('attendance-attempts', [AttendanceAttemptsController::class, 'index']);
                Route::post('sessions/{session}/attendance', [SessionController::class, 'mark']);
                Route::get('sessions/{session}/attendance/export', [AttendanceMethodsController::class, 'exportSession']);
                Route::get('groups/{group}/attendance/export', [AttendanceMethodsController::class, 'exportGroup']);
                Route::get('sessions/{session}/sheet.pdf', [AttendanceMethodsController::class, 'blankSheet']);
                Route::get('sessions/{session}/kiosk', [AttendanceMethodsController::class, 'kiosk']);
                Route::post('sessions/{session}/signatures', [AttendanceMethodsController::class, 'sign']);
                Route::post('sessions/{session}/staff-scan', [AttendanceMethodsController::class, 'staffScan'])->middleware('throttle:scan');
                Route::get('sessions/{session}/trainer-attendance', [AttendanceMethodsController::class, 'trainerRows']);
                Route::post('sessions/{session}/trainer-attendance', [AttendanceMethodsController::class, 'markTrainer']);
            });

            // Tasks
            Route::middleware('permission:tasks.manage')->group(function () {
                Route::post('programs/{program}/tasks', [TaskController::class, 'store']);
                Route::put('tasks/{task}', [TaskController::class, 'update']);
                Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
            });
            Route::middleware('can_or_grant:tasks.review,tasks.review')->group(function () {
                Route::get('tasks/{task}/submissions', [TaskController::class, 'submissions']);
                Route::post('submissions/{submission}/review', [TaskController::class, 'review']);
                Route::post('submissions/{submission}/trainer-decision', [TaskController::class, 'trainerDecision']);
                Route::get('submissions/{submission}/file', [TaskController::class, 'file'])->name('api.submissions.file');
            });

            // Registrations & nominations
            Route::get('registrations/import/template', [RegistrationController::class, 'template'])->middleware('permission:registrations.import');
            Route::middleware('permission:registrations.view')->group(function () {
                Route::get('registrations', [RegistrationController::class, 'index']);
                Route::get('registrations/{registration}', [RegistrationController::class, 'show']);
            });
            Route::middleware('permission:registrations.manage')->group(function () {
                Route::patch('registrations/{registration}/status', [RegistrationController::class, 'updateStatus']);
                Route::post('registrations/bulk-status', [RegistrationController::class, 'bulkStatus']);
            });
            Route::middleware('permission:nominations.center|nominations.school')->group(function () {
                Route::post('programs/{program}/nominations', [RegistrationController::class, 'nominate']);
                Route::get('programs/{program}/candidates', [RegistrationController::class, 'candidates']);
                Route::post('programs/{program}/registrations/import', [RegistrationController::class, 'import'])->middleware('permission:registrations.import');
            });

            // Certificates
            Route::middleware('permission:certificates.view')->group(function () {
                Route::get('certificates', [CertificateController::class, 'index']);
                Route::get('registrations/{registration}/certificate-requirements', [CertificateController::class, 'requirements']);
            });
            // Certificate designer (templates made from an uploaded PDF / picture)
            Route::prefix('certificate-templates')->controller(CertificateTemplateController::class)->group(function () {
                Route::middleware('permission:certificates.view')->group(function () {
                    Route::get('/', 'index');
                    Route::get('{template}', 'show');
                    Route::get('{template}/file/{name}', 'file');
                    Route::post('preview', 'preview');
                });
                Route::middleware('permission:certificates.issue')->group(function () {
                    Route::post('/', 'store');
                    Route::put('{template}', 'update');
                    Route::delete('{template}', 'destroy');
                    Route::post('{template}/default', 'makeDefault');
                    Route::post('{template}/background', 'background');
                    Route::delete('{template}/background', 'removeBackground');
                    Route::post('{template}/assets', 'asset');
                });
            });
            Route::post('registrations/{registration}/certificate', [CertificateController::class, 'issue'])->middleware('permission:certificates.issue');
            Route::post('certificates/send', [CertificateController::class, 'send'])->middleware('permission:certificates.issue');
            Route::post('programs/{program}/certificates', [CertificateController::class, 'issueForProgram'])->middleware('permission:certificates.issue');
            Route::post('certificates/{certificate}/revoke', [CertificateController::class, 'revoke'])->middleware('permission:certificates.revoke');

            // Organization
            Route::middleware('permission:schools.view|schools.manage')->group(function () {
                Route::get('schools/map', [SchoolController::class, 'map']);
                Route::get('schools', [SchoolController::class, 'index']);
                Route::get('schools/{school}', [SchoolController::class, 'show']);
            });
            Route::middleware('permission:schools.manage')->group(function () {
                Route::post('schools/sync', [SchoolController::class, 'sync'])->middleware('throttle:6,1');
                Route::post('schools', [SchoolController::class, 'store']);
                Route::put('schools/{school}', [SchoolController::class, 'update']);
                Route::post('schools/{school}/logo', [SchoolController::class, 'logo']);
            });
            Route::middleware('permission:employees.view|employees.manage')->group(function () {
                Route::get('employees', [EmployeeController::class, 'index']);
                Route::get('employees/{employee}', [EmployeeController::class, 'show']);
            });
            Route::middleware('permission:employees.manage')->group(function () {
                Route::post('employees', [EmployeeController::class, 'store']);
                Route::put('employees/{employee}', [EmployeeController::class, 'update']);
                Route::put('employees/{employee}/skills', [EmployeeController::class, 'skills']);
            });

            // Catalog
            Route::prefix('trainers')->controller(TrainerController::class)->group(function () {
                Route::middleware('permission:programs.view|trainers.manage')->group(function () {
                    Route::get('/', 'index');
                    Route::get('options', 'options');
                    Route::get('candidates', 'candidates');
                    Route::get('suggest', 'suggest');
                    Route::get('{trainer}', 'show');
                    Route::get('{trainer}/schedule', 'schedule');
                });
                Route::middleware('permission:trainers.manage')->group(function () {
                    Route::post('/', 'store');
                    Route::put('{trainer}', 'update');
                    Route::post('{trainer}/photo', 'photo');
                    Route::delete('{trainer}', 'destroy');
                });
            });
            Route::prefix('partners')->controller(PartnerOrganizationController::class)->group(function () {
                Route::get('/', 'index')->middleware('permission:programs.view|trainers.manage');
                Route::middleware('permission:trainers.manage')->group(function () {
                    Route::post('/', 'store');
                    Route::put('{partner}', 'update');
                    Route::delete('{partner}', 'destroy');
                });
            });
            Route::middleware('permission:programs.manage')->group(function () {
                Route::post('categories', [CatalogController::class, 'storeCategory']);
                Route::post('skills', [CatalogController::class, 'storeSkill']);
                Route::post('job-titles', [CatalogController::class, 'storeJobTitle']);
            });

            // Training needs
            Route::get('training-needs', [TrainingNeedController::class, 'index'])->middleware('permission:needs.view|needs.submit');
            Route::post('training-needs', [TrainingNeedController::class, 'store'])->middleware('permission:needs.submit');
            Route::patch('training-needs/{need}', [TrainingNeedController::class, 'update'])->middleware('permission:needs.manage');
            Route::get('training-needs/analytics', [TrainingNeedController::class, 'analytics'])->middleware('permission:needs.view');

            // Training-needs assessment surveys
            Route::middleware('permission:needs.manage')->prefix('needs-surveys')->group(function () {
                Route::get('/', [NeedsSurveyController::class, 'index']);
                Route::post('/', [NeedsSurveyController::class, 'store']);
                Route::get('templates', [NeedsSurveyController::class, 'templates']);
                Route::get('audience/options', [NeedsSurveyController::class, 'audienceOptions']);
                Route::post('audience/preview', [NeedsSurveyController::class, 'audiencePreview']);
                Route::get('{needsSurvey}', [NeedsSurveyController::class, 'show']);
                Route::put('{needsSurvey}', [NeedsSurveyController::class, 'update']);
                Route::delete('{needsSurvey}', [NeedsSurveyController::class, 'destroy']);
                Route::post('{needsSurvey}/duplicate', [NeedsSurveyController::class, 'duplicate']);
                Route::post('{needsSurvey}/publish', [NeedsSurveyController::class, 'publish']);
                Route::post('{needsSurvey}/remind', [NeedsSurveyController::class, 'remind']);
                Route::post('{needsSurvey}/close', [NeedsSurveyController::class, 'close']);
                Route::post('{needsSurvey}/reopen', [NeedsSurveyController::class, 'reopen']);
                Route::get('{needsSurvey}/report', [NeedsSurveyController::class, 'report']);
                Route::get('{needsSurvey}/export', [NeedsSurveyController::class, 'export']);
                Route::post('{needsSurvey}/generate-needs', [NeedsSurveyController::class, 'generateNeeds']);
            });

            Route::middleware('permission:integrations.manage|integrations.logs')->group(function () {
                Route::get('tickets', [TicketsController::class, 'index']);
                Route::post('tickets/{ticket}/resend', [TicketsController::class, 'resend'])->whereUuid('ticket');
            });

            // Microsoft Teams: meeting and attendance on the session, team and files on the group, Forms quizzes on an assessment.
            Route::middleware('permission:groups.manage|attendance.manage')->group(function () {
                Route::get('sessions/{session}/teams', [TeamsController::class, 'session']);
                Route::post('sessions/{session}/teams', [TeamsController::class, 'createMeeting'])->middleware('throttle:30,1');
                Route::delete('sessions/{session}/teams', [TeamsController::class, 'cancelMeeting']);
                Route::post('sessions/{session}/teams/attendance', [TeamsController::class, 'syncAttendance'])->middleware('throttle:20,1');
                Route::get('groups/{group}/teams', [TeamsController::class, 'group'])->whereUuid('group');
                Route::post('groups/{group}/teams', [TeamsController::class, 'syncGroup'])->whereUuid('group')->middleware('throttle:10,1');
            });
            Route::middleware('permission:assessments.manage')->group(function () {
                Route::put('assessments/{assessment}/forms', [TeamsController::class, 'linkForms']);
                Route::post('assessments/{assessment}/forms/import', [TeamsController::class, 'importForms'])->middleware('throttle:10,1');
            });

            // Security: password policy, MFA, sessions, unlocking.
            Route::get('security-policy', [SecurityPolicyController::class, 'show'])->middleware('permission:security.policy|sessions.manage');
            Route::put('security-policy', [SecurityPolicyController::class, 'update'])->middleware(['permission:security.policy', 'step_up']);
            Route::middleware('permission:sessions.manage')->group(function () {
                Route::get('auth-sessions', [SecurityPolicyController::class, 'sessions']);
                Route::delete('auth-sessions/{authSession}', [SecurityPolicyController::class, 'terminate'])->middleware('step_up');
                Route::post('users/{user}/sessions/terminate', [SecurityPolicyController::class, 'terminateAll'])->middleware('step_up');
                Route::post('users/{user}/unlock', [SecurityPolicyController::class, 'unlock']);
                Route::post('users/{user}/mfa/reset', [SecurityPolicyController::class, 'resetMfa'])->middleware('step_up');
            });

            // Integration hub, webhooks and the event bus.
            Route::middleware('permission:integrations.manage|integrations.logs')->group(function () {
                Route::get('integrations', [IntegrationsController::class, 'index']);
                Route::get('integrations/{key}/logs', [IntegrationsController::class, 'logs'])->where('key', '[a-z_]+');
            });
            Route::put('integrations/{key}', [IntegrationsController::class, 'update'])->where('key', '[a-z_]+')->middleware(['permission:integrations.manage|sso.manage', 'step_up']);
            Route::middleware('permission:integrations.manage')->group(function () {
                Route::post('integrations/{key}/check', [IntegrationsController::class, 'check'])->where('key', '[a-z_]+')->middleware('throttle:20,1');
                Route::post('integrations/{key}/sync', [IntegrationsController::class, 'sync'])->where('key', '[a-z_]+')->middleware('throttle:6,1');
            });
            Route::middleware('permission:webhooks.manage')->group(function () {
                Route::get('webhooks', [WebhooksController::class, 'index']);
                Route::post('webhooks', [WebhooksController::class, 'store']);
                Route::put('webhooks/{subscription}', [WebhooksController::class, 'update']);
                Route::delete('webhooks/{subscription}', [WebhooksController::class, 'destroy']);
                Route::post('webhooks/{subscription}/rotate-secret', [WebhooksController::class, 'rotate']);
                Route::post('webhooks/{subscription}/test', [WebhooksController::class, 'test'])->middleware('throttle:10,1');
                Route::get('webhook-deliveries', [WebhooksController::class, 'deliveries']);
                Route::post('webhook-deliveries/{delivery}/replay', [WebhooksController::class, 'replay']);
            });

            // Reports: the hub (everyone who can open the admin area sees the reports meant for their role), the builder, schedules, KPIs.
            Route::get('report-datasets', [ReportBuilderController::class, 'datasets'])->middleware('permission:reports.builder');
            Route::get('report-definitions', [ReportBuilderController::class, 'index']);
            Route::get('report-definitions/{definition}', [ReportBuilderController::class, 'show']);
            Route::post('report-definitions/{definition}/preview', [ReportBuilderController::class, 'preview']);
            Route::post('report-definitions/{definition}/run', [ReportBuilderController::class, 'run'])->middleware('throttle:20,1');
            Route::post('report-definitions/{definition}/favorite', [ReportBuilderController::class, 'favorite']);
            Route::middleware('permission:reports.builder')->group(function () {
                Route::post('report-definitions', [ReportBuilderController::class, 'store']);
                Route::post('report-definitions/preview', [ReportBuilderController::class, 'previewDraft']);
                Route::put('report-definitions/{definition}', [ReportBuilderController::class, 'update']);
                Route::delete('report-definitions/{definition}', [ReportBuilderController::class, 'destroy']);
                Route::post('report-definitions/{definition}/copy', [ReportBuilderController::class, 'copy']);
            });
            Route::get('report-runs', [ReportBuilderController::class, 'runs']);
            Route::get('report-runs/{run}', [ReportBuilderController::class, 'showRun']);
            Route::get('report-runs/{run}/download', [ReportBuilderController::class, 'download']);
            Route::middleware('permission:reports.schedule')->group(function () {
                Route::get('report-schedules', [ReportBuilderController::class, 'schedules']);
                Route::post('report-schedules', [ReportBuilderController::class, 'storeSchedule']);
                Route::put('report-schedules/{schedule}', [ReportBuilderController::class, 'updateSchedule']);
                Route::delete('report-schedules/{schedule}', [ReportBuilderController::class, 'destroySchedule']);
            });
            Route::middleware('permission:kpi.view')->group(function () {
                Route::get('kpis', [KpiController::class, 'index']);
                Route::post('kpis/refresh', [KpiController::class, 'refresh'])->middleware('throttle:6,1');
                Route::get('kpis/integrity', [KpiController::class, 'integrity']);
                Route::get('kpis/report', [KpiController::class, 'report']);
                Route::put('kpi-targets', [KpiController::class, 'targets'])->middleware('permission:dashboards.manage');
            });
            Route::middleware('permission:dashboards.manage')->group(function () {
                Route::get('dashboard-presets', [DashboardController::class, 'presets']);
                Route::put('dashboard-presets/{slug}', [DashboardController::class, 'savePreset']);
            });

            // Communication center
            Route::middleware('permission:announcements.manage|announcements.publish')->group(function () {
                Route::get('announcements', [AnnouncementController::class, 'index']);
                Route::get('announcements/archive', [AnnouncementController::class, 'archive']);
                Route::post('announcements', [AnnouncementController::class, 'store']);
                Route::put('announcements/pins/order', [AnnouncementController::class, 'reorderPins']);
                Route::put('announcements/{announcement}', [AnnouncementController::class, 'update']);
                Route::post('announcements/{announcement}/publish', [AnnouncementController::class, 'publish']);
                Route::post('announcements/{announcement}/pin', [AnnouncementController::class, 'pin']);
                Route::post('announcements/{announcement}/archive', [AnnouncementController::class, 'archiveOne']);
                Route::post('announcements/{announcement}/unarchive', [AnnouncementController::class, 'unarchive']);
                Route::post('announcements/{announcement}/republish', [AnnouncementController::class, 'republish']);
                Route::post('announcements/{announcement}/media', [AnnouncementController::class, 'media']);
                Route::delete('announcements/{announcement}/media', [AnnouncementController::class, 'removeMedia']);
                Route::post('announcements/{announcement}/attachments', [AnnouncementController::class, 'attach']);
                Route::get('announcements/{announcement}/rsvps', [AnnouncementController::class, 'rsvps']);
                Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy']);
            });
            Route::post('announcements/{announcement}/export-ministry', [AnnouncementController::class, 'exportMinistry'])->middleware('permission:ministry_feed.manage');
            Route::middleware('permission:ministry_feed.manage')->prefix('settings/ministry-site')->group(function () {
                Route::get('/', [MinistrySiteController::class, 'show']);
                Route::put('/', [MinistrySiteController::class, 'update']);
                Route::post('run', [MinistrySiteController::class, 'run'])->middleware('throttle:10,1');
                Route::get('file', [MinistrySiteController::class, 'file']);
            });
            Route::middleware('permission:notifications.rules')->group(function () {
                Route::get('notification-rules', [NotificationRulesController::class, 'index']);
                Route::post('notification-rules', [NotificationRulesController::class, 'store']);
                Route::put('notification-rules/{rule}', [NotificationRulesController::class, 'update']);
                Route::delete('notification-rules/{rule}', [NotificationRulesController::class, 'destroy']);
            });
            Route::middleware('permission:notifications.schedule')->group(function () {
                Route::get('scheduled-notifications', [ScheduledNotificationsController::class, 'index']);
                Route::post('scheduled-notifications', [ScheduledNotificationsController::class, 'store']);
                Route::put('scheduled-notifications/{scheduled}', [ScheduledNotificationsController::class, 'update']);
                Route::delete('scheduled-notifications/{scheduled}', [ScheduledNotificationsController::class, 'destroy']);
            });
            Route::post('notifications/audience/preview', [ScheduledNotificationsController::class, 'previewAudience'])->middleware('permission:announcements.manage|announcements.publish|notifications.schedule');
            Route::middleware('permission:notifications.reports')->group(function () {
                Route::get('notifications/deliveries', [NotificationDeliveriesController::class, 'index']);
                Route::get('notifications/deliveries/export', [NotificationDeliveriesController::class, 'export']);
            });
            Route::middleware('permission:cms.manage')->group(function () {
                Route::get('pages/{page}/blocks', [CmsController::class, 'blocks']);
                Route::put('pages/{page}/blocks', [CmsController::class, 'saveBlocks']);
                Route::get('pages/{page}/preview', [CmsController::class, 'preview']);
                Route::post('pages/{page}/publish', [CmsController::class, 'publish']);
                Route::get('pages/{page}/versions', [CmsController::class, 'versions']);
                Route::post('pages/{page}/rollback/{version}', [CmsController::class, 'rollback'])->whereNumber('version');
                Route::get('public-stats', [CmsController::class, 'stats']);
                Route::put('public-stats', [CmsController::class, 'saveStats']);
            });

            // AI assistant
            Route::middleware('permission:ai.assistant')->group(function () {
                Route::get('ai/status', [AiAssistantController::class, 'status']);
                Route::post('ai/ask', [AiAssistantController::class, 'ask'])->middleware('throttle:ai');
                Route::get('ai/history', [AiAssistantController::class, 'history']);
            });

            // Reports
            Route::middleware('permission:reports.view')->group(function () {
                Route::get('reports', [ReportController::class, 'index']);
                Route::get('reports/programs/{program}', [ReportController::class, 'program']);
                Route::post('reports/executive-snapshot', [ReportController::class, 'snapshot']);
            });

            // Brand Studio (appearance)
            Route::middleware('permission:settings.manage')->group(function () {
                Route::put('theme', [ThemeController::class, 'update']);
                Route::post('theme/reset', [ThemeController::class, 'reset']);
                Route::post('theme/assets', [ThemeController::class, 'upload']);

                // Labels, security and attendance rules
                Route::get('settings/labels', [LabelController::class, 'show']);
                Route::put('settings/labels', [LabelController::class, 'update']);
                Route::get('settings/security', [SecuritySettingsController::class, 'show']);
                Route::put('settings/security', [SecuritySettingsController::class, 'update']);
                // Attendance rules (location check)
                Route::get('rfp-status', RfpStatusController::class);
                Route::get('features', [FeaturesController::class, 'index']);
                Route::put('features/{key}', [FeaturesController::class, 'update'])->middleware('throttle:30,1');
                Route::get('settings/ai-models', [AiModelsController::class, 'show']);
                Route::post('settings/ai-models/connections', [AiModelsController::class, 'saveConnection']);
                Route::put('settings/ai-models/connections/{id}', [AiModelsController::class, 'saveConnection']);
                Route::delete('settings/ai-models/connections/{id}', [AiModelsController::class, 'deleteConnection']);
                Route::get('settings/ai-models/connections/{id}/catalog', [AiModelsController::class, 'catalog'])->middleware('throttle:20,1');
                Route::post('settings/ai-models/models', [AiModelsController::class, 'saveModel']);
                Route::put('settings/ai-models/models/{id}', [AiModelsController::class, 'saveModel']);
                Route::delete('settings/ai-models/models/{id}', [AiModelsController::class, 'deleteModel']);
                Route::put('settings/ai-models/assignments', [AiModelsController::class, 'assign']);
                Route::post('settings/ai-models/models/{id}/test', [AiModelsController::class, 'test'])->middleware('throttle:20,1');
                Route::get('settings/lobby-screen', [LobbyScreenController::class, 'show']);
                Route::put('settings/lobby-screen', [LobbyScreenController::class, 'update']);
                Route::post('settings/lobby-screen/slides', [LobbyScreenController::class, 'addSlide'])->middleware('throttle:30,1');
                Route::put('settings/lobby-screen/slides/order', [LobbyScreenController::class, 'order']);
                Route::put('settings/lobby-screen/slides/{slide}', [LobbyScreenController::class, 'updateSlide']);
                Route::delete('settings/lobby-screen/slides/{slide}', [LobbyScreenController::class, 'destroySlide']);
                Route::post('settings/lobby-screen/token', [LobbyScreenController::class, 'regenerateToken']);
                Route::get('settings/room-screen', [RoomScreenSettingsController::class, 'show']);
                Route::put('settings/room-screen', [RoomScreenSettingsController::class, 'update']);
                Route::post('settings/room-screen/reset', [RoomScreenSettingsController::class, 'reset']);
                Route::get('settings/training-day', [TrainingDaySettingsController::class, 'show']);
                Route::put('settings/training-day', [TrainingDaySettingsController::class, 'update']);
                Route::get('settings/attendance', [AttendanceSettingsController::class, 'show']);
                Route::put('settings/attendance', [AttendanceSettingsController::class, 'update']);

                // Push notifications (Firebase)
                Route::get('settings/push', [PushSettingsController::class, 'show']);
                Route::put('settings/push', [PushSettingsController::class, 'update']);
                Route::get('settings/push/recipients', [PushSettingsController::class, 'recipients']);
                Route::post('settings/push/verify', [PushSettingsController::class, 'verify']);
                Route::post('settings/push/test', [PushSettingsController::class, 'test'])->middleware('throttle:10,1');
            });

            // Error log — system administrator only (the permission is held by no role but the super admin's all-access)
            Route::middleware('permission:logs.manage')->prefix('error-logs')->controller(ErrorLogController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('badge', 'badge');
                Route::get('prompt', 'prompt');
                Route::get('settings', 'settings');
                Route::put('settings', 'updateSettings');
                Route::post('bulk', 'bulk');
                Route::get('{log}', 'show');
                Route::put('{log}', 'update');
                Route::post('{log}/fix', 'fix');
                Route::delete('{log}', 'destroy');
            });

            // Users, roles, audit
            Route::middleware('permission:users.manage')->group(function () {
                Route::middleware('feature:test_accounts')->group(function () {
                    Route::get('test-accounts', [TestAccountsController::class, 'show']);
                    Route::post('test-accounts', [TestAccountsController::class, 'seed']);
                    Route::post('test-accounts/scenario', [TestAccountsController::class, 'scenario'])->middleware(['feature:demo_scenarios', 'throttle:30,1']);
                });
                Route::post('users/{user}/impersonate', [ImpersonationController::class, 'start'])->middleware(['feature:impersonation', 'throttle:20,1']);
                Route::post('users/{user}/impersonate/stop', [ImpersonationController::class, 'stop'])->middleware('feature:impersonation');
                Route::get('users', [UserController::class, 'index']);
                Route::post('users', [UserController::class, 'store']);
                Route::put('users/{user}', [UserController::class, 'update']);
                Route::get('users/{user}/roles', [UserRoleController::class, 'index']);
                Route::post('users/{user}/roles', [UserRoleController::class, 'store']);
                Route::delete('users/{user}/roles/{roleUser}', [UserRoleController::class, 'destroy']);
                Route::get('roles', [UserController::class, 'roles']);
            });
            Route::put('roles/{role}/permissions', [UserController::class, 'updateRolePermissions'])->middleware('permission:roles.manage');
            Route::middleware('permission:roles.create')->group(function () {
                Route::post('roles', [RoleAdminController::class, 'store']);
                Route::put('roles/{role}', [RoleAdminController::class, 'update']);
                Route::delete('roles/{role}', [RoleAdminController::class, 'destroy']);
            });
            Route::get('staff-lookup', [UserRoleController::class, 'lookup'])->middleware('permission:program_grants.manage|users.manage');
            // School internal workshops: school submits and runs them, the centre approves.
            Route::middleware('permission:workshops.internal|workshops.approve')->group(function () {
                Route::get('internal-workshops', [InternalWorkshopController::class, 'index']);
            });
            Route::middleware('permission:workshops.internal')->group(function () {
                Route::post('internal-workshops', [InternalWorkshopController::class, 'store']);
                Route::post('internal-workshops/{program}/register', [InternalWorkshopController::class, 'register']);
            });
            Route::post('internal-workshops/{program}/decision', [InternalWorkshopController::class, 'decision'])->middleware('permission:workshops.approve');
            // Needs cycle: window, department proposals, manager requests.
            Route::get('needs-cycles', [NeedsCycleController::class, 'index'])->middleware('permission:needs.cycles|needs.propose|needs.request');
            Route::middleware('permission:needs.cycles')->group(function () {
                Route::post('needs-cycles', [NeedsCycleController::class, 'store']);
                Route::get('needs-cycles/{cycle}', [NeedsCycleController::class, 'show']);
                Route::put('needs-cycles/{cycle}', [NeedsCycleController::class, 'update']);
                Route::post('needs-cycles/{cycle}/open', [NeedsCycleController::class, 'open']);
                Route::post('needs-cycles/{cycle}/close', [NeedsCycleController::class, 'close']);
                Route::post('proposals/{proposal}/review', [NeedsCycleController::class, 'reviewProposal']);
                Route::post('institutional-requests/{institutionalRequest}/review', [NeedsCycleController::class, 'reviewRequest']);
            });
            Route::middleware('permission:needs.propose|needs.cycles')->group(function () {
                Route::get('needs-cycles/{cycle}/proposals', [NeedsCycleController::class, 'proposals']);
                Route::post('needs-cycles/{cycle}/proposals', [NeedsCycleController::class, 'storeProposal']);
                Route::put('proposals/{proposal}', [NeedsCycleController::class, 'updateProposal']);
            });
            Route::middleware('permission:needs.request|needs.cycles')->group(function () {
                Route::get('institutional-requests', [NeedsCycleController::class, 'requests']);
                Route::post('institutional-requests', [NeedsCycleController::class, 'storeRequest']);
            });
            // Competency framework and gap analysis.
            Route::middleware('permission:competencies.manage|gaps.view|needs.view')->group(function () {
                Route::get('competency-domains', [CompetencyController::class, 'domains']);
                Route::get('competencies', [CompetencyController::class, 'index']);
                Route::get('competencies/export', [CompetencyController::class, 'export']);
                Route::get('competencies/weights', [CompetencyController::class, 'weights']);
                Route::get('job-titles/{jobTitle}/requirements', [CompetencyController::class, 'requirements']);
            });
            Route::middleware('permission:competencies.manage')->group(function () {
                Route::post('competency-domains', [CompetencyController::class, 'storeDomain']);
                Route::put('competency-domains/{domain}', [CompetencyController::class, 'updateDomain']);
                Route::post('competencies', [CompetencyController::class, 'store']);
                Route::post('competencies/import', [CompetencyController::class, 'import'])->middleware('throttle:20,1');
                Route::put('competencies/weights', [CompetencyController::class, 'weights']);
                Route::put('competencies/{competency}', [CompetencyController::class, 'update']);
                Route::put('job-titles/{jobTitle}/requirements', [CompetencyController::class, 'syncRequirements']);
            });
            Route::middleware('permission:gaps.view')->group(function () {
                Route::get('gaps', [GapController::class, 'index']);
                Route::get('gaps/employees/{employee}', [GapController::class, 'employee']);
            });
            Route::post('gaps/to-plan', [GapController::class, 'toPlan'])->middleware('permission:plans.manage');
            // Individual needs, rules, performance data, instrument approval.
            Route::middleware('permission:needs.approve_individual|needs.cycles')->group(function () {
                Route::get('individual-needs', [NeedsToolsController::class, 'index']);
                Route::post('individual-needs/decide', [NeedsToolsController::class, 'decide']);
            });
            Route::middleware('permission:needs.cycles')->group(function () {
                Route::put('individual-needs/settings', [NeedsToolsController::class, 'settings']);
                Route::get('needs-rules', [NeedsToolsController::class, 'rules']);
                Route::post('needs-rules', [NeedsToolsController::class, 'storeRule']);
                Route::post('needs-rules/run', [NeedsToolsController::class, 'runRules']);
                Route::put('needs-rules/{rule}', [NeedsToolsController::class, 'updateRule']);
                Route::delete('needs-rules/{rule}', [NeedsToolsController::class, 'destroyRule']);
            });
            Route::middleware('permission:performance.import')->prefix('performance')->group(function () {
                Route::post('appraisals/import', [NeedsToolsController::class, 'importAppraisals'])->middleware('throttle:20,1');
                Route::post('observations/import', [NeedsToolsController::class, 'importObservations'])->middleware('throttle:20,1');
                Route::get('weak', [NeedsToolsController::class, 'weak']);
                Route::post('weak/target', [NeedsToolsController::class, 'targetWeak']);
            });
            Route::post('needs-surveys/{needsSurvey}/submit-approval', [NeedsToolsController::class, 'submitApproval'])->middleware('permission:needs.manage');
            Route::post('needs-surveys/{needsSurvey}/approve', [NeedsToolsController::class, 'approve'])->middleware('permission:instruments.approve');
            Route::post('needs-surveys/{needsSurvey}/return', [NeedsToolsController::class, 'returnSurvey'])->middleware('permission:instruments.approve');
            // Places, buildings, bookings, seating plans and logistics.
            Route::middleware('permission:programs.view|rooms.book|logistics.manage')->group(function () {
                Route::get('places', [RoomsOpsController::class, 'places']);
                Route::get('room-bookings', [RoomsOpsController::class, 'bookings']);
                Route::get('rooms/calendar', [RoomsOpsController::class, 'calendar']);
                Route::get('rooms/{room}/seating', [RoomsOpsController::class, 'seat']);
            });
            Route::middleware('permission:places.manage')->group(function () {
                Route::post('places', [RoomsOpsController::class, 'savePlace']);
                Route::put('places/{place}', [RoomsOpsController::class, 'savePlace']);
                Route::post('buildings', [RoomsOpsController::class, 'saveBuilding']);
                Route::put('buildings/{building}', [RoomsOpsController::class, 'saveBuilding']);
            });
            Route::middleware('permission:rooms.book|rooms.manage')->group(function () {
                Route::post('room-bookings', [RoomsOpsController::class, 'storeBooking']);
                Route::put('room-bookings/{booking}', [RoomsOpsController::class, 'updateBooking']);
                Route::delete('room-bookings/{booking}', [RoomsOpsController::class, 'cancelBooking']);
            });
            Route::middleware('permission:seating.manage')->group(function () {
                Route::put('rooms/{room}/seating', [RoomsOpsController::class, 'saveSeating']);
                Route::post('rooms/{room}/seating/auto', [RoomsOpsController::class, 'autoSeat']);
            });
            Route::middleware('permission:programs.view|logistics.manage')->group(function () {
                Route::get('logistics-requests', [RoomsOpsController::class, 'logisticsIndex']);
                Route::post('logistics-requests', [RoomsOpsController::class, 'logisticsStore']);
                Route::put('logistics-requests/{logisticsRequest}', [RoomsOpsController::class, 'logisticsUpdate']);
            });
            Route::get('course/lessons/{lesson}/interactions', [VideoInteractionController::class, 'index'])->middleware('permission:programs.view');
            Route::put('course/lessons/{lesson}/interactions', [VideoInteractionController::class, 'save'])->middleware('permission:programs.manage');
            // Content packages and the standards settings.
            Route::middleware('permission:packages.manage')->group(function () {
                Route::get('packages', [PackageController::class, 'index']);
                Route::get('packages/{package}', [PackageController::class, 'show']);
                Route::post('packages', [PackageController::class, 'store'])->middleware('throttle:20,1');
                Route::post('packages/sign', [PackageController::class, 'sign']);
                Route::post('packages/process', [PackageController::class, 'process'])->middleware('throttle:20,1');
                Route::delete('packages/{package}', [PackageController::class, 'destroy']);
                Route::put('course/lessons/{lesson}/package', [PackageController::class, 'attach']);
            });
            Route::middleware('permission:packages.manage')->group(function () {
                Route::get('packages/{package}/cc/preview', [ContentImportController::class, 'ccPreview']);
                Route::post('packages/{package}/cc/import', [ContentImportController::class, 'ccImport']);
                Route::get('content-imports', [ContentImportController::class, 'imports']);
            });
            Route::middleware('permission:banks.manage|assessments.manage')->group(function () {
                Route::post('question-banks/{bank}/qti/import', [ContentImportController::class, 'qtiImport'])->middleware('throttle:20,1');
                Route::get('question-banks/{bank}/qti/export', [ContentImportController::class, 'qtiExport']);
            });
            Route::middleware('permission:library.manage')->group(function () {
                Route::post('library-items', [LibraryController::class, 'store']);
                Route::put('library-items/{item}', [LibraryController::class, 'update']);
                Route::delete('library-items/{item}', [LibraryController::class, 'destroy']);
                Route::post('library-items/{item}/files', [LibraryController::class, 'upload'])->middleware('throttle:30,1');
                Route::get('library-collections', [LibraryController::class, 'collections']);
                Route::post('library-collections', [LibraryController::class, 'saveCollection']);
                Route::put('library-collections/{collection}', [LibraryController::class, 'saveCollection']);
                Route::delete('library-collections/{collection}', [LibraryController::class, 'deleteCollection']);
                Route::get('external-libraries/search', [LibraryController::class, 'externalSearch'])->middleware('throttle:30,1');
                Route::post('external-libraries/import', [LibraryController::class, 'externalImport']);
                Route::match(['GET', 'PUT'], 'settings/external-libraries', [LibraryController::class, 'externalSettings']);
            });
            Route::middleware('permission:providers.manage')->group(function () {
                Route::match(['GET', 'PUT'], 'settings/content-providers', [ExternalLearningController::class, 'settings']);
                Route::post('content-providers/sync', [ExternalLearningController::class, 'sync']);
                Route::get('external-courses', [ExternalLearningController::class, 'catalogue']);
                Route::post('external-courses/{course}/program', [ExternalLearningController::class, 'createProgram']);
            });
            Route::middleware('permission:providers.manage|programs.manage')->group(function () {
                Route::get('external-completions', [ExternalLearningController::class, 'completions']);
                Route::post('external-completions/{completion}/decision', [ExternalLearningController::class, 'review']);
            });
            Route::middleware('permission:sharing.manage|library.view')->group(function () {
                Route::post('shares', [LibraryController::class, 'share']);
                Route::get('shares', [LibraryController::class, 'shares']);
                Route::delete('shares/{share}', [LibraryController::class, 'unshare']);
            });
            Route::middleware('permission:sharing.manage')->group(function () {
                Route::get('sharing-policies', [ContentLifecycleController::class, 'policies']);
                Route::post('sharing-policies', [ContentLifecycleController::class, 'savePolicy']);
                Route::delete('sharing-policies/{policy}', [ContentLifecycleController::class, 'deletePolicy']);
            });
            Route::middleware('permission:job_groups.manage|sharing.manage')->group(function () {
                Route::get('job-groups', [ContentLifecycleController::class, 'jobGroups']);
                Route::post('job-groups', [ContentLifecycleController::class, 'saveJobGroup']);
                Route::put('job-groups/{group}', [ContentLifecycleController::class, 'saveJobGroup']);
                Route::delete('job-groups/{group}', [ContentLifecycleController::class, 'deleteJobGroup']);
                Route::get('job-groups/{group}/members', [ContentLifecycleController::class, 'jobGroupMembers']);
            });
            Route::middleware('permission:programs.manage')->group(function () {
                Route::get('course/lessons/{lesson}/versions', [ContentLifecycleController::class, 'versions']);
                Route::post('course/lessons/{lesson}/versions', [ContentLifecycleController::class, 'publish']);
                Route::get('course/lessons/{lesson}/versions/diff', [ContentLifecycleController::class, 'diff']);
                Route::post('course/lessons/{lesson}/versions/{version}/restore', [ContentLifecycleController::class, 'restore'])->whereNumber('version');
                Route::put('course/lessons/{lesson}/versions/{version}/archive', [ContentLifecycleController::class, 'archive'])->whereNumber('version');
                Route::get('kits/{kit}/programs', [ContentLifecycleController::class, 'kitPrograms']);
                Route::put('kits/{kit}/programs', [ContentLifecycleController::class, 'syncKitPrograms']);
            });
            Route::middleware('permission:lti.manage')->group(function () {
                Route::get('lti-tools', [LtiToolController::class, 'index']);
                Route::post('lti-tools', [LtiToolController::class, 'save']);
                Route::put('lti-tools/{tool}', [LtiToolController::class, 'save']);
                Route::delete('lti-tools/{tool}', [LtiToolController::class, 'destroy']);
                Route::post('lti-tools/rotate-keys', [LtiToolController::class, 'rotate']);
                Route::post('lti-tools/{tool}/deep-link', [LtiToolController::class, 'deepLink']);
            });
            Route::middleware('permission:standards.manage')->group(function () {
                Route::get('settings/standards', [StandardsController::class, 'show']);
                Route::put('settings/standards', [StandardsController::class, 'update']);
                Route::post('settings/standards/lrs-credentials', [StandardsController::class, 'addCredential']);
                Route::delete('settings/standards/lrs-credentials/{key}', [StandardsController::class, 'removeCredential']);
                Route::post('caliper/flush', [StandardsController::class, 'flushCaliper']);
            });
            // Career paths, licences, professional development and knowledge transfer.
            Route::middleware('permission:paths.manage|licences.manage')->group(function () {
                Route::get('career-paths', [CareerAdminController::class, 'index']);
                Route::get('career-paths/{path}/compliance', [CareerAdminController::class, 'compliance']);
            });
            Route::middleware('permission:paths.manage')->group(function () {
                Route::post('career-paths', [CareerAdminController::class, 'store']);
                Route::put('career-paths/{path}', [CareerAdminController::class, 'update']);
                Route::put('career-paths/{path}/levels', [CareerAdminController::class, 'levels']);
                Route::post('career-paths/evaluate', [CareerAdminController::class, 'evaluate']);
                Route::post('career-paths/{path}/achieve', [CareerAdminController::class, 'achieve']);
            });
            Route::middleware('permission:licences.manage')->group(function () {
                Route::get('licences', [CareerAdminController::class, 'licences']);
                Route::post('licences', [CareerAdminController::class, 'saveLicence']);
                Route::put('licences/{licence}', [CareerAdminController::class, 'saveLicence']);
                Route::post('licences/import', [CareerAdminController::class, 'importLicences'])->middleware('throttle:20,1');
            });
            Route::middleware('permission:pd.types.manage')->group(function () {
                Route::post('pd-activity-types', [PdAdminController::class, 'saveType']);
                Route::put('pd-activity-types/{type}', [PdAdminController::class, 'saveType']);
            });
            Route::get('pd-activity-types', [PdAdminController::class, 'types'])->middleware('permission:pd.types.manage|pd.recognise');
            Route::middleware('permission:pd.recognise|pd.approve')->group(function () {
                Route::get('pd-activities', [PdAdminController::class, 'activities']);
                Route::post('pd-activities/{activity}/decision', [PdAdminController::class, 'decide']);
                Route::get('pd-recognitions', [PdAdminController::class, 'recognitions']);
                Route::get('pd-reports', [PdAdminController::class, 'report']);
            });
            Route::post('pd-recognitions/{recognition}/decision', [PdAdminController::class, 'recognise'])->middleware('permission:pd.recognise');
            Route::get('pd-targets', [PdAdminController::class, 'targets'])->middleware('permission:pd.targets.manage|pd.recognise');
            Route::put('pd-targets', [PdAdminController::class, 'saveTargets'])->middleware('permission:pd.targets.manage');
            Route::middleware('permission:knowledge_transfer.review')->group(function () {
                Route::get('knowledge-transfers', [PdAdminController::class, 'transfers']);
                Route::post('knowledge-transfers/{transfer}/decision', [PdAdminController::class, 'decideTransfer']);
                Route::get('programs/{program}/knowledge-transfer', [PdAdminController::class, 'reach']);
            });
            Route::put('programs/{program}/knowledge-transfer', [PdAdminController::class, 'saveKnowledgeTransferSetting'])->middleware('permission:programs.manage');
            // Evaluation: forms and approval, settings, the evaluation centre, interviews, reports, alerts and exports.
            Route::middleware('permission:evaluations.manage')->group(function () {
                Route::get('evaluation-forms', [EvaluationFormController::class, 'index']);
                Route::post('evaluation-forms', [EvaluationFormController::class, 'store']);
                Route::put('evaluation-forms/{form}', [EvaluationFormController::class, 'update']);
                Route::delete('evaluation-forms/{form}', [EvaluationFormController::class, 'destroy']);
                Route::post('evaluation-forms/{form}/submit-approval', [EvaluationFormController::class, 'submitApproval']);
                Route::get('settings/evaluation', [EvaluationFormController::class, 'settings']);
                Route::put('settings/evaluation', [EvaluationFormController::class, 'updateSettings']);
                Route::get('settings/impact-schedule', [EvaluationFormController::class, 'settings'])->defaults('part', 'impact');
                Route::put('settings/impact-schedule', [EvaluationFormController::class, 'updateSettings'])->defaults('part', 'impact');
                Route::post('programs/{program}/evaluations/assign', [GroupEvaluationController::class, 'assign']);
                Route::post('evaluation-assignments/{assignment}/remind', [GroupEvaluationController::class, 'remind']);
                Route::get('evaluations/assignable-users', [GroupEvaluationController::class, 'assignable']);
            });
            Route::post('evaluation-forms/{form}/{decision}', [EvaluationFormController::class, 'decide'])->where('decision', 'approve|return')->middleware('permission:instruments.approve');
            Route::get('settings/satisfaction-alerts', [EvaluationFormController::class, 'settings'])->defaults('part', 'alerts')->middleware('permission:satisfaction_alerts.manage|evaluations.manage');
            Route::put('settings/satisfaction-alerts', [EvaluationFormController::class, 'updateSettings'])->defaults('part', 'alerts')->middleware('permission:satisfaction_alerts.manage');
            Route::middleware('permission:evaluations.manage|evaluation_reports.prepare|impact.view')->group(function () {
                Route::get('programs/{program}/evaluations', [GroupEvaluationController::class, 'board']);
                Route::get('programs/{program}/evaluations/{kind}/results', [GroupEvaluationController::class, 'results']);
                Route::get('evaluation-responses/{response}/evidence/{question}/{index}', [GroupEvaluationController::class, 'evidenceUrl']);
                Route::get('programs/{program}/comparative', [EvaluationInsightsController::class, 'comparative']);
                Route::get('programs/{program}/satisfaction-alerts', [EvaluationInsightsController::class, 'alerts']);
                Route::get('evaluations/satisfaction-ranking', [GroupEvaluationController::class, 'ranking']);
                Route::get('programs/{program}/evaluation-reports', [EvaluationReportController::class, 'index']);
                Route::get('programs/{program}/evaluation-reports/preview', [EvaluationReportController::class, 'preview']);
                Route::get('evaluation-reports/{report}/export', [EvaluationReportController::class, 'export']);
                Route::get('programs/{program}/satisfaction/export', [SurveyExportController::class, 'satisfaction']);
                Route::get('evaluation-forms/{form}/export', [SurveyExportController::class, 'form']);
            });
            Route::middleware('permission:evaluation_reports.prepare')->group(function () {
                Route::post('programs/{program}/evaluation-reports', [EvaluationReportController::class, 'store']);
                Route::put('evaluation-reports/{report}', [EvaluationReportController::class, 'update']);
            });
            Route::post('evaluation-reports/{report}/approve', [EvaluationReportController::class, 'approve'])->middleware('permission:evaluation_reports.approve');
            Route::middleware('permission:interviews.manage')->group(function () {
                Route::get('programs/{program}/interviews', [EvaluationInsightsController::class, 'interviews']);
                Route::post('programs/{program}/interviews', [EvaluationInsightsController::class, 'storeInterview']);
                Route::put('interviews/{interview}', [EvaluationInsightsController::class, 'updateInterview']);
                Route::delete('interviews/{interview}', [EvaluationInsightsController::class, 'destroyInterview']);
            });
            Route::get('surveys/{needsSurvey}/export', [SurveyExportController::class, 'needs'])->middleware('permission:needs.view|needs.manage');
            // Passing policies, exceptions and the final approval of tasks.
            Route::middleware('permission:passing.manage')->group(function () {
                Route::get('passing-policies/{scope}/{id?}', [PassingController::class, 'show'])->where('scope', 'global|program|group');
                Route::put('passing-policies/{scope}/{id?}', [PassingController::class, 'save'])->where('scope', 'global|program|group');
                Route::delete('passing-policies/{scope}/{id?}', [PassingController::class, 'destroy'])->where('scope', 'global|program|group');
                Route::post('passing-policies/preview', [PassingController::class, 'preview']);
            });
            Route::get('registrations/{registration}/pass-status', [PassingController::class, 'status'])->middleware('permission:registrations.view');
            Route::post('registrations/{registration}/exceptions', [PassingController::class, 'grantException'])->middleware('permission:pass_exceptions.grant');
            Route::delete('pass-exceptions/{exception}', [PassingController::class, 'revokeException'])->middleware('permission:pass_exceptions.grant');
            Route::get('pass-exceptions/{exception}/file', [PassingController::class, 'exceptionFile'])->middleware('permission:registrations.view');
            Route::post('submissions/{submission}/final-decision', [TaskController::class, 'finalDecision'])->middleware('permission:tasks.final_approve');
            Route::put('sessions/{session}/participation', [PassingController::class, 'participation'])->middleware('permission:attendance.manage');
            // Assessments.
            Route::middleware('permission:assessments.manage')->group(function () {
                Route::get('programs/{program}/assessments', [AssessmentController::class, 'index']);
                Route::post('programs/{program}/assessments', [AssessmentController::class, 'store']);
                Route::get('assessments/{assessment}', [AssessmentController::class, 'show']);
                Route::put('assessments/{assessment}', [AssessmentController::class, 'update']);
                Route::delete('assessments/{assessment}', [AssessmentController::class, 'destroy']);
                Route::put('assessments/{assessment}/sections', [AssessmentController::class, 'sections']);
                Route::post('assessments/{assessment}/validate', [AssessmentController::class, 'validateAssessment']);
                Route::post('assessments/{assessment}/publish', [AssessmentController::class, 'publish']);
                Route::post('assessments/{assessment}/access-codes', [AssessmentController::class, 'accessCode']);
                Route::post('assessments/{assessment}/regrade', [AssessmentController::class, 'regrade']);
                Route::post('assessments/{assessment}/release', [AssessmentController::class, 'release']);
            });
            Route::middleware('permission:assessments.invigilate|assessments.manage')->group(function () {
                Route::get('assessments/{assessment}/live', [AssessmentController::class, 'live']);
                Route::post('attempts/{attempt}/extend', [AssessmentController::class, 'extend']);
                Route::post('attempts/{attempt}/void', [AssessmentController::class, 'void']);
            });
            Route::middleware('permission:assessments.grade|assessments.manage')->group(function () {
                Route::get('assessments/{assessment}/grading', [AssessmentController::class, 'grading']);
                Route::post('attempts/{attempt}/grade', [AssessmentController::class, 'grade']);
            });
            Route::middleware('permission:assessments.analytics|assessments.manage')->group(function () {
                Route::get('assessments/{assessment}/analytics', [AssessmentController::class, 'analytics']);
                Route::get('programs/{program}/knowledge-gain', [AssessmentController::class, 'knowledgeGain']);
            });
            // Question banks.
            Route::middleware('permission:banks.manage|assessments.manage')->group(function () {
                Route::get('question-types', [QuestionBankController::class, 'types']);
                Route::get('question-banks', [QuestionBankController::class, 'index']);
                Route::post('question-banks', [QuestionBankController::class, 'store']);
                Route::put('question-banks/{bank}', [QuestionBankController::class, 'update']);
                Route::delete('question-banks/{bank}', [QuestionBankController::class, 'destroy']);
                Route::get('question-banks/{bank}/categories', [QuestionBankController::class, 'categories']);
                Route::post('question-banks/{bank}/categories', [QuestionBankController::class, 'saveCategory']);
                Route::put('question-banks/{bank}/categories/{category}', [QuestionBankController::class, 'saveCategory']);
                Route::get('question-banks/{bank}/questions', [QuestionBankController::class, 'questions']);
                Route::post('question-banks/{bank}/questions', [QuestionBankController::class, 'storeQuestion']);
                Route::post('question-banks/{bank}/questions/bulk', [QuestionBankController::class, 'bulk']);
                Route::post('question-banks/{bank}/import', [QuestionBankController::class, 'import'])->middleware('throttle:20,1');
                Route::get('question-banks/{bank}/export', [QuestionBankController::class, 'export']);
                Route::put('questions/{question}', [QuestionBankController::class, 'updateQuestion']);
                Route::delete('questions/{question}', [QuestionBankController::class, 'destroyQuestion']);
            });
            // Absence alerts, excuses and leaves.
            Route::get('absence-alerts', [AbsenceController::class, 'alerts'])->middleware('permission:attendance.manage');
            Route::put('absence-alerts/{alert}', [AbsenceController::class, 'updateAlert'])->middleware('permission:attendance.manage');
            Route::get('excuses', [AbsenceController::class, 'excuses'])->middleware('permission:registrations.approve_manager|excuses.decide');
            Route::post('excuses/{excuse}/decision', [AbsenceController::class, 'decideExcuse']);
            Route::post('attendance/{attendance}/leaves', [AbsenceController::class, 'storeLeave'])->middleware('permission:leaves.manage|attendance.manage');
            Route::delete('attendance-leaves/{leave}', [AbsenceController::class, 'destroyLeave'])->middleware('permission:leaves.manage|attendance.manage');
            // Attendance devices (fingerprint / badge).
            Route::middleware('permission:attendance.devices')->group(function () {
                Route::get('attendance-devices', [AttendanceDeviceController::class, 'index']);
                Route::post('attendance-devices', [AttendanceDeviceController::class, 'save']);
                Route::put('attendance-devices/{device}', [AttendanceDeviceController::class, 'save']);
                Route::delete('attendance-devices/{device}', [AttendanceDeviceController::class, 'destroy']);
                Route::post('attendance-devices/{device}/test', [AttendanceDeviceController::class, 'test']);
                Route::post('attendance-devices/{device}/import', [AttendanceDeviceController::class, 'import'])->middleware('throttle:20,1');
                Route::get('attendance-devices/{device}/log', [AttendanceDeviceController::class, 'log']);
            });
            // Admission: seats per entity and the two approval stages.
            Route::middleware('permission:programs.view')->get('groups/{group}/seats', [AdmissionController::class, 'seats']);
            Route::middleware('permission:seats.manage|groups.manage')->put('groups/{group}/seats', [AdmissionController::class, 'updateSeats']);
            Route::middleware('permission:priority.manage')->group(function () {
                Route::get('priority-rules', [AdmissionRulesController::class, 'rules']);
                Route::post('priority-rules', [AdmissionRulesController::class, 'saveRule']);
                Route::put('priority-rules/{rule}', [AdmissionRulesController::class, 'saveRule']);
                Route::delete('priority-rules/{rule}', [AdmissionRulesController::class, 'deleteRule']);
                Route::post('priority-rules/preview', [AdmissionRulesController::class, 'preview']);
            });
            Route::get('programs/{program}/equivalences', [AdmissionRulesController::class, 'equivalences'])->middleware('permission:programs.view');
            Route::put('programs/{program}/equivalences', [AdmissionRulesController::class, 'syncEquivalences'])->middleware('permission:programs.manage');
            Route::get('groups/{group}/candidates', [AdmissionRulesController::class, 'candidates'])->middleware('permission:registrations.view');
            Route::post('groups/{group}/accept', [AdmissionRulesController::class, 'accept'])->middleware('permission:registrations.manage|registrations.approve_center');
            // External registration: form builder and review queue.
            Route::middleware('permission:external_forms.manage')->group(function () {
                Route::get('registration-forms', [ExternalRequestController::class, 'forms']);
                Route::post('registration-forms', [ExternalRequestController::class, 'saveForm']);
                Route::put('registration-forms/{form}', [ExternalRequestController::class, 'saveForm']);
            });
            Route::middleware('permission:external_requests.review')->group(function () {
                Route::get('registration-requests', [ExternalRequestController::class, 'requests']);
                Route::get('registration-requests/{registrationRequest}', [ExternalRequestController::class, 'show']);
                Route::get('registration-requests/{registrationRequest}/snapshot', [ExternalRequestController::class, 'snapshot']);
                Route::post('registration-requests/{registrationRequest}/approve', [ExternalRequestController::class, 'approve']);
                Route::post('registration-requests/{registrationRequest}/reject', [ExternalRequestController::class, 'reject']);
                Route::post('registration-requests/{registrationRequest}/request-info', [ExternalRequestController::class, 'requestInfo']);
            });
            // Withdrawal queues, policy and reasons.
            Route::get('withdrawals', [WithdrawalController::class, 'index'])->middleware('permission:registrations.approve_manager|withdrawals.decide');
            Route::post('withdrawals/{withdrawal}/decision', [WithdrawalController::class, 'decision'])->middleware('permission:registrations.approve_manager|withdrawals.decide');
            Route::middleware('permission:withdrawals.policy')->group(function () {
                Route::get('settings/withdrawal-policy', [WithdrawalController::class, 'policy']);
                Route::put('settings/withdrawal-policy', [WithdrawalController::class, 'policy']);
                Route::get('withdrawal-reasons', [WithdrawalController::class, 'reasons']);
                Route::post('withdrawal-reasons', [WithdrawalController::class, 'saveReason']);
                Route::put('withdrawal-reasons/{reason}', [WithdrawalController::class, 'saveReason']);
            });
            Route::get('approvals/{stage}', [AdmissionController::class, 'queue'])->middleware('permission:registrations.approve_manager|registrations.manage');
            Route::post('registrations/{registration}/manager-decision', [AdmissionController::class, 'managerDecision']);
            // Annual training plan.
            Route::middleware('permission:plans.view')->group(function () {
                Route::get('plans', [AnnualPlanController::class, 'index']);
                Route::get('plans/{plan}', [AnnualPlanController::class, 'show']);
                Route::get('plans/{plan}/execution', [AnnualPlanController::class, 'execution']);
                Route::get('plans/{plan}/changes', [AnnualPlanController::class, 'changes']);
                Route::get('plans/{plan}/export', [AnnualPlanController::class, 'export']);
            });
            Route::middleware('permission:plans.manage')->group(function () {
                Route::post('plans', [AnnualPlanController::class, 'store']);
                Route::put('plans/{plan}', [AnnualPlanController::class, 'update']);
                Route::put('plans/{plan}/rules', [AnnualPlanController::class, 'rules']);
                Route::post('plans/{plan}/generate', [AnnualPlanController::class, 'generate']);
                Route::post('plans/{plan}/items', [AnnualPlanController::class, 'storeItem']);
                Route::put('plans/{plan}/items/{item}', [AnnualPlanController::class, 'updateItem']);
                Route::delete('plans/{plan}/items/{item}', [AnnualPlanController::class, 'destroyItem']);
                Route::post('plans/{plan}/submit', [AnnualPlanController::class, 'submit']);
            });
            Route::middleware('permission:plans.approve')->group(function () {
                Route::post('plans/{plan}/return', [AnnualPlanController::class, 'return']);
                Route::post('plans/{plan}/approve', [AnnualPlanController::class, 'approve']);
                Route::post('plans/{plan}/activate', [AnnualPlanController::class, 'activate']);
                Route::post('plans/{plan}/close', [AnnualPlanController::class, 'close']);
            });
            // Training groups: every program runs as one or more groups.
            Route::middleware('permission:programs.view')->group(function () {
                Route::get('programs/{program}/groups', [TrainingGroupController::class, 'index']);
                Route::get('groups/board', [TrainingGroupController::class, 'board']);
                Route::get('groups/{group}', [TrainingGroupController::class, 'show']);
                Route::get('groups/{group}/sessions', [TrainingGroupController::class, 'sessions']);
                Route::get('groups/{group}/participants', [TrainingGroupController::class, 'participants']);
            });
            Route::middleware('permission:groups.manage')->group(function () {
                Route::post('programs/{program}/groups', [TrainingGroupController::class, 'store']);
                Route::post('programs/{program}/groups/preview', [TrainingGroupController::class, 'preview']);
                Route::put('groups/{group}', [TrainingGroupController::class, 'update']);
                Route::delete('groups/{group}', [TrainingGroupController::class, 'destroy']);
                Route::post('groups/{group}/clone', [TrainingGroupController::class, 'clone']);
                Route::post('groups/{group}/publish', [TrainingGroupController::class, 'publish']);
                Route::post('groups/{group}/unpublish', [TrainingGroupController::class, 'unpublish']);
                Route::post('groups/{group}/trainers', [TrainerAssignmentController::class, 'store']);
                Route::delete('group-trainers/{groupTrainer}', [TrainerAssignmentController::class, 'destroy']);
                Route::post('programs/{program}/kit-developers', [TrainerAssignmentController::class, 'kitDevelopers']);
            });
            Route::post('group-trainers/{groupTrainer}/decision', [TrainerAssignmentController::class, 'decision'])->middleware('permission:trainers.approve|groups.status');
            Route::post('groups/{group}/status', [TrainingGroupController::class, 'status'])->middleware('permission:groups.status');
            Route::middleware('permission:programs.manage')->group(function () {
                Route::post('programs/{program}/sub-programs', [ProgramStructureController::class, 'storeSub']);
                Route::put('programs/{program}/units', [ProgramStructureController::class, 'syncUnits']);
            });
            Route::get('programs/{program}/tree', [ProgramStructureController::class, 'tree'])->middleware('permission:programs.view');
            Route::middleware('permission:program_grants.manage')->group(function () {
                Route::get('programs/{program}/grants', [ProgramGrantController::class, 'index']);
                Route::post('programs/{program}/grants', [ProgramGrantController::class, 'store']);
                Route::delete('programs/{program}/grants/{grant}', [ProgramGrantController::class, 'destroy']);
            });
            Route::middleware('permission:scopes.manage')->group(function () {
                Route::post('school-groups/import', [SchoolGroupController::class, 'import'])->middleware('throttle:20,1');
                Route::get('school-groups', [SchoolGroupController::class, 'index']);
                Route::post('school-groups', [SchoolGroupController::class, 'store']);
                Route::get('school-groups/{schoolGroup}', [SchoolGroupController::class, 'show']);
                Route::put('school-groups/{schoolGroup}', [SchoolGroupController::class, 'update']);
                Route::delete('school-groups/{schoolGroup}', [SchoolGroupController::class, 'destroy']);
                Route::put('school-groups/{schoolGroup}/schools', [SchoolGroupController::class, 'syncSchools']);
            });
            Route::get('audit-logs', [UserController::class, 'audit'])->middleware('permission:audit.view');
        });
    });
});
