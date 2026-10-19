# The guided tour — every feature, for every user type

Date: 2026-10-19 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Follows [05-trainer-teaching-desk.md](05-trainer-teaching-desk.md).

The welcome tour («جولة تعريفية») used to be three or four generic cards. It is now a complete walk through what the platform offers the person's role.

## 1. How it is built

1. **Shared basics** (everyone): welcome, search (Ctrl+K), the help button, notifications, language / account / security.
2. **The story of the role** (`TourCatalog::journey`, from the server, Arabic and English, with an icon, bullet points and a page to open): one for each of the 17 roles — employee, trainer, school administrator / supervisor / academic deputy, coordinator / centre administrator / super administrator, training head / leadership / executive, planning head / specialist, logistics officer, finance officer, kit developer, QA reviewer.
3. **One chapter per section of the menu the person can actually open** (built in the web app from the same menu the person sees, so permissions, switched-off features and the administrator's own menu arrangement are respected): every page with what it is for (`components/help/tourFeatures.ts`, one line per page in both languages) and an **Open** button. Hovering a card lights the matching entry in the side menu. For administrators a final chapter lists every settings section they may use, with its description.
4. **A closing step** with the number of pages and sections covered, the help centre and a replay.

Behaviour: a chapter rail with progress, a fixed-size panel, Next / Back, **minimise** (to open a page and carry on from a floating "resume" pill), "skip and don't show again", and Esc / backdrop minimise. The portal (trainees, supervisors) and the administration console share the component. The tour is remembered per person under a new key (`first-login:{role}.v2`), so everyone sees the complete tour once; the help centre replays it; «what's new» keeps its own short tour.

## 2. Files

Backend — `app/Help/TourCatalog.php` (rewritten), `app/Help/HelpService.php` (key `.v2`), `tests/Feature/HelpCentreTest.php`.
Web — `components/help/TourHost.tsx` (rewritten), `components/help/tourFeatures.ts`, `i18n/tour.ts`; `AdminLayout` passes the visible menu to the tour and tags menu entries with `data-tour`.

## 3. Tests executed and actual results

- `HelpCentreTest` → 15 tests passed, including the new one: every one of the 17 roles has its own story after the shared steps, every step has Arabic and English text and matching bullet points, no step is repeated.
- With the teaching-desk and routing suites: 35 tests passed.
- Playwright (Arabic) as trainer, administrator and employee after resetting their tours: the tour opens with 9 / 9 / 8 server steps and 14 / 17 / 10 chapters in the rail; chapters list the pages that role has, the hovered page is ringed in the menu.
- `tsc --noEmit` clean; pint clean; `oxlint` only the project's usual effect warnings.

## 4. Limits

- A page added to the menu later appears in the tour automatically but without a description until a line is added to `tourFeatures.ts`.
- The mobile app has its own onboarding; this tour is for the web.

---
Full suite after this change (`php artisan test --compact`, Windows, 2026-10-19): 1188 tests, 1118 passed, 17013 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors) and the environment-bound social-files test noted earlier; the help-centre, teaching-desk, routing and data-classification suites pass.
