# Phase 12 — Reports, role dashboards and the live KPI dashboard

Closes: HOM-06, ATT-11, RPT-01, RPT-02, RPT-04…RPT-09, TEC-17 (available); RPT-03 (partly — see below).

## Report engine

- `ReportDatasetRegistry` — 16 whitelisted datasets (employees, registrations, attendance, programs and groups, sessions/calendar, certificates, assessments, evaluations/satisfaction/knowledge gain, professional development, trainers and delivered hours, kits, notification deliveries, rooms, plan items, withdrawals, needs). Each field declares label (ar/en), type, SQL expression, allowed aggregates; personal fields need `reports.export_personal`. Nothing a user types reaches SQL: keys, operators and aggregates are checked against the registry, values are bound.
- **Scope**: every dataset applies the person's `AccessScope` itself (a school's report never contains another school). Datasets about the centre (trainers, kits, rooms, plan items) are closed to school scopes. Tests run two schools through every employee dataset.
- `ReportRunner` — columns with aggregates, filters (friendly operators per type, adjustable filters left blank are ignored), grouping, sorting, chart, totals, tokens `@me`, `@my_employee`, `@my_trainer`, `@today` for "mine" reports, matrix reports (employees × programs / licences), composite reports (several tables → one sheet each), printable sheets (blank columns).
- `BuiltInReports` — 36 system reports across roles: system admin (14+), supervisor (4), trainer (3), direct manager (3), trainee (6), QA and kit developer (2), plus evaluation and PD. Administrators copy and change them; `ensure()` keeps the originals current.
- `ReportRunService` — on-screen paging; Excel / PDF / Word through the shared `ReportExporter`; runs with up to 2,000 rows are produced while the person waits, larger ones by `tedc:reports-run` (every minute) with an in-app notice; files in the `documents` bucket, expiring after 7 days; downloads of reports holding personal data are written to the audit log.
- `ReportScheduleService` — daily / weekly / monthly (06:00), recipients are people, roles and e-mail addresses, each gets a signed link that expires in two days; failures alert administrators.

## Dashboards

`DashboardService`: 30 widgets (kpis, bar, donut, gauge, table, list, heatmap), scope-aware, date range, each with the report it drills into. Presets for 16 roles (trainee, principal, deputy, direct manager, head of training, training supervisor, leadership, executive, trainer, centre admin, system admin, kit developer, QA, planning head and specialist, logistics). Administrators edit presets (`/admin/dashboard-presets`); people hide and reorder within their preset (`PUT /dashboard/layout`); a widget outside the preset is refused.

## Live KPI dashboard

`KpiService` measures the twelve indicators — response time p50/p95 (from sampled requests, `RecordRequestMetrics`, 25 % sampling by default, written after the response), concurrent users (and 24 h peak), uptime (a probe every 5 minutes: database + cache), error rate, data integrity (seven automated consistency checks), data completion, course completion, monthly active users, satisfaction (/5), knowledge gain, security breaches (blocked attempts shown in the detail). Targets come from the RFP and are editable; a breach alerts administrators once a day; a monthly PDF/Word report is produced on demand. `tedc:kpi-collect` runs every 5 minutes.

## Permissions

`reports.builder`, `reports.schedule`, `reports.export_personal`, `dashboards.manage`, `kpi.view`. Everyone who can open the app sees the reports meant for their role; the data is always limited to their scope.

## Web, mobile

Web: reports hub (categories, search, favourites, viewer with adjustable filters and chart, three exports, print, schedules, copy/edit), report builder with live preview, role dashboard on the admin home and the portal home (period, personalise), live KPI page (30-second refresh, gauges, sparklines, targets, integrity detail, monthly report), dashboard presets page. Mobile: role dashboard on the home screen, "My reports" with the statement as a PDF.

## Known limits

- RPT-03: the "path" column is the program category (career paths are not yet joined), no appraisal or licence filter on the employee report, quarterly figures come from month grouping.
- The concurrency KPI shows current and peak users; proving 10,000 concurrent users needs a load test (Phase 17).
- Uptime comes from an in-app probe; an external synthetic monitor is better for an SLA.
- Response time is measured from a sample of API requests inside the application, not from the user's browser.
- The mobile dashboard draws bars as progress rows (no charts); heat maps are web-only.
- Datasets are joined at query time; very large tables will need materialised summaries.
