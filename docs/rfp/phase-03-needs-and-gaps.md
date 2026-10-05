# Phase 03 — Needs assessment, competency framework and gap analysis

**Closes:** NDS-01 … NDS-06, NDS-15.

- **Cycle:** `needs_cycles` (draft → open → closed). Proposals (`program_proposals`) and manager requests (`institutional_requests`) are accepted only inside the window; planners accept (adds a plan item to the cycle's plan), merge into an existing program, or reject with a reason. Daily `tedc:needs-cycles` reminds once and closes at the deadline.
- **Individual needs:** `individual_needs` from self declaration, survey answers (competence/need questions linked to competencies), and rules. Managers (direct reports) or planners approve in bulk; `tedc:needs-daily` also auto-approves after N days (setting `needs.auto_approve_days`, default 14, 0 = off) and writes an audit row.
- **Rules:** new hire (months), appraisal (years + ratings), observation (below level), specialisation/stage. Idempotent by (employee, competency, source).
- **Performance data:** CSV/Excel import of appraisals and observations with a line-by-line validation report; weak-performer report by years and ratings with one-click targeting.
- **Competency framework:** domains, competencies with 1–5 descriptors, licence flag, required levels per job (stage / subject overrides), Excel/CSV import-export, evidence weights (`competency.weights`).
- **Gap analysis:** `GET /admin/gaps?group_by=skill|school|job_title|region` ranks by gap × people affected × licence weight, explains each row, flags gaps no program covers, `POST /admin/gaps/to-plan`.
- **Instrument approval:** needs surveys need `approval_status = approved` before publishing (existing surveys were migrated as approved).

**Permissions:** `competencies.manage`, `needs.cycles`, `needs.propose`, `needs.request`, `needs.approve_individual`, `performance.import`, `gaps.view`.

**Known limits:** the HR-connector endpoint for appraisals arrives with Phase 13; diagnostic-test evidence with Phase 06; licence rules with Phase 09; "suggest a new program for an uncovered gap" through the program planner is not wired yet.
