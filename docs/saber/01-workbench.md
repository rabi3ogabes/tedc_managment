# سابر+ Phase 1 — rights, data foundation and the programmes & groups workbench

Plan: `00-gap-analysis.md` (items A1–A13, E1–E2). Screen: **«سابر+»** in the menu under «دورة التدريب» (`/admin/saber`, right `saber.view`).

## What it does

- **One screen for every programme.** Each row shows the code, hours, days per group, number of groups, the date range, three status lights
  (days with a date / a room / a trainer, out of groups × days) and the kit readiness chip. Opening a row shows the supervisor, the days per
  group, a link to the programme page, «إضافة مجموعة», and every group with its days.
- **Each training day edited in place, saved immediately:** date, start and end time, room, trainer. A day is a programme session numbered
  1…n in its group (`program_sessions.sequence`); a day without a date has no session yet. Extra sessions beyond the planned days show as
  «يوم إضافي».
- **The same rules as the session screens:** the training calendar (closed days refused with `calendar_closed`), the training-day window,
  room and trainer clashes. A clash you chose is refused with the reason; a clash that only comes from moving a date clears the inherited
  room or trainer and says so.
- **Spreading:** the trainer chosen for day 1 goes to every other day where they are free; the first room chosen for a group goes to every
  day where it is free (and becomes the group's default room). The notice says how many days were filled and which were skipped.
- **Pickers that warn before you choose:** the room and trainer lists are searchable (Arabic letters normalised) and, for the day's slot,
  mark rooms taken (with the programme holding them) and trainers busy, sorted last (`GET /admin/saber/availability`).
- **Groups:** number (renumber, unique per programme), name (unique), colour from a 10-colour palette (a free one is given to a new group),
  term (first Aug–Dec / second Jan–Jul / whole year, or automatic from the first day), clear all days, delete (only when the setting allows,
  and never the last group or one with registrations).
- **Approval «اعتماد المجموعة»** by the head of training (`saber.group.validate`), with who and when; pressing again withdraws it.
- **Kit readiness** per programme: set by hand, otherwise ready when a linked kit is approved or published.
- **Filters:** search (name or code), date range, first / second term, kit ready / not ready, clear; 20 programmes a page, sorted by Arabic
  name.
- **Personal toolbar:** each tool can be hidden; the choice and the rows/cards view are kept on the device.
- **Settings** (settings administrators): allow changing the supervisor (default on), allow deleting groups (default off).

## Rights (`saber.*`, role matrix group «سابر+»)

| Right | Super admin | Centre admin | Head of training | Coordinator | Logistics |
|---|---|---|---|---|---|
| saber.view | ✓ | ✓ | ✓ | ✓ | ✓ |
| saber.program.create / cancel | ✓ | ✓ | ✓ | create | |
| saber.program.reactivate | ✓ | | | | |
| saber.group.manage / rename | ✓ | ✓ | ✓ | ✓ | |
| saber.group.validate | ✓ | ✓ | ✓ | | |
| saber.day.edit, room.assign, trainer.assign | ✓ | ✓ | ✓ | ✓ | room |
| saber.supervisor.assign, plan.view, survey_links.manage | ✓ | ✓ | ✓ | ✓ | |
| saber.import, saber.meeting.recurring | ✓ | ✓ | ✓ | | recurring |
| trainers.validate | ✓ | ✓ | ✓ | | |

New rights reach existing roles on the next deployment (`RolePermissionSeeder::ADDITIVE`).

## Data (`2026_01_01_008700_saber_plus_foundation`)

- `programs`: `training_days`, `kit_ready`, `survey_link`, `survey_edit_link`, `cancel_reason`, `cancel_requested_by`, `cancel_attachment_path`,
  `cancelled_at`, `cancelled_by`.
- `training_groups`: `color`, `term`, `validated_at`, `validated_by`, `validated_by_name`, `cancel_requested_by`, `cancel_attachment_path`,
  `cancelled_at`, `cancelled_by`.
- `training_rooms`: `room_type` (training | meeting, default training), `tag`.

The cancellation, survey-link and room-type columns are used by phases 2–4.

## API (`/api/v1/admin/saber`, all behind `saber.view`; each action checks its own right)

`GET overview` · `GET programs` · `GET availability` · `PATCH programs/{id}` · `POST programs/{id}/groups` · `PATCH groups/{id}` ·
`DELETE groups/{id}` · `POST groups/{id}/validate` · `POST groups/{id}/reset` · `PUT groups/{id}/days/{n}` · `DELETE groups/{id}/days/{n}` ·
`PUT settings` (`settings.manage`).

Code: `app/Http/Controllers/Api/V1/Admin/SaberController.php`, `app/Services/Saber/Workbench.php`; web `web/src/pages/admin/saber/`.

## Tests

`tests/Feature/SaberWorkbenchTest.php` — 9 tests, 85 assertions, on SQLite and PostgreSQL 16: listing with lights and rights, dates and
spreading of room and trainer, explicit vs inherited clash, closed day and day window, approval toggle, add / renumber / rename / delete
with the setting, kit and term and date filters, clearing a day and a group, availability. Related suites (groups, calendar, rooms,
sessions, permissions) pass. Checked in the browser on the local demo data: expand, room picker with availability, saving a room, cards view.

## Next phases

2. Scheduling tools: plan simulation, programme / group cancellation with reactivation, holiday requests, weekly holidays and events,
   details window, survey link tools (QR, copy, edit link).
3. Rooms and meetings: room type and tag in the rooms screen, meeting bookings with hour slots and recurrence, calendar filters and room
   window, room map.
4. Data tools: rooms-calendar Excel import with fuzzy matching, programme list import, survey links Excel, trainers manager, supervisors page.
