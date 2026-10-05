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
| POST | `/me/attendance/scan` | authenticated — body `payload` plus optional `latitude`, `longitude`, `accuracy`, `mocked`; refused with `location_required`, `outside_venue`, `low_accuracy` or `mock_location` |
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
| GET / PUT | `/admin/settings/attendance` | `settings.manage` — location check on/off, range, accuracy |
| GET | `/public/labels` | public — the names of menus / buttons set by administrators (`{ar: {key: text}, en: {...}}`, plus `version`) |
| GET / PUT | `/admin/settings/labels` | `settings.manage` — rename menus, buttons and texts; an empty value restores the default, `replace: true` clears everything |
| GET / PUT | `/admin/settings/security` | `settings.manage` — idle lock of the administration team (`idle_lock_enabled`, `idle_lock_minutes`) |
| POST | `/me/presence` | authenticated — heartbeat `{platform: web|mobile, path, idle_seconds}`; returns the lock state and, for the administration team, the lock timeout |
| POST | `/auth/lock`, `/auth/unlock` | authenticated — lock the dashboard now / confirm the password again (`423 session_locked` is returned by `/admin/*` while locked) |
| GET / POST / PUT / DELETE | `/admin/notifications/templates` (+ `/{id}/reset`, `/preview`) | `announcements.manage` — notification templates: each automatic notification has an on/off switch, a push switch and editable Arabic / English wording with `{{placeholders}}` |
| POST | `/admin/notifications/send` | `announcements.manage` — send a notification to the trainees of a program (`audience`: `trainees` or `pending_survey`) |
| GET | `/admin/notifications/campaigns`, `/campaigns/{id}`, `/campaigns/{id}/export`, `/tracking` | `announcements.manage` — who received / saw / read a notification |
| GET / PUT / POST | `/admin/programs/{program}/survey` (+ `open`, `close`, `notify`) | `programs.manage` — the program survey: always available, opened by hand, or opened automatically N hours after the program ends |
| POST | `/me/notifications/seen` | authenticated — the list was displayed (records the "seen" time) |
| GET | `/admin/presence/live` | `analytics.view` — who is online now (administration team vs app users), timeline, top pages |
| GET | `/admin/presence/report`, `/admin/presence/export` | `analytics.view` — usage summary for a date range (`from`, `to`, `team`, `platform`) and its CSV download |
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

## Training Kit Studio (الحقيبة التدريبية)

Permissions: `kits.view`, `kits.manage` (build kits, edit files), `kits.generate` (AI), `kits.review` (QA), `kits.publish`.
Roles: **Kit Developer** (معد الحقيبة) and **Quality Assurance** (فريق ضمان الجودة); center staff see every kit, the others only kits they are members of.

A kit moves `draft -> in_review -> changes_requested | approved -> published`. Approval is blocked while a major or critical
comment is not resolved. Comments follow *fix then verify*: the developer marks a comment `addressed`, QA `resolved` (or reopens it).
Presentations are stored as an editable slide model; the developer and QA edit the same deck and saves merge slide by slide
(`409` + `conflicts` when the same slide was changed by someone else; resend with `force` to overwrite).

| Method | Path | Notes |
|---|---|---|
| GET | `/admin/kits` `/kits/board` `/kits/stats` `/kits/people` | list, board columns, dashboard counters, assignable people |
| POST / PUT / DELETE | `/admin/kits`, `/admin/kits/{kit}` | create (from a program), update, delete |
| PUT | `/admin/kits/{kit}/members` | owner + team (`developer`, `qa`, `reviewer`, `viewer`) |
| POST | `/admin/kits/{kit}/submit` `request-changes` `approve` `publish` `reopen` `archive` | lifecycle |
| GET | `/admin/kits/{kit}/activity` `reviews` `suggestions` | feed, review rounds, what to build next |
| GET / POST | `/admin/kits/{kit}/files`, `files/create` | list, upload (Word, PDF, PPTX, image, video), blank deck |
| GET / PUT | `/admin/kits/{kit}/files/{file}/deck` | read / save the editable deck |
| POST | `.../deck/import`, `.../export` | attach the deck extracted from a PPTX, store the exported PPTX |
| GET / POST | `.../versions`, `.../versions/{v}/restore` | history |
| POST | `.../heartbeat`, `.../analyze` | who is editing which slide; automatic quality check (`?ai=1` adds a pedagogy review) |
| GET / POST | `/admin/kits/{kit}/comments`, `comments/bulk`, `comments/{c}/status` | anchored review comments |
| POST | `/admin/kits/{kit}/ai/deck` `ai/image` `ai/storyboard` `ai/rewrite`, `files/{file}/ai/slides` | generation |

Generation works without keys (built-in template deck, on-brand placeholder pictures). `ANTHROPIC_API_KEY` switches on Claude for
decks, storyboards, rewriting, reviews and vector illustrations; `OPENAI_API_KEY` adds raster pictures. Videos are rendered in the browser
from the storyboard (WebM with captions).

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

## Feature flags and RFP compliance (Phase 00)

| Method | Path | Access |
|---|---|---|
| GET | `/features` | signed in: `{flags: {key: bool}, unsafe_active: [key], environment}` — which features are on (web `useFeature`, app `featureOn`) |
| GET | `/admin/features` | `settings.manage`: every flag with title, description, default, source (default/override), reason and the last five changes |
| PUT | `/admin/features/{key}` | `settings.manage`: `{enabled, reason?}`. In production, switching on `impersonation`, `test_accounts`, `demo_scenarios` or `self_heal` also needs `users.manage` and a reason (≥ 5 characters); audited as `feature_toggled` |
| GET | `/admin/rfp-status` | `settings.manage`: coverage of the RFP's requirements (totals, phases, modules with items, the 31 main items), generated from `docs/rfp/gap-register.md` |
| GET | `/public/mobile-config` | now also returns `demo_accounts` (true only where demo mode is on) |

Routes can be gated with the `feature:<key>` middleware; a disabled feature answers `403` with `code: feature_disabled`.

## Roles, scopes, grants and search (Phase 01)

| Method | Path | Access |
|---|---|---|
| GET | `/auth/me` | signed in: `roles[]` (each grant: `id`, `slug`, names, `scope_type`, `scope_id`, `scope_label_ar/en`, `landing_route`, `expires_at`, `active`) and `active_role` |
| POST | `/auth/active-role` | signed in: `{role_user_id}` — switch (and remember) the role; returns the new profile. Any request may also send `X-Active-Role: <role grant id>`; a role the user does not hold or that expired answers 403 `role_not_held` |
| GET/POST/PUT/DELETE | `/admin/school-groups[...]`, PUT `/{id}/schools`, POST `/import` (CSV) | `scopes.manage` |
| GET/POST/DELETE | `/admin/users/{user}/roles[/{grant}]` | `users.manage`: grant a role at a scope (`role_id`, `scope_type`, `scope_id`, `expires_at`) |
| POST/PUT/DELETE | `/admin/roles[/{role}]` | `roles.create`: create or clone (`clone_from`) a custom role; system roles are protected; a role with users cannot be deleted. Permission matrix: `PUT /admin/roles/{role}/permissions` (`roles.manage`) |
| GET/POST/DELETE | `/admin/programs/{program}/grants[/{grant}]` | `program_grants.manage`: abilities `attendance.mark`, `notifications.send`, `tasks.review`, `kits.assign`, optional expiry |
| GET | `/admin/staff-lookup?q=` | `program_grants.manage` or `users.manage`: find people to grant to |
| GET | `/search?q=&types=` | signed in with `search.global`: grouped results for programs, people (scoped), trainers, kits, certificates, news |

Attendance marking, task review and program notifications also accept a person who holds the matching program grant (`can_or_grant` middleware plus a program check in the controller).
