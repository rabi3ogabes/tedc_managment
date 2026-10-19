# Programmes — training kits, generated instruments and announcements

Date: 2026-10-19 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Follows [03-generated-session-plan.md](03-generated-session-plan.md).

On the new / edit programme form the administrator now chooses one or more training kits (الحقائب التدريبية). From the kits the system prepares the programme's evaluation instruments — the trainee survey, the trainer's self-reflection survey and, when a kit carries assessment tools, the pre-test and the post-test — and the programme announces itself to the people around it: when registration opens, and when the centre assigns trainees directly.

## 1. How it behaves

**Kits.** A card on the form lists the approved and published kits (search by name or code, kits matching the programme's delivery mode first, a badge when a kit is meant for another mode). Each card shows the code, hours, number of files and whether the kit has pre-test / post-test tools. Several kits can be chosen; each is pinned at the version it has when chosen (`kit_program.pinned_version`).

**Instruments** (`program_instruments`, one row per programme and kind; all four can be switched off, default on):

| Instrument | What is generated | When it reaches people |
|---|---|---|
| Trainee survey (إستبانة متدرب) | the programme survey on the default satisfaction form, `survey_mode = auto` | opened by the system N hours after the programme ends (default 24, 0–720); off → `manual` |
| Trainer survey — self-reflection (إستبانة مدرب – تأمل ذاتي) | the default `trainer_reflection` form | assigned to each group's trainer when the group completes (`EvaluationService::assignForGroup` honours the switch) |
| Pre-test (إختبار قبلي) | a draft test of the programme named after the kit's tool, window opens 3 days before the first session and closes at its start | only when published (it has no questions yet) |
| Post-test (إختبار بعدي) | a draft test, window opens at the last session's end and closes 7 days later | only when published |

"If available on the kit": a kit has a pre-test / post-test when it holds files in the `assessment` category; a file whose name says pre (قبلي, pre, diagnostic, entry) serves the pre-test, one that says post (بعدي, post, final, exit) the post-test, one that says neither serves both. With no such file the instrument shows "not available in the kit" and its switch is disabled. Nothing is generated twice: an existing test of that kind is reused.

**Announcements** (`ProgramAnnouncer`, through `NotificationService`, so templates, switches, quiet hours, user preferences and delivery tracking all apply; push, e-mail and SMS follow each channel's platform and user settings):

- **Registration opens** — the programme is created open, or moves to open (by an admin or by the scheduler's date-based opening): the programme's trainers get one notice (the dates, the first room), and the system administrator of every school in the target group gets one notice with how many of the school's staff are eligible. Sent once per programme (`programs.registration_announced_at`); a draft is silent.
- **Direct assignment by the centre's specialist** — the trainee gets the existing "program assigned" notice; the programme's trainers get one digest (how many, seats taken); each school's administrators get one digest naming their school's trainees. A school administrator nominating from their own school notifies the trainee and the trainers but not the centre flow.

New events (all channels on by default, editable in the notification templates): `program.registration_open.trainer`, `program.registration_open.school`, `program.trainees_assigned.trainer`, `program.trainees_assigned.school`.

## 2. Files

Backend, new — `ProgramInstrument` model and migration `2026_01_01_007500_program_instruments_and_announcements.php`; `Services/Programs/ProgramInstruments.php` (kit options, test detection, generate, overview); `Services/Programs/ProgramAnnouncer.php`; `ProgramInstrumentController` with `GET programs/kit-options` and `GET|PUT programs/{program}/instruments`; `tests/Feature/ProgramKitsAndAnnouncementsTest.php`.
Backend, changed — `Program` (kits, instruments, announcement mark, `updated` hook), `ProgramController` (kits and instruments on save, announcement after create), `RegistrationController` and `ProgramPlanner` (assignment digests), `BulkImportService`, `AdvanceProgramLifecycle` (sweep after bulk opening), `EvaluationService`, `NotificationCatalog`.
Web — `pages/admin/program/ProgramKits.tsx`, `kitsState.ts`; `ProgramEditor.tsx`; the shared `Switch` gained `disabled`; `i18n` `admin.programs.kits.*` in both languages.

## 3. Assumptions to confirm with the Ministry

1. "المسؤول عن إدارة النظام في المدرسة" is the school administrator role (`school_admin`) of the school; the schools concerned are those in the programme's target group (every school when it is open to all).
2. The pre/post detection by file name above; the Ministry can rename kit files or tell us a stricter rule.
3. Instruments reuse the existing satisfaction and trainer-reflection forms; programme-specific question sets can be edited in the evaluation suite.

## 4. Tests executed and actual results

- `ProgramKitsAndAnnouncementsTest` → 3 tests, 69 assertions, passing: kit options filtering and tool detection; instruments generated (pre/post only when the kit has tools, "unavailable" otherwise, the survey set to auto with the chosen hours, the trainer reflection off and on through `assignForGroup`); registration-open announcements to the trainer and the school administrators once, e-mail and SMS deliveries recorded, a draft silent, the status change and the scheduler sweep; direct centre nomination notifying the trainee, the trainer digest and per-school digests, a school administrator's own nomination not notifying the trainer.
- 44 neighbouring tests (programme builder, schedule plan, registrations, evaluation, notification catalogue) passing.
- Playwright against the local servers (Arabic): the kits card lists two kits (one with both tools, one with none), choosing the first lights up the pre-test and post-test rows with the kit's files, the other kit leaves them disabled; the notification note switches to "registration is open: after saving…" when the status is registration open.
- `tsc --noEmit` clean; pint clean.
- Full suite: see the line appended at the end of this report.

## 5. Security-sensitive changes

- Kit options and instruments need `programs.manage`; only approved and published kits can be attached (checked on the server, not only in the picker).
- Notifications carry titles, dates and counts only; names of assigned trainees are limited to the trainee's own school administrator and capped at eight.
- Announcements are idempotent (database mark, atomic update), so a retried request or the scheduler never notifies twice.

## 6. Known limitations

- The generated pre/post tests are draft shells without questions; trainees see nothing until the administrator adds questions and publishes. Drafting questions from the kit's objectives with the AI guard is a possible next step.
- The kit picker is on the new form and the edit page; the programme page does not yet show the instruments' status panel (the data is served by `GET programs/{program}/instruments`).
- A changed audience after announcement does not re-announce.

---
Full suite after this change (`php artisan test --compact`, Windows, 2026-10-19): 1184 tests, 1113 passed, 16222 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors) and the environment-bound social-files test noted earlier; the programme, kit, evaluation, notification and PLC suites pass.
