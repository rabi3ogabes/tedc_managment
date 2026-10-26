# سابر+ Phase 3 — rooms and meeting bookings

Plan: `00-gap-analysis.md` (items C1–C4). The «سابر+» screen now has three tabs: **البرامج والمجموعات** (the workbench), **تقويم القاعات** and
**خريطة القاعات** (`/admin/saber?tab=calendar|map`). On the rooms tabs the header shows «حجز قاعة اجتماعات»; on the programmes tab «برنامج
جديد» opens the programme wizard for whoever manages programmes.

## C1 — room type and tag

The rooms screen (`/admin/rooms`) asks for the **type** (قاعة تدريب / قاعة اجتماعات) and a **tag** (مختبر، VIP، ورشة …); the rooms API accepts
and returns `room_type` and `tag` and filters by type. The calendar and the map list training rooms first, then meeting rooms, each ordered
by tag and name; the booking dialog groups rooms by type.

## C2 — «حجز قاعة اجتماعات» (meeting-room booking)

- Title, who asked, why (all required), purpose (meeting, training, event, exam, other), attendees, room, date.
- **Hour slots 07:00–14:00**: the slots of that room and day come from the server (`room-slots`) — taken hours are crossed out with what holds
  them; hours are picked as one consecutive run.
- **Recurrence** daily / weekly (same day) / every two weeks × count (up to 60), only for holders of `saber.meeting.recurring`; a series
  shares a `series_id`.
- **Holidays**: a date closed for training refuses the booking unless the day allows events (a calendar day or weekly holiday marked
  «مسموح بالفعاليات», an approved day) — exam and «normal» days are working days and take events. The refusal lists the dates.
- **Clashes**: every hour of every date must be free of sessions and bookings in that room (the platform's room rules, including «one
  session per room per day» when that setting is on); the refusal lists the clashes. Nothing is created unless every date is possible.
- Allowed to holders of `rooms.book`, `saber.program.create` or `rooms.manage`.

## C3 — «تقويم القاعات» (rooms calendar)

- Month grid (week from Sunday): each day shows booked rooms / all rooms with a coloured bar and a strip per event; holidays show their
  name; previous / today / next.
- Filters: **rooms** all / free / booked (applies to the day panel), **days** all / available / booked (dims the others), **view** calendar or
  rows; colour legend.
- The day panel lists the rooms with their **hour slots** (7–13) and what holds them: training days (programme, group and day, trainer,
  group colour) and bookings (title, hours, purpose, who asked, why, who recorded it, «حجز متكرر · n مواعيد»). Bookings are **edited**
  (title, requester, reason — for the whole series if wanted — and the hours, checked again) and **cancelled** (one, or every upcoming date
  of the series) in place by whoever booked them or manages rooms. «+» on a room books it for that day.

## C4 — «خريطة القاعات» (room map)

For a chosen day, every room by building and floor with its state — free, partly booked, booked, or holiday — an hour strip and the first
thing holding it; a room opens a window with its details, hour slots and every training day and booking (edit / cancel there too). Totals
of free / partly booked / booked rooms at the top.

## Data and API

Migration `2026_01_01_008900_saber_plus_meeting_bookings`: `room_bookings.requested_by`, `reason`, `series_id`, `recurrence`.
`GET /admin/saber/rooms-month?month=YYYY-MM` (rooms, days with calendar state, sessions and bookings) · `GET room-slots?room_id&date` ·
`POST meetings` · `PATCH meetings/{id}` · `DELETE meetings/{id}?series=1`. Service `app/Services/Saber/Meetings.php`;
`CalendarService::eventsAllowed()` now also counts approved days. Web: `RoomsCalendar`, `RoomMap`, `MeetingDialog`, `BookingCard`, `rooms.ts`.

## Tests

`tests/Feature/SaberMeetingsTest.php` — 5 tests: hour booking and overlap refused, slots; recurrence right, series dates, series edit,
cancel by series and the right to do it; holidays refuse unless events are allowed (weekend, vacation, weekly holiday, daily series);
the rooms month (days, order, sessions and bookings); room type and tag through the rooms API. With phases 1–2 and the calendar, rooms,
groups and permissions suites: 61 tests, 688 assertions on PostgreSQL 16. Checked in the browser: both tabs, a booking made from the dialog
(slot picker, saved, shown in the day panel with its details) and cancelled from its card, the room map.
