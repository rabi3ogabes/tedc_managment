<?php

use App\Http\Controllers\Api\V1\Admin\AiAssistantController;
use App\Http\Controllers\Api\V1\Admin\AnalyticsController;
use App\Http\Controllers\Api\V1\Admin\AnnouncementController;
use App\Http\Controllers\Api\V1\Admin\CalendarController;
use App\Http\Controllers\Api\V1\Admin\CatalogController;
use App\Http\Controllers\Api\V1\Admin\CertificateController;
use App\Http\Controllers\Api\V1\Admin\EligibilityRuleController;
use App\Http\Controllers\Api\V1\Admin\EmployeeController;
use App\Http\Controllers\Api\V1\Admin\MaterialController;
use App\Http\Controllers\Api\V1\Admin\NeedsSurveyController;
use App\Http\Controllers\Api\V1\Admin\ProgramController;
use App\Http\Controllers\Api\V1\Admin\PushSettingsController;
use App\Http\Controllers\Api\V1\Admin\RegistrationController;
use App\Http\Controllers\Api\V1\Admin\ReportController;
use App\Http\Controllers\Api\V1\Admin\RoomController;
use App\Http\Controllers\Api\V1\Admin\SchoolController;
use App\Http\Controllers\Api\V1\Admin\SessionController;
use App\Http\Controllers\Api\V1\Admin\TaskController;
use App\Http\Controllers\Api\V1\Admin\ThemeController;
use App\Http\Controllers\Api\V1\Admin\TrainingNeedController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\Me\DeviceController;
use App\Http\Controllers\Api\V1\Me\MeController;
use App\Http\Controllers\Api\V1\Me\MyNeedsSurveyController;
use App\Http\Controllers\Api\V1\Me\MyOutcomesController;
use App\Http\Controllers\Api\V1\Me\MyTrainingController;
use App\Http\Controllers\Api\V1\MobileConfigController;
use App\Http\Controllers\Api\V1\Public\PublicController;
use App\Http\Controllers\Api\V1\SystemController;
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

    // Serverless operations (Vercel Cron / one-time setup), protected by CRON_SECRET ---
    Route::prefix('system')->controller(SystemController::class)->middleware('throttle:10,1')->group(function () {
        Route::get('cron', 'cron');
        Route::post('setup', 'setup');
    });

    // Public website ----------------------------------------------------------
    Route::prefix('public')->middleware(['throttle:public', 'edge.cache:60'])->controller(PublicController::class)->group(function () {
        Route::get('home', 'home');
        Route::get('stats', 'stats');
        Route::get('theme', [ThemeController::class, 'show']);
        Route::get('mobile-config', MobileConfigController::class);
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
        });
    });

    Route::middleware(['auth:api', 'throttle:api'])->group(function () {

        // Employee self-service -----------------------------------------------
        Route::prefix('me')->group(function () {
            Route::get('home', [MeController::class, 'home']);
            Route::get('recommendations', [MeController::class, 'recommendations']);
            Route::get('passport', [MeController::class, 'passport']);
            Route::put('skills', [MeController::class, 'updateSkills']);
            Route::post('devices', [DeviceController::class, 'store']);
            Route::delete('devices', [DeviceController::class, 'destroy']);
            Route::get('notifications', [MeController::class, 'notifications']);
            Route::post('notifications/read-all', [MeController::class, 'readAllNotifications']);
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
            Route::post('attendance/scan', [MyTrainingController::class, 'scan'])->middleware('throttle:scan');

            Route::get('tasks', [MyOutcomesController::class, 'tasks']);
            Route::post('tasks/{task}/submit', [MyOutcomesController::class, 'submitTask']);
            Route::post('registrations/{registration}/evaluation', [MyOutcomesController::class, 'submitEvaluation']);
            Route::get('certificates', [MyOutcomesController::class, 'certificates']);
            Route::get('surveys', [MyOutcomesController::class, 'surveys']);
            Route::post('surveys/{survey}', [MyOutcomesController::class, 'submitSurvey']);
            Route::get('needs-surveys', [MyNeedsSurveyController::class, 'index']);
            Route::get('needs-surveys/{needsSurvey}', [MyNeedsSurveyController::class, 'show']);
            Route::post('needs-surveys/{needsSurvey}', [MyNeedsSurveyController::class, 'submit']);

            Route::get('team', [MyOutcomesController::class, 'team'])->middleware('permission:impact.supervise');
            Route::post('team/registrations/{registration}/evaluation', [MyOutcomesController::class, 'supervisorEvaluation'])->middleware('permission:impact.supervise');
        });

        Route::get('certificates/{certificate}/download', [MyOutcomesController::class, 'downloadCertificate'])->name('api.certificates.download');

        // Administration --------------------------------------------------------
        Route::prefix('admin')->group(function () {
            Route::get('lookups', [CatalogController::class, 'lookups']);

            Route::get('dashboard', [AnalyticsController::class, 'dashboard'])->middleware('permission:dashboard.view');
            Route::get('analytics/executive', [AnalyticsController::class, 'executive'])->middleware('permission:analytics.executive');
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

            // Training rooms (locations, layouts, equipment, availability)
            Route::prefix('rooms')->controller(RoomController::class)->group(function () {
                Route::middleware('permission:programs.view|rooms.manage')->group(function () {
                    Route::get('/', 'index');
                    Route::get('options', 'options');
                    Route::get('availability', 'availability');
                    Route::get('{room}', 'show');
                    Route::get('{room}/schedule', 'schedule');
                });
                Route::middleware('permission:rooms.manage')->group(function () {
                    Route::post('/', 'store');
                    Route::put('{room}', 'update');
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
            Route::post('registrations/{registration}/certificate', [CertificateController::class, 'issue'])->middleware('permission:certificates.issue');
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
            Route::get('trainers', [CatalogController::class, 'trainers'])->middleware('permission:programs.view|trainers.manage');
            Route::middleware('permission:trainers.manage')->group(function () {
                Route::post('trainers', [CatalogController::class, 'storeTrainer']);
                Route::put('trainers/{trainer}', [CatalogController::class, 'updateTrainer']);
                Route::post('trainers/{trainer}/photo', [CatalogController::class, 'trainerPhoto']);
                Route::delete('trainers/{trainer}', [CatalogController::class, 'destroyTrainer']);
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

                // Push notifications (Firebase)
                Route::get('settings/push', [PushSettingsController::class, 'show']);
                Route::put('settings/push', [PushSettingsController::class, 'update']);
                Route::post('settings/push/verify', [PushSettingsController::class, 'verify']);
                Route::post('settings/push/test', [PushSettingsController::class, 'test'])->middleware('throttle:10,1');
            });

            // Users, roles, audit
            Route::middleware('permission:users.manage')->group(function () {
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
