# The centre's two forms in Shaheen

2026-10-28. The two programme forms are now exactly the centre's Microsoft Forms — same sections, statements, scales and order — answered
inside Shaheen through the programme links (`/f/t/{code}`, `/f/r/{code}`, docs/saber/07). Defined once in `app/Support/EvaluationTemplates.php`.

## استمارة المتدرب (trainee form)

The welcome message («أخي المتدرب / أختي المتدربة …»), then:

| # | Question | Answer |
| --- | --- | --- |
| 1 | أولاً: تقييم المدرب — 6 statements | أوافق بشدة · أوافق · محايد · غير موافق · غير موافق بشدة (every statement required) |
| 2 | ثانيًا: تقييم المحتوى التدريبي — 6 statements | same |
| 3 | ثالثًا: تقييم البيئة التدريبية والتسهيلات — 3 statements | same |
| 4 | رابعًا: تقييم الأثر المتوقع — 3 statements | same |
| 5 | تقييمك العام للورشة التدريبية | ممتاز · جيد جدا · جيد · مقبول · ضعيف |
| 6 | عبِّر عن رأيك .. فرُبَّ كلمة منك تؤثِّر | text (required) |

The Microsoft form's first page (group, programme, sub-programme, trainer) is not asked: the window shows the programme, group, trainers and
dates from the registration. A progress line counts the answered questions; submitting with gaps highlights them and scrolls to the first.
Stored as one rating per statement (`trainer.clarity` → 1–5) plus `overall` (ممتاز = 5 … ضعيف = 1) and the comment, so the satisfaction
results, alerts, exports and certificate rules work as before; reports now show each statement's text. Satisfaction = mean of the 18
statements as a percentage of the top of the scale (أوافق on all = 80 %). The previous four-rating payload is still accepted, so older
mobile app versions keep working until the app is updated.

## استمارة المدرب — التقييم الذاتي للمدرب (trainer form)

«أولاً: معلومات عامة» is shown filled in — name, job title (trainer record or staff job title), programme and its dates — then:

| Section | Statements | Answer |
| --- | --- | --- |
| ثانياً: معلومات عن مركز التدريب والتطوير | 8 | أوافق تماماً · أوافق · لا أوافق · لا أوافق أبداً |
| ثالثاً: معلومات عن تقديم البرنامج التدريبي | 9 | same |
| ما هو تقييمك العام للبرنامج التدريبي؟ | — | ممتاز · جيد · مرضي · غير مرضي |

Assigned to the programme's trainers when their group ends, as before; the grids now count towards the reflection score.

## On deploy (migration `2026_01_01_009200_centre_evaluation_forms`)

- The trainee (satisfaction) form takes the centre's questions and welcome message.
- «التقييم الذاتي للمدرب» is created, approved, and made the default self-reflection; programmes that used the previous default (or none)
  switch to it, a programme that chose another form keeps it. Earlier forms and every answer stay. Safe to run twice.

## Engine changes

- Scales can name every point (`scale.labels`, lowest first) and show the highest first (`scale.order: 'desc'`); rows and options keep an
  English label (`label_en`). The form page shows the names as column headers (and on the buttons on a phone).
- `EvaluationService::score` counts matrix answers.

## API and code

`GET me/registrations/{registration}/evaluation` (form + what is known + open / submitted); `POST` the same with `answers` (or the old
`ratings`). `App\Support\EvaluationTemplates`, `App\Services\TraineeSurvey`, `MyOutcomesController`, `MyEvaluationController` (context),
`SurveySchema`; web `pages/portal/TraineeEvaluation.tsx`, `MyEvaluations` (general information card), `components/surveys/SurveyForm.tsx`.

## Tests

`CentreFormsTest` (4): the trainee form is the centre's (welcome text, 6 questions, named 5-point scale), every statement and the comment
are required, storage per statement, overall mapping, satisfaction, statement labels for reports; the old four ratings still accepted; the
trainer form (8 + 9 statements, named 4-point scale, overall options, general information filled in, score); the migration on a database
with the previous forms (new default, programmes moved, own choice kept, satisfaction questions set, idempotent). `EvaluationSuiteTest` and
`SurveyMigrationTest` now use their own copy of the previous reflection form for the evidence and engine-migration cases. Evaluation,
survey, certificate, SABER+ and trainer suites: 165 passing on SQLite (2 TLS-certificate tests fail on this machine's OpenSSL, unrelated);
the form tests on PostgreSQL 16. Checked in the browser: a trainee arriving from the QR link, the full form, required highlighting, and a
stored submission.

## Follow-up

The mobile app still shows the earlier four-star evaluation (accepted by the server); it should render this form from
`GET me/registrations/{id}/evaluation`.
