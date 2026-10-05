# Phase 08 — Evaluation, surveys and impact

**Closes:** SRV-03, SRV-04, SRV-08, EVL-01, EVL-02, EVL-04, EVL-05, EVL-06, EVL-07, EVL-08, RPT-10.

## Safer path chosen: adapters, not migration
The satisfaction survey (`evaluations`), the trainee impact survey (`impact_surveys`) and the manager's impact form (`supervisor_evaluations`) keep their tables, so every historical record keeps reporting exactly as before. They appear in the form registry as read-only *system* forms. New form-driven instruments live in `evaluation_forms` → `evaluation_assignments` → `evaluation_responses`: trainer self-reflection, planning-team evaluation, program-supervisor feedback, planning-specialist feedback, custom forms. Impact forms gained evidence (files / links).

## Automatic assignment
`tedc:evaluations-hourly` (and the daily `tedc:dispatch-surveys`): at the end of a group the reflection goes to each approved trainer, supervisor feedback to the program coordinator, specialist feedback to every planning specialist; the planning head assigns the planning-team evaluation by hand. The trainee impact form is scheduled at **45 days** (optional second form), the manager's at **60 days** (a request to the direct manager); all editable in *Evaluation settings*. Reminders after N days, expiry after the deadline.

## Evidence and anonymity
Questions can accept evidence: PDF, image, Office, MP4 up to 10 MB or http(s) links, limited per question. Satisfaction results show only after N responses (default 3).

## Analysis and reports
- **Pre/post:** averages, knowledge gain against the 35 % target, distribution of gains, per-skill gains, and a paired-difference hint (an approximation, worded plainly).
- **Program evaluation report:** metrics from every instrument, a classification (successful / needs review / weak) from editable thresholds with the reasons shown, rule-based strengths, improvements and recommendations for the specialist to edit, review then approval by the planning head, export to PDF, Word and Excel in Arabic or English. (AI-assisted drafting arrives in Phase 15.)
- **Low-satisfaction alert:** response rate ≥ 80 % and average < 50 % (both editable) alerts the leadership and the supervisor once per group; ranking of groups for the leadership dashboard.
- **Exports:** satisfaction, evaluation forms and needs surveys to Excel / PDF / Word / CSV through one `ReportExporter` (Phase 12 reuses it). Word files are written by a small built-in writer (no extra dependency).

## Permissions
`evaluations.manage`, `evaluations.respond_planning`, `evaluation_reports.prepare`, `evaluation_reports.approve`, `interviews.manage`, `satisfaction_alerts.manage`.

## Limits
- The trainee/manager impact forms keep their fixed questions (evidence added); only the new instruments use the form designer.
- Mobile: answer forms (ratings, yes/no, text) with evidence links; attaching files is on the web.
- The significance figure is an approximation meant as a hint.
