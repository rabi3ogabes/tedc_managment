# البرامج حسب يوم التدريب — the programme list by day

2026-11-16. The programme list of the dashboard (`/admin/programs`, «البرامج») can be read by the day of training: choose a day and the
list shows the programmes that have a session on it.

## What the reader sees

| | |
| --- | --- |
| The row of days | Above the list, fifteen days around today. A day on which programmes train **is lit** and carries their number. A working day with no programme **has its light off**. **Days off are not in the row**: the weekend, a weekly holiday, a vacation. |
| Choosing a day | A press on a day shows the programmes that train on it; a second press, or «كل التواريخ», shows every date again. |
| Yesterday, tomorrow | The two arrows beside the name of the day go to the shown day before or after, over the weekend and over a vacation. «اليوم» comes back to today. |
| Any day | «اختر يوماً من التقويم» opens the calendar. The double arrows at the two ends of the row bring the earlier or the later days. |
| A date in the search box | A date typed where programmes are searched is read as a date: `16/11/2026`, `16-11-2026`, `16.11.2026`, `2026-11-16`, with Western or Arabic digits. A whole date is applied as it is typed. Day and month alone (`17/11`) are offered under the box («اعرض برامج يوم …») and applied with Enter. |
| The list of a day | It reads like the day's agenda: the programme that starts first comes first, and each one shows «موعد اليوم», its hours and its room (or «عن بُعد»). The counts of the three kinds (حضوري، عن بُعد، مدمج) are the counts of that day. |
| A day without training | The list is replaced by a dark panel, «لا تدريب في هذا اليوم», with the nearest day that has training before and after it, one press away. |
| A day off that has training | It was approved, so it is in the row, lit, with «عطلة» under its number. A day off picked from the calendar is shown as chosen and says it is a day off. |
| The address | The chosen day is in the address (`/admin/programs?on=2026-11-16`): the page of a day can be bookmarked or sent to a colleague. |

The row and the list follow the same filters (search text, status, kind), so a lit day always has something to show.

The two states use the colours of the brand (the palette tokens, so the Brand Studio recolours them): a day with training is the
maroon (`navy-900`), a day without is the light dune (`gold-100`); the chosen day carries a dune ring.

## How it is built

* **API** — `GET /api/v1/admin/programs?on=YYYY-MM-DD` narrows the list to the programmes that train on the day, orders it by the first
  session of the day and adds `day_sessions` (hours, room, trainer) to each programme. `GET /api/v1/admin/programs/days?around=&before=&after=`
  returns the row: `days[]` (`date`, `programs`, `sessions`, `off`, `kind`, `title`), and `prev` / `next`, the first shown days beyond
  the two ends. Both take the filters of the list and the same permission as the list. A trainer sees the days of their own programmes.
* **What «trains on a day» means** — a session of the programme that is not cancelled touches the day. A session that runs over
  midnight counts on both days. Sessions are stored on the centre's own clock, so a day is read with local bounds.
* **Days off** — `CalendarService` decides: the configured weekend (Friday and Saturday), a weekly holiday and a vacation are days off;
  an exam day and a «normal» day are working days closed for training, so they stay in the row with their light off. A day off with a
  session is kept. The row looks up to 75 days each way, so a long vacation is skipped in one go.
* **Code** — `App\Services\Programs\ProgramDays`, `ProgramController::index` and `::days`, tests in `tests/Feature/ProgramsByDayTest.php`.
  Web: `pages/admin/ProgramsAdmin.tsx`, `pages/admin/programs/DayRow.tsx`, `programs/days.ts` (reading a typed date, moving along the
  row; unit tests in `days.test.ts`), texts in `i18n/programDays.ts`.

## Limits

* The public catalogue and the mobile app list programmes as before; the day view is on the dashboard list.
* A date with a two-digit year (`16/11/26`) is not read as a date: it could as well be part of a programme code.
