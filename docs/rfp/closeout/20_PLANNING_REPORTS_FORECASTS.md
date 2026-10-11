# Phase 20 — Planning rules enforced, the RFP report list completed, forecasts exportable

> Run after Phase 19. All rules in `00_MASTER_PROMPT.md` apply. Skills: `test-driven-development`, `dataviz` (report and forecast visuals), `frontend-design` + `impeccable`, `anthropic-skills:xlsx` / `pdf` / `docx` (check exported files open correctly), `verification-before-completion`.

## Goal
1. The annual plan obeys the year's rules the RFP describes, including the five programme types: **ترخيص (licensing), تمكين (empowerment), تمهين (professionalisation), تخصيص (specialisation), تخيير (electives)**. Mandatory categories and total seat and hour caps are enforced, with the reasons shown.
2. The system-administrator reports match the RFP's list word for word: filters for entity type, workplace, professional licence, annual appraisals and training path, plus annual, quarterly and monthly statistics.
3. Forecasts and risk lists export to Excel, PDF and Word like every other report.

## Closes
`NDS-08` yearly planning rules and program types · `RPT-03` system-admin reports · `RPT-11` predictive analytics **and reports**.

## What exists today (verified 11 Oct 2026)
- **Plan rules.** `backend/app/Services/AnnualPlanService.php` has default rules (lines ~20–35): `program_types` (`licensing, empowerment, apprenticeship, specialisation, electives`), `mandatory_categories` (uuid list), `max_seats_per_group`, `default_hours_per_group`, `max_seats_total`, `max_hours_total`, `min_fill_percent`, `carry_over`, `windows` and `weights`. `AnnualPlanController::rules` validates them.
  - **Generation uses only** weights, windows, max seats per group, default hours, min fill and carry-over.
  - **`program_types`, `mandatory_categories`, `max_seats_total` and `max_hours_total` are stored but never enforced.**
  - **Programmes have no type at all.** There is no `program_type` column on `programs` or `program_categories`, so the five types cannot be applied. (The English slug list also says `apprenticeship` where the RFP's تمهين means *professionalisation*: check this with the RFP text and correct the label, keeping the slug stable if it is already stored.)
- **Reports.** `backend/app/Services/Reports/BuiltInReports.php` defines the built-in reports (`employees_data`, `employee_courses`, `programs_paths_groups`, `employee_courses_lookup`, `attendance_detail`, `programs_licences_matrix`, `trainer_followup`, `achievement_statistics`, `process_tracking`, `trainee_results`, `programs_by_job_category`, `satisfaction_*`, `hours_approved_vs_actual`, `statistics_periodic`, …) over the datasets in `ReportDatasetRegistry.php`, which has year, month and academic-year helpers (lines ~29–48). Missing, compared with the RFP:
  - **Employee data report:** filters for *entity type (نوع الجهة)*, *workplace*, *professional licence*, *job title*, *years of experience* and *annual appraisals*. Only school, job category, gender, nationality, stage and qualification exist.
  - **Employee courses report:** filters for *training path (مسار التدريب)*, *programme name*, *entity*, *job title*, *qualification* and *period*. The "path" column is currently the programme **category**, not the career path.
  - **Programs, paths and groups:** link the real **career path** (`career_paths`, `career_path_levels`, `employee_path_progress`) as well as the category.
  - **Periodic statistics:** annual, **quarterly** and monthly. A quarterly grouping does not exist.
- **Forecasts.** `app/Ai/Forecast/{ForecastService,RiskService}.php` and `app/Http/Controllers/Api/V1/Ai/AiAdminController.php` (`forecasts`, `risks`, `runForecasts`, `toPlan`) feed the web pages `ForecastRisks.tsx` and the forecasts page. **There is no export.** `app/Services/Reports/ReportExporter.php` renders one document model to xlsx, pdf, docx and csv (RTL aware) and is the exporter to reuse.
- **Data sources:** `performance_appraisals`, `classroom_observations`, `professional_licences` (`path_id`, `level_no`, `status`, `expires_at`) and `program_categories` (`slug`, names, icon, colour).
- **Classification oddity to check:** `program_categories.name_ar/name_en` are classified *Confidential, masked in exports* (`docs/security/data-classification.md`). Category names are public reference data and masking them would spoil every report export. Confirm how exports treat them and correct the classification if needed (with the reason in the commit).

## 1. Data model
- Migration `2026_01_01_006000_program_types.php`:
  - `program_categories.program_type` (nullable string ≤ 20: `licensing|empowerment|professionalisation|specialisation|electives`);
  - `programs.program_type` (nullable; defaults from the category, can be overridden per programme);
  - `training_plan_items.program_type` (copied at generation, so later edits do not rewrite history).
  - Backfill from the demo categories where the mapping is obvious; leave the rest null and list them on the plan screen as "type missing".
- No new table is needed for reports. Add any missing indexes for the new filter columns (appraisal year and rating, licence status, path id).

## 2. Rules and services
- **`AnnualPlanService::generate`:**
  - Only items whose programme type is in the plan's `program_types` are proposed. Excluded needs are listed with the reason "type not in this year's plan".
  - Every category in `mandatory_categories` gets at least one item. If no approved need exists, a placeholder item is created with source `mandatory` and the rationale "mandatory category", for the planner to complete.
  - When the totals would exceed `max_seats_total` / `max_hours_total`, the lowest-scored items are trimmed first. Trimmed items are kept as `deferred` with the reason, never silently dropped.
- **`AnnualPlanService::submit`** refuses a plan that breaks a rule. A `BusinessRuleException` lists every violation (type not allowed, mandatory category missing, totals over the caps), bilingual. The planning head may approve with a documented exception, which is audited.
- **A compliance summary on every plan:** seats and hours against the caps, items per type and per category, mandatory categories covered, and the list of violations. It is shown on the plan page and in the plan export.
- **Reports.** Extend the datasets, not just the report definitions:
  - `employees`: entity type, workplace, latest licence (level and status), job title, experience years (in the Ministry, outside it, in the current title), and the latest annual appraisal rating and year (filter by year and rating).
  - `registrations`: the career path and level of the programme, entity, job title and qualification.
  - A **quarter** helper (`quarter(col)` for SQLite and PostgreSQL), plus quarter grouping in `statistics_periodic` and the report builder's "group by period" picker (year, academic year, quarter, month).
  - Update each built-in report's columns and filters to the RFP list (quoted under "Reports" above). Each filter is optional and remembered per user.
- **Forecast export.** A `ForecastReport` builds the document model (title, filters, generated date, a table per dimension with value, low and high range, trend and explanation, and a chart image for the PDF) and renders it through `ReportExporter` for xlsx, pdf and docx. Risks export the same way (type, subject, score, reasons, status). Everything is bilingual and RTL.

## 3. API and permissions

| Method & path | Permission |
|---|---|
| `PUT admin/program-categories/{category}` (adds `program_type`), `PUT admin/programs/{program}` (adds `program_type`) | as today |
| `GET admin/plans/{plan}/compliance` | `plans.view` |
| `POST admin/plans/{plan}/approve` (accepts `exception_reason` when rules are broken) | `plans.approve` |
| `GET admin/forecasts/export/{format}`, `GET admin/risks/export/{format}` (format `xlsx|pdf|docx`) | `ai.forecasts.view` |

Large exports run on the existing report-run queue (`ReportRunService`) and hand back a signed download.

## 4. Web
- **Plan studio** (`/admin/plans`):
  - a "Rules" panel with the five types as clear toggle chips (Arabic names with an English hint), a mandatory-categories picker and caps;
  - a **compliance card** (progress bars for seats and hours against the caps, per-type and per-category counts, red/amber/green violations with "fix" links);
  - submit is blocked with the reasons listed; approval with an exception needs a reason.
- **Categories and programmes:** a programme-type select with a short explanation of each type.
- **Reports:** the new filters appear in the built-in reports and in the report builder (period grouping includes Quarter). The employee report is cross-checked against the RFP list in the UI help text.
- **Forecasts and risks:** "Export" buttons (Excel, PDF, Word) on both pages, the same pattern as other reports, with a progress toast for queued runs.

## 5. Mobile
No new mobile screens. Make sure `my_reports_screen.dart` still works with the changed dataset columns.

## 6. Tests (write first)
- **`PlanRulesEnforcementTest`:**
  - needs of a type outside `program_types` are excluded, with a reason;
  - a missing mandatory category creates a placeholder item;
  - the totals cap trims the lowest-scored items into `deferred`;
  - submit refuses a violating plan and lists every violation;
  - approval with an exception requires a reason and is audited;
  - `program_type` is copied to items and a later edit does not change them.
- **`RfpReportsTest`:** the employee report filters by licence status, appraisal year/rating, entity type and experience band; the employee courses report filters by career path; `statistics_periodic` groups by quarter on SQLite **and** on PostgreSQL (the CI postgres job); scope is respected (a school-scoped user sees only their school).
- **`ForecastExportTest`:** xlsx, pdf and docx are produced, open, carry RTL and an Arabic title, and include the explanation column; the permission is enforced.

## Review focus
1. **Plans approved before this phase** have no types. They must keep working, be readable and export as before.
2. **An empty `mandatory_categories` or null caps** means "no rule", not "zero allowed".
3. **Quarter maths** on PostgreSQL vs SQLite: test both with dates on quarter boundaries (31 Mar / 1 Apr).
4. **Report scope:** the new joins (licences, appraisals) must not leak other schools' employees.
5. **Very large exports** (10,000+ rows) go to the queue and never time out the request.

## Definition of Done
- **NDS-08:** the five programme types exist and are applied; generation and submission enforce the types, mandatory categories and caps; violations are explained; approval exceptions are audited. → **Available**
- **RPT-03:** every system-administrator report listed in the RFP has the RFP's filters and columns, and annual, quarterly and monthly statistics work. → **Available**
- **RPT-11:** forecast and risk pages export to Excel, PDF and Word. → **Available**
- The gate is green, `docs/rfp/phase-20-planning-reports-forecasts.md` is written, the register is updated and the change is pushed with everything green.
