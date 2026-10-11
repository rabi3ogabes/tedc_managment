# Phase 27 — Adoption content and deliverables: real manuals, enriched e-kits, Arabic architecture with real prices, a signed-off document pack

> Run last. All rules in `00_MASTER_PROMPT.md` apply. Skills: `webapp-testing` (Playwright screenshots and videos), `anthropic-skills:docx` and `anthropic-skills:pdf` (editable Word/PDF deliverables), `doc-coauthoring` (workshop packs), `frontend-design` (annotation style), `verification-before-completion`.

## Goal
Everything a Ministry reviewer opens should look finished:
- role manuals with **real, annotated screenshots and short videos** captured automatically from the running system, in Arabic and English, regenerated whenever the UI changes;
- the two interactive e-kits **enriched** (interactive video, drag-and-drop, captions, xAPI) and proven in a real LMS;
- the HLD, LLD and bill of materials **fully in Arabic** with **real Azure Qatar Central prices**;
- the deliverables pack in **editable Word** with English copies, workshop packs to confirm the As-Is, a UAT executed as automated end-to-end tests, and release and go-live documents filled with what is known.

## Closes
`UTR-01` role manuals with screenshots, videos and PDF · `EKT-01` two interactive e-learning kits · `NFR-04 ★` HLD/LLD, bill of materials, sizing and bandwidth · `DLV-01` As-Is / To-Be · `DLV-02` needs assessment, scope, plan, BRD · `DLV-03` UX design and architecture · `DLV-04` Alpha, Beta, Final releases · `DLV-05` user manuals and training & adoption plan · `DLV-06` test plan, test reports, bug tracker · `DLV-07` go-live plan, handover report, QA certificate, SLA.

## What exists today (verified 11 Oct 2026)
- **Help centre.** `backend/app/Help/{HelpService,TourCatalog}.php`, 28 bilingual articles (`database/seeders/data/help-articles.php`), a versioned editor with screenshot and video upload (`admin/help/articles/{id}/screenshots|video`), role manuals as branded PDFs (mPDF) and guided tours. **No real screenshots or videos have been captured.** There is no Playwright in `web/package.json`.
- **E-kits.** `backend/resources/ekits/kits.json` holds two kits ("Using the Educational Training Portal", "Effective classroom questioning") with guides and a session plan. `php artisan tedc:ekits-build` publishes them as e-courses and exports SCORM 2004 (`app/Learning/EKits/{EKitSource,EKitBuilder,ScormExporter}.php`).
  - **Not built:** H5P interactions, interactive video, drag-and-drop, captions and xAPI.
  - The packages were never run in a real LMS, and the topics are proposals the centre has not agreed.
  - H5P support exists in the platform (`web/public/h5p`, the `H5p` question type and content packages).
- **Architecture.** `docs/architecture/{HLD,LLD,BOM-and-sizing,environments}.md` are in English with Arabic summaries. **The BOM has no prices** (40 lines).
- **Deliverables pack.** `docs/deliverables/` holds `01`–`07`, `brd.generated.md`, `test-report.generated.md`, `traceability.generated.md` and `uat/template.md`. `php artisan tedc:deliverables --format=docx|pdf` builds editable files. As-Is items are marked ⚑ until workshops confirm them. **No UAT has run and no release is tagged.** Long tables are in English with Arabic headings.

## 1. Automatic screenshots and videos for the manuals (UTR-01, DLV-03, DLV-05)
- **Set-up.** Add Playwright (`@playwright/test`, dev only) under `web/e2e/` with a config that starts the local stack (`api` + `web` from `.claude/launch.json`, or `php artisan serve` + `vite preview`) on a **freshly seeded local database**. Never point it at production.
- **Capture map.** `web/e2e/manuals/capture.spec.ts` reads a capture map from `backend/resources/help/captures.json`: for each help article, which role signs in, which route to open, which steps to perform, which elements to highlight and the callout text (AR/EN).
- **Capturing.** For each article × language (AR, EN) × viewport (desktop 1440, phone 390):
  1. sign in as the role's **local demo** account;
  2. perform the steps;
  3. inject a small annotation layer (numbered brand-colour callouts and soft spotlight masks, styled with the theme tokens);
  4. take screenshots, and record a **≤ 60-second video** for the 10 key flows: register for a programme, approve a nomination, take attendance by QR, issue a certificate, build a course, run a report, submit a PD record, withdraw, ask the trainer, complete an evaluation.
  5. Add WebVTT **captions** in Arabic and English, generated from the step script.
- **Publishing.** `php artisan tedc:help-media-sync` uploads the outputs to each article through `HelpService` (versioned, with alt text from the step), so the role PDFs embed real screenshots and the help centre plays the videos. A CI job (manual dispatch) re-captures when the UI changes and opens a PR with the diffs.
- **Quality bar.** Pixel-perfect RTL; no personal data (synthetic demo names only); dark-mode variants for the app manuals; every image has alt text.

## 2. Enriched e-kits (EKT-01)
- **Decision gate (light):** ask the user whether the centre has agreed the two topics. If not, keep them and label them "proposed" in the kits' metadata.
- **Enrich each kit:**
  - an **interactive video** chapter (H5P Interactive Video, or TEDC's own in-video interactions) with embedded questions;
  - one **drag-and-drop** and one **categorisation** activity (the question types exist: use `categorization` and `ordering`, or H5P Drag and Drop);
  - **captions** (WebVTT, AR/EN) for every video;
  - **xAPI** statements for each interaction, sent to TEDC's LRS.
  - Narration is optional: if added, use AI speech through `AiGateway::speech` with the in-country rule, and get human review.
- **SCORM and cmi5 exports.** `ScormExporter` packages the enriched kit (SCORM 2004 4th ed. and SCORM 1.2) and a cmi5 course. Validate the manifests against the XSDs.
- **Proof in a real LMS.** Run the packages in **Moodle in Docker** locally (official image; import SCORM; complete as a test student; check the score, completion and suspend data). Record screenshots and the gradebook as evidence in `docs/deliverables/ekits-lms-evidence.md`. Also run the cmi5 course against TEDC's own cmi5 launcher.

## 3. Arabic architecture with real prices (NFR-04)
- **Translate** `HLD.md`, `LLD.md`, `BOM-and-sizing.md` and `environments.md` into **full professional Arabic**: Arabic first and English below, with diagrams labelled in both, consistent terminology (a glossary in `docs/architecture/glossary.md`) and Western digits.
- **Price the BOM** with the public **Azure Retail Prices API** (`https://prices.azure.com/api/retail/prices`, filtered by `armRegionName eq 'qatarcentral'`, no authentication):
  - `php artisan tedc:bom-prices` reads the SKUs from `infra/parameters/*.bicepparam` and the BOM, fetches retail prices (USD and QAR, with the exchange rate and its date stated), and writes the priced tables per environment: monthly and yearly, pay-as-you-go vs 1-year and 3-year reservations where they exist;
  - flag SKUs with no Qatar Central price.
- Add the **bandwidth design** (expected traffic per user type from the capacity model in Phase 25, egress costs, Front Door/CDN offload) and a **sizing table** for years 1–3 (+20–30 %/year).
- Produce Word versions (`docx` skill) with tracked versions and an approval block.

## 4. The deliverables pack (DLV-01, 02, 04, 05, 06, 07)
- **DLV-01 / DLV-02:** produce **workshop packs** (`doc-coauthoring`): one per process area with the As-Is statements marked ⚑ turned into confirmation questions, To-Be diagrams and a sign-off sheet, as editable Word files. Update the needs assessment with the platform's real data (counts from the database: schools, employees, programmes, needs), clearly dated. The BRD stays generated from the register.
- **DLV-04:** prepare the **Alpha** release (the gate checklist filled with evidence, release notes from `CHANGELOG.md`, the known-issues list). **Ask the user before creating any git tag or GitHub release** (outward-facing).
- **DLV-05:** the training and adoption plan gets the real manuals (§1), a Train-the-Trainer kit (slides from the manuals, exercises, an assessment) and a session calendar template. Delivering the sessions is a service, not software.
- **DLV-06:**
  - turn `uat/template.md` into **UAT scenarios per role**, each with an automated Playwright twin (`web/e2e/uat/*.spec.ts`) that runs on the seeded local stack in CI (manual dispatch) and produces an HTML report;
  - `tedc:deliverables` attaches the latest run to `test-report.generated.md`;
  - the **bug tracker** uses GitHub Issues with labels matching the SLA severities (P1–P4); create the label set and an issue template (ask before changing repository settings).
- **DLV-07:** fill the go-live plan, handover report, QA certificate template and SLA with everything known (systems, contacts placeholders clearly marked for the centre, measured performance and availability from Phases 25–26 when present), and produce Word/PDF.
- Every deliverable is Arabic first with an English copy, an editable Word version, a version table and an approval block.

## 5. Tests and checks
- **E2E:** the Playwright capture runs green on a seeded stack (CI manual job); the UAT specs pass, and their report is attached.
- **`HelpMediaSyncTest`:** uploads are versioned, alt text is required, and the role PDF embeds the images.
- **`BomPricesTest`:** Retail Prices API fixtures → correct monthly and yearly totals and QAR conversion; the command never calls the network in tests.
- **E-kits:** the manifests validate against the SCORM XSDs; cmi5 launches in TEDC; the Moodle evidence is recorded.
- **Docs:** every `.docx` opens. Render it to PDF and look at each page (`docx` skill workflow) for RTL, tables and fonts.

## Review focus
1. **No personal data** in screenshots or videos: demo data only, and blur anything that looks like a real ID.
2. **Arabic quality:** natural, formal Modern Standard Arabic suitable for a Ministry; consistent with the glossary.
3. **Prices are dated and sourced.** Never present them as a quote.
4. **Screenshots go stale:** the re-capture job and its PR keep them current.
5. **Outward actions** (tags, releases, repository labels, sending documents to the Ministry): ask first.

## Definition of Done
- **UTR-01:** real annotated screenshots and captioned videos in every role manual, AR/EN, regenerated automatically. → **Available**
- **NFR-04:** HLD/LLD/BOM fully in Arabic with English copies, priced from the Retail Prices API for Qatar Central, with bandwidth and three-year sizing, as Word. → **Available**
- **DLV-03:** the UX and architecture document with real screenshots, as Word. → **Available**
- **DLV-06:** the test plan, the automated UAT with its report and the bug tracker set up. → **Available** (manual UAT with centre users is still recommended; record it if it happens).
- **EKT-01:** enriched kits proven in Moodle. → **Available only if** the centre has agreed the topics; otherwise **Partial — waiting for: the centre's agreement on the topics**.
- **DLV-01, 02, 04, 05, 07:** ready packs. → stay **Partial — waiting for:** workshops, sign-offs, the release decision and go-live dates with the centre (name each one in the evidence).
- The gate is green, `docs/rfp/phase-27-adoption-content-and-deliverables.md` is written, the register is updated, `tedc:rfp-status` and `tedc:deliverables` are regenerated, and the change is pushed with everything green. Finish with a **final close-out report**: the before/after counts, every ID's final status with evidence, and the complete list of what the Ministry and the centre must still provide.
