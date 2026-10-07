# Duplicate features — 7 October 2026

Rule applied: when a feature existed before the RFP programme and a phase built its replacement, **the phase's version stays and the old one goes**. Where an old feature and a phase feature do different jobs, both stay.

## Removed (the old copy; the phase version remains)

| Old copy | Replaced by | Notes |
|---|---|---|
| **Training needs page** (`/admin/needs`: school requests + analytics tabs) | **Needs hub** (Phase 3): manager requests, individual needs, gap analysis | The *needs surveys* tab had no replacement, so it moved into the needs hub (`/admin/needs-hub?tab=surveys`); `/admin/needs` redirects there. The old table `training_needs` and its API stay because annual-plan, forecast, AI and report code still read it. |
| **Settings quick switcher** (Ctrl/⌘K inside Settings) | **Global search** (Phase 1), which also lists every settings section | The Settings home button now opens the global search. |
| **Approve / reject buttons on the registrations list** | **Approvals inbox** (Phase 4): manager stage, centre stage, withdrawals, excuses, external requests | The list stays as the full register of registrations; it links to the inbox. |
| **Old KPI cards and impact ring on the dashboard** | **Role dashboard widgets** (Phase 12), `executive_kpis` | Same numbers (same source). `executive_kpis` is now in the default dashboard of the roles that saw the old cards. The charts (trend, status, category, top programs, upcoming sessions) have no widget equivalent and stay. |
| **`POST /me/registrations/{id}/cancel`** | **`POST /me/registrations/{id}/withdraw`** (Phase 4: reasons, manager stage, waiting list) | No screen or app called it; two tests now use the withdrawal. |
| **`POST /admin/announcements/{id}/attachments`** | **`POST /admin/announcements/{id}/media`** (Phase 11: images, video, audio, files, links) | No caller. |
| **`GET/PUT /admin/settings/impact-schedule` and `settings/satisfaction-alerts`** | **`GET/PUT /admin/settings/evaluation`** (one screen for both) | No caller. |

## Looked at and kept (different jobs)

* **Rooms** (room details, equipment, screens) and **Room operations** (calendar, bookings, buildings, seating): complementary.
* **AI assistant page for staff** (`/admin/ai`, analytics questions) and the **trainee assistant** (Phase 15, answers from the portal's content): different audiences.
* **Satisfaction survey, trainee impact survey, manager impact form**: Phase 8 deliberately kept them as read-only system forms next to the new evaluation forms, so every historical record still reports.
* **Lesson quizzes** and the **assessment engine** (Phase 6): lesson quizzes keep working with their attempts; `tedc:migrate-lesson-quizzes` copies them into banks when the centre chooses.
* **Settings tabs and menu entries** that open the same page (users, audit log, rooms, trainers, calendar): two doors to one page, not two features.
* **Public chat widget** (visitors) and the **portal assistant** (signed-in trainees).

## Data move
The needs raised on the old school-request screen (`training_needs`) are copied into the needs-cycle requests (`institutional_requests`) by `php artisan tedc:needs-migrate-legacy [--dry-run]`, and `tedc:deploy` runs it on every release. Each old row is copied once (`legacy_need_id` is unique), with its author, reviewer, notes and original dates; priority low / medium / high / critical becomes degree 2 / 3 / 4 / 5; submitted and under review become *submitted*, approved becomes *accepted*, planned and fulfilled become *merged*, rejected stays *rejected*. They sit outside any needs cycle, show the headcount the old form recorded, and planners can decide them in the needs hub.

## Not done
* The old `training_needs` table and API were kept: annual-plan, forecast, AI-assistant and report code still reads them. Pointing those at the new tables is separate work, and until then the two tables hold the same older needs.
* The old rows carry a headcount, not a list of people, so the migrated requests have no named employees.
