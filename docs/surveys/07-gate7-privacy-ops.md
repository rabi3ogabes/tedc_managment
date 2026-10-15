# Survey Studio (ستوديو الاستبانات) — Gate 7 report: privacy and operations

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Gate 6](06-gate6-analytics.md).

Gate 7 makes the engine's privacy promises operational: a daily retention run (drafts, orphan files, invitations, pseudonym keys, answers with aggregates kept, legal hold), the person's rights over their survey data inside the existing data-subject queue (access and erasure), retention transparency on every respondent form, a privacy-and-retention panel for the administrators, the scheduler wired on both hosts, a DPIA note and a runbook section. Everything stays behind `survey_studio_v2`.

## 1. Files changed

New (backend)
- `database/migrations/2026_01_01_007100_add_survey_retention_columns.php` — `surveys.legal_hold`, `aggregates`, `answers_purged_at`, `key_deleted_at`.
- `app/Services/Surveys/SurveyRetention.php` — `policy()` from `config/tedc.php` (`surveys.*`), `report()` (what the next run removes, the surveys falling due with their dates, legal holds, counts of purged surveys and deleted keys), `run(dry)` (expired drafts, orphan files past the draft TTL, invitations after close + 90 days, pseudonym keys after close + 30 days with the drafts filed under pseudonymous keys, answers after the survey's own retention period), `dropKey()`, `retire()` (freezes the summary, per-question statistics and the needs ranking on the survey, then deletes files from storage, submissions and their edits, drafts, participants and invitations, revokes public links), `retireAt()`.
- `app/Services/Surveys/SurveyDataSubject.php` — `export()` (participations; identified answers by question title; a plain note that confidential and anonymous answers cannot be linked), `preview()`, `erase()` (detaches the person from every identified answer, clears their segment snapshot and file answers, deletes their files, drafts and participation rows; a `subject_erased` event per survey, an audit row and a security event).
- `app/Console/Commands/SurveysRetention.php` — `php artisan tedc:surveys-retention [--dry-run]`, scheduled daily at 03:30 and added to the Vercel cron list in `SystemController::cron`.
- `app/Http/Controllers/Api/V1/Admin/SurveyPrivacyController.php` — `GET/POST admin/surveys/retention` (`surveys.manage|privacy.manage`), `GET admin/privacy/subjects/{user}/surveys`, `POST admin/privacy/subjects/{user}/surveys/erase` (`privacy.manage`).
- `tests/Feature/SurveyRetentionTest.php`.

Changed (backend)
- `Survey` model (fillable, casts, `distributions()`), `SurveyWorkflow::update` and `SurveyController` (`legal_hold`; `retire_at`, `answers_purged_at`, `key_deleted_at` presented), `SurveyAnalytics::results` (a purged survey answers from its frozen aggregates with `purged_at`), `SurveyEvents::ADMIN`, `DataSubjectService::export` (a `surveys` section), `PrivacyController::index` (`user_id`), `MySurveyController::show` and `PublicSurveyController::definition` (`retention_days`), routes, `routes/console.php`, `SystemController`, `docs/security/data-classification.md` regenerated.

New / changed (web)
- `src/pages/admin/surveys/RetentionPanel.tsx` — the panel الخصوصية والحفظ on the surveys list: the four policy periods with what is pending for each, the surveys due and upcoming with their dates, legal holds, counts, a dry run and a run by hand, and a note on the schedule (daily 03:30; once a day at 05:00 on Vercel).
- `Studio.tsx` — a legal-hold switch in settings with the computed purge date or the key-deleted note under the retention field; header badges for purged answers and legal hold. `ResultsTab.tsx` — a purged survey shows its frozen aggregates with a notice. `EngineForm.tsx` — a fourth welcome fact: how many months the answers are kept (portal, public page and the studio preview pass it). `PrivacyQueue.tsx` — on access and erasure requests, the person's survey data (identified answers, files, participations) and, for erasure, the detach action with a plain confirmation. `i18n/{studio2,privacy}.ts`.

Docs
- `docs/security/dpia-survey-studio.md` (the DPIA note for the DPO), a Survey Studio section in `docs/ops/operations-handbook.md`, `CHANGELOG.md`.

## 2. What the person can do now

- An administrator opens الخصوصية والحفظ, reads the policy and what the next run removes, runs a dry run or a real run, and sees which surveys are held or due.
- A survey owner puts a legal hold on a survey from its settings and sees when its answers would otherwise be purged.
- A respondent reads on the welcome screen how long answers are kept.
- A privacy handler sees a requester's survey data on an access or erasure request and, for erasure, detaches the person from their identified answers in one audited action.
- A person's "my data" download includes their survey participations and identified answers.

## 3. Tests executed and actual results

- `php artisan test --filter="SurveyRetentionTest"` → passed, 2 tests: (1) on an open confidential survey only the expired draft and the orphan file go (a dry run changes nothing; the command runs); closed 31 days the key goes, the three answers stay, a confidential answer can no longer be found for its person, the aggregates still work; closed 91 days the invitation goes; closed past the retention period a legal hold stops retirement and is listed, lifting it makes the survey due, the run purges the answers and participants, the frozen aggregates keep the counts and the average, the results endpoint answers from them with `purged_at`, and a second run changes nothing. (2) A person's export lists both participations and only the identified answer by question title with the note; a handler previews the counts, a plain employee is refused, the erasure leaves the answer anonymous, deletes the file and the participations, leaves the other person untouched, records the event, and a second preview is empty.
- Combined run with `SurveyAnalyticsTest|SurveyEngineTest|SurveyDistributionTest` → passed, 13 tests, 404 assertions.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only the pre-existing warning in the studio loader.
- Playwright sweep in Arabic against the local servers: the retention panel after a dry run, the studio settings with the legal-hold switch, the privacy queue with a teacher's erasure request and the survey-data block, and the respondent welcome with the retention fact.

## 4. Security-sensitive changes

- Deletions are irreversible by design; the run is daily and can be rehearsed. Every key deletion, purge and erasure writes a survey event, an audit row and a security (SIEM) event.
- The retention panel needs `surveys.manage` or `privacy.manage`; erasure needs `privacy.manage` and is only offered from the handlers' queue.
- Erasure keeps the answer anonymous rather than deleting it: statistics hold, and nothing about the person remains (identity, segments, files, drafts, participation).

## 5. Known limitations and decisions

- The live host (Vercel) runs the shared cron once a day at 05:00; the 03:30 schedule applies to hosts with a real scheduler (Azure). Both are documented in the runbook.
- Aggregates frozen at retirement are kept indefinitely; whether they should expire is a Ministry decision (listed in the DPIA note).
- Purge of public distributions keeps the link rows (no personal data) revoked rather than deleted, so a printed QR explains itself as closed.

## 6. Exact next step

Gate 8 — AI assistance: summaries and themes of open-text answers for surveys that opt in (anonymous or pseudonymised text only), through the configured model with logging, and question suggestions in the studio. Report in `docs/surveys/08-gate8-ai.md`.

---
Full suite after Gate 7 (`php artisan test --compact`, Windows): 1166 tests, 1095 passed, 15542 assertions. Besides the known Windows-only failures (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors), the run caught one real regression: `SystemEndpointsTest::test_cron_runs_the_scheduled_commands` expects the exact cron command list, which now includes `tedc:surveys-retention`; the expectation was updated and the suite passes (8 tests).
