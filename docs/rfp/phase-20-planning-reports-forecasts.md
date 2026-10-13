# Phase 20 — Planning rules enforced, the RFP report list completed, forecasts exportable

Closes: `NDS-08` · `RPT-03` · `RPT-11`. Prompt: `docs/rfp/closeout/20_PLANNING_REPORTS_FORECASTS.md`.

## What was built

### The year's planning rules and the five programme types (`NDS-08`)
* **Programme types.** ترخيص Licensing · تمكين Empowerment · تمهين Professionalisation · تخصيص Specialisation · تخيير Electives
  (`App\Support\ProgramTypes`, `GET admin/program-types`). The first English slug for تمهين was `apprenticeship`; plans saved with it read
  it as `professionalisation`.
* **Where a type lives** (migration `2026_01_01_006000_program_types.php`): a category carries a type (`PUT admin/categories/{category}`,
  audited), a programme may set its own (`programs.program_type`, otherwise it follows its category), and each plan item keeps the type it
  was planned with (`training_plan_items.program_type`), so later edits never rewrite an approved plan. The migration only fills a category
  whose slug names the type; the rest show as "type missing" until the centre sets them (Plan studio → Rules → *Programme type of each
  category*).
* **Generation** (`AnnualPlanService::generate`):
  * needs whose programme type is not in the year's list are left out and listed with the reason ("type not in this year's plan");
    needs without a programme are kept and reported as "type missing";
  * every mandatory category gets at least one item — without a need, a placeholder (source `mandatory`) for the planner to complete;
  * seat and hour caps keep generated items **in priority order while they fit**; the others are kept as `deferred` with the reason
    (`seats_over_cap` / `hours_over_cap`). An item larger than what is left never pushes out smaller ones behind it, manual, mandatory and
    emergency items always count first, and deferred items come back when a later run fits them.
* **Saved rules replace lists.** Lists such as the year's types used to be merged position by position with the defaults, so a shorter
  list could never take effect; they now replace. An empty type list and empty caps mean "no rule", never "nothing allowed".
* **Compliance** (`GET admin/plans/{plan}/compliance`): seats and hours against the caps, items per type and category, mandatory categories
  covered, needs left out, deferred items, violations (`type_not_allowed`, `mandatory_missing`, `seats_over_cap`, `hours_over_cap`) and
  warnings (`type_missing`). It is on the plan page and in the Excel and PDF export of the plan (with a type column).
* **Submission and approval.** A plan that breaks a rule cannot be submitted; the error lists every violation in Arabic and English. Only
  someone with the approval right (head of planning) may go ahead, and only with a written reason; approving a plan that still breaks a rule
  needs the reason again. Each exception is stored on the plan (`rule_exception`) and audited (`plan_rule_exception`, with the violations
  and the IP address).
* **Web.** Plan studio: a compliance card (seat and hour bars, counts per type with the year's types highlighted, mandatory categories,
  violations and warnings with *Fix* links, needs left out, the documented exception), a type column and deferred rows in the items table,
  and a Rules tab with type chips (Arabic name, English hint), category types, mandatory categories and the yearly caps. A refused
  submission opens the list of violations, and the head of planning can continue with a reason. Programme editor: a type select that shows
  what the category gives when left on "as the category".

### The system-administrator reports, as the RFP lists them (`RPT-03`)
* **Employee data**: filters for entity type (government, private, international, community schools; the centre), workplace, job title,
  job category, experience band, professional licence (status and path), annual appraisal (latest year and rating), gender, nationality,
  stage and qualification; columns add experience in and outside the Ministry, years in the current title, licence path, level and status,
  and the latest appraisal.
* **Employee courses**: filters for the training path (the career path whose levels require the programme), programme, entity, workplace,
  job title, qualification, status and period.
* **Programmes, paths and groups**: the real career path and its level beside the category, and the programme type.
* **Yearly, quarterly and monthly statistics**: three tables (by year, by quarter, by month). A quarter field exists on registrations,
  certificates and groups for the report builder (`ReportDatasetRegistry::quarter()`, SQLite and PostgreSQL).
* Licence and appraisal values are read per employee inside each dataset's scope, so a school-scoped person never sees another school's
  staff through them.
* Coded values (entity type, licence status, programme type, gender) read as words on screen and in Excel, PDF and Word. The report hub
  labels every adjustable filter, offers a list for coded fields and keeps the last filters of each report on the device.
* Programme categories (names and type) are reclassified as **Public** reference data; as Confidential they would have been scrambled in
  non-production copies and spoiled every report there. A person's name stays Confidential.

### Forecasts and risks exportable (`RPT-11`)
* `GET admin/forecasts/export/{xlsx|pdf|docx}` and `GET admin/risks/export/{xlsx|pdf|docx}` (`ai.forecasts.view`), through the shared
  `ReportExporter`: title and filters, a section per dimension with the forecast, its range, the trend against the last known year, the
  confidence, the model and the explanation, and a bar chart of the largest values in the PDF; risks by type with their reasons. Arabic files
  are right to left, English left to right. *Export* buttons (Excel, PDF, Word) on both pages.
* Exports are produced while the person waits, up to 5,000 rows (`ForecastReport::MAX_ROWS`); the forecast and risk tables are far smaller
  than that, so they do not use the report-run queue.

## Also fixed
* `tedc:ai-nightly` rebuilds next year's forecasts every Monday; when sample data was loaded on a Monday it wiped the sample forecasts and,
  with too little history, made none (the sample-data test failed every Monday). The sample seeder now runs the job with
  `--skip-forecasts`.
* The report hub's viewer grew past the page edge with wide tables; it now scrolls inside its card.

## Tests
* `PlanRulesEnforcementTest` (12): the five types and their names; a programme follows its category unless it sets its own; needs of a type
  outside the year are left out with the reason; empty lists and caps mean no rule; mandatory placeholders; caps defer the lowest items; an
  item bigger than the cap does not take smaller ones with it and returns when the cap grows; submission refused with every violation; the
  exception path (approval right, reason, audit); items keep their type; old `apprenticeship` plans; the export carries the type and the
  compliance summary.
* `RfpReportsTest` (8): licence, appraisal, entity type, experience band and workplace filters; career path on courses; quarter grouping
  across 31 March / 1 April and the three-part periodic report; scope with the new joins; the built-in filters; the classification; the hub's
  filter descriptions; coded values as words in files.
* `ForecastExportTest` (4): Excel (right to left, Arabic title, explanation column), PDF and Word (bidi), risks in English, permission and
  format.
* Exercised in the running web app as the super administrator: category types, the year's rules, generation with a cap (which found and
  fixed the cap bug above), a refused submission continued with an exception, the approval prompt, the programme type field, the forecast
  export and the employee report with its new filters, in Arabic and English, at desktop and phone width.

## Limits
* The PostgreSQL forms of the new report expressions (quarter, years since a date, career-path match) are exercised by CI's PostgreSQL job,
  not locally.
* Appraisal filters use each employee's **latest** appraisal (year and rating); filtering by the rating of an earlier year is not offered.
