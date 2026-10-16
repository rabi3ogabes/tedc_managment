# PLC (مجتمعات التعلم المهنية) — Phase 8 report: smart assistants

Date: 2026-10-16 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Phase 7](07-phase7-reporting.md). This phase closes the PLC programme (design in [00-analysis-and-design.md](00-analysis-and-design.md) §9).

Phase 8 adds the advisory layer the design asked for, with the rule that nothing here decides anything: a planning checklist and completion tracker computed from the workshop's own records (every item says why and where to go, and the next step is the first thing not started), the explanations behind the school and trainee suggestions brought onto the workshop page, the decision log grouped by kind on the workshop and a decisions-and-exceptions register across the scope, the need → workshop → attendance → evaluation → impact chain so the loop from a registered need to its effect is visible, and feedback themes from the open-text answers of the PLC forms through the AI guard, only after the Ministry opts in from the PLC policy, with the respondents' names hidden before anything leaves and the result marked advisory. Everything stays behind `plc`.

## 1. Files changed

New (backend)
- `app/Services/Plc/WorkshopAssistant.php` — `checklist()` (twelve items: source, basics, host, participating schools, trainees, trainers, sessions, approval, invitations, attendance, evaluation, close; each `done` / `warn` / `todo` with a bilingual reason and a link; progress; the next step; the school reasons and distances; the trainees' seats, clashes and statuses; decisions by kind; the impact chain; the themes status), `impactChain()`, `themesStatus()` and `themes()` (opt-in from `PlcSettings::ai_feedback_opt_in`, at least 3 text answers, `AiGuard::json` under the new `plc` feature with the respondents' names passed to the redactor, the result cleaned, cached for a day per answers snapshot, recorded in the audit log).
- `app/Http/Controllers/Api/V1/Admin/Plc/WorkshopAssistantController.php` — `GET workshops/{plcWorkshop}/assistant` (`plc.view`, scope checked), `POST workshops/{plcWorkshop}/assistant/themes` (`plc.evaluations.manage|plc.manage`).
- `tests/Feature/PlcAssistantTest.php`.

Changed (backend)
- `AiPolicy::FEATURES` gains `plc` (its own switch, model and residency rule in the AI settings); `PlcSettings` gains `ai_feedback_opt_in` (false by default); `PlcHubController` accepts it.
- `PlcReports` — `need_id` filter and `decisions()` (the log across the scope, filtered by kind, decision and date, with the report filters); `PlcReportController::decisions` (`GET admin/plc/reports/decisions`, `plc.reports`).
- `lang/{ar,en}/messages.php` (`plc.ai_opt_out`, `ai_too_few`, `ai_unavailable`), routes, `docs/deliverables/*.generated.md`.

New / changed (web)
- `src/pages/admin/plc/AssistantPanel.tsx` — on the workshop page: the progress bar, the next step as a call to action, the checklist with reasons and links, the advisory note; the chain card (need, seats, completed and attendance, evaluation, impact), why these schools, clash warning, and the decision-log filter by kind.
- `WorkshopDetail.tsx` (the panel; the log filters by kind), `Evaluation.tsx` (the feedback-themes card: opt-in state with an enable button for `plc.settings`, availability, the minimum, generate / regenerate, summary, sentiment bar, themes with quotes, suggestions, the model and the redaction count), `PlcReports.tsx` (the decisions and exceptions register with kind chips), `PlcHub.tsx` (the need deep link into the reports; "need impact" from the needs register), `i18n/plc.ts` (`plc.assistant.*`, the newer decision kinds and values, the overview banner), `i18n/ai.ts` (the `plc` feature label).

## 2. What the person can do now

- Open any workshop and see at a glance how far it is, what is missing and why, and jump to the next step; warnings (hours missing, seats below capacity, a proposed trainer not yet confirmed, a session short of a room or trainer, a session ended and not locked) never block, they point.
- See why each school was suggested and how many nominations clash, filter the decision log by kind, and follow a need to the people it reached, their attendance, their evaluations and their impact forms.
- Read, across the scope, the register of decisions and exceptions with who decided and why.
- With the Ministry's opt-in, extract the themes of the open answers of a workshop's forms, see the sentiment, verbatim quotes and suggestions for the centre, and how many identifiers were hidden; without the opt-in the page says so and the settings holder can enable it in place.

## 3. Tests executed and actual results

- `php artisan test --filter="PlcAssistantTest"` → passed, 1 test, 69 assertions: a plain employee cannot read the assistant; a fresh draft shows source and host done, basics and participating schools as warnings (hours and schools missing), the rest to do, trainees as the next step and 2 of 12 done; after nominations and a proposed trainer the trainees item is done, the trainers item warns and the explanations count three seated; after confirmation, a session, approval and scheduling the next step is attendance and 7 of 12 are done (58 %); after the session, the completion and three open answers the chain shows 3 completed and 3 answers; themes refuse before the opt-in (`plc_ai_opt_out`), are unavailable without a model and with an external model (`plc_ai_unavailable`); with a model inside Qatar the request leaves with the answer text and without the respondent's name, the result carries the themes and is marked advisory, the second call is served from the cache (one HTTP call in all), the audit log holds the record and the assistant shows the cached result; the trainer form made mandatory leaves the close incomplete and the centre closes with an exception, which the register lists under `exception` with its reason and workshop, and the `state=closed` filter counts it.
- Combined with `PlcReportsTest|PlcEvaluationTest|SurveyAiTest` → all passing (the Survey Studio AI path is unaffected by the new feature key).
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only pre-existing warnings.
- Playwright sweep in Arabic against the local servers: the assistant panel on the workshop page, the themes card on the evaluation page (opt-out state with the enable button), the register on the reports tab.

## 4. Security-sensitive changes

- Feedback themes run only after the Ministry's opt-in in the PLC policy (`plc.settings`), through the AI guard's own feature switch, residency rule and redactor; the respondents' names are passed to the redactor; the prompt is logged only when the AI policy logs prompts; every run is audited with the answer count, the model and the redaction count; the output is cleaned (lengths, counts, at most 8 themes and 6 suggestions) and marked advisory.
- The assistant exposes counts, statuses and reasons already visible on the workshop's own pages, inside the PLC scope; the register needs `plc.reports` and is scoped the same way as the reports.

## 5. Known limitations

- Themes are cached per answers snapshot for a day; a regenerate button forces a fresh run.
- The trainee suggestion explanations are the clash and status counts; per-person reasons remain on the trainees step.
- Pre / post knowledge tests and per-workshop rule overrides remain as noted in Phase 6.

## 6. Programme wrap-up and open Ministry decisions

The eight phases of the PLC design are built, tested and reported (`docs/plc/01` to `08`). Open decisions for the Ministry, each with the default the system applies today:
- The AI opt-in for feedback themes (off by default) and which model, inside Qatar, may read the text.
- The evaluation rules (which instruments are mandatory, the closing response rate, the impact sources) — defaults from the design table.
- Whether a workshop below the minimum attendance should still get impact follow-ups (today only those who met it are completed).
- The راصد integration stays a manual entry with a reference until an importer exists.

---
Full suite after Phase 8 (`php artisan test --compact`, Windows, 2026-10-16): 1174 tests, 1103 passed, 15951 assertions. Besides the known Windows-only failures (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors in SamlSso/LtiTool/PushNotifications/Lti/SupabaseConnection/IdentitySecurity), the run caught one date-dependent test: `PlcScheduleTest` chose its third session day by an offset that, after skipping a weekend, landed on the same date as its seeded closed day; the test now counts from the closed day itself and the five PLC suites pass together (6 tests, 379 assertions).
