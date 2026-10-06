# Phase 10 — E-learning standards, content lifecycle, library and offline

**Closes:** TYP-15, CNT-01, TEC-09, TEC-11, TYP-17, CNT-03, CNT-04, KIT-03, KIT-05, KIT-06 · **Partly:** TEC-08, TEC-13, TYP-14, CNT-05, TYP-12 (see the gap register).

## Content packages (10.1–10.4)
- Upload a zip (SCORM 1.2 / 2004, cmi5, xAPI/TinCan, H5P, HTML5, Common Cartridge). The service refuses zip-slip paths, executables, oversize and empty packages, detects the standard, parses the manifest (organisation tree, units, cmi5 `moveOn`) and stores the files privately. Large files can go through a signed upload and `POST /admin/packages/process`.
- Files are served by a **same-origin proxy** `/content/{token}/{package}/{path}` (signed, short-lived token in the path so relative links work; right MIME types; byte ranges; `nosniff`; frame-ancestors self). Because the package runs on the app's origin (SCORM needs it), only trusted staff with `packages.manage` may upload.
- **SCORM**: the player uses scorm-again; commits are mapped to completion, success, score, total time, suspend data and location; unfinished attempts resume, finished ones start a retake; a lesson can require a pass.
- **xAPI LRS** (`/api/v1/xapi`): statements POST/PUT/GET with validation, idempotent ids, 409 on a conflicting resend, voiding, agent/verb/activity/registration/since filters, paging; state and profile documents; per-credential Basic auth (read / write). TEDC's own learning activity (lesson completion, package launches) is recorded as xAPI too, and can be forwarded to an external LRS.
- **cmi5**: launch with fetch URL and one-time auth token; the unit's statements update the session and complete the lesson by its `moveOn` rule.
- **IMS Caliper 1.2**: events queued as they happen and sent in batched envelopes with bearer auth and retry accounting (`tedc:caliper-flush`).
- **H5P** (h5p-standalone) and **HTML5** (postMessage `tedc:complete`) with xAPI capture and completion.

## LTI (10.5)
TEDC as platform: **LTI 1.3** (OIDC third-party login, RS256 `id_token`, JWKS with key rotation, nonce/state single use, **Deep Linking 2.0**, **AGS** score passback with client-credentials JWT assertion and replay protection, **NRPS**) and **LTI 1.1** (OAuth 1.0a signed launch, Basic Outcomes with body-hash and nonce checks). Tools are registered in *Standards and integrations* with copy-ready platform details. *TEDC as an LTI tool* (other LMSs launching TEDC) is not built.

## QTI and Common Cartridge (10.6)
QTI 2.1 export and import (choice, multiple, true/false, text entry, extended text, match, order, inline choice, multi-blank text) and QTI 1.2 import; unsupported interactions are listed, never dropped silently; external entities are never read. Common Cartridge 1.1–1.3 / thin CC: tree preview, selective import (pages → article lessons with scripts/frames/handlers stripped, files → materials, QTI → a question bank, web links → link lessons, LTI links → inactive LTI tools, discussions skipped until Phase 14), with an import log.

## Lifecycle, sharing, library, providers (10.7)
- **Lesson versions**: snapshots, publish with "keep" or "move" for learners in progress, diff, restore, archive. Pinning covers the lesson content (text, media, package, settings); quiz questions are not pinned.
- **Kits → several programs** (`kit_program`, migrated from the old single link, optional pinned version).
- **Job groups** (rules over job titles, categories, schools, subjects) and **sharing** (`resource_shares`) under **per-role policies**: allowed resource and target types, reshare, download; items whose rights forbid download are view-only; every share is audited; "Shared with me".
- **Digital library**: items with bilingual metadata, rights (owner, licence, download, print, watermark, embargo), audience in eligibility-rule syntax, Arabic-folding search, shelves, ratings, reader with watermark, downloads per rights, collections, counters. Search runs on a normalised text column with `LIKE` (a PostgreSQL `tsvector` index is a later optimisation).
- **External libraries** (Maktabati, Qatar National Library): configured search URL (deep link) or JSON API; records imported as link items; demo driver.
- **Providers** (Coursera, edX, Udemy Business, LinkedIn Learning): generic REST adapter configured in Settings (catalogue and completions paths) plus a demo driver; catalogue → program; completions count hours. **Programs on other platforms**: open the course from TEDC, send evidence, the centre approves, hours count.

## Offline (10.8)
Server: `GET /me/courses/{r}/offline-manifest` (downloadable lessons, expiry from settings) and `POST /me/sync` (idempotent by batch key; never lowers progress), behind the `offline_mobile` flag. Mobile: the offline queue (merge, idempotency key, persistence) is built and tested; **encrypted downloads, the download manager and offline exam answers in the app are not built yet.**

## Permissions
`packages.manage`, `lti.manage`, `standards.manage`, `library.manage`, `library.view`, `sharing.manage`, `providers.manage`, `job_groups.manage`.

## Limits
- No SCORM / H5P vendor package or ADL LRS conformance run yet (unit-tested with generated packages); do these on the first real packages.
- Vercel's PHP function limits large package files (single files over ~4.5 MB through the proxy); use a storage-backed host for big video inside packages.
- Real provider and library APIs need the Ministry's credentials and specifications.
