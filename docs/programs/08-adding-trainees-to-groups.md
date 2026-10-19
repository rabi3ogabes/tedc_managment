# Programmes — adding trainees from the Groups tab, target audience only

Date: 2026-10-19 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Follows [07-groups-on-the-form.md](07-groups-on-the-form.md).

## 1. What changed

- **Tab order.** On the program page «المجموعات» now comes before «المشاركون» and is the tab the page opens on.
- **Trainees of each group.** Every group card has a «المتدربون» panel (opened on demand) listing the group's trainees — name, employee number, school, job title, status — and **إضافة متدربين**.
- **Adding, two ways** (one dialog, one selection list at the bottom, one submit):
  1. **برقم الموظف** — type or paste one or many employee numbers (space, comma, `;` or new line). The numbers are checked as you type: who it is, whether they are in the target audience and — if not — the reason (the failed rule, e.g. «ليس من الفئة المستهدفة (البرنامج غير موجّه لفئتك الوظيفية)»), whether they are already in this group or in another group of the program (naming it), whether the group is closed or full, and an unknown number says so. Everyone who can be added is offered with «أضف إلى القائمة».
  2. **من موظفي المدرسة** — search and pick a school; the dialog opens that school's staff **who are in the target audience and not yet in the program**, with a summary («4 من 10 موظفاً ضمن الفئة المستهدفة — 6 خارج الفئة المستهدفة لا يظهرون — مسجلون في البرنامج: n»), a search box, select-all and a tick per person.
- **No exception.** «Target audience» is the program's own eligibility check (target rows — job title, department, school type, stage — and the eligibility rules, including the ones generated from gender / experience / job titles). The school list is filtered by it, the number check explains it, and `POST groups/{group}/trainees` refuses anyone outside it again — **there is no override on this screen**, whatever the request says. Time conflicts and already-completed equivalents are still enforced; the centre's direct assignment ignores the registration window only (a school administrator keeps it), is approved at once and announces the assignment to the trainers and the schools' administrators as before. A group with no seat left refuses (it does not waitlist).
- **Hours trained last year, beside every trainee.** The hours of the counting year before the current one (the year start is the PD setting): centre programs and school workshops (valid certificates), approved outside activities and approved knowledge transfer, summed (`AnnualHoursService::lastYearHours`, three queries for any number of people). A green chip «12 ساعة · 2025» (grey for 0) shows in: the add dialog (both ways, and on the chips of the selection), the group's trainees panel, the program's «المشاركون» tab, the nomination dialog's candidates, and the registrations list — `POST admin/employees/hours-last-year` serves any list.
- A school administrator sees and adds only their own school's staff.

> The request said "trainers" once; the whole sentence (target audience, school staff, hours trained) is about trainees, so that is what was built. Trainers keep their own proposal / approval flow on the same tab.

## 2. Files

Backend — `Admin/GroupTraineeController` (`GET groups/{group}/trainee-candidates`, `POST groups/{group}/trainees/check`, `POST groups/{group}/trainees`, `POST employees/hours-last-year`), `AnnualHoursService::lastYearHours`, `RegistrationService` (`ignoreWindow`), routes, messages, `tests/Feature/GroupTraineesTest.php`.
Web — `program/AddTraineesModal.tsx`, `program/lastYearHours.tsx` (`useLastYearHours`, `HoursChip`), `GroupsTab` (trainees panel), `ParticipantsTab`, `Registrations`, `registrationModals` (hours), `ProgramManage` (tab order), `i18n`.

## 3. Tests executed and actual results

- `GroupTraineesTest` → 3 tests passed: the school list holds only the program's audience (3 teachers of 4 staff — the principal is left out and counted), each with last year's hours (12 from a certificate + 5 from an approved activity = 17; this year's 40 not counted; 0 for the others), the hours endpoint, validation and permission; the number check (found / not in audience with reasons / unknown / already in this group / in another group, named), an add that refuses the principal even with `override: true`, teachers going into the group asked for and approved, a full group refused, and the list afterwards showing 0 left and 3 already registered; a school administrator limited to their school and to the registration window.
- With the group, registration, nomination and enrolment suites: 56 tests, all passing except the known OpenSSL environment error.
- Playwright (Arabic): the page opens on «المجموعات»; the dialog showed «4 من 10 موظفاً ضمن الفئة المستهدفة — 6 خارج الفئة المستهدفة»; a number list with the principal, a teacher and an unknown number gave refused / allowed / not found with reasons; adding put the four teachers in the group, and the group's panel then listed them with their hours.
- `tsc --noEmit` clean; pint clean.
- Full suite: see the line appended at the end of this report.

## 4. Limits

- Last year's hours are the actual hours recorded, not the counted hours after the annual-target caps.
- The school list shows up to 800 staff per school; a search narrows it.

---
Full suite after this change (`php artisan test --compact`, Windows, 2026-10-19): 1195 tests, 1124 passed, 17112 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors) and the environment-bound social-files test noted earlier.
