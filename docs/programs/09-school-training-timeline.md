# 09 — A school's page: its staff and their training as a timeline

**Request.** Import the three Ministry workbooks with every column in the database, on the trainees and on the school's staff; pressing a school shows its employees and their training history as a timeline.

## Import (columns)

The three workbooks were re-exported on 20 Oct (still 142 302 rows). The importer was run again against them: **0 rejected rows, 0 new or duplicated course records (142 302 already there), people 31 502, schools 283 matched, hours 718 198 = source, reconciled.** The files carry one column the first load did not know, **`EMPTYPE`** (employee type code; blank in every row of this extract). It is now read when present (older extracts without it still load) and kept in `employees.employee_type_code` (migration `…008000`). All 29 columns of the workbooks are therefore in the database; the map of column → field is in `docs/ops/trainee-history-import.md` §2.

**A defect found and fixed on the way:** each load added the schools' sub-units («departments») again — the insert ignored duplicates but nothing made them unique, so a third load left three copies of each of the 100 units. The importer now reuses a unit it already has (same school and code) and migration `…008100` merged the copies already in the local database (310 → 110 rows, employees re-pointed). A repeat load now creates 0 units.

## The school page — `/admin/schools/{id}`

Reached by pressing a school's name in the list, or «الخط الزمني للتدريب» in the school's card on the map.

- Four figures: staff (and how many are in service), how many of them trained (and the share), training records, training hours.
- **Staff** (one side): search by name or employee number, order by employee number or by training hours; each person shows job title, unit, hours, number of courses; pressing a person selects them.
- **Timeline** (other side): for the whole school — a rail of years (hours and how many staff trained that year) and, for the chosen year, every course on a vertical timeline with its dates, hours, track, type, provider and **how many of the school's staff attended**; for a selected person — their own timeline year by year (courses, hours, small bar per year), with «ملف الموظف» and «المدرسة كلها» buttons.
- Endpoints: `GET admin/schools/{school}/staff` and `GET admin/schools/{school}/timeline[?year=|employee_id=]` — permission `schools.view` **and** `employees.view` (they list staff); a person from another school is not found through the page; national IDs never appear.
- Tests: `SchoolTrainingTimelineTest` (staff list with hours and last course, search, school timeline totals / years / attendees / other year, a person's timeline, another school's person 404, an employee 403).

**Full suite (2026-10-20):** 1203 tests, 1132 passed after regenerating the data-classification register; the remaining failures are the known set (VercelEnvTest x2, ContractDriverTest mTLS, IntegrationDocsTest line endings, SocialFilesTest) and 54 openssl errors, none touching the school page or the rename.
