# Phase 09 — Career paths, licences, professional development and knowledge transfer

**Closes:** CAR-01, CAR-02, CAR-03, CAR-04, CPD-01, CPD-02, CPD-03, CPD-04, CPD-05, CPD-06, TYP-19.

## Paths and licences
- Paths of type promotion, licence (four levels, names configurable) or specialisation; each level has conditions in the eligibility-rule syntax (experience, grade, appraisal, licence level, path level, PD hours, completed programs …), required program groups ("any N of"), a minimum of PD hours and, for licences, a validity.
- `CareerPathEngine` evaluates everyone nightly (`tedc:career-daily`) and on program completion / PD approval / licence changes, stores per-condition explanations, and tells the employee **and** the direct manager once when a level becomes available or a condition is lost. Granting a level issues the licence (platform source) or stores the promotion level.
- New eligibility fields `licence_level`, `path_level`, `pd_hours`, `has_licence` let a program require a level, so registration is blocked until it is met.
- Licence register: manual entry, idempotent CSV import (`licences_system` source, ready for the Phase 13 sync), expiry and 90 / 60 / 30-day reminders, each sent once.
- Compliance: funnel per level, matrix employees × status with what is missing, licence expiry, filters, Excel / PDF / Word export.

## Professional development
- Types with hour rules (factor per participation level, cap per activity and per year). The employee logs an activity with evidence and sees the computed hours before saving; it goes to the direct manager (else the school's academic deputy, else the centre) who approves, rejects or returns with a note.
- Recognition: when requested, the centre sets the recognised hours and the equivalent programs; equivalents count as completed in eligibility checks.
- Annual minimum hours per audience, from every source (centre programs, school workshops, approved external PD, approved knowledge transfer) with optional caps per source; calendar or fiscal year; quarterly and 60-days-before-year-end alerts to the employee and the manager.
- Reports: hours per employee, by domain, approval rates, people below target; Excel / PDF / Word.

## Knowledge transfer
Program setting (required, minimum beneficiaries and hours, deadline, evidence). When a trainee completes a program that requires it, a task with a deadline appears; the trainee submits date, hours, method, beneficiaries (colleagues or names) and evidence; a reviewer approves. Approved transfers count as hours and indirect beneficiaries (reach report) and can be a Phase 07 passing criterion (`knowledge_transfer`). Reminders before and after the deadline.

## Permissions
`paths.manage`, `licences.manage`, `pd.types.manage`, `pd.approve` (Phase 01), `pd.recognise`, `pd.targets.manage`, `knowledge_transfer.review`. Managers decide on their own staff's activities without an admin permission (`/me/team/pd-activities`).

## Limits
- `min_pd_hours` and the `pd_hours` field count all-time approved hours, not hours within the licence validity.
- The Ministry's Licences system and the HR system are synced in Phase 13 (the data is shaped for it).
- Mobile: paths, licences, yearly hours, activity logging with an evidence link and submission; camera/file evidence and manager approvals are on the web for now.
