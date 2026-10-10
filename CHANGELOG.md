# Changelog

Format: Keep a Changelog. Versions: Alpha (core modules), Beta (all modules, integrations, security), Final (production on Azure).

## [Unreleased]
### Added — by RFP phase
- 00 Foundation: feature flags, production safety, RFP traceability pack.
- 01 Roles with scopes, role switching, global search. 02 Programme structure, groups, annual plan, internal workshops.
- 03 Needs cycles, competencies, gap analysis. 04 Enrolment, waiting list, withdrawals, external users.
- 05 Attendance methods, rooms and logistics. 06 Assessment engine. 07 Passing rules and certificates. 08 Evaluation, surveys, impact.
- 09 Career paths, licences, CPD. 10 SCORM/xAPI/cmi5/LTI, library, offline mobile.
- 11 Notifications, announcements, events, CMS. 12 Reports, role dashboards, live KPIs. 13 Integration hub, Entra SSO/MFA, Teams, Ministry systems, migration toolkit.
- 14 Communities, forums, ask-the-trainer, ratings, gamification. 15 AI (recommendations, feedback, adaptive, forecasts, assistant).
- 16 Course purchasing and payments. 17 Azure hosting code and IaC, SIEM, classification, data-subject rights.
- 18 Help centre, guided tours, e-kits, deliverables pack.

## 2026-10-10 — RFP comparison
Every RFP requirement re-checked in the code: 228 of 278 available, 50 partial, none missing; 21 of the 25 software mandatory items met (`docs/rfp/rfp-comparison-2026-10-10.html`, `.md`). Fixed: the video full-screen rule and pause limit are now enforced (server-side count, web player and app), the minimum time per slide is enforced, evaluation forms marked “required before the certificate” now hold the certificate back, the lesson editor shows the real default for hidden-page time, and the lesson page no longer gives the player and the discussion panel the same React key. The register is corrected (scrambled exam rows, an overstated face-recognition item, stale mandatory items and roles) and kept consistent by `scripts/rfp_sync.py`.

## 2026-10-07 — audit
Fixed 19 unreachable administration pages, a hidden room-calendar route, feature-off pages, the help drawer, production help articles, plus new satisfaction and support-ticket screens. See `docs/rfp/audit-2026-10-07.md`.

## 2026-10-07 — campus tour
Home page: an animated tour of the training centre (ground and first floor plans, the ministry campus) that follows a training journey from need to impact, with live figures. Also fixed the home page scrolling sideways on phones when upcoming programs are listed.

## 2026-10-07 — campus plans
The two floor plans of the campus tour are redrawn as vector plans in the site's brand colours (they follow the active theme, including occasion themes): an ivory day plan for the ground floor and a deep night plan for the first floor, with room names in Arabic or English. The first stage now starts at the building entrance instead of showing the aerial photo.
