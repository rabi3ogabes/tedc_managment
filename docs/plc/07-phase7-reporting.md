# PLC (مجتمعات التعلم المهنية) — Phase 7 report: reporting and the dashboard

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Phase 6](06-phase6-evaluation.md).

Phase 7 gives the module one place that counts. Every figure on the overview, the new reports tab, the school and community breakdowns and the exports comes from the same service, computed live from the database inside the person's scope and under the same filters, so a number on one screen is the same number on another. The reports tab filters by period, source, scenario, state, delivery mode, school, community and region; shows workshops, sessions, seats, attendance, evaluation response and averages, impact follow-ups, needs conversion and the median wait from need to workshop; breaks the figures down by state, source, mode and month, by school and by community; flags workshops that ended and were not closed and those closed by exception; and exports to Excel, PDF and CSV in Arabic or English. Everything stays behind `plc` and `plc.reports`.

## 1. Files changed

New (backend)
- `app/Services/Plc/PlcReports.php` — `query()` (the scoped, filtered workshop query every figure starts from), `build()` (workshops by state / source / scenario / mode, held, closed by exception, overdue close; sessions and hours; capacity, seated, completed, unique people, schools and communities; attendance rate and people meeting the minimum; evaluation per instrument with averages only above the anonymity minimum; impact trainee and manager forms; needs by status and source, conversion rate and median wait; the monthly trend; per school; per community; the best-attended workshops), `document()` (the same figures as an exportable document) and `export()`, `options()` (schools, communities and regions inside the scope).
- `app/Http/Controllers/Api/V1/Admin/Plc/PlcReportController.php` — `GET admin/plc/reports`, `GET admin/plc/reports/options`, `GET admin/plc/reports/export?format=xlsx|pdf|docx|csv&lang=ar|en` (`plc.reports`); filters validated (`from`, `to`, `source`, `scenario`, `state`, `mode`, `school_id`, `community_id`, `region`).
- `tests/Feature/PlcReportsTest.php`.

Changed (backend)
- `PlcHubController::dashboard()` — adds `kpis` (attendance, evaluation, people, sessions, impact) from the same service for people with `plc.reports`.
- `WorkshopAttendance::lock()` — every seated person's rate is recalculated when the session is locked, so a check-in without a check-out counts up to the session's end (found by this phase's reconciliation test: a single-session workshop left attendees at 0 % and nobody was completed).
- `routes/api.php`; `docs/deliverables/*.generated.md` (regenerated counts).

New / changed (web)
- `src/pages/admin/plc/PlcReports.tsx` — the reports tab: the filter bar with export buttons, eight KPI cards, the alert for overdue closes and exceptions, the bars by state / source / mode, the monthly trend, evaluation by instrument with the best-attended workshops, the school table (click a school to filter) and the community table (click a community to filter).
- `PlcHub.tsx` — the tab, deep links `?tab=reports&school_id=…|community_id=…`, the "attendance and evaluation" strip on the overview from the same figures; the overview banner now describes Phases 1–7.
- `i18n/plc.ts` (`plc.reports.*`, `plc.tabs.reports`, `plc.phase`).

## 2. What the person can do now

- Read on the overview, next to the counts, the attendance rate, the evaluation response, the seated people and the sessions, and jump to the reports.
- Filter the reports by period, source, scenario, state, delivery mode, school, community and region, and reset in one click.
- See the breakdowns by state, source, mode, month, school and community; click a school or a community to narrow the whole page to it.
- See which workshops ended without being closed and open their evaluation page, and how many were closed by exception.
- Export the filtered report to Excel, PDF or CSV in the interface language.

## 3. Tests executed and actual results

- `php artisan test --filter="PlcReportsTest"` → passed, 1 test, 57 assertions: a راصد-sourced workshop in a community with three seated people, one session attended by two, plus a draft centre workshop; after the clock completes the first, the figures match the database (2 workshops, 1 held, by state and source, 1 session of 3 hours, 20 seats, 3 seated, 2 completed, the attendance rate equals the registrations' average, 2 met the minimum, 3 workshop forms assigned, 2 impact surveys, 2 months of trend, the school row with 2 workshops and 3 people, the community row, the best-attended workshop); the dashboard returns the same totals, states, attendance and evaluation figures; filters by source, community, state and mode, school and region, and a period outside the dates narrow correctly, an invalid state is refused, a deputy of another school sees nothing, the options list only the schools and regions in scope; the CSV carries the school and community names, the Excel and PDF exports come back with their content types.
- Combined with the PLC and attendance suites (`PlcAttendanceTest|PlcEvaluationTest|PlcScheduleTest|PlcFoundationTest|PlcPeopleTest|PlcSchoolsTest|AttendanceAndCertificateTest|AttendanceMethodsTest|GeofenceAttendanceTest`) → 37 tests, 652 assertions, all passing after the lock-time recalculation.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only pre-existing warnings.
- Playwright sweep in Arabic against the local servers: the reports tab and the overview strip.

## 4. Security-sensitive changes

- The reports show counts, rates and averages only; no answers and no per-person scores; averages appear only above the evaluation settings' anonymity minimum.
- Every figure is computed inside `WorkshopService::query` (the PLC scope), and the options list only what is in scope; the routes need `plc.reports`.

## 5. Known limitations

- The trend is by the workshop's start month; there is no week view.
- Needs' "median wait" counts days from the need's receipt to the workshop's creation, not to its first session.
- The DOCX export is available through the API (`format=docx`) but not offered as a button.

## 6. Exact next step

Phase 8 — smart assistants: explanations for school and trainee suggestions, the planning checklist, the completion tracker, decision-log views, feedback themes (AI opt-in only) and impact linking. Report in `docs/plc/08-phase8-assistants.md`.

---
Full suite after Phase 7 (`php artisan test --compact`, Windows): 1173 tests, 1103 passed, 15918 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors in SamlSso/LtiTool/PushNotifications/Lti/SupabaseConnection/IdentitySecurity); every PLC, attendance, evaluation, cron and classification test passes.
