# Programme forms as generated links («استمارات التقييم»)

2026-10-28. SABER's «روابط الاستمارات» asked for a workbook of external form links (serial, form link, edit link) and for each link to be
given to its programme by hand. Shaheen already builds both forms for every programme — the **trainee form** (the programme survey) and
the **trainer form (self-reflection)** — so the links are now generated: nothing to upload, nothing to assign.

## The two links of a programme

| Form | Link | Opens for the signed-in person |
| --- | --- | --- |
| استمارة المتدرب | `/f/t/{programme code}` | their own evaluation of the programme («تدريبي», the evaluation window opens by itself) |
| استمارة المدرب (التأمل الذاتي) | `/f/r/{programme code}` | their own self-reflection («تقييماتي» → the form) |

The address uses the site address (`TEDC_WEB_URL`, the same one the room screens use) and the programme code, so it never changes and a QR
code printed once keeps working. A visitor who is not signed in signs in and comes back to the link. When the form is not open for them the
page says why, plainly: thank you (already answered), opens on a date, closed, switched off, registration waiting for approval, not
registered in this programme, opens when your group ends, or not a trainer of this programme. Someone arriving from a link fills the form
first: a first-sign-in guided tour waits minimised until they resume it.

## Where they stand

- **Trainee form** — follows the programme survey setting: always available, open, scheduled (opens N hours after the programme ends, with
  the time), closed, or switched off (programme instruments). Answered / registered trainees.
- **Trainer self-reflection** — assigned to the programme's approved trainers when their group ends (`EvaluationService::assignForGroup`);
  waiting (groups ended out of all, trainers), open, complete, or switched off. Answered / assigned. The form used is the programme's own
  choice or the default «تأمل المدرب الذاتي».

## In سابر+

- **Workbench** — each programme's «الاستمارات» chip opens «استمارات البرنامج»: the two forms side by side with status, progress, the
  link (copy, open), the QR code (download PNG) and what the person may do: open the trainee form now / close it (`programs.manage`, the
  existing programme-survey actions, trainees told on opening), the results (programme page → التقييم), edit the self-reflection form
  (`evaluations.manage`). «طباعة بطاقة QR» prints an A4 card with both codes.
- **Details window** — the two forms with their status and links.
- **أدوات البيانات → «استمارات التقييم»** (replaces «روابط الاستمارات», same right `saber.survey_links.manage`) — every programme (not
  drafts, archived or cancelled) with its two links, status and counts: search, running / upcoming / ended, **export to Excel** (clickable
  links), and **print the QR codes of the page** (one card per programme).
- **New programmes** published from SABER+ get both forms from the start (`ProgramInstruments::ensureForms`): the self-reflection, and the
  trainee form opening automatically 24 hours after the programme ends — the programme form's default. A choice already made is kept.

## Removed

The upload, template, assign and clear endpoints of the links workbook (`/admin/saber/data/survey-links*`), the programme's manual survey
link fields in SABER+ and their dialogs. The `saber_survey_links` table and the programmes' `survey_link` / `survey_edit_link` columns are
left in place (no data deleted); nothing reads them any more.

## API and code

`GET admin/saber/forms` (q, when, page), `GET admin/saber/forms/export`, `GET admin/saber/programs/{program}/forms` (right `saber.view`);
`GET me/form-links/trainee/{code}`, `GET me/form-links/reflection/{code}`. `app/Services/Saber/FormLinks.php`, `SaberFormsController`,
`Me/MyFormLinkController`, `ProgramInstruments::ensureForms` (called on publishing a new SABER+ programme); web
`pages/admin/saber/FormLinks.tsx`, `pages/public/FormLink.tsx`, `MyTraining` (`?evaluate=`), `TourHost`.

## Tests

`SaberFormsTest` (4): both links with status and counts (open, scheduled with time, switched off, waiting), list with search and period,
Excel export, details window, rights; the trainee link per person (open with the registration, scheduled, closed, submitted, not
registered, pending, unknown code); the reflection link per trainer (waiting, not a trainer, open with the assignment once the group ends,
submitted, complete); a programme published from SABER+ gets both forms and the automatic survey. The old upload test was removed and the
details test now checks the forms. SABER+, evaluation and survey suites: 86 tests on SQLite; the form-related 38 on PostgreSQL 16. Checked
in the browser on demo data: the list, the programme window with both QR codes, the landing page, and a trainee signing in from the link
straight into their evaluation.
