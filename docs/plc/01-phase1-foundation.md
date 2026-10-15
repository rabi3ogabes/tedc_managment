# مجتمعات التعلّم المهني (PLC) — Phase 1 report: foundation

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [the analysis and design](00-analysis-and-design.md). Placement decided by the Ministry's reviewer: the whole module lives on the existing page **مجتمعات التعلم المهنية** (`/admin/communities`) and uses that page's feature switch (`plc`); the former discussion spaces are its last tab.

## 1. Files changed

New (backend)
- `backend/database/migrations/2026_01_01_006600_create_plc_tables.php` — `plc_communities`, `plc_community_schools`, `plc_needs`, `plc_workshops` (keyed by `program_id`), `plc_workshop_schools`, `plc_session_trainers`, `plc_decisions`; attaches existing school-internal workshops to the engine (reversible).
- Models `PlcCommunity`, `PlcNeed`, `PlcWorkshop`, `PlcWorkshopSchool`, `PlcSessionTrainer`, `PlcDecision`.
- `app/Services/Plc/PlcSettings.php` (policy defaults from the approved decisions), `WorkshopWorkflow.php` (state machine, approval path, programme sync, decisions + audit, notifications), `WorkshopService.php` (create / edit on the programme engine, internal preset, source freezing, scoped queries, legacy bridge), `NeedsService.php` (register, convert), `CommunityService.php`.
- Controllers `Api/V1/Admin/Plc/{WorkshopController, NeedController, CommunityController, PlcHubController}`.
- `tests/Feature/PlcFoundationTest.php` — 4 tests, 96 assertions.

Changed (backend)
- `routes/api.php` — `admin/plc/*` (27 routes) behind `feature:plc`; `AppServiceProvider` bindings `plcWorkshop`, `plcNeed`, `plcCommunity`.
- `RolePermissionSeeder` — 13 `plc.*` permissions granted per the approved matrix (coordinator = centre officer, training head = approver with exceptions, school admin = principal with the school step, academic deputy = school coordinator, trainer / executives / leadership / planning head = view and reports).
- `lang/{ar,en}/messages.php` — `messages.plc.*`; `NotificationCatalog` — `plc.workshop_submitted / _changes / _decided / _cancelled` (editable, switchable).
- `InternalWorkshopService::submit` — the legacy route now also registers the programme in the engine as an internal workshop in review.
- `AnalyticsService::geographic` — school points carry `code`, `gender`, `district` (for the zone box below).
- `docs/security/data-classification.md` regenerated.

New (web)
- `web/src/pages/admin/plc/PlcHub.tsx` (overview with live counts, communities, workshops, needs register, discussions tab), `WorkshopWizard.tsx` (step 1 of 5; steps 2–5 shown locked with "next phase"), `WorkshopDetail.tsx` (facts, approval path, actions, decision log), `plcApi.ts`, `web/src/i18n/plc.ts`.
- Routes: `/admin/communities` → the hub; `/admin/communities/workshops/new`, `/:id`, `/:id/edit`; `/admin/communities/:id` stays the discussion space. Menu label now reads مجتمعات التعلم المهنية.

Also in this commit (a request received while Phase 1 was in progress): on **التحليل الجغرافي** a zone box lists the eight educational zones with their school counts and coverage; pressing a zone (or a region row) opens a popup of that zone's schools with filters حكومية / خاصة and ذكور / إناث / مختلط, a search box and per-filter counts. Data comes from the official school record (`type`, `gender`), never from the name.

## 2. Features

- Communities: create with host and member schools, list, archive (never delete), scoped to the person's schools.
- Needs register: sources راصد / centre / entity / school with reference number, received date, link, notes, schools, proposer and justification; review states new / under review / converted / declined; conversion creates a draft workshop bound to the need (several per need allowed); the source of a converted need is frozen. The catalogue and the hub say plainly that راصد has no electronic integration.
- Workshops: one engine for both scenarios. School users get the internal preset (host = own school, source = school, owner type school) whatever they send; centre staff choose the scenario and the host. Draft → submitted → (needs changes →) approved, with a per-scenario approval path from settings (internal: school step then centre step; shared: centre step), each step taken only by someone holding its permission; editing a submitted workshop restarts the review; source and reference frozen after approval unless `plc.exceptions` records a reason; cancel with reason; every transition in `plc_decisions` and the audit log; programme status kept in sync so existing screens read right; notifications to the next approvers and back to the requester.
- Scope: everything is filtered by `AccessScope` on the server; another school's record answers 403 even by id.

## 3. Migrations

Seven new tables, portable SQL, no change to existing tables. Down drops the seven tables.

## 4. Tests executed and actual results

- `php artisan test --filter=PlcFoundationTest`: **4 passed, 96 assertions** (internal preset and scope isolation; the two-step path with changes, resubmission, source freezing, cancellation and the decision log; needs registered by hand and converted, shared path, rejection; legacy route bridge and scoped communities).
- Web: `tsc --noEmit` clean; `oxlint` reports no warnings in the new files (pre-existing warnings elsewhere unchanged). Screens opened as the administrator in Arabic: hub overview, workshops, needs, wizard, geo page with the zone popup and the girls filter.
- Full backend suite: see §4a.

## 4a. Full suite

`php artisan test` (SQLite, Windows): **1,152 tests, 1,082 passed, 4 failed, 54 errors, 14,965 assertions, 11.5 min**. The 58 non-passing cases are the same pre-existing Windows-only ones recorded in the Survey Studio Gate 2 report (54 `openssl_pkey_*` errors; `VercelEnvTest` x2; the mutual-TLS certificate byte count; the integration-guides line endings). None touches PLC, the surveys or the geographic analytics; CI on Linux runs the same suite on push.

## 5. Security-sensitive changes

- New permission family `plc.*`; approval steps are checked against the actor's permissions on the server, never trusted from the client.
- Scope enforcement on every PLC route; lookups (schools, users) limited to the person's scope and 20–30 rows.
- The decision log and audit log record actor, reason and IP for every transition and exception.
- No new secrets or external calls. راصد: manual entry only, declared as such in the API and the UI.

## 6. Known defects and limitations

- The wizard's steps 2–5 are visible but locked (schools, trainees, trainers, scheduling) until their phases; the workshop detail says so.
- `plc_session_trainers` is created now and used from Phase 3.
- The hub embeds the discussion spaces page, which keeps its own header inside the tab.
- The zone popup lists schools without coordinates too (good), but the map itself still shows only schools with official positions.

## 7. Requirements not yet verified

Everything from Phase 2 onwards: school compatibility and proximity, nominations, trainers, sessions and calendar, QR check-in / check-out, evaluation rules, reports and exports, smart assistants.

## 8. Human decisions

None new. The ten design decisions were taken with the recommendations on 2026-10-15; the placement on the communities page was decided by the reviewer.

## 9. Exact next step

Phase 2 — schools: host selection, manual search and filters, compatibility rules (gender policy from settings), proximity with graceful degradation, seats per school, the excluded list with reasons; wizard step 2 unlocked. Report in `docs/plc/02-phase2-schools.md`.
