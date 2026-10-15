# Survey Studio (ستوديو الاستبانات) — Gate 8 report: AI assistance, and the programme wrap-up

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Gate 7](07-gate7-privacy-ops.md).

Gate 8 adds the two AI helpers the programme approved, both under the platform's existing AI policy: summaries of open-text answers for surveys that opt in (themes with counts and verbatim quotes, a sentiment split, recommended actions), and question suggestions in the studio from a stated goal. Nothing personal ever leaves: only the answer texts, with names, e-mails and phones redacted (and, for identified surveys, the respondents' names hidden explicitly); the prompt is never stored; every call is logged; results are kept with who asked and when. Everything stays behind `survey_studio_v2` and the AI feature flag.

## 1. Files changed

New (backend)
- `database/migrations/2026_01_01_007200_create_survey_ai_insights.php`, `app/Models/SurveyAiInsight.php` — what the model produced (summary or suggestions), for which version and question, how many answers, which model, how many redactions, by whom, when.
- `app/Services/Surveys/SurveyAi.php` — `status()` (opt-in, availability under the policy, text questions with counts, past insights), `summarise()` (refuses without the per-survey opt-in, after a purge, without text questions or under three answers; collects up to 300 answers per text question trimmed to 400 characters, never the evidence link companions, e-mails or phones; sends them through `AiGuard::json` as personal data with the respondents' names to redact on identified surveys; cleans the model's answer into themes with shares, sentiment, quotes and recommendations; stores the insight and records `ai_summarised`), `suggest()` (goal in, 3–8 questions out, each normalised by the engine and dropped when the engine cannot hold it; nothing personal sent; `ai_suggested` recorded).
- `app/Http/Controllers/Api/V1/Admin/SurveyAiController.php` — `GET {studioSurvey}/ai` (`surveys.results|surveys.manage`), `POST {studioSurvey}/ai/summaries` (`surveys.results`), `POST {studioSurvey}/ai/suggestions` (`surveys.manage`); both actions throttled.
- `tests/Feature/SurveyAiTest.php`.

Changed (backend)
- `AiPolicy::FEATURES` gains `surveys` (on by default, external models not allowed to see personal text unless the policy says so), so the AI settings page governs it like every other feature; `SurveyEvents::ADMIN`; routes; `lang/{ar,en}/messages.php` (`surveys.ai_*`); `docs/security/data-classification.md` regenerated.

New / changed (web)
- `src/pages/admin/surveys/AiPanel.tsx` — in the results tab: the opt-out note, the policy-unavailable note, buttons to analyse all text answers or one question (disabled under three answers), past analyses to switch between, and the analysis itself: summary, sentiment bar, themes with shares and quotes, recommended actions, and a line with answers, model, redactions, who and when.
- `src/pages/admin/surveys/SuggestDialog.tsx` — from the build tab's palette: a goal, suggested questions with their type and options, pick and add to the survey.
- `Studio.tsx` (the suggest button, the AI opt-in switch in settings), `ResultsTab.tsx` (the panel), `i18n/{studio2,ai}.ts` (strings; the feature's name on the AI settings page).

## 2. What the person can do now

- A survey owner opts the survey in to AI analysis from its settings (off by default), with a plain explanation of what is sent.
- Someone with results rights presses "analyse" and reads, in Arabic, the themes people raised with how many touched each, verbatim quotes, the overall sentiment, and suggested actions; past analyses stay with the survey.
- An author describes the goal and gets questions in the engine's format to add and edit.
- The administrator switches the feature off, assigns a model, or allows an external model for it, on the AI settings page like for every other AI feature.

## 3. Tests executed and actual results

- `php artisan test --filter="SurveyAiTest"` → passed, 2 tests, 46 assertions: (1) an identified survey with four text answers carrying the respondents' names and an e-mail question: without the opt-in the call is refused before any model; opted in without a model it is unavailable and nothing is stored; an external model with residency enforced is refused and the AI log says `residency`; a model inside Qatar is called and the request body carries the answers but neither the respondents' names nor the e-mail, the insight is stored with shares and quotes, the survey event and the AI log line exist; a fresh survey with one answer is refused as too few; a plain employee is forbidden; switching the feature off in the AI policy makes it unavailable. (2) Suggestions come back normalised in the engine's format, a disallowed type and an invalid question are dropped, the event is recorded, and a goal that is too short is rejected.
- Combined with `SurveyEngineTest` and `AiCompletionTest` → 31 tests, all passing.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only the pre-existing warning in the studio loader.
- Playwright sweep in Arabic against the local servers (no model is assigned locally): the settings tab with the opt-in switch, the results tab's AI panel in its policy-unavailable state, and the suggestion dialog with a goal typed.

## 4. Security-sensitive changes

- The feature cannot run outside the AI policy: feature on, a model assigned, residency respected for personal text, redaction applied, prompts never stored, every outcome logged (`ai_logs`, feature `surveys`).
- Opt-in is per survey and off by default; a purged survey cannot be analysed; evidence links, e-mails and phones are never part of the text sent.
- Outputs are bounded and cleaned before storage (at most 8 themes, 3 quotes each, 6 recommendations; lengths capped).

## 5. Known limitations

- Summaries are one-shot per request; very large surveys are sampled (the most recent 300 answers per question).
- Quotes are what the model returns; the service caps their length but does not verify them word for word against the answers.
- No AI runs on the respondent side (no live suggestions while answering), by design.

## 6. Programme wrap-up

| Gate | Delivered | Commits |
|---|---|---|
| 0–1 | Discovery and the approved decisions (`00-gate0-discovery.md`) | — |
| 2 | The unified engine (21 types, logic, versions, privacy modes, edits, audit) | 8f5b534 |
| 3 | The studio and the respondent form | 7b41253 |
| 4 | Links, QR, invitations, public respondents, file answers | 323e878 |
| 5 | The training workflows on the engine (migration with rollback) | 6fa9c0d, 7d1c62f |
| 6 | Analytics and exports | 469cc03, 39b22c2 |
| 7 | Privacy and operations (retention, data-subject rights, DPIA) | ee154ed, e7a278e |
| 8 | AI assistance | this commit |

Decisions still open for the Ministry, gathered from the gate reports: replacing the fixed satisfaction form with an engine survey (changes the mobile app); when to run the data migration on live data; whether frozen aggregates should expire; the Azure move before go-live; how sparingly to grant `surveys.results.identified`. Everything else in the master prompt's scope is in place behind `survey_studio_v2`.
