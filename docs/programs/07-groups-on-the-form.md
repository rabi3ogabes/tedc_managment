# Programmes — the groups on the new / edit form

Date: 2026-10-19 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Follows [06-guided-tour.md](06-guided-tour.md).

A «المجموعات» card now sits at the top of the side column of انشاء برنامج / تعديل برنامج, above «المهارات المستهدفة». The administrator adds, renames and deletes the programme's groups there, and the system suggests each group's name from the target audience with the group's number.

## 1. Behaviour

- **Names from the audience.** `GroupNamer` takes the job titles of «الفئة المستهدفة» and the gender and returns «معلمون 1 · منسقو المواد 2 · قادة 3»: with several job titles the groups take them in turn, with one they are numbered after it (معلمون 1، معلمون 2). Women only gives the feminine plural (منسقات المواد). Known job titles have their own plurals (11 of them, in Arabic and English); an unlisted title uses its category (معلمون / قادة / إداريون / فنيون), and with no audience the name is «المجموعة 1». `POST admin/programs/group-names`.
- **New programme.** The groups are held on the form and saved with it (`groups: [{title_ar, title_en, capacity?}]`): the first renames the group every programme starts with, the others are added with the programme's dates and seats, in the same transaction. A name the administrator has not touched keeps following the suggestion (it changes with the audience and renumbers when a group is removed); typing in it stops that; the wand re-applies the suggestion; «اقترح الأسماء من الفئة المستهدفة» does it for every group. An empty English name follows the Arabic one.
- **Existing programme.** Each change is saved at once: a name is saved when the field loses focus or on Enter (a ✓ confirms it, a server refusal is shown), «+» adds a group named by the suggestion for the next number, the bin deletes after a confirmation. Each row shows its status, seats taken of capacity and number of sessions. Renaming is possible at any time and on any status.
- **Rules (server).** Two groups of one programme cannot share a name (`duplicate_title`); a programme keeps at least one group (`last_group`); a group with registrations cannot be deleted (`has_registrations`, the bin is disabled with the reason); more than one group on a new programme needs `groups.manage`.

## 2. Files

Backend — `Services/Programs/GroupNamer.php`; `TrainingGroupController::suggestNames`; `ProgramController` (`groups` on store, `applyGroups`); `TrainingGroupService` (unique name, last group); routes; messages; `tests/Feature/ProgramGroupsFormTest.php`.
Web — `pages/admin/program/GroupsCard.tsx`; `ProgramEditor.tsx` (state, payload, card above the skills); `i18n` `admin.programs.groups.*`.

## 3. Tests executed and actual results

- `ProgramGroupsFormTest` → 2 tests passed: the suggestions (three job titles taken in turn and numbered, feminine plural, the "no audience" name, permission), and a programme saved with three named groups (first renamed, others added with its dates, English following Arabic, per-group seats), renamed later, a duplicate refused, a group added, a group with registrations and the last group refused. With the group, builder and catalogue suites: 17 passed.
- Playwright (Arabic): with teachers, subject coordinators and principals as the audience the card suggested «معلمون 1 · منسقو المواد 2 · مديرو المدارس 3»; one name was typed by hand, the programme saved with those three groups; on the edit page a group was renamed (✓ shown) and a fourth added as «معلمون 4».
- `tsc --noEmit` clean; pint clean.
- Full suite: see the line appended at the end of this report.

## 4. Limits

- Only the first group receives the generated session plan on a new programme; sessions of other groups are added from the group's own page.
- Suggested names follow job titles; a programme with several unrelated target rows (school type, stage) uses only the job titles.

---
Full suite after this change (`php artisan test --compact`, Windows, 2026-10-19): 1190 tests, 1119 passed, 17043 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors) and the environment-bound social-files test noted earlier.
