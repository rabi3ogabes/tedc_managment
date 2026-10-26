# سابر+ Phase 4 — data tools

Plan: `00-gap-analysis.md` (items D1–D5). Two new tabs on «سابر+»: **أدوات البيانات** (`?tab=data`, one card per tool, shown to whoever
holds its right) and **المشرفون** (`?tab=supervisors`). Every tool shows what it will do before it writes anything.

## D1 — «استيراد تقويم القاعات التدريبية» (`saber.import`)

- Reads the rooms × dates workbook: the sheet whose name contains «تقويم» (else the first), the room-header row found automatically (the row
  with the most distinct names from column H), the capacity under it («25 متدرب»), the date in column D (Excel date, `dd/mm/yyyy` or ISO).
- Each cell «<البرنامج> - <المجموعة أو المشرف>» is matched to an **existing programme** with Arabic-aware fuzzy matching (letters folded,
  diacritics dropped, bigram and word similarity, bracketed qualifiers ignored; threshold 70 %). A trailing number is the group number; a
  short name is a supervisor. **No programme is ever created**; unmatched labels are listed with the closest programme.
- One label in several rooms on the same date means parallel groups (each keeps its room when it can).
- Each group found in the file gets its **target** (same group name, else same number, else a new group) and a **verdict**: up to date,
  needs updating, new group. Missing rooms and supervisors not in the platform are shown.
- **Apply** (selected groups): missing rooms are added, the target group is found or created (with a free colour), its days are replaced
  through the workbench rules — a day refused by the calendar or a clash is reported and the rest goes on; a group with attendance is not
  touched — the supervisor is set when matched to a platform supervisor, and, with «اعتماد», groups applied without a problem are
  approved (holders of `saber.group.validate`). **Re-check** reads the file again against what is saved.

## D2 — «استيراد قائمة البرامج» (`saber.import`)

- An Excel file, or a OneDrive / SharePoint link shared with «anyone with the link» (only those hosts, https only, downloaded server-side).
- Every sheet with a header row holding «اسم البرنامج»; columns: type, delivery (آلية التدريب), audience, hours, days, objective, groups
  (الكتل التدريبية), kit, execution date, notes. Notes per sheet and row: sheet without a header, row without a name, duplicate name,
  unreadable date, no number of days.
- Each row **updates** the programme of the same name (days, hours, delivery, kit readiness; the start date and objective only when empty)
  or **creates** it as a draft (code from the catalogue numbering, delivery, kit, start date, objective; type, audience and notes in the
  description). The programme gets at least as many groups as the file says (groups are only ever added).

## D3 — «روابط الاستمارات» (`saber.survey_links.manage`)

Upload the workbook (م، رابط الإستمارة، رابط تعديل الإستمارة; replace or merge by number), download the template, clear the list, and give
each link to a programme (searchable) — the programme's survey and edit links follow; moving a link takes it from the previous programme.
The links then show in the programme's survey tools (QR, copy, open).

## D4 — «إدارة المدربين» (`trainers.manage`; approval `trainers.validate`)

- Trainers with name, **national ID** (encrypted, shown masked, with a keyed hash to find duplicates and staff records), e-mail, mobile and
  type (مدرب من المركز / من الوزارة / من منتسبي المدارس / خارجي → the trainer source). Search by name, ID, e-mail or mobile; filter by type
  or «بانتظار الاعتماد».
- **Approval**: a trainer added by someone without `trainers.validate` waits; supervisors cannot pick them in SABER+ until approved
  (approvers see them, marked). Trainers that existed before this release were approved by the migration.
- **Excel**: template, import (matched by national ID, then name, then e-mail; an existing national ID is never changed; bad e-mails and
  mobiles are listed and left empty), export of everyone (styled, right-to-left).
- **«تعبئة البريد من بيانات الموظفين»**: trainers with a national ID get the e-mail, mobile, staff record, account and school of the staff
  member with the same national ID, where missing.
- Delete: a trainer who never taught is deleted; one with sessions is deactivated.

## D5 — «المشرفون»

Every supervisor (programme coordinators and heads of training) with e-mail, the programmes in their care (as coordinator or as group
supervisor) and, per programme, approved and pending groups; search, pages of 15; a programme opens its details window.

## Data, API, code

Migration `2026_01_01_009000_saber_plus_data_tools`: `trainers.national_id` (encrypted), `national_id_hash`, `validated_at`, `validated_by`;
table `saber_survey_links`. API under `/api/v1/admin/saber/data/…`: `calendar/preview|apply`, `programs/preview|apply`, `survey-links`
(list, upload, template, clear, assign, delete), `trainers` (list, save, delete, validate, import/preview|apply, export, fill-from-staff),
`supervisors`. Code: `SaberDataController`, `app/Services/Saber/{ArabicMatch,CalendarImport,ProgramListImport,TrainerRoster}.php`; web
`ImportDialogs`, `RosterDialogs`, `DataTab`.

## Tests

`tests/Feature/SaberDataToolsTest.php` — 5 tests with workbooks generated in the test (no real data): calendar import (matching, unmatched
label, target group, verdict, apply with a new room and approval, re-check finds it up to date, right required), programme list (header
row, update vs create, duplicate reported, draft with groups, delivery, kit, date, link host refused), survey links (upload, assign, move,
template, clear), trainers (approval gate in the SABER+ pickers, duplicate national ID, import by national ID with notes, fill from staff,
export, pending filter), supervisors page. All SABER+ and related suites: 100 tests, 1 198 assertions on PostgreSQL 16. Checked in the
browser: data tools tab and each dialog, trainers list, survey links, supervisors tab.
