# Survey Studio (ستوديو الاستبانات) — Gate 3 report: the studio and the respondent form

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Gate 2](02-gate2-engine.md).

Gate 3 gives the new engine its screens: a dedicated menu entry **ستوديو الاستبانات**, the surveys list, the studio (build, settings, audience, live preview, versions and event log, the whole lifecycle), the respondent form in the portal with autosave and resume, and the surveys list on the portal page. Everything stays behind the flag `survey_studio_v2`.

## 1. Files changed

New (web)
- `src/lib/surveyEngine.ts` — the question model and a faithful port of the server's `LogicEngine` (condition groups, page breaks, jumps) and per-type answer checks, so the preview and the form show exactly what the server validates.
- `src/components/surveys/EngineForm.tsx` — the respondent form: 21 types, pages, live logic, per-page validation with an error summary and scroll-to-error, autosave (1.2 s after the last change) with resume, submit, edit after submit when allowed, privacy fact on the welcome screen, thank-you.
- `src/pages/admin/surveys/SurveysList.tsx` — list with search, state filter, "mine", and the create dialog (title, kind, privacy mode).
- `src/pages/admin/surveys/Studio.tsx` — palette grouped by structure / text / choices / scales / other; inline question editor (bilingual title, description, required, options with paste-a-list and English labels, "other", min/max select, scale bounds and labels, text length and placeholder, number range, file rules); move, duplicate, remove (references in other questions' logic are cleaned); the logic editor (visible-when groups with all/any and typed values, jumps to a later question or the end); settings (privacy mode frozen once answers exist, edit policy, approval, threshold, segment fields, welcome/thank-you, one per page, progress, notify, colour, window, retention, kind); audience filters with a live size preview; preview tab running the same logic; versions and event log; lifecycle actions by permission (submit for review, approve, return with a note, publish / publish new version, close, reopen, duplicate, archive).
- `src/pages/portal/SurveyRespond.tsx` — `/portal/surveys/:id` on the new engine; `src/i18n/studio2.ts` (`ss.*`).

Changed
- `backend/routes/api.php` + `SurveyController::audienceOptions` — `GET admin/surveys/audience/options` so the studio needs no needs-hub permission.
- `App.tsx` (routes `/admin/surveys`, `/admin/surveys/:id`, `/portal/surveys/:id`), `menuCatalog.ts` (new entry under دورة التدريب, permission `surveys.view`, feature `survey_studio_v2`), `i18n/index.ts`, `pages/portal/Surveys.tsx` (a section listing the engine's surveys addressed to the person, shown only when there are any).

## 2. What the person can do now

- Open ستوديو الاستبانات from the menu, create a survey with its privacy mode, build it with any of the 21 types, add page breaks, conditions and jumps, see the respondent view in the preview tab with the same logic, set the audience and see how many people match, submit for review, approve, publish (a version is frozen and participants enrolled), close, reopen, publish a new version after changes, read the versions and the event log.
- A participant sees the survey on the portal surveys page, answers page by page with clear errors, loses nothing (the draft is saved and restored), and may edit until close when the policy allows. Anonymous surveys never show a stored answer.

## 3. Tests executed and actual results

- Backend: `php artisan test --filter="SurveyEngineTest"` was not changed by this gate; the new route is covered by the existing catalogue permissions. Full suite result appended below.
- Web: `tsc --noEmit` clean; lint reports one setState-in-effect warning in the studio's loader (the pattern used elsewhere in the project) and nothing else in the new files.
- Screens opened as the administrator in Arabic: the list, the builder with a selected question and its jump, the preview mid-form, the settings tab; as a teacher: the portal list with the new section and the form with a page validation error.
- Not done: automated browser tests for the studio (the project's Vitest suite covers units only); the file-upload type renders as a placeholder until Gate 4 brings storage.

## 4. Security-sensitive changes

None on the server beyond the read-only audience options endpoint (permission `surveys.view`). The client never decides validity: the server validates every submission again.

## 5. Known limitations

- Drag-and-drop ordering is not used (no drag library in the project); ordering is by arrows, which also works with the keyboard.
- The audience filters cover regions, school types, stages, genders, specialisations, nationalities and qualifications; school-by-school and job-title filters come with the shared school picker in Gate 4.
- File upload shows the rules but cannot accept files until Gate 4.

## 6. Exact next step

Gate 4 — distribution: public and invitation links with QR codes, duplicate rules (invitation, e-mail code, device), file answers with storage and checks, reminders, and the mobile form. Report in `docs/surveys/04-gate4-distribution.md`.
