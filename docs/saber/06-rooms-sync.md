# Rooms sync from a file («مزامنة القاعات من ملف»)

2026-10-28. The training rooms brought in line with a file in one reviewed step: rooms added, rooms changed, rooms no longer in the file
removed — with history kept.

## Where

- **القاعات التدريبية** → «مزامنة من ملف» (header button), and **سابر+ → أدوات البيانات** → «مزامنة القاعات من ملف».
- Right: `rooms.manage` (the same right as adding, editing and deleting a room by hand).

## Files it reads

1. **The centre's rooms calendar** («تقويم القاعات التدريبية», the SABER workbook): room names in the header row, the capacity under each
   («25 متدرب») and the trainee priority above it (رجال / نساء / الكل → the room's **tag**). The header row is found by itself (the same
   detection as the import wizard, `CalendarImport::gridRooms`).
2. **A room list** with a header row anywhere in the first ten rows and columns in any order: اسم القاعة (required), السعة, النوع
   (تدريب / اجتماعات), الوسم, الرمز, المبنى, الطابق, الاسم بالإنجليزية. Only the columns present are synced. «تنزيل نموذج قائمة القاعات»
   gives an empty list with two example rows.

## The preview

- **Matching** — a file name finds its room with the article dropped and digits folded: «قاعة 3», «القاعة 3» and «القاعة ٣» are one room
  (`ArabicMatch::roomKey`, now also used by the calendar import, which used to create a duplicate «القاعة 3» next to «قاعة 3»).
- **تُضاف** — rooms not in the platform, with type (training unless the list says meeting), tag, capacity and code (a code another room
  already holds is flagged and not set).
- **تُعدَّل** — per room, each change as a chip («السعة 20 ← 25», «الاسم قاعة 3 ← القاعة 3», «الوسم — ← نساء»); clicking a chip leaves that
  change out. An inactive room named in the file comes back («إعادة تفعيل»). A capacity change also updates the default seating layout.
- **تخرج** — active rooms no longer in the file. A room never used is **deleted**; a room with training days, bookings, groups, open
  SABER+ drafts, seating plans or an attendance device is **deactivated** so history, reports and drafts stay whole. Rooms with
  upcoming training days, bookings or draft days are listed with a warning and are not ticked by default.
- **محمية** — the calendar lists training rooms only, so when the file names no meeting rooms, meeting rooms are left as they are.
- Rooms already inactive and absent from the file are counted, not touched.

Applying runs in one transaction; the delete-or-deactivate decision is taken again on the server from the room's current use, never from
the browser. Applying the same file twice changes nothing.

## Also fixed

Deleting a single room on «القاعات التدريبية» deleted it whenever it had no training days — its meeting bookings and seating plans were
deleted with it (cascade). It now uses the same rule: any use → deactivated.

The room pickers of the programme sessions form, room bookings, seating plans and attendance devices listed deactivated rooms too; they
now offer active rooms only (a room already chosen stays shown). After a sync every open room list in the browser reloads at once
(`web/src/lib/rooms.ts`). The server already refused to book or schedule an inactive room.

## Code and tests

`app/Services/RoomSync.php` (read, plan, apply, usage), `RoomSyncController` (`POST admin/rooms/sync/preview`, `POST admin/rooms/sync/apply`,
`GET admin/rooms/sync/template`), `CalendarImport::gridRooms`, `ArabicMatch::roomKey`; web `pages/admin/RoomSync.tsx`. `RoomSyncTest` (3):
the calendar adds, renames («قاعة 3» → «القاعة 3»), changes capacity and tag, deletes an unused room, deactivates a booked one (booking
kept), protects meeting rooms, and is idempotent; a room list in any column order with Arabic-Indic digits reactivates a room and adds a
meeting room; rights, an unreadable file, single delete of a booked room, and the template.
