# Phase 18 — Adoption, help centre, interactive e-kits and the deliverables pack

Closes (as far as the product and the repository can): `UTR-01` · `UTR-02` · `UTR-03` · `EKT-01` · `DLV-01` … `DLV-07`.
Read the **Limits** section before relying on any of it: several parts of this phase cannot be finished by software.

## What was built

### Help centre (`UTR-01`, `UTR-03`)
* **Articles** (`help_articles`): bilingual, role-targeted, linked to pages (`related_routes`, `*` wildcard), versioned (`help_article_versions`, every text change keeps the earlier text and can be restored), with screenshots, a video link or an uploaded video. 28 starter articles for 17 roles are seeded by `HelpArticlesSeeder` (only when missing, so edits are never overwritten).
* **Contextual help**: the «?» button in the top bar opens a panel with the articles for the current page, search, and a link to the help centre (`components/help/HelpButton.tsx`, `GET /me/help/articles?route=`).
* **Help centre page** (`/portal/help`, `/admin/help`): articles by section, search, role manuals as PDF (Arabic or English), support channels, service levels P1–P4, the person's tickets and their Saaed status, replay of the tours.
* **Role manuals as PDF** (`GET /me/help/manuals/{role}/pdf?lang=`): mPDF, branded, cover, contents, built from the **current published versions**; a person may download the manuals of the roles they hold (administrators: all).
* **Guided tours** (`GET/POST/DELETE /me/tours`): a welcome tour per role on first sign-in and «What's new» for the current release (`HelpService::RELEASE`); dismissible, remembered per person. The mobile app shows the same tours.
* **Support**: phone, email and Saaed link come from configuration (`TEDC_SUPPORT_PHONE`, `TEDC_SUPPORT_EMAIL`, `TEDC_SUPPORT_SAAED_URL`); **the page says "Set by the centre" until they are supplied**. «Report a problem» (Phase 13) opens from the help page.
* **Editor** (`/admin/help-articles`, permission `help.manage`): roles, pages, screenshots and video upload, versions with restore, reader feedback (helpful yes/no with comment) ranked worst first.
* **Mobile**: help centre, article reader (text, screenshots, video link, feedback), manuals as PDF, support channels, tour.

### Interactive e-kits (`EKT-01`)
* Sources in `backend/resources/ekits/kits.json` (it ships with the API, so the admin screen works in production; two kits: «Using the Educational Training Portal» and «Effective classroom questioning»), with trainer guide, trainee guide and a session plan.
* `php artisan tedc:ekits-build [--rebuild] [--only=CODE] [--export=DIR]` publishes each kit as an e-course (a module per chapter: reading + «check yourself» quiz, then a final assessment drawn at random from a question bank, three attempts, 70 % pass mark, automatic certificate) and exports **SCORM 2004 4th edition** packages in Arabic and English.
* The SCORM package: `imsmanifest.xml` with a SCO per chapter and a final SCO (scaled pass marks 50 % / 70 %), `cmi.completion_status`, `cmi.success_status`, `cmi.score.*`, language and direction per page, skip link, native keyboard-operable controls and a live region for feedback. It also works outside an LMS.
* Admin page `/admin/ekits`: publish, rebuild, download SCORM (`GET /admin/ekits/{code}/scorm?lang=`).
* A test publishes a kit and completes it as a trainee through the real endpoints up to the certificate.

### Deliverables pack (`DLV-01` … `DLV-07`)
* `docs/deliverables/01…07` (Arabic first, English copy, version table, approval block): As-Is/To-Be, needs/scope/plan, UX and architecture, release management (+ `CHANGELOG.md`), training and adoption plan (with Train-the-Trainer), test plan, go-live/handover/QA certificate/SLA, UAT template.
* `php artisan tedc:deliverables [--format=md|docx|pdf|all] [--out=DIR] [--results=FILE]` generates `brd.generated.md` (a section per module with acceptance criteria and an «Open items» list), `traceability.generated.md` (requirement → evidence → test files) and `test-report.generated.md`, and exports every document to **.docx** (bilingual, right-to-left per paragraph) and **PDF**.
* The traceability matrix links a requirement to the test files of the phase that closed it (`docs/rfp/phase-tests.json`, rebuilt by `python3 scripts/rfp_phase_tests.py`) and to test files that mention the same classes or routes. It is a lead for the reviewer, not a proof of coverage.

## Tests
`HelpCentreTest` (12), `EKitsTest` (7), `DeliverablesTest` (6); mobile `test/help_test.dart`.

## Limits — said plainly
* **No real screenshots or screen-recording videos were produced.** The editor, the upload and the PDF/mobile display exist; someone must capture the screens (the starter articles are text and numbered steps).
* **Contact details** for support are not known: they must be supplied.
* **The two e-kit topics are proposals** and the content is a compact first version (one to two knowledge-check questions per chapter, five to six final questions). The centre must agree the topics and enrich the content. **Not built:** H5P interactions, interactive video with embedded questions, drag-and-drop activities, captions (there is no video), xAPI statements from the SCORM package. The SCORM package was checked with the repository's own manifest parser and structure tests, **not against an LMS or ADL's conformance suite**.
* **As-Is analysis needs workshops** with the centre; every As-Is item is marked ⚑ until confirmed. The needs assessment, scope and plan are drafts for the centre's review.
* **UAT has not been run**, **no load test has been run**, no VAPT has been performed (see `docs/security/`). The test report says so.
* **Alpha / Beta / Final releases** are defined (gates, exit criteria, sign-off) but not tagged: tagging happens at each milestone with the centre.
* **Arabic:** the pack is Arabic first with an English copy, but long tables (BRD, traceability, compliance sheet) are in English with Arabic headings; a full professional Arabic translation is outstanding.
* **Training and Train-the-Trainer sessions are services, not software**: the plan exists, the sessions have not been held.
* Flutter code was checked only by CI (Flutter is not installed on the development machine).

## Requirement status after this phase
`UTR-02` available (plan). `UTR-01`, `UTR-03`, `EKT-01`, `DLV-01` … `DLV-07` stay **partial** for the reasons above.
