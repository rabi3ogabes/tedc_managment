# Architecture

## System overview

```
                        ┌──────────────────────────────┐
  Public visitors  ───▶ │  Web (React, RTL)            │
  Admins / staff   ───▶ │  • public website            │──┐
  Employees        ───▶ │  • admin dashboard / portal  │  │  HTTPS + Bearer JWT
                        └──────────────────────────────┘  │  X-Locale: ar|en
                        ┌──────────────────────────────┐  │
  Employees/schools ──▶ │  Mobile (Flutter)            │──┤
                        └──────────────────────────────┘  ▼
                                               ┌──────────────────────────────┐        ┌──────────────────┐
                                               │  Laravel 13 REST API /api/v1 │──────▶ │ Anthropic Claude │
                                               │  RBAC · engines · scheduler  │        │ (AI assistant)   │
                                               └──────────────┬───────────────┘        └──────────────────┘
                     ┌────────────────────────────────────────┼─────────────────────────────┐
                     ▼                                        ▼                             ▼
          Supabase Auth (GoTrue)                   Supabase Postgres (RLS)          Supabase Storage
          password grant + JWT                     business data, audit log         private buckets +
                                                   Realtime → notifications         signed URLs
```

* The **API is the single writer** of business data. Clients use Supabase directly only for Realtime notification
  streams (protected by RLS) and the public-assets bucket.
* Authentication: `POST /auth/login` exchanges credentials with Supabase Auth; every request carries the Supabase JWT,
  verified by `App\Auth\JwtVerifier` (HS256 secret or the project's JWKS). A `local` driver issues equivalent tokens for
  development and tests.

## Backend layout (`backend/app`)

| Path | Responsibility |
|------|----------------|
| `Auth/` | JWT verification, user resolution & just-in-time provisioning, login/refresh via Supabase |
| `Http/Middleware` | `SetLocale` (Arabic default), `EnsurePermission` (RBAC), `SecurityHeaders` |
| `Http/Controllers/Api/V1/Public` | Public website endpoints (cached) |
| `Http/Controllers/Api/V1/Me` | Employee self-service used by the web portal and the mobile app |
| `Http/Controllers/Api/V1/Admin` | Administration, analytics, AI assistant, RBAC, audit |
| `Services/Eligibility` | Smart Eligibility Engine |
| `Services/RecommendationEngine` | Smart Recommendation Engine |
| `Services/RegistrationService` | Registration workflow, nominations, waiting list, status transitions |
| `Services/AttendanceService` | Dynamic QR, check-in/out, attendance % |
| `Services/CertificateService` | Requirement checks, PDF generation, verification, revocation |
| `Services/ImpactService` | 30/60/90-day surveys, supervisor evaluation, Training Impact Score |
| `Services/AnalyticsService` | Dashboard, executive, geographic and training-needs analytics |
| `Services/AiAssistant` | Grounded Claude assistant + deterministic fallback |
| `Services/CommunicationService` | Audience targeting & notification fan-out |
| `Services/FileStorage` | Supabase Storage / local disk abstraction with signed URLs |
| `Console/Commands` | Scheduled jobs: program lifecycle, session reminders, impact survey dispatch |
| `Models/Concerns/Auditable` | Automatic audit trail for sensitive models |

## Data model

All primary keys are UUIDs (Supabase-friendly). Bilingual columns use `_ar` / `_en` suffixes.

| Domain | Tables |
|--------|--------|
| Identity & RBAC | `users` (`auth_id` → `auth.users`), `roles`, `permissions`, `role_user`, `permission_role` |
| Organization | `schools`, `departments`, `job_titles`, `employees` (encrypted `national_id`), `skills`, `employee_skills` |
| Catalogue | `program_categories`, `programs`, `program_skill`, `program_trainer`, `program_sessions`, `trainers`, `training_rooms`, `target_groups`, `eligibility_rules`, `materials` |
| Enrollment | `nominations`, `registrations`, `waiting_lists`, `attendance` |
| Outcomes | `tasks`, `task_submissions`, `evaluations`, `supervisor_evaluations`, `impact_surveys`, `certificates` |
| Engagement | `training_needs`, `announcements`, `notifications`, `reports`, `audit_logs`, `contact_messages` |

## Engines

### Smart Eligibility Engine
Each program has an ordered list of rules `{field, operator, value, is_mandatory, message_ar, message_en}`.

* Fields: `job_title`, `job_category`, `department`, `school_type`, `school_stage`, `education_stage`, `region`,
  `experience_years`, `qualification`, `completed_program`, `skill_level`.
* Operators: `eq`, `neq`, `in`, `not_in`, `gt`, `gte`, `lt`, `lte`, `completed`, `not_completed`, `has_skill`, `lacks_skill`.
* Mandatory rules are combined with **AND**; optional rules only produce warnings. Target groups act as an additional
  mandatory rule (the employee must match at least one group).
* Every rule produces a human explanation (custom or generated from translations), e.g.
  *«سنوات الخبرة يجب أن يكون أكبر من 2 (الحالي: 1.5)»*.
* The result snapshot is stored on the registration; training-center staff may override (recorded in the snapshot).

Example from the brief — *Teacher AND experience > 2 AND previous program not completed*:

```json
[
  {"field": "job_title", "operator": "eq", "value": "TEACHER"},
  {"field": "experience_years", "operator": "gt", "value": 2},
  {"field": "completed_program", "operator": "not_completed", "value": ["PREV-101"]}
]
```

### Smart Recommendation Engine (0–100, explainable)

| Signal | Weight | Source |
|--------|-------:|--------|
| Skill gap | 35 | program target skills vs. employee skill levels |
| Role fit | 25 | target groups / eligibility for the employee's role |
| School needs | 20 | open training needs of the employee's school, weighted by priority |
| Career stage | 10 | program level vs. years of experience |
| Peer rating | 10 | average satisfaction of previous participants |

Ineligible, already-registered and completed programs are excluded; each recommendation carries its reasons.

### Dynamic QR attendance
Payload `TEDC1.{session_id}.{window}.{sig}` where `window = floor(unix_time / 30)` and
`sig = HMAC-SHA256(session.qr_secret, "{session_id}.{window}")[0..20]`. The current and previous windows are accepted.
First scan = check-in (late after 15 min), second scan = check-out; minutes are clipped to the session time.
`attendance % = attended minutes / scheduled minutes` (excused sessions are excluded).

### Smart Certificate Engine
Issued only when: registration approved · attendance ≥ program minimum · all required tasks approved · evaluation
submitted. On issue: PDF (A4 landscape, Arabic + English, QR → `/verify/{code}`) stored in the private `certificates`
bucket, registration marked completed, skills credited to the employee's profile, impact surveys scheduled.

### Training Impact Score
Weighted blend (weights re-normalised over the signals available so far):

| Component | Weight | Measure |
|-----------|-------:|---------|
| Attendance | 15 | attendance % |
| Learning | 25 | 0.6 × post-test + 0.4 × satisfaction (or satisfaction alone) |
| Application | 25 | average application score in 30/60/90-day surveys |
| Supervisor | 20 | supervisor-observed application score |
| Follow-up | 15 | answered / due follow-up surveys |

### AI Training Assistant
`AiAssistant::snapshot()` aggregates non-personal data (requested skills by priority, skills without a program, weakest
skill coverage, previous programs with satisfaction/impact, coverage by region). Claude receives it with a fixed,
cache-friendly system prompt and must answer with structured JSON (`answer`, `insights`, `suggestions[]` with seats,
target group, priority and rationale). Without an API key — or on any error/refusal — a deterministic analyst returns
the same shape. Every answer is stored in `reports` (`type = ai_insight`).

## RBAC

| Role | Scope |
|------|-------|
| Super Admin | everything (implicit) |
| Training Center Admin | everything except editing role permissions |
| Program Coordinator | programs, sessions, rules, materials, registrations, center nominations, attendance, tasks, certificates, needs, communication, AI, reports |
| Trainer | own programs: materials, attendance QR, tasks & reviews |
| School Admin | own school: employees, nominations, bulk import, training needs, certificates, dashboard |
| Supervisor | team members, supervisor impact evaluations |
| Executive | dashboards, executive & geographic analytics, AI assistant, reports |
| Employee | self-service (`/me/*`) |

Permissions are data (`permissions`, `permission_role`) and editable from the admin console; the matrix above is the
seeded default (`database/seeders/RolePermissionSeeder.php`). School admins are automatically scoped to their school.

## Security controls

* JWT verification with audience and token-type checks; refresh tokens cannot be used as access tokens.
* Permission middleware on every admin route; row scoping for school admins, trainers and supervisors.
* Supabase RLS enabled on every table; clients can read only their own notifications and published news.
* Private buckets; files are served only through short-lived signed URLs after authorization checks.
* `national_id` encrypted at rest (Laravel `encrypted` cast); secrets never written to the audit log.
* Append-only `audit_logs` (database trigger) for creates/updates/deletes and role/permission changes.
* Rate limits on login, verification, contact, QR scan, AI and general API usage; security headers on responses.
* Mobile: tokens in Keychain/Keystore; HTTPS-only network security config (cleartext only for the emulator host).

## Feature flags and production safety

`config/features.php` defines every flag (title, description, owner phase, `unsafe`, defaults per environment). `App\Services\FeatureSettings` merges the defaults with overrides stored in `site_settings` (`features` key) and audits every change; `App\Support\Features::enabled('key')` is the single question the code asks, the `feature:` middleware gates routes, and the web (`useFeature`) and app (`featureOn`) read the same map from `GET /features`.

In production the four demonstration/support tools (`impersonation`, `test_accounts`, `demo_scenarios`, `self_heal`) default to **off**; enabling one needs `users.manage` and a reason, and a banner is shown to every administrator. `App\Support\DemoGuard` keeps demo seeders and the demo-accounts panel out of production unless `TEDC_ALLOW_DEMO_IN_PRODUCTION=true`. The error auto-fixer only suggests remedies while `self_heal` is off.

## RFP traceability

`docs/rfp/gap-register.md` is the source of truth; `php artisan tedc:rfp-status` turns it into `backend/resources/rfp/status.json` (read by Settings → RFP Compliance) and `docs/rfp/compliance-sheet.md`.

## Roles, scopes and the active role

A user holds roles through `role_user` rows, each with a **scope** (`ministry`, `school_group`, `school`, `department`), the person who granted it and an optional end date. A request runs as exactly one grant — the **active role** (`X-Active-Role` header, else the one the user last chose, else the highest) — and `ResolveActiveRole` stores it in `App\Support\ActiveRole`; `User::hasPermission()`/`hasRole()` then look at that role only. `App\Support\AccessScope` turns the active grant into the schools and departments it reaches; controllers call `constrainEmployees`, `constrainThroughEmployee` and `constrainSchoolColumn` instead of filtering by hand. A school-level role (`scope_levels` without `ministry`) granted without a scope falls back to the school of the person's employee record, never Ministry-wide.

Per-program rights (`program_grants`) let the head of training give one person attendance, notification, task-review or kit rights on a single program (`ProgramGrantService`). Deploys call `RolePermissionSeeder::additive()` so new roles and permissions reach an existing database without undoing hand-made changes.
