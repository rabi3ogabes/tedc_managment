# مجتمعات التعلّم المهني (PLC) — Phase 2 report: schools

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Phase 1](01-phase1-foundation.md).

## 1. Files changed

New
- `backend/app/Services/Plc/SchoolSelector.php` — compatibility (gender from the official record), proximity (Haversine from official coordinates, otherwise district → region, never an invented distance), eligible staff and clash counts, ranked suggestions with reasons, the excluded list with reasons, host change, add with seats, remove, exclude; every change in the decision log.
- `backend/app/Http/Controllers/Api/V1/Admin/Plc/WorkshopSchoolController.php`; routes `admin/plc/workshops/{id}/schools` (list), `/search`, `/suggest`, `PUT /host`, `POST` (add), `PUT /{school}/seats`, `POST /{school}/exclude`, `DELETE /{school}`.
- `backend/tests/Feature/PlcSchoolsTest.php` — 3 tests, 70 assertions.
- `web/src/pages/admin/plc/SchoolsStep.tsx` — wizard step 2 (host card, suggestions with reason chips and the collapsible excluded list, manual search with gender and region filters, participants with seats, exclusion, the gender-exception dialog) and the shared `Stepper`.

Changed
- `lang/{ar,en}/messages.php` — new `messages.plc.*` keys; `web/src/i18n/plc.ts` — `plc.schools.*`; `App.tsx` route `communities/workshops/:id/schools`; the wizard now continues to step 2 after creation and uses the shared stepper; the workshop page has "إدارة المدارس".

## 2. Rules implemented (from the approved design)

- Gender policy from PLC settings: `strict` refuses an incompatible school (422 `plc_gender_mismatch`); `allow_with_exception` needs `plc.exceptions` and a reason, recorded as an exception decision; `open` accepts. Mixed schools match both categories; an unknown gender is flagged, never silently accepted or excluded.
- Proximity: when both schools have official coordinates the distance is computed and the configurable maximum (25 km default) applies; otherwise the suggestion says "same district" or "same region" or "other region" and shows no distance. `require_same_region` excludes other regions when on.
- Eligible staff: active employees with an account; a school with none is excluded with `no_staff`. Clashes: staff already registered in another programme overlapping the workshop's dates are counted and shown, not hidden.
- Ranking: proven distance first, then same district, same region, elsewhere; community members before others; more eligible staff first; fewer clashes first.
- Internal scenario: the host never changes (`plc_internal_host_fixed`); adding other schools needs `plc.manage` and is recorded as `scope_widened`.
- Editing schools of a submitted workshop sends it back to draft (review restarts), as any other edit. Schools may still be adjusted on approved or scheduled workshops by `plc.manage` holders.
- Suggestions never select or invite anything by themselves.

## 3. Tests executed and actual results

- `php artisan test --filter=PlcSchoolsTest`: **3 passed, 70 assertions** (ranking by distance then district with exact reasons and distance tolerance, exclusions with reasons, a wider radius, manual search annotation, strict refusal, seats, exclusion, removal, the host cannot be removed, the decision log; the configurable policy with exceptions recorded and the flag instead of exclusion; the internal scope and the review restart).
- Web: `tsc --noEmit` clean; lint shows two pre-existing-pattern warnings in the wizard (setState in an effect used to load an existing workshop), none in the new step. Screens opened as the administrator in Arabic: the schools step with suggestions, after adding a school, and the workshop page.
- Full backend suite (`php artisan test`, SQLite, Windows): **1,155 tests, 1,085 passed, 4 failed, 54 errors, 15,035 assertions**. The non-passing cases are the same pre-existing Windows-only OpenSSL and line-ending cases as in the earlier reports; none touches PLC.

## 4. Security-sensitive changes

Search and suggestions are limited to the person's scope; a school outside it cannot be added without `plc.manage`. Exceptions and scope widening carry the actor and reason in the decision log and the audit log.

## 5. Known limitations

- Eligible staff counts every active employee of the school; filtering by the workshop's target job titles arrives with the trainee step (Phase 3), which also enforces seats.
- Clashes are counted at programme-date level; session-level clashes come with scheduling (Phase 4).
- Sample school positions are empty, so on the demo site suggestions show districts and regions, not distances, exactly as designed.

## 6. Exact next step

Phase 3 — people: trainee nomination on `registrations` with the declined state, duplicates, clashes, waiting list and per-school seats; trainers lead and co per session with conflicts and change after approval; wizard steps 3 and 4. Report in `docs/plc/03-phase3-people.md`.
