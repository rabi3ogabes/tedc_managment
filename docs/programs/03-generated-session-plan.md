# Programmes — the session plan generated on "برنامج جديد"

Date: 2026-10-18 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Follows [02-program-codes-and-catalogue.md](02-program-codes-and-catalogue.md).

The new-programme form now writes the sessions of the programme's first group for the administrator, from what they filled in: the start date, the hours, the capacity, the delivery mode and the first trainer, plus how the sessions recur. Every session stays editable, rooms are chosen from the real bookings, and the plan is saved together with the programme.

## 1. How it behaves

- **Recurrence.** One fixed weekday every week ("every Monday"), the same weekday every two weeks, consecutive training days, or chosen dates. The weekday defaults to the start date's; Friday and Saturday (the weekend) are disabled. The number of sessions is automatic (hours divided by the training day's hours, five by default) or typed by the administrator; hours are shared in quarter hours with the last session taking the rest (12 hours over three days is 4 + 4 + 4; 12 over five is 2.5 ×4 + 2).
- **Closed days.** A holiday, exam day or weekend in the series is skipped and the session moves to the next date of the series; the plan says which date was skipped and why. A date the person types onto a closed day is flagged, and saving needs the calendar approval permission and a reason, which approves those days with the programme.
- **Rooms.** "One fixed room" (the default) picks the preferred room, or the best one, only if it is free on every date and big enough for the capacity; the booking calendar and room bookings are both consulted. If the preferred room is busy on some date the plan names that date and fixes another room free on all dates. If no single room is free everywhere, each session takes the best free room and the sessions on a different room are marked. "Best room per session" and "no room" are available; every room shows how many of the dates it is free on, and the administrator can pick one or change a row's room. Online programmes have no room.
- **Checks on every row.** Closed day, outside the training day window, room already booked, trainer already teaching, past date, no room. Editing a date, a time or a room re-checks the whole plan without regenerating it; changing the form's facts regenerates it only while the administrator has not edited rows by hand, otherwise a banner offers "regenerate".
- **Saving.** The plan is written on the programme's first group in the same transaction as the programme; the programme's start and end dates follow the first and last session. A session refused by the server (a room or trainer taken in the meantime, a closed day) stops the whole save, rolls everything back and names the session number.

## 2. Files changed

New (backend)
- `app/Services/Programs/ProgramSchedulePlanner.php` — `plan()` and `check()`.
- `app/Services/Programs/ProgramSessionWriter.php` — the one writer of a session plan with the calendar, day-window, room and trainer rules, shared by the programme builder and the new form.
- `app/Http/Controllers/Api/V1/Admin/ProgramScheduleController.php` — `POST admin/programs/schedule-plan` (`programs.manage`, throttled; generates, or re-checks when `sessions` are sent).
- `tests/Feature/ProgramSchedulePlanTest.php`.

Changed (backend)
- `ProgramPlanner::create()` writes its sessions through the shared writer (the builder's behaviour is unchanged and its suite passes); `ProgramController::store()` accepts `sessions` and `calendar_approval_reason`; `CatalogController::lookups()` returns the weekend days; messages; routes.

New / changed (web)
- `src/pages/admin/program/SchedulePlanner.tsx` — the "جلسات البرنامج · المجموعة 1" card: the recurrence chips, the fixed-day chips, the number of sessions, the start time, the room strategy and the preferred-room chips with their availability, the summary line, the notes, and the session rows (date with weekday name, from, to, room, flags, delete, add).
- `ProgramEditor.tsx` — the card on the new form (a switch turns it off), the plan state, the payload, the approval reason, the save button held while an unresolved conflict remains; `i18n/ar.ts`, `en.ts` (`admin.programs.plan.*`).

## 3. Tests executed and actual results

- `php artisan test --filter="ProgramSchedulePlanTest"` → 3 tests passed: five Mondays with hours split 2.5/2.5/2.5/2.5/2 and room 5 fixed (a room of ten seats rejected for twenty), every other week fourteen days apart, a holiday skipped with its note, the preferred room busy on one Monday so another room free on all dates is fixed and the plan explains why, a confirmed booking holding a room, no single room free so rooms mix and the odd one is marked; consecutive days skipping the weekend, a weekend weekday with no open days and no flood of notes, chosen dates with the hours shared and a busy trainer flagged, an online programme with no room, the re-check of an edited plan flagging a booked room and an out-of-window time and re-sorting by date; the plan saved with the programme on its first group with the room, the trainer and the numbering, a second save onto the same rooms refused with the session number and nothing left behind, and a weekend date refused until the approval reason is given.
- With `ProgramBuilderTest|ProgramCatalogTest|ProgramVoiceAssistantTest|PlanRulesEnforcementTest` → 24 tests, 307 assertions, all passing.
- Playwright against the local servers (Arabic): the new form with a start Monday, 12 and 25 hours and a capacity of 20 generates the plan; "once every two weeks" regenerates it; saving created five sessions on the same room and the programme took the first and last session dates.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only pre-existing effect warnings.

## 4. Security-sensitive changes

- The plan endpoint needs `programs.manage`; closed days are approved only for a person holding `calendar.approve`, with a reason; every room and trainer rule is enforced again on the server at save.

## 5. Known limitations

- The plan targets the programme's first group (group 1); extra groups are still added from the programme's Groups tab.
- Online sessions get no link here; the link is added in the session screen or from the programme's remote settings.
- The same generator is not yet offered on the edit page of an existing programme (its Sessions tab remains the way to add sessions).
