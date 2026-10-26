# سابر+ Phase 2 — scheduling tools

Plan: `00-gap-analysis.md` (items B1–B4, A14, A15). Everything lives on the «سابر+» screen (`/admin/saber`): four new toolbar tools (each can
be hidden in «تخصيص شريط الأدوات»), a details button on every programme, survey-link tools and group actions when a programme is open.

## B1 — «خطة الأيام القادمة» (plan of the coming days)

- Programme (searchable) and group, optional room and trainer, weekday, every week or every two weeks, how many dates (up to 4 × the days per
  group), from which date.
- Each date comes back **free**, **booked** (the room or trainer is taken by another group — the group's own days don't count, they are about
  to be replaced) or **holiday** (closed for training: the holiday's name, «إجازة أسبوعية», and whether events are allowed). Nothing is saved.
- Free dates are selected; booked ones can be added; holidays can't. «تطبيق على المجموعة» replaces the group's days with the selected dates
  (day 1 = earliest), after a confirmation when the group already has saved days. A date whose room or trainer is taken keeps the date
  without them, with a notice. Refused when a day already has attendance, or when a selected date is closed.
- Also opened from a group's header (calendar icon), with the programme and group already chosen.

## B2 — «إلغاء البرامج» (cancellation)

- **Cancel a programme** or **one group**: reason, who asked (person or body), optional supporting document (PDF, Word, image; private
  storage, opened through a 5-minute link).
- Uses the group status machine (`TrainingGroupService::transition` → cancelled), so the registered people, their managers and the supervisor
  are notified, and the days are cancelled: their rooms and trainers are free for other programmes at once.
- Cancelling the last running group cancels the programme too.
- **«الملغاة»** lists cancelled programmes and cancelled groups of running programmes with reason, who asked, who cancelled, when and the
  attachment.
- **Reactivation — super administrator only** (`saber.program.reactivate`): the group goes back to the status it had (planned, open,
  ongoing, postponed or incomplete), its days come back with their room and trainer — or without them, with a notice, when someone booked
  them meanwhile. Reactivating a programme brings back the groups cancelled with it (not a group cancelled on its own earlier).

## B3 — training on a closed day

- Putting a closed date into a day no longer ends in an error: the screen opens **«طلب حجز في يوم إجازة»** with the date, group and day and
  asks for the reason.
- **Send the request** → whoever approves the calendar (`calendar.approve`, and super administrators) is notified; **«طلبات أيام الإجازة»**
  shows the pending count on its button. They approve (the day is approved on the training calendar, so it opens for everyone) or reject
  with a note; the requester is notified either way. One pending request per date and group.
- **«اعتماد فوري وفتح اليوم»** for whoever holds `calendar.approve`: recorded as an approved request and the day opens at once.
- Others see their own requests and their status.

## B4 — holidays

- **«الإجازات الأسبوعية»**: weekdays off every week on top of the configured weekend, each with a label and «مسموح بالفعاليات». They close
  training everywhere the calendar is checked (sessions, the group planner, SABER+), show on the training calendar as weekend days, and saving one warns how many upcoming sessions fall on that weekday. Managed by `calendar.manage`.
- **«مسموح بالفعاليات»** also exists on single calendar days (`calendar_days.allow_events`, settable through the calendar API).
  `CalendarService::eventsAllowed()` answers it; meeting bookings (phase 3) will use it.

## A14 — programme details window

Overview (status, hours and days, delivery, capacity, category, supervisor, kits with their status, survey link, description, objectives,
audience, cancellation with its document), groups (status, approval, registrations / capacity, term, reason) and every day with its date,
time, room and trainer.

## A15 — survey link tools

On an open programme: open, copy, a QR code shown large and downloadable as PNG, and — with `saber.survey_links.manage` — editing the
survey link and the form-edit link (validated as web addresses).

## Data (`2026_01_01_008800_saber_plus_scheduling_tools`)

- `calendar_weekly_rules` (weekday unique, title_ar/en, allow_events, created_by).
- `calendar_days.allow_events`.
- `calendar_requests` (date, programme, group, day, reason, status pending | approved | rejected, requested_by, decided_by / at, note).
- `programs.status_before_cancel`; `training_groups.status_before_cancel`, `training_groups.cancel_snapshot` (each day's room and trainer).

## API (`/api/v1/admin/saber`)

`GET programs/{id}/details` · `POST groups/{id}/plan/preview` (`saber.plan.view`) · `POST groups/{id}/plan/apply` (`saber.day.edit`) ·
`POST programs/{id}/cancel`, `POST groups/{id}/cancel` (`saber.program.cancel`, multipart) · `POST programs/{id}/reactivate`,
`POST groups/{id}/reactivate` (`saber.program.reactivate`) · `GET cancelled` · `GET cancellations/{program|group}/{id}/attachment` ·
`GET offday-requests` · `POST offday-requests` (`saber.day.edit`) · `POST offday-requests/{id}/decide` (`calendar.approve`) ·
`GET weekly-rules` · `POST weekly-rules`, `DELETE weekly-rules/{id}` (`calendar.manage`) · `PATCH programs/{id}` now also takes
`survey_link`, `survey_edit_link` (`saber.survey_links.manage`).

Code: `app/Services/Saber/Scheduling.php`, `SaberController`, `CalendarService` (weekly rules, `eventsAllowed`), models
`CalendarWeeklyRule`, `CalendarRequest`; web `PlanDialog`, `CancelDialog`, `CalendarDialogs`, `ProgramExtras`, `ProgramSearch`, `actions.ts`.

## Tests

`tests/Feature/SaberSchedulingTest.php` — 8 tests: plan preview (free / booked / holiday, fortnightly), apply (replace, taken room skipped
with a notice, closed date refused), cancel a group with attachment (room freed, programme cancelled with it, list, reactivation super-admin
only, room restored), programme reactivation with a room taken meanwhile, request → approve → day opens, instant approval, weekly holiday
(closes the weekday, events allowed, managed by `calendar.manage`), details and survey links. With phase 1 and the calendar, groups, rooms
and permissions suites: 43 tests, 501 assertions on PostgreSQL 16; SQLite too. Checked in the browser: toolbar, plan preview, weekly holidays,
requests inbox, details window, cancel dialog and its cancelled list, the closed-day request opened from a Friday.
