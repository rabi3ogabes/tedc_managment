# مجتمعات التعلّم المهني (PLC) — Phase 3 report: people (trainees and trainers)

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Phase 2](02-phase2-schools.md).

## 1. Files changed

New
- `backend/database/migrations/2026_01_01_006700_create_plc_nominations.php` — `plc_nominations` (one row per person per workshop; keeps the link to the registration once it exists).
- `backend/app/Models/PlcNomination.php` — states `nominated, approved, waitlisted, rejected, declined, withdrawn, completed` with the allowed transitions and the states that need a reason.
- `backend/app/Services/Plc/WorkshopPeople.php` — the workshop's training group (created on first use), eligible staff, nominations (scope, participating schools only, duplicates, clashes with other programmes, seats per school and capacity → waiting list, promotion when a seat frees), the state machine, materialisation into registrations, trainers (suggestion by specialisation and availability, proposal with double-booking check, confirmation, replacement with notice, withdrawal).
- `backend/app/Http/Controllers/Api/V1/Admin/Plc/WorkshopPeopleController.php` and routes under `admin/plc/workshops/{id}/`: `people/eligible`, `nominations` (GET, POST, POST `{nomination}` decide, POST `nominations-materialise`), `trainers` (GET, POST, `suggest`, `{assignment}/confirm`, `{assignment}/replace`, DELETE).
- `backend/tests/Feature/PlcPeopleTest.php` — 2 tests, 70 assertions.
- `web/src/pages/admin/plc/TraineesStep.tsx` (step 3) and `TrainersStep.tsx` (step 4); the stepper now unlocks steps 1–4; the workshop page links to both; the schools step continues to the trainees.

Changed
- `WorkshopWorkflow::move` — when a workshop becomes approved, its approved nominations are materialised as registrations.
- `lang/{ar,en}/messages.php`, `web/src/i18n/plc.ts` (`plc.people.*`, `plc.trainers.*`), `NotificationCatalog` (`plc.nominated`, `plc.nomination_decided`, `plc.trainer_changed`), `AppServiceProvider` bindings `nomination`, `assignment`, data-classification register.

## 2. Design note: nominations and registrations

The design said nominations would live on `registrations`. The programme engine refuses a registration before the programme is approved (the legacy internal-workshop test guards that), while the Ministry's procedure nominates people while the workshop is still being planned. So a nomination is its own auditable record, and an approved nomination becomes a registration (seats, waiting list, attendance, certificate) the moment the workshop is approved, or immediately when approved afterwards. Withdrawing, declining or rejecting a nomination that already has a registration withdraws or rejects that registration too. Nothing is duplicated: the person, the seat and the attendance stay on the engine.

## 3. Rules implemented

- Only staff of the host and participating schools, inside the nominator's scope, can be nominated; adding a school never nominates anyone.
- The same person cannot be nominated twice while a nomination is live; a rejected, declined or withdrawn person may be nominated again.
- A clash with another programme (a seat-holding registration whose dates overlap) is refused; the centre may override with a reason, recorded as an exception.
- Seats: the school's seats (when set) and the workshop's capacity are checked on nomination and on approval; beyond them the person waits; a freed seat promotes the first waiting person of that school (then of any school).
- Decisions by `plc.nominate` holders inside their scope (a principal for their school) or `plc.manage`; reasons are required to reject, decline or withdraw; every move is in the decision log and the person is told.
- Trainers: suggested by specialisation match with the workshop domain and availability on its dates; a trainer already booked on those dates is refused unless the centre overrides with a reason; lead or co-trainer; nothing counts as confirmed until it is confirmed; a replacement, even after confirmation, needs a reason, marks the old assignment replaced and notifies the old trainer, the new one and the coordinator; withdrawing a confirmed trainer needs a reason.

## 4. Tests executed and actual results

- `php artisan test --filter=PlcPeopleTest`: **2 passed, 70 assertions** (eligibility limited to participating schools with clashes shown; nominations skipped with codes for clash and foreign school; duplicate refused; override needs a reason; seats per school then capacity → waiting list; principal scope and reason rules; promotion of the waiting person; invalid transition refused; no registration before approval; materialisation on approval with an approved registration and one group; later approval registers immediately; withdrawal withdraws the registration. Trainers: suggestion order, school user refused, busy trainer refused, duplicate refused, hours default, proposal notice, confirmation once, replacement with reason and notice, decision log, withdrawal).
- All PLC classes together: 9 tests, 236 assertions. Web `tsc --noEmit` and lint clean for the new files. Screens opened as the administrator in Arabic: trainees step, trainers step, workshop page.
- Full backend suite: appended below.

## 5. Security-sensitive changes

Nomination decisions are scope-checked on the server; overrides (clash, busy trainer, seats) need `plc.manage` or `plc.exceptions` and a reason and are logged with the actor. Notifications to nominees and trainers carry only the workshop title and the reason.

## 6. Known limitations

- Clashes are detected at programme-date level; session-level clashes come with scheduling (Phase 4), which will also reconcile the registrations' time conflicts.
- Trainer availability is checked against the workshop's whole date range; per-session trainers (`plc_session_trainers`) are filled in Phase 4.
- The eligible list shows every active employee of the participating schools; filtering by the workshop's structured target audience is a Phase 8 assistant.

## 7. Exact next step

Phase 4 — scheduling: sessions per delivery mode (in person with room, remote with a protected link, hybrid), per-session trainers, the calendar with filters and a conflicts panel, alternative slots never applied automatically, schedule changes after invitations recorded and notified; wizard step 5. Report in `docs/plc/04-phase4-scheduling.md`.
