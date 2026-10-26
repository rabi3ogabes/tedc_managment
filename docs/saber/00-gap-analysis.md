# سابر+ (SABER+) into شاهين — feature inventory, gap analysis and build plan

Source read: `rabi3ogabes/tedcqatar` (Lovable · React + Supabase), commit `408433c` (26 Oct 2026) — `src/pages/SaberPlus*.tsx` (≈5 800 lines),
`src/components/saber/*` (9 components, ≈3 300 lines), `src/lib/saberCalendarImport.ts`, `src/lib/spScheduling.ts`, `src/hooks/useSaberCaps.tsx`,
`src/components/TrainersManagerDialog.tsx`, the survey-links and programme-source panels of `src/pages/Settings.tsx`, and the `sp_*` tables of
`supabase/migrations`.

## Decision: build SABER+ on شاهين's own data, not beside it

SABER+ keeps its own copies of programmes, groups, trainers, supervisors and rooms (`sp_programs`, `sp_program_groups` with a JSON
`day_schedule`, `sp_trainers` …). شاهين already has these as real entities with a scheduling engine that enforces the rules SABER+ checks in the
browser (`ProgramSessionWriter`: training calendar, training-day window, room and trainer conflicts, closed-day approvals). Copying the `sp_*`
tables would create a second programme system that disagrees with the first. So every SABER+ feature is built on:

| SABER+ | شاهين |
|---|---|
| `sp_programs` | `programs` |
| `sp_program_groups` | `training_groups` |
| `day_schedule[]` (date, room, trainer, times per day) | `program_sessions` (one row per training day) |
| `sp_classrooms` | `training_rooms` |
| `sp_trainers` / `trainers` | `trainers` |
| `supervisors` | users with the supervisor role, `training_groups.supervisor_id` |
| `settings.sp_off_days` | `calendar_days` (+ weekend setting) |
| `sp_offday_requests` | new `calendar_requests` (request → decision) next to `calendar_approvals` |
| caps `cap:sp.*` | permissions `saber.*` in the role matrix |

## Feature inventory and status

Status: ✅ exists in شاهين · 🟡 partly · 🆕 to build

### A. The programmes & groups workbench (`/saber-plus`)
| # | Feature | Status |
|---|---|---|
| A1 | One screen listing every programme with its groups; expand a programme to edit its groups and each training day inline; changes saved at once | ✅ phase 1 |
| A2 | Header figures: programmes this month, awaiting approval, booked rooms, available trainers | ✅ phase 1 |
| A3 | Per programme: hours, number of groups, days per group, assigned days; status lights «days with a date / a room / a trainer» | ✅ phase 1 |
| A4 | Per day: date (Arabic dd/mm/yyyy input, holiday blocked), room (searchable, taken rooms flagged), trainer (searchable, busy flagged), times | ✅ phase 1 (holidays refused by the server, flagged in the pickers) |
| A5 | Trainer of day 1 propagates to every day; the first room chosen applies to every day except where that room is taken | ✅ phase 1 |
| A6 | Room / trainer double-booking refused; inherited conflicts auto-cleared with a notice | ✅ phase 1 |
| A7 | Group: number (renumber), custom name, colour (calendar), term (first Aug–Dec / second Jan–Jul / whole year), add group, delete group (setting), reset all days, reset one day | ✅ phase 1 |
| A8 | Group approval «اعتماد المجموعة» (head of training only), with who and when; press again to withdraw | ✅ phase 1 |
| A9 | Training-kit readiness toggle per programme (جاهزة / غير جاهزة) | ✅ phase 1 |
| A10 | Filters: search, date range, term 1 / term 2, kit ready / not ready; clear filters; pagination (20) | ✅ phase 1 |
| A11 | Toolbar personalisation — show/hide each tool, remembered | ✅ phase 1 |
| A12 | Days shown as compact rows or as cards | ✅ phase 1 |
| A13 | Supervisor per programme (assignable, setting can lock it) | ✅ phase 1 |
| A14 | Programme details window: overview, groups, days, all details | ✅ phase 2 |
| A15 | Per-programme survey link: open, copy, QR code (download PNG), edit link | ✅ phase 2 |
| A16 | Per-programme survey Excel report | ✅ evaluation exports — linked from the workbench |

### B. Scheduling tools
| # | Feature | Status |
|---|---|---|
| B1 | Plan of the coming days (simulation): programme, group, optional room and trainer, weekday, weekly / every two weeks, count, from date → each date free / busy / holiday; select; apply to the group (replaces its days, with a warning) | ✅ phase 2 |
| B2 | Cancel a programme or one group: reason, requested by, attachment; frees rooms and trainers; cancelled list; reactivation for the super administrator only | ✅ phase 2 |
| B3 | Booking on a holiday: request with reason → inbox «طلبات أيام الإجازة» → approve / reject; instant approval for whoever holds the right | ✅ phase 2 |
| B4 | Holidays: single dates and weekly recurring days, with a label; «allow an exceptional event» on a holiday | ✅ phase 2 (events flag enforced for meetings since phase 3) |

### C. Rooms and meeting bookings
| # | Feature | Status |
|---|---|---|
| C1 | Rooms: name, type (training / meeting), tag, capacity, building, floor, layout | ✅ phase 3 |
| C2 | Meeting-room booking: title, requested by, reason, date, hour slots 7:00–14:00, purpose, recurrence daily / weekly / every two weeks × count (permission), hour conflicts and holidays checked | ✅ phase 3 |
| C3 | Rooms month calendar: all / free / booked rooms, available / booked filter, calendar or rows view, colour legend, room window with hour slots, edit / cancel a booking, recurring bookings shown together | ✅ phase 3 |
| C4 | Room map: status of every room for a chosen day, booking details | ✅ phase 3 |

### D. Data tools
| # | Feature | Status |
|---|---|---|
| D1 | Import «تقويم القاعات التدريبية» (rooms × dates grid): fuzzy-matches each label to a programme and group/supervisor, adds missing rooms and supervisors, updates groups' days, re-check against what is saved, approve the matched groups; never creates programmes | 🆕 |
| D2 | Programme list import from Excel (or a OneDrive link): name, type, method, audience, hours, days, objective, groups, kit, execution date, notes; issues listed | 🟡 |
| D3 | Survey links: upload Excel (م, رابط الإستمارة, رابط تعديل الإستمارة), template, replace / clear, assign a link to each programme | 🆕 |
| D4 | Trainers manager: trainer types, Excel template / import / export, approval before supervisors can pick a trainer, fill e-mails and details from the staff records by national ID | 🟡 trainers ✅; import/export/approval 🆕 |
| D5 | Supervisors page: every supervisor with the programmes assigned, approved vs pending groups | 🆕 |

### E. Administration
| # | Feature | Status |
|---|---|---|
| E1 | 19 fine-grained rights (create / edit / cancel programme, recurring meeting, manage / rename / approve group, edit days, assign room / trainer / supervisor, manage holidays, approve / view holiday requests, view plan, manage / approve trainers, manage supervisors, export survey) | ✅ phase 1 as `saber.*` permissions |
| E2 | Settings: supervisor editing allowed, group deletion allowed | ✅ phase 1 |
| E3 | Departments, users & roles, permission groups, workflow rules, notifications, audit log | ✅ (roles & permissions, approvals, notifications, audit) |

## Build plan (each phase: backend + web + tests on SQLite and PostgreSQL, deployed)

1. **Foundation and the workbench** — `saber.*` rights; group colour, term, approval; programme kit readiness and survey links; the workbench API and screen (A1–A13, E1–E2).
2. **Scheduling tools** — plan simulation (B1), cancellation (B2), holiday requests (B3), weekly holidays and events (B4), details window (A14), survey link tools (A15).
3. **Rooms and meetings** — room type and tag (C1), meeting bookings with slots and recurrence (C2), calendar filters and room window (C3), room map (C4).
4. **Data tools** — calendar import (D1), programme list import (D2), survey links (D3), trainers manager (D4), supervisors page (D5).
