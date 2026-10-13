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

## 2026-10-13 — Phase 21: offline learning, adaptive practice, the assistant's retrieval, TEDC as an LTI tool
Trainees download a course to the app and learn without a connection: downloads resume, are checked against the server's checksum and kept encrypted (AES-256-GCM, a key for that phone only, in its keystore) until they expire; videos play from a private temporary copy, PDF slides and articles open in the app, and the progress is sent safely when the phone is back online. Exams the trainer allows can be downloaded, taken offline with a timer the phone's clock cannot fool, and handed in later; the server checks the package, the phone and the timing again. A Downloads screen shows the space used, each course's expiry, remove and Wi-Fi only; signing out deletes it all (after a warning when something was not sent). PDF presentations now complete from the slides viewed, each after the author's minimum time, in the app as on the web, and full-screen lessons play only in a landscape full-screen player. Adaptive practice asks one question at a time at the learner's level in each competency and says why each question was chosen (web and app); review lessons are assigned to the learners who need them. The assistant has a pgvector store, model embeddings, streaming answers with Stop and a golden-set evaluation. Other learning platforms can launch TEDC as an LTI 1.3 or 1.1 tool. Fixed: busy pages could make low-limit routes refuse requests (429), tall windows had their top out of reach, learners saw option ids in their result review.

## 2026-10-13 — Phase 20: planning rules, the RFP report list, forecast exports
The annual plan follows the year's rules: the five programme types (ترخيص، تمكين، تمهين، تخصيص، تخيير) set on categories and programmes, mandatory categories (with a placeholder when no need covers one), and seat and hour caps that keep items in priority order and defer the rest with the reason. A plan that breaks a rule cannot be submitted; the head of planning may continue only with a written, audited exception. The employee, course and path reports carry the RFP's filters (entity type, workplace, licence, job title, experience, annual appraisal, training path, qualification) and statistics come by year, quarter and month. Forecasts and risk lists export to Excel, PDF and Word. Fixed: the report viewer overflowed with wide tables.

## 2026-10-13 — Preflight follow-up
The live site's Vercel settings overrode `APP_ENV`, so the production-only guards were off there and the public mobile configuration still served the shared demo password. The production deployment now always runs as production (`vercel-env.php`, `VercelEnvTest`). Sample forecasts no longer disappear on Mondays (`SampleForecastsMondayTest`).

## 2026-10-11 — Phase 19: collaboration completion
Members share real files in communities and discussions on the web and in the app (photos, PDF, Office, audio, video, ZIP): checked by their content, photos cleaned of location data and given thumbnails, kept private behind short-lived links, with limits set in the settings and per space. Every training material has its own discussion and private notes; lessons, materials, kits, library items and programmes have one rating card (average, spread, newest reviews), and moderators hide a review with a reason. Phones play the TEDC chime for new notifications (and stay silent for people who switched the sound off). Seven colour shades the web app used but never defined are now part of the theme, so hairlines and light tints draw correctly.

## 2026-10-11 — Preflight of the close-out
Production can no longer run in demo mode, deployments never set passwords, and the shared demo password is gone from the web app, the mobile app, the workflows and the docs (the sign-in screens get it from the server only on development and staging servers). Settings → Security lists the accounts that may still use it, with suspend and random-password actions (`tedc:demo-accounts`). The monthly restore test skips cleanly until Azure is connected. The project constitution (Spec Kit) is filled in, and the baseline is recorded in `docs/rfp/closeout/BASELINE.md`.

## 2026-10-11 — close-out prompt pack
`docs/rfp/closeout/`: the list of the 50 RFP requirements still partial (`OPEN_ITEMS.md`, with what exists, what is missing, who unblocks it) and phase prompts 19–27 plus a master prompt with a Preflight. The Preflight covers the live site's demo-mode exposure, failing Vercel deploys, the container CVE exception and the scheduled restore job that cannot succeed yet.

## 2026-10-10 — RFP comparison
Every RFP requirement re-checked in the code: 228 of 278 available, 50 partial, none missing; 21 of the 25 software mandatory items met (`docs/rfp/rfp-comparison-2026-10-10.html`, `.md`). Fixed: the video full-screen rule and pause limit are now enforced (server-side count, web player and app), the minimum time per slide is enforced, evaluation forms marked “required before the certificate” now hold the certificate back, the lesson editor shows the real default for hidden-page time, and the lesson page no longer gives the player and the discussion panel the same React key. The register is corrected (scrambled exam rows, an overstated face-recognition item, stale mandatory items and roles) and kept consistent by `scripts/rfp_sync.py`.

## 2026-10-07 — audit
Fixed 19 unreachable administration pages, a hidden room-calendar route, feature-off pages, the help drawer, production help articles, plus new satisfaction and support-ticket screens. See `docs/rfp/audit-2026-10-07.md`.

## 2026-10-07 — campus tour
Home page: an animated tour of the training centre (ground and first floor plans, the ministry campus) that follows a training journey from need to impact, with live figures. Also fixed the home page scrolling sideways on phones when upcoming programs are listed.

## 2026-10-07 — campus plans
The two floor plans of the campus tour are redrawn as vector plans in the site's brand colours (they follow the active theme, including occasion themes): an ivory day plan for the ground floor and a deep night plan for the first floor, with room names in Arabic or English. The first stage now starts at the building entrance instead of showing the aerial photo.
