# Survey Studio (ستوديو الاستبانات) — Gate 6 report: analytics and exports on the engine

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Gate 5](05-gate5-workflows.md).

Gate 6 gives the engine its own results: a results tab in the studio with the summary, segment filters under the survey's threshold rule, per-question statistics for every type, the needs ranking with needs generation for needs surveys, individual answers gated by the identified permission, comparison across surveys that share questions, Arabic and English exports (Excel, PDF, Word, CSV) that are audited, and an overview strip on the surveys list. Everything stays behind `survey_studio_v2`.

## 1. Files changed

New (backend)
- `app/Services/Surveys/SurveyAnalytics.php` — `submissions()` (the survey's own answers, never the context answers of evaluation assignments; by version and by segment), `results()` (summary, timeline, sources, versions, per-question statistics, the segments a viewer may filter by, the needs ranking), the threshold rule (a filtered segment smaller than the threshold is hidden, and a segment value is only offered as a filter when at least the threshold of answers stand behind it, unless the viewer may see identified answers of an identified survey), `answers()` (one row per submission; the person only for identified surveys and viewers with `surveys.results.identified`; segment fields only above the threshold), `compare()` (summaries side by side and every comparable question shared by id and type), `export()` (through `SurveyDocument` and `ReportExporter`; the respondent column only when identified answers are open to the viewer; refused under the threshold; audited as `exported`), `generateNeeds()` (training needs from an engine needs survey, overall or per school, keyed by `engine_survey_id`, audited as `needs_generated`), `overview()`.
- `app/Http/Controllers/Api/V1/Admin/SurveyResultsController.php` — `GET admin/surveys/overview` (`surveys.view`), `GET admin/surveys/compare?ids[]` (`surveys.results`, registered before the id routes), `GET {studioSurvey}/results`, `GET {studioSurvey}/answers` (`surveys.results`), `GET {studioSurvey}/export?format&lang` (`surveys.export`), `POST {studioSurvey}/generate-needs` (`needs.manage`).
- `database/migrations/2026_01_01_007000_add_engine_survey_to_training_needs.php` — `training_needs.engine_survey_id`.
- `tests/Feature/SurveyAnalyticsTest.php`.

Changed (backend)
- `SurveyAnalyzer` — `questionStats()` skips the layout types and understands Likert (as a choice), file uploads (a count) and the new text types; `signalsFor()`, `needsFor()` and `engineRows()` are public and definition-agnostic so the engine and the legacy report share one implementation.
- `TrainingNeed` fillable, `SurveyEvents::ADMIN` (`migrated`, `needs_generated`), routes, `lang/{ar,en}/messages.php` (`surveys.segment_hidden`, `surveys.not_needs`), `docs/security/data-classification.md` regenerated.

New / changed (web)
- `src/pages/admin/surveys/ResultsTab.tsx` — KPIs, the timeline sparkline, segment selects with counts (only the values the threshold allows), source and version selects, export buttons, the hidden-segment notice, the needs panel with generation (overall / per school), per-question cards (option bars, distributions, matrix rows, text samples, file counts), the individual answers table with expandable rows (option labels resolved, files named, segments labelled), and the comparison panel (pick up to five surveys with answers; shared questions side by side).
- `Studio.tsx` (the النتائج tab for `surveys.results`), `SurveysList.tsx` (the overview strip: open surveys, answers over thirty days with a bar sparkline, open surveys with their rates), `i18n/studio2.ts` (`ss.results.*`, `ss.overview.*`).

## 2. What the person can do now

- Open النتائج on any survey: see who answered and how fast, filter by school, job title, stage, specialisation, nationality, gender, experience band or source (only when the segment is large enough), switch versions, read every question's statistics, and download the report in Excel, PDF, Word or CSV in either language.
- For a needs survey, read the derived needs ranking by skill, pick skills and create approved training needs (one overall or one per school) straight into the needs register.
- Read individual answers: names and schools only on identified surveys for people allowed to see them; never on confidential or anonymous surveys, whoever looks.
- Compare a survey with up to five others that share questions, side by side.
- See the studio at a glance on the surveys list.

## 3. Tests executed and actual results

- `php artisan test --filter="SurveyAnalyticsTest"` → passed, 1 test, 96 assertions: a confidential needs survey answered by six people from two schools (matrix with a skill, single, rating, NPS, Likert, long text); the statistics per type; schools under the threshold are not offered as filters while the experience band is; a school filter hides the questions and refuses answers and exports with `survey_segment_hidden`; a lower threshold shows the segment; confidential answers never carry the person; needs generated per school and marked as generated; a viewer without `needs.manage` is refused; an identified survey names people only for the identified permission and is never hidden for such a viewer; comparison returns the shared questions with their averages and NPS; exports in every format with the respondent column only where allowed, four audit events; a plain employee cannot export; the overview counts.
- Combined run of the six survey suites (`SurveyAnalyticsTest|SurveyMigrationTest|SurveyEngineTest|SurveyDistributionTest|NeedsSurveyTest|EvaluationSuiteTest`) → passed, 30 tests, 613 assertions.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only the pre-existing warning in the studio loader.
- Playwright sweep in Arabic against the local servers with the migrated needs survey (128 answers): the overview strip, the results tab (summary, needs ranking, question cards), a school filter, the individual answers with an expanded row, the comparison panel, and a PDF export (126 KB) over the API.

## 4. Security-sensitive changes

- The threshold rule is enforced on the server for results, individual answers and exports; the client only mirrors it.
- Identities appear only for identified surveys and only for viewers holding `surveys.results.identified`; the pseudonym of confidential answers is never returned.
- Every export and every needs generation writes a survey event mirrored into the audit log, with the format, the row count, whether identities were included and the filters used.

## 5. Known limitations

- Comparison matches questions by id and type; surveys built independently with the same meaning but different ids are not matched (duplicating a survey keeps the ids, which is the intended way to build comparable rounds).
- Text answers are shown as the latest thirty samples per question; AI summaries of open text wait for Gate 8 (opt-in per survey).
- The legacy needs report page keeps its own layout for legacy and migrated surveys; engine-born needs surveys use the new results tab only.

## 6. Exact next step

Gate 7 — privacy and operations: retention and deletion of answers after the retention period, deletion of pseudonym keys after close plus grace, data-subject requests on identified surveys, the scheduled jobs on the live host, and the operations runbook. Report in `docs/surveys/07-gate7-privacy-ops.md`.
