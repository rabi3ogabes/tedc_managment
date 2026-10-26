# سابر+ — planning drafts, review in the Programmes page, and imports with any layout

Requested 26 Oct 2026: whatever an administrator creates or changes in SABER+ — a programme, a group, a date, a trainer, a supervisor — is a
draft that the person responsible reviews and publishes from **البرامج**; imports accept any Excel layout, with the columns arranged before
anything is applied, and go to review too. Decision taken with the user: **the published programme stays live, unchanged, until the reviewer
publishes** (trainees, the calendar and room bookings keep the published version meanwhile).

## How it works

- **One draft per programme** (`saber_revisions`): the planned state of its groups (number, name, colour, term, supervisor) and of each
  group's days (date, times, room, trainer), plus only the programme fields SABER+ changed (days per group, supervisor, and from imports:
  hours, delivery, kit, start date, objective). It starts from the live programme the first time someone edits it in SABER+.
- **Everything in SABER+ edits the draft**: day date / times / room / trainer, the plan of the coming days, adding / renaming / renumbering /
  removing groups, clearing days, the supervisor and days per group, and the imports. The same rules are checked while planning: the
  training calendar, the training-day window, and rooms and trainers against the **live bookings of other programmes and every other open
  draft** (two planners cannot plan the same room at the same time). A day with recorded attendance cannot be changed.
- **Stays live, immediately**: approval «اعتماد المجموعة» (the head of training's sign-off on a published group), cancellation and
  reactivation, kit readiness, survey links, holiday requests, weekly holidays and meeting bookings.
- **«برنامج جديد»** in SABER+ creates the programme as a **draft programme** in البرامج (code from the catalogue numbering, delivery,
  hours, days, groups, kit, start date, supervisor, objective, capacity); its groups are planned in SABER+ and it is published with them.
- **The planner** sees on each programme its draft bar: «مسودة» / «بانتظار المراجعة» / «أُعيدت بملاحظة» with the reviewer's note, the number
  of changed groups and days, who edited it last; **عرض التغييرات** (old vs new), **إرسال للمراجعة**, **تجاهل المسودة** (author or reviewer).
  Changed days are marked «معدّل», groups that exist only in the draft «جديدة». The header shows «مسوداتي» and, for reviewers, the count
  waiting.
- **The reviewer** (`saber.publish`: super administrator, centre administrator, head of training) sees «مراجعة سابر+» on the Programmes page
  with the number waiting, and the review page `/admin/programs/saber-review`: tabs waiting / being planned / published / discarded, each
  draft with its source (edited in SABER+, new programme, rooms-calendar import, programme list, Excel import), planner and time, and the
  comparison — programme fields, groups added / removed / changed, every day old → new with room and trainer.
  - **نشر**: applied to the live programme in one transaction — programme fields, groups removed / created / renamed / renumbered /
    supervisor, every changed day through the calendar, training-day, room and trainer rules. If anything clashes (for example a room
    booked by another programme after the plan was made), **nothing is applied** and the reviewer sees each conflict to send back. A draft
    programme becomes published with its groups. The planner is notified, and the registered trainees of groups whose schedule changed get
    «تغيّر جدول مجموعتك التدريبية».
  - **إعادة بملاحظة** (note required): back to the planner, who edits and sends again.
  - **تجاهل**: the draft is dropped (a programme that only existed as a SABER+ draft is removed with it); the planner is notified.
- Notifications: reviewers when a draft is sent; the planner when it is published, returned or discarded.

## Imports with any layout («استيراد ملف Excel»)

One wizard on «أدوات البيانات» replaces the two fixed imports:

1. **File** — Excel (.xlsx / .xls) or CSV, or a OneDrive / SharePoint link shared with «anyone with the link».
2. **Sheet and type** — each sheet with its rows count and a preview of its first rows (dates shown as formatted in Excel); the header row is
   guessed and changed with one click; the kind of file: **rooms calendar** (rooms × dates), **one training day per row**, or **programme
   list**.
3. **Columns** — for a day per row: programme* , date* , group, start, end, room, trainer, supervisor; for a programme list: name* , type,
   delivery, audience, hours, days, objective, groups, kit, execution date, notes; for a rooms calendar: the header row, the date column and
   the first room column. Columns are suggested from their headers, each with a sample value; required ones are flagged. **Saved layouts**
   («حفظ هذا الترتيب») map next month's workbook in one click.
4. **Preview** — matched programmes (Arabic-aware fuzzy matching, never creating programmes from a schedule), each group's target and
   verdict (up to date / needs updating / new group), new rooms, supervisors and trainers not found in the platform, unmatched names with
   the closest programme; for a programme list, create vs update per row with the notes.
5. **Save as drafts** — never the live programmes — and, by default, **send them for review**. Dates in dd/mm/yyyy, ISO or Excel form and
   times like 08:00, 8:30 ص/م are understood.

## The centre's rooms calendar workbook (2026-10-26, from the real 2026-2027 file)

The centre's own «تقويم القاعات التدريبية» workbook was read in full (counts only — it holds staff names and stays out of the repository):
29 rooms in row 3 with a men / women / all priority above them and a capacity («25 متدرب») below, dates in column D, 218 booked dates,
about 1 800 filled cells with 263 distinct labels, the school calendar written once in column A (merged across dates), two centre-wide
events and a person's standing reservation. The sheet «بيانات اللوحة» is all `#REF!` and is not used. What the rooms-calendar import
now understands:

- **Header row** — chosen by the most distinct room names, with a bonus when the next row holds capacities; each room keeps its **tag**
  (the row above) and **capacity** (the row below), used when a missing room is created.
- **School calendar** — labels merged left of the date column are periods (exams → «اختبارات», holidays / Eid → «إجازة», the rest
  «مغلق للتدريب»); a label merged across the room columns, or written in most rooms on one date, is an event. They are not bookings:
  the preview lists them (dates, type to change, «موجود في التقويم», «حجوزات داخلها»), and the ticked ones are added to the training
  calendar (weekends skipped, days already in the calendar untouched; needs the calendar right).
- **People's reservations** («أ./د. name – …») are recognised and shown as one information line — not programmes, not imported.
- **Group numbers glued to the name** («برنامج 1», «برنامج 2») are separate groups of one programme.
- **Programmes not in Shaheen** — names that match no programme are no longer dead ends: they become **proposals** (name cleaned of the
  group number, a leading note, a department or a person), ticked by default for holders of `saber.program.create`, with groups, days
  per group, bookings and the closest existing programme. Applying creates each ticked one as a **draft programme** (next code, days and
  hours from the file, capacity 25, attendance 80 %) whose groups and days go into its draft for review — nothing is published.

**«The POST method is not supported for route …/data/calendar/preview»** — the page was opened before the release that replaced the old
calendar import with the wizard, so it called an address that no longer exists. A 405 from an `/admin/…` address now reloads the page
once (at most every ten minutes), so the new version loads by itself.

## Data, API, code

Migration `2026_01_01_009100_saber_plus_revisions` (`saber_revisions`); permission `saber.publish` (given to existing roles on deploy).
Workbench group endpoints are now programme-scoped (`/admin/saber/programs/{program}/groups/{key}/…`, the key being the group's id, also for
groups that exist only in the draft); new: `POST programs` (draft programme), `GET|DELETE programs/{id}/draft`, `POST programs/{id}/draft/submit`,
`GET reviews`, `GET reviews/{id}`, `POST reviews/{id}/publish|return|discard`; imports: `POST data/import/inspect|preview|apply`,
`GET|POST data/import/presets`. Code: `app/Services/Saber/Drafts.php` (planning rules, diff, publish), `WorkbookReader.php`, `CalendarImport.php`
(grid and row layouts → drafts), `ProgramListImport.php` (mapping → drafts); web `DraftControls`, `DiffView`, `SaberReview`, `ImportWizard`.

## Tests

`SaberReviewTest` (5): a SABER+ programme is a draft until published (reviewers notified, diff, coordinator cannot publish, publish creates
the groups and sessions and publishes the programme); publishing is all-or-nothing with the clash reported; return with a note and
resubmit; discard (live untouched; a draft-only programme removed); supervisor and days per group planned then published.
`SaberWorkbenchTest` rewritten for drafts (10, incl. two drafts refused the same room), `SaberSchedulingTest` and `SaberDataToolsTest`
updated (inspect, grid → drafts → publish, day-per-row with shuffled columns, programme list with mapped columns). All SABER+ and related
suites: 112 tests, 1 359 assertions on PostgreSQL 16. Checked in the browser on demo data: banner and counters, a day planned → draft bar →
send for review → Programmes banner → review page comparison → discard; the import wizard with a CSV (columns mapped automatically,
preview matched the programme).
