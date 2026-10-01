<?php

use App\Http\Controllers\Api\V1\Admin\AiAssistantController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\AnnouncementController;
use App\Http\Controllers\Api\V1\Admin\AttendanceSettingsController;
use App\Http\Controllers\Api\V1\Admin\CalendarController;
use App\Http\Controllers\Api\V1\Admin\CatalogController;
use App\Http\Controllers\Api\V1\Admin\CertificateController;
use App\Http\Controllers\Api\V1\Admin\CertificateTemplateController;
use App\Http\Controllers\Api\V1\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Api\V1\Admin\CourseController;
use App\Http\Controllers\Api\V1\Admin\EligibilityRuleController;
use App\Http\Controllers\Api\V1\Admin\EmployeeController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitAiController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitAssetController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitCommentController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitController;
use App\Http\Controllers\Api\V1\Admin\Kits\KitFileController;
use App\Http\Controllers\Api\V1\Admin\LabelController;
use App\Http\Controllers\Api\V1\Admin\MaterialController;
use App\Http\Controllers\Api\V1\Admin\NeedsSurveyController;
use App\Http\Controllers\Api\V1\Admin\NotificationTemplateController;
use App\Http\Controllers\Api\V1\Admin\NotificationTrackingController;
use App\Http\Controllers\Api\V1\Admin\PartnerOrganizationController;
use App\Http\Controllers\Api\V1\Admin\PresenceController;
use App\Http\Controllers\Api\V1\Admin\ProfileRequestController;
use App\Http\Controllers\Api\V1\Admin\ProgramBuilderController;
use App\Http\Controllers\Api\V1\Admin\ProgramController;
use App\Http\Controllers\Api\V1\Admin\ProgramSurveyController;
use App\Http\Controllers\Api\V1\Admin\PushSettingsController;
use App\Http\Controllers\Api\V1\Admin\RegistrationController;
use App\Http\Controllers\Api\V1\Admin\RemoteProgramController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\RoomController;
use App\Http\Controllers\Api\V1\Admin\SchoolController;
use App\Http\Controllers\Api\V1\Admin\SecuritySettingsController;
use App\Http\Controllers\Api\V1\Admin\SessionController;
use App\Http\Controllers\Api\V1\Admin\TaskController;
use App\Http\Controllers\Api\V1\Admin\TestAccountsController;
use App\Http\Controllers\Api\V1\Admin\ThemeController;
use App\Http\Controllers\Api\V1\Admin\TrainerController;
use App\Http\Controllers\Api\V1\Admin\TrainingDaySettingsController;
use App\Http\Controllers\Api\V1\Admin\TrainingNeedController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Me\AccountController;
use App\Http\Controllers\Api\V1\Me\DeviceController;
use App\Http\Controllers\Api\V1\Me\MeController;
use App\Http\Controllers\Api\V1\Me\MyCourseController;
use App\Http\Controllers\Api\V1\Me\MyNeedsSurveyController;
use App\Http\Controllers\Api\V1\Me\MyOutcomesController;
use App\Http\Controllers\Api\V1\Me\MyTrainingController;
use App\Http\Controllers\Api\V1\MobileConfigController;
use App\Http\Controllers\Api\V1\Public\ChatController as PublicChatController;
use App\Http\Controllers\Api\V1\Public\PublicController;
use App\Http\Controllers\Api\V1\SystemController;
use App\Models\TrainingRoom;
use App\Services\FileStorage;
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

    // Deployment diagnostics: no rate limiter here, because it needs the (possibly broken) database cache.
    Route::get('public/health', HealthController::class);

    // The screen at a classroom door: reached by its secret token, no sign-in.
    Route::get('public/room-screen/{token}', function (string $token, Request $request, RoomScreenService $screen) {
        $room = TrainingRoom::where('display_token', $token)->firstOrFail();
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json(['data' => $screen->day($room, $data['date'] ?? null)]);
    })->middleware('throttle:120,1');

    // Browser uploads of big course files with the local storage driver (Supabase has its own signed upload URLs).
    Route::put('uploads/{bucket}/{path}', function (string $bucket, string $path, FileStorage $files) {
        abort_if(str_contains($path, '..'), 422);
        $files->putStream($bucket, $path, fopen('php://input', 'rb'));

        return response()->json(['message' => 'ok']);
    })->where('path', '.*')->middleware('signed')->name('uploads.local');

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

    // Authentication ----------------------------------------------------------
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::post('login', 'login')->middleware('throttle:login');
        Route::post('refresh', 'refresh')->middleware('throttle:login');
        Route::middleware('auth:api')->group(function () {
            Route::get('me', 'me');
            Route::patch('me', 'updateProfile');
            Route::post('lock', 'lock');
            Route::post('unlock', 'unlock')->middleware('throttle:10,1');
        });
    });

    Route::middleware(['auth:api', 'throttle:api'])->group(function () {

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
            Route::get('notifications', [MeController::class, 'notifications']);
            Route::post('notifications/read-all', [MeController::class, 'readAllNotifications']);
            Route::post('notifications/seen', [MeController::class, 'seenNotifications']);
            Route::post('notifications/{notification}/read', [MeController::class, 'readNotification']);

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
            Route::get('lookups', [CatalogController::class, 'lookups']);

            Route::get('dashboard', [AnalyticsController::class, 'dashboard'])->middleware('permission:dashboard.view');
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
            Route::middleware('permission:announcements.manage')->prefix('notifications')->group(function () {
                Route::get('templates', [NotificationTemplateController::class, 'index']);
                Route::post('templates', [NotificationTemplateController::class, 'store']);
                Route::post('templates/preview', [NotificationTemplateController::class, 'preview']);
                Route::put('templates/{template}', [NotificationTemplateController::class, 'update']);
                Route::delete('templates/{template}', [NotificationTemplateController::class, 'destroy']);
                Route::post('templates/{template}/reset', [NotificationTemplateController::class, 'reset']);
                Route::post('send', [NotificationTrackingController::class, 'send']);
                Route::get('audience', [NotificationTrackingController::class, 'audience']);
                Route::get('campaigns', [NotificationTrackingController::class, 'campaigns']);
                Route::get('campaigns/{campaign}', [NotificationTrackingController::class, 'campaign']);
                Route::get('campaigns/{campaign}/export', [NotificationTrackingController::class, 'exportCampaign']);
                Route::get('tracking', [NotificationTrackingController::class, 'tracking']);
            });
            Route::middleware('permission:programs.manage')->prefix('programs/{program}/survey')->group(function () {
                Route::get('/', [ProgramSurveyController::class, 'show']);
                Route::put('/', [ProgramSurveyController::class, 'update']);
                Route::post('open', [ProgramSurveyController::class, 'open']);
                Route::post('close', [ProgramSurveyController::class, 'close']);
                Route::post('notify', [ProgramSurveyController::class, 'notify']);
            });
            Route::get('presence/live', [PresenceController::class, 'live'])->middleware('permission:analytics.view');
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

                    Route::middleware('throttle:ai')->group(function () {
                        Route::post('ai/deck', [KitAiController::class, 'deck'])->middleware('permission:kits.generate');
                        Route::post('ai/image', [KitAiController::class, 'image'])->middleware('permission:kits.generate');
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
            Route::post('sessions/{session}/remind', [RemoteProgramController::class, 'remind'])->middleware('permission:attendance.manage');
            Route::middleware('permission:attendance.manage')->group(function () {
                Route::get('sessions/{session}/qr', [SessionController::class, 'qr']);
                Route::get('sessions/{session}/attendance', [SessionController::class, 'attendance']);
                Route::post('sessions/{session}/attendance', [SessionController::class, 'mark']);
            });

            // Tasks
            Route::middleware('permission:tasks.manage')->group(function () {
                Route::post('programs/{program}/tasks', [TaskController::class, 'store']);
                Route::put('tasks/{task}', [TaskController::class, 'update']);
                Route::delete('tasks/{task}', [TaskController::class, 'destroy']);
            });
            Route::middleware('permission:tasks.review')->group(function () {
                Route::get('tasks/{task}/submissions', [TaskController::class, 'submissions']);
                Route::post('submissions/{submission}/review', [TaskController::class, 'review']);
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
                Route::get('schools', [SchoolController::class, 'index']);
                Route::get('schools/{school}', [SchoolController::class, 'show']);
            });
            Route::middleware('permission:schools.manage')->group(function () {
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

            // Communication center
            Route::middleware('permission:announcements.manage')->group(function () {
                Route::get('announcements', [AnnouncementController::class, 'index']);
                Route::post('announcements', [AnnouncementController::class, 'store']);
                Route::put('announcements/{announcement}', [AnnouncementController::class, 'update']);
                Route::post('announcements/{announcement}/publish', [AnnouncementController::class, 'publish']);
                Route::post('announcements/{announcement}/attachments', [AnnouncementController::class, 'attach']);
                Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy']);
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

            // Users, roles, audit
            Route::middleware('permission:users.manage')->group(function () {
                Route::get('test-accounts', [TestAccountsController::class, 'show']);
                Route::post('test-accounts', [TestAccountsController::class, 'seed']);
                Route::get('users', [UserController::class, 'index']);
                Route::post('users', [UserController::class, 'store']);
                Route::put('users/{user}', [UserController::class, 'update']);
                Route::get('roles', [UserController::class, 'roles']);
            });
            Route::put('roles/{role}/permissions', [UserController::class, 'updateRolePermissions'])->middleware('permission:roles.manage');
            Route::get('audit-logs', [UserController::class, 'audit'])->middleware('permission:audit.view');
        });
    });
});
