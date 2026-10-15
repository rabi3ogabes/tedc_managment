# PLC (مجتمعات التعلم المهنية) — Phase 6 report: evaluation

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Phase 5](05-phase5-attendance.md).

Phase 6 puts the evaluation of a workshop on the evaluation suite's forms and assignment engine and adds the suggestion rules of the design (§7): the workshop (satisfaction) form to every seated trainee at the end of the last session; one trainer form per confirmed trainer at the end of that trainer's last session; a short session form per session for multi-session workshops when the policy says so; a community form to trainees and coordinators when the workshop belongs to a community; impact follow-ups (+45 / +90 trainee, +60 manager) through the existing impact engine for the configured sources (راصد by default). Every suggestion shows its reason and timing, nothing goes out before the workshop has started, one assignment exists per form, respondent and target, a sending is reported with its delivery evidence and never as "sent" without it, and a workshop closes when its mandatory instruments reached the policy's response rate or with a documented exception recorded by the centre. The trainee answers in the same place as every other form (web portal and the mobile app's evaluations screen), now told which trainer or session the form is about. Everything stays behind `plc`.

## 1. Files changed

New (backend)
- `database/migrations/2026_01_01_007400_plc_evaluation_targets.php` — `evaluation_assignments.program_session_id`, `trainer_id`, `rule_key`; the unique rule widened to (form, group, respondent, subject, session, trainer).
- `app/Services/Plc/WorkshopEvaluation.php` — `formFor()` (the four PLC instruments, created on first use as approved custom forms with the key in `settings.plc`), `jobs()` (one job per instrument target with respondents, timing and reason), `dispatch()` (due jobs by the clock, or chosen instruments by hand; dedupe through the suite's `assign`; a decision per sending; a digest to the coordinator), `onCompleted()` (seated people who met the minimum attendance are completed, impact follow-ups scheduled per policy, due instruments sent or the coordinator told they are ready when the policy sends by hand), `tick()` and `settle()` (completed → awaiting_evaluation → closed), `close()` (complete, or an exception with `plc.exceptions` and a reason), `remind()` (once a day per assignment), `onCancelled()` (unanswered PLC forms expire), `tracking()` and `csv()` (the plan, counters per instrument and per trainer / session, the delivery evidence per assignment from the in-app notification row and the e-mail / SMS deliveries, response rates, average scores only above the anonymity minimum, the impact follow-ups, what blocks the close).
- `app/Http/Controllers/Api/V1/Admin/Plc/WorkshopEvaluationController.php` — `GET workshops/{plcWorkshop}/evaluation`, `GET …/evaluation/export` (`plc.evaluations.manage|plc.manage|plc.reports`); `POST …/evaluation/send|remind|close` (`plc.evaluations.manage|plc.manage`).
- `tests/Feature/PlcEvaluationTest.php`.

Changed (backend)
- `EvaluationService::assign()` — an optional `$target` (session, trainer, rule key, message detail); the dedupe includes the target.
- `EvaluationAssignment` — the new columns and the `session`, `trainer`, `subject` relations; `MyEvaluationController` names the target (trainer or session) in the trainee's list and form.
- `PlcSettings` — `evaluation_rules` (per instrument: enabled, mandatory, days to answer; `impact_sources`, `auto_send`, `close_response_rate`) with defaults from the design table, validated on update; `PlcHubController` accepts it.
- `WorkshopLifecycle` / `tedc:plc-tick` — after completion the evaluation layer runs; completed and awaiting workshops are visited on every pass (session and trainer forms go out as their sessions end; the state settles; the close by the clock).
- `WorkshopWorkflow::move()` — cancelling expires the unanswered PLC forms. `WorkshopService::detail()` — `can.evaluations`.
- `NotificationCatalog` (`plc.evaluations_sent`, `plc.evaluations_ready`, `plc.closed`), `lang/{ar,en}/messages.php` (`plc.evaluation_state`, `evaluation_unknown`, `evaluation_disabled`, `evaluation_incomplete`, `exception_permission`, `close_state`), routes, `docs/security/data-classification.md` regenerated.

New / changed (web)
- `src/pages/admin/plc/Evaluation.tsx` — the evaluation page of a workshop: counters (sent, answers and rate, overdue, not delivered), what blocks the close, one card per instrument with its reason, timing, targets, status, the response bar, the notified / undelivered / failed counts and the average once enough people answered (per trainer and per session where it applies), "send now" when the policy leaves it to the coordinator, the tracking table with the delivery evidence per person, the impact follow-up card, the rules card (read for everyone, editable in place with `plc.settings`), reminders, and the close dialog (plain, or with an exception and its reason).
- `WorkshopDetail.tsx` (a link to the evaluation page from "in progress" onwards), `App.tsx` (route `/admin/communities/workshops/:id/evaluation`), `plcApi.ts`, `i18n/plc.ts` (`plc.evaluation.*`), `portal/MyEvaluations.tsx` (the trainer or session the form is about).

## 2. What the person can do now

- Open a workshop's evaluation page and read, before anything is sent, which forms will go to whom, when and why.
- Let the clock send them (the policy's default), or switch the policy to manual and send each instrument when ready; sending twice creates nothing.
- See for every person whether the form reached them (in-app, e-mail, SMS with the provider's status), who is overdue, and remind them.
- Read the response rate per instrument, per trainer and per session, and the average score once the anonymity minimum is reached.
- Close the workshop when the mandatory instruments are complete, or, as the centre, with a documented exception; cancelled workshops keep the answers already given.
- Change the rules (which instrument, mandatory or not, days to answer, impact sources, automatic sending, the closing response rate) from the same page with `plc.settings`.
- Trainees answer in the portal or the app as before, and see which trainer or session each form is about.

## 3. Tests executed and actual results

- `php artisan test --filter="PlcEvaluationTest"` → passed, 2 tests: (1) a راصد-sourced workshop inside a community with three seated people, one trainer and two sessions; the policy switched to session forms on; nothing sent before the start (422 `plc_evaluation_state`); the plan lists five instruments with five community targets (three trainees, the coordinator, the community's lead); after session 1 the session form reaches the two who attended it and the trainer form waits; after session 2 the workshop completes, the workshop, trainer and community forms go out (3 / 3 / 5), the two who met the minimum attendance are completed and get their +45 impact survey, the third stays approved, the state is awaiting evaluation; sending again creates nothing; the tracking shows notified = assigned with the in-app notification row holding the assignment id; closing refuses (incomplete), the exception refuses without `plc.exceptions` and without a reason; reminders go once a day; two of three answers (66 %) let the clock close the workshop; the score stays hidden below three answers; the CSV carries the names. (2) A single-session centre workshop: the coordinator sends by hand after the start, the session form is off and the community form not applicable, no impact for a centre source; nobody answers, the centre closes with an exception recorded as a decision; a cancelled workshop expires its unanswered form.
- Combined with `PlcAttendanceTest|PlcScheduleTest|PlcFoundationTest|PlcPeopleTest|PlcSchoolsTest|EvaluationSuiteTest|SystemEndpointsTest|DataClassificationTest` → 35 tests, 628 assertions, all passing. `PlcAttendanceTest` now expects the workshop to move from completed straight into the evaluation state.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only pre-existing warnings.
- Playwright sweep in Arabic against the local servers: the evaluation page after a manual sending (8 forms, the delivery evidence per person), the rules editor, and the detail page's link.

## 4. Security-sensitive changes

- The tracking view shows who answered and whether the form reached them, never the answers; per-instrument averages appear only above the evaluation settings' anonymity minimum; the CSV carries no scores.
- Exceptions need `plc.exceptions` and a reason and are decisions in the log; every sending, reminder and close is a decision.
- The rules are only changeable with `plc.settings`; the routes keep the PLC scope check (`assertVisible`).

## 5. Known limitations

- Pre / post knowledge tests keep the platform's existing path (the programme's tests through `KnowledgeService`); the policy "per workshop" from the design table is not a PLC switch yet.
- "Sent" means the in-app notification exists and the e-mail / SMS statuses are shown; push delivery is not tracked per assignment.
- Impact follow-ups go to people who met the minimum attendance; a trainee below it is not completed and gets none, which is the programme engine's rule.
- Per-workshop overrides of the rules are not provided; the coordinator chooses what to send when the policy is manual.

## 6. Exact next step

Phase 7 — reporting and the dashboard: the PLC overview (workshops by state, source and school; attendance and evaluation rates; needs converted), per-community and per-school reports with export. Report in `docs/plc/07-phase7-reporting.md`.

---
Full suite after Phase 6 (`php artisan test --compact`, Windows): 1172 tests, 1102 passed, 15861 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors in SamlSso/LtiTool/PushNotifications/Lti/SupabaseConnection/IdentitySecurity); every PLC, evaluation, cron and classification test passes.
