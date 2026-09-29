# API reference (v1)

Base URL: `/api/v1`. JSON everywhere. Authenticate with `Authorization: Bearer <access_token>`.
Localized content: send `X-Locale: ar|en` (Arabic is the default).
Errors: `422` business-rule errors return `{message, code, details}`; validation errors return `{message, errors}`.

## Public website (no auth)

| Method | Path | Permission |
|---|---|---|
| GET | `/public/calendar` | — |
| GET | `/public/categories` | — |
| GET | `/public/certificates/verify/{code}` | — |
| POST | `/public/contact` | — |
| GET | `/public/home` | — |
| GET | `/public/news` | — |
| GET | `/public/news/{id}` | — |
| GET | `/public/programs` | — |
| GET | `/public/programs/{idOrCode}` | — |
| GET | `/public/stats` | — |
| GET | `/public/theme` | — (active Brand Studio theme) |
| GET | `/public/trainers` | — |

## Authentication

| Method | Path | Permission |
|---|---|---|
| POST | `/auth/login` | — (rate limited) |
| GET | `/auth/me` | authenticated |
| PATCH | `/auth/me` | authenticated |
| POST | `/auth/refresh` | — (rate limited) |

## Employee self-service (web portal & mobile)

| Method | Path | Permission |
|---|---|---|
| POST | `/me/attendance/scan` | authenticated |
| GET | `/me/calendar` | authenticated |
| GET | `/me/calendar.ics` | authenticated |
| GET | `/me/certificates` | authenticated |
| GET | `/me/home` | authenticated |
| GET | `/me/materials/{material}/download` | authenticated |
| GET | `/me/notifications` | authenticated |
| POST | `/me/notifications/read-all` | authenticated |
| POST | `/me/notifications/{notification}/read` | authenticated |
| GET | `/me/passport` | authenticated |
| GET | `/me/programs/{program}/eligibility` | authenticated |
| POST | `/me/programs/{program}/register` | authenticated |
| GET | `/me/recommendations` | authenticated |
| GET | `/me/registrations` | authenticated |
| GET | `/me/registrations/{registration}` | authenticated |
| POST | `/me/registrations/{registration}/cancel` | authenticated |
| POST | `/me/registrations/{registration}/evaluation` | authenticated |
| GET | `/me/registrations/{registration}/materials` | authenticated |
| PUT | `/me/skills` | authenticated |
| GET | `/me/surveys` | authenticated |
| POST | `/me/surveys/{survey}` | authenticated |
| GET | `/me/tasks` | authenticated |
| POST | `/me/tasks/{task}/submit` | authenticated |
| GET | `/me/team` | `impact.supervise` |
| POST | `/me/team/registrations/{registration}/evaluation` | `impact.supervise` |

## Certificates (shared)

| Method | Path | Permission |
|---|---|---|
| GET | `/certificates/{certificate}/download` | authenticated |

## Administration (RBAC)

| Method | Path | Permission |
|---|---|---|
| POST | `/admin/ai/ask` | `ai.assistant` |
| GET | `/admin/ai/history` | `ai.assistant` |
| GET | `/admin/ai/status` | `ai.assistant` |
| GET | `/admin/analytics/executive` | `analytics.executive` |
| GET | `/admin/analytics/geographic` | `analytics.view` |
| GET | `/admin/announcements` | `announcements.manage` |
| POST | `/admin/announcements` | `announcements.manage` |
| PUT | `/admin/announcements/{announcement}` | `announcements.manage` |
| DELETE | `/admin/announcements/{announcement}` | `announcements.manage` |
| POST | `/admin/announcements/{announcement}/attachments` | `announcements.manage` |
| POST | `/admin/announcements/{announcement}/publish` | `announcements.manage` |
| GET | `/admin/audit-logs` | `audit.view` |
| POST | `/admin/categories` | `programs.manage` |
| GET | `/admin/certificates` | `certificates.view` |
| POST | `/admin/certificates/{certificate}/revoke` | `certificates.revoke` |
| GET | `/admin/dashboard` | `dashboard.view` |
| GET | `/admin/employees` | `employees.view|employees.manage` |
| POST | `/admin/employees` | `employees.manage` |
| GET | `/admin/employees/{employee}` | `employees.view|employees.manage` |
| PUT | `/admin/employees/{employee}` | `employees.manage` |
| PUT | `/admin/employees/{employee}/skills` | `employees.manage` |
| POST | `/admin/job-titles` | `programs.manage` |
| GET | `/admin/lookups` | authenticated |
| DELETE | `/admin/materials/{material}` | `materials.manage` |
| GET | `/admin/programs` | `programs.view|programs.manage` |
| POST | `/admin/programs` | `programs.manage` |
| GET | `/admin/programs/{program}` | `programs.view|programs.manage` |
| PUT | `/admin/programs/{program}` | `programs.manage` |
| DELETE | `/admin/programs/{program}` | `programs.manage` |
| GET | `/admin/programs/{program}/candidates` | `nominations.center|nominations.school` |
| POST | `/admin/programs/{program}/certificates` | `certificates.issue` |
| POST | `/admin/programs/{program}/cover` | `programs.manage` |
| GET | `/admin/programs/{program}/eligibility-rules` | `programs.view|programs.manage` |
| PUT | `/admin/programs/{program}/eligibility-rules` | `programs.manage` |
| GET | `/admin/programs/{program}/eligibility/{employee}` | `registrations.view` |
| GET | `/admin/programs/{program}/impact` | `programs.view|programs.manage` |
| GET | `/admin/programs/{program}/materials` | `programs.view|programs.manage` |
| POST | `/admin/programs/{program}/materials` | `materials.manage` |
| POST | `/admin/programs/{program}/nominations` | `nominations.center|nominations.school` |
| POST | `/admin/programs/{program}/registrations/import` | `nominations.center|nominations.school` + `registrations.import` |
| GET | `/admin/programs/{program}/sessions` | `programs.view|programs.manage` |
| POST | `/admin/programs/{program}/sessions` | `programs.manage` |
| PATCH | `/admin/programs/{program}/status` | `programs.manage` |
| GET | `/admin/programs/{program}/tasks` | `programs.view|programs.manage` |
| POST | `/admin/programs/{program}/tasks` | `tasks.manage` |
| GET | `/admin/registrations` | `registrations.view` |
| POST | `/admin/registrations/bulk-status` | `registrations.manage` |
| GET | `/admin/registrations/import/template` | `registrations.import` |
| GET | `/admin/registrations/{registration}` | `registrations.view` |
| POST | `/admin/registrations/{registration}/certificate` | `certificates.issue` |
| GET | `/admin/registrations/{registration}/certificate-requirements` | `certificates.view` |
| PATCH | `/admin/registrations/{registration}/status` | `registrations.manage` |
| GET | `/admin/reports` | `reports.view` |
| POST | `/admin/reports/executive-snapshot` | `reports.view` |
| GET | `/admin/reports/programs/{program}` | `reports.view` |
| GET | `/admin/roles` | `users.manage` |
| PUT | `/admin/roles/{role}/permissions` | `roles.manage` |
| POST | `/admin/rooms` | `programs.manage` |
| GET | `/admin/schools` | `schools.view|schools.manage` |
| POST | `/admin/schools` | `schools.manage` |
| GET | `/admin/schools/{school}` | `schools.view|schools.manage` |
| PUT | `/admin/schools/{school}` | `schools.manage` |
| POST | `/admin/schools/{school}/logo` | `schools.manage` |
| PUT | `/admin/sessions/{session}` | `programs.manage` |
| DELETE | `/admin/sessions/{session}` | `programs.manage` |
| GET | `/admin/sessions/{session}/attendance` | `attendance.manage` |
| POST | `/admin/sessions/{session}/attendance` | `attendance.manage` |
| GET | `/admin/sessions/{session}/qr` | `attendance.manage` |
| POST | `/admin/skills` | `programs.manage` |
| GET | `/admin/submissions/{submission}/file` | `tasks.review` |
| POST | `/admin/submissions/{submission}/review` | `tasks.review` |
| PUT | `/admin/tasks/{task}` | `tasks.manage` |
| DELETE | `/admin/tasks/{task}` | `tasks.manage` |
| GET | `/admin/tasks/{task}/submissions` | `tasks.review` |
| GET | `/admin/trainers` | `programs.view|trainers.manage` |
| POST | `/admin/trainers` | `trainers.manage` |
| PUT | `/admin/trainers/{trainer}` | `trainers.manage` |
| DELETE | `/admin/trainers/{trainer}` | `trainers.manage` |
| POST | `/admin/trainers/{trainer}/photo` | `trainers.manage` |
| GET | `/admin/training-needs` | `needs.view|needs.submit` |
| POST | `/admin/training-needs` | `needs.submit` |
| GET | `/admin/training-needs/analytics` | `needs.view` |
| PATCH | `/admin/training-needs/{need}` | `needs.manage` |
| GET | `/admin/users` | `users.manage` |
| POST | `/admin/users` | `users.manage` |
| PUT | `/admin/users/{user}` | `users.manage` |

## Training calendar

Every date resolves to a `kind`: `workday`, `weekend` (Fri/Sat, `TEDC_WEEKEND_DAYS`), `vacation`, `exam` or `normal`.
Vacation and weekend are **off days**; exam and normal days are working days that are **closed for training**.
A closed day only accepts sessions after an **approval**. Creating or moving a session onto an unapproved closed
day fails with `422` and `code: calendar_closed`. Users with `calendar.approve` may send `calendar_approval_reason`
with the session to approve and schedule in one request.

| Method | Path | Permission |
|---|---|---|
| GET | `/admin/calendar?from=&to=&kind=` (max 800 days; one row per date, for calendar and table views) | `calendar.view|calendar.manage` |
| POST | `/admin/calendar/days` (`date`, optional `end_date`, `type`, `title_ar`, `title_en`, `notes`; re-marking a date updates it) | `calendar.manage` |
| PUT / DELETE | `/admin/calendar/days/{day}` | `calendar.manage` |
| POST | `/admin/calendar/approvals` (`date`, optional `end_date`, `reason`) | `calendar.approve` |
| DELETE | `/admin/calendar/approvals/{date}` | `calendar.approve` |

## Brand Studio

| Method | Path | Permission |
|---|---|---|
| PUT | `/admin/theme` | `settings.manage` |
| POST | `/admin/theme/reset` | `settings.manage` |
| POST | `/admin/theme/assets` | `settings.manage` (multipart `file`; `kind` = hero, banner, pattern or logo (JPG/PNG/WebP ≤ 6 MB, SVG rejected), or font (WOFF2/WOFF/TTF/OTF ≤ 4 MB)) |

## Push notifications (Firebase)

| Method | Path | Access |
|---|---|---|
| GET | `/public/mobile-config` | public: Firebase client options (when enabled) and brand colors for the app |
| POST | `/me/devices` | signed in: register this device (`token`, `platform` android/ios/web, `locale`, `app_version`) |
| DELETE | `/me/devices` | signed in: unregister (`token`) |
| GET / PUT | `/admin/settings/push` | `settings.manage`: settings (the service account is write-only), categories, stats, delivery log |
| POST | `/admin/settings/push/verify` | `settings.manage`: sign in to Google with the service account |
| POST | `/admin/settings/push/test` | `settings.manage`: test notification to own devices (`audience=me`) or all devices (`audience=all`) |
