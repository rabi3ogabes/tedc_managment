# PLC (مجتمعات التعلم المهنية) — Phase 4 report: scheduling and the calendar

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Phase 3](03-phase3-people.md).

Phase 4 gives a workshop its sessions on the programme engine: each with its own time, delivery mode (in person with a centre room or a venue at the host school, remote with a protected link, or hybrid with both) and trainers drawn from the workshop's confirmed trainers. Every plan is checked against the training calendar, the training-day window, the room, the trainers' other sessions and the workshop's own sessions; alternatives are suggested and never applied by themselves. "Scheduled" is reached only when every session is complete and sends the invitations; from then on any change needs a reason, is recorded as a decision and announced to everyone affected. A calendar tab shows the sessions in the person's scope with filters and the closed days. Everything stays behind `plc`.

## 1. Files changed

New (backend)
- `app/Services/Plc/WorkshopSchedule.php` — `overview()` (sessions with what each still lacks, readiness, whether the people were invited, the conflicts panel, the confirmed trainers, the programme span), `plan()` (create or change a session: span sanity (same day, 12 hours at most), mode defaults to the programme's, trainers must be confirmed on the workshop, the workshop's own sessions must not overlap, then the calendar (`calendar.approve` holders may approve a closed day in the same request), the training-day window, the room through `RoomService::assertBookable` with the seated count, the trainers through `TrainerService::assertAssignable` with an override that needs a recorded reason; the session is written with its group, sequence, lead trainer for the engine and the per-session trainers in `plc_session_trainers`; the programme and group dates stretch to hold the sessions; every plan is a decision; after invitations a reason is required and `plc.schedule_changed` goes out), `remove()` (deleted while planning; cancelled with a reason and `plc.session_cancelled` after invitations), `alternatives()` (the same time and length on the next 21 training days where the room and the trainers are free and the workshop has nothing else, plus rooms free for the requested span; nothing is applied), `conflicts()` (per session: closed day, room clash, trainer clash, how many seated trainees have another session at that time), `schedule()` (approved → scheduled only when complete; `plc.scheduled` to the seated people and the trainers; the counts recorded), `calendar()` (sessions of the workshops in the person's scope for a span of at most two months, with the calendar's closed days; filters by school, region, trainer, mode and state).
- `app/Http/Controllers/Api/V1/Admin/Plc/WorkshopScheduleController.php` — `GET workshops/{plcWorkshop}/sessions`, `POST …/sessions`, `PUT/DELETE …/sessions/{plcSession}`, `POST …/sessions/alternatives`, `POST …/schedule` (`plc.manage|plc.internal.submit`), `GET plc/calendar` (`plc.view`).
- `tests/Feature/PlcScheduleTest.php`.

Changed (backend)
- `ProgramSession::plcTrainers()`, `Program::plcWorkshop()`; `WorkshopService::detail` (`sessions.count`, `sessions.next`); three catalogue entries (`plc.scheduled`, `plc.schedule_changed`, `plc.session_cancelled`); `lang/{ar,en}/messages.php` (`plc.schedule_reason`, `session_span`, `session_overlap`, `force_reason`, `trainer_not_assigned`, `not_plannable`, `not_approved_for_schedule`, `schedule_incomplete`, `calendar_span`); routes.

New / changed (web)
- `src/pages/admin/plc/ScheduleStep.tsx` — step 5 of the wizard: the sessions with mode, venue or link, trainers, what is missing and the conflicts; the add/edit dialog (title, date, time, mode chips, centre room with booking and capacity checked or a venue at the school, protected link with passcode and platform, trainers as chips, a reason after invitations); the engine's refusal shown inline with the matching way out (override a trainer clash with a reason, approve a closed day for calendar approvers, or "suggest alternatives" whose slots and rooms fill the form only when pressed); the readiness card with "confirm the schedule and send invitations"; the conflicts panel; the programme span note.
- `src/pages/admin/plc/PlcCalendar.tsx` — the calendar tab of the hub: a month grid starting on Sunday, closed days shaded with their kind, sessions as chips coloured by mode, filters by school, region, trainer and mode, a day panel linking to the workshop's schedule.
- `PlcHub.tsx` (the tab), `WorkshopDetail.tsx` (the sessions item with the next session), `SchoolsStep.tsx` (the stepper's fifth link), `App.tsx` (the route), `plcApi.ts`, `i18n/plc.ts` (`plc.schedule.*`, `plc.calendar.*`).

## 2. What the person can do now

- Plan the sessions of a workshop from step 5 at any time before execution, see what each session still lacks, and read the conflicts panel.
- When a plan collides, read why and pick a way out: an alternative slot or room, an override with a reason for a trainer clash, or an approved closed day when allowed.
- Confirm the schedule once the workshop is approved and every session is complete; the seated people and the trainers are invited in one step.
- Change or cancel a session after the invitations with a reason that everyone receives and the decision log keeps.
- Open the calendar tab to see every workshop session in scope for a month, filtered by school, region, trainer or mode, with the closed days.

## 3. Tests executed and actual results

- `php artisan test --filter="PlcScheduleTest"` → passed, 1 test, 71 assertions: a shared workshop with two seated nominations and two confirmed trainers; a trainer outside the workshop is refused; a session drafted before approval is complete but cannot be scheduled; the lead trainer lands on the engine's session; a closed day (`calendar_closed`), an overlap with the workshop's own session (`plc_session_overlap`), a booked room (`room_conflict`) and a busy trainer (`trainer_conflict`) are refused; alternatives skip the closed day and apply nothing; the trainer clash is overridden only with a reason and recorded; a remote session without its link is reported as incomplete; the programme's end date stretches to the last session; the overridden clash stays visible in the conflicts panel; completing the link makes the plan ready and the link protected; scheduling before approval is refused, after approval moves the state, invites two trainees and two trainers and the registrations stand; after invitations a change without a reason is refused, with a reason it is recorded and announced; removing a session cancels it and announces it; the calendar returns the live sessions with the closed day and honours the trainer, school and mode filters; a plain employee is refused.
- Combined with `PlcPeopleTest|PlcSchoolsTest|PlcFoundationTest` → 10 tests, all passing.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only pre-existing warnings.
- Playwright sweep in Arabic against the local servers: the schedule step with a remote session, the add-session dialog, the calendar tab with closed days, the workshop detail's sessions item. The sweep also showed the engine refusing a session on a closed day of the dev calendar, as intended.

## 4. Security-sensitive changes

- Session links and passcodes are returned only to people who may plan (`plc.manage` or `plc.internal.submit`); others see only whether a protected link exists. Invitations point to "my training", where the engine's existing access rules apply.
- Every plan, change, cancellation, override and the sending of invitations is a decision in `plc_decisions` with the actor, mirrored into the audit log through the existing workflow.

## 5. Known limitations

- Trainee clashes in the conflicts panel count people, not names, and are computed from the engine's sessions; a programme without sessions does not count.
- The calendar spans at most two months per request and filters by the host school's region; a participating school's region is not a filter yet.
- The "ready" state (QR issued for the first session, or T-24h) belongs to Phase 5 with attendance.

## 6. Exact next step

Phase 5 — attendance: check-in and check-out codes with revocation and windows, statuses including left-early, hybrid channels, corrections and locking, the live roster and report, the mobile scan update. Report in `docs/plc/05-phase5-attendance.md`.
