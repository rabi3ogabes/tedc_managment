# Phase 21 — Learning engine completion: offline learning, adaptive practice, a production-grade assistant, TEDC as an LTI tool

> Run after Phase 20. All rules in `00_MASTER_PROMPT.md` apply. Skills: `test-driven-development`, `systematic-debugging`, `security-review` (offline exams, LTI), `mobile-native`, `frontend-design`, `claude-api` (any model call; default to the in-country provider adapters already in `AiGateway`), `verification-before-completion`.

## Goal
- A trainee can **download** lessons and an allowed exam to the app, **learn and sit the exam with no connection**, and have everything sync safely later.
- Practice questions get **harder or easier with the learner's mastery**.
- The trainee assistant uses **proper vector search and an in-country model** when one is configured, streams its answers, and is measured against a golden question set.
- Other platforms can **launch TEDC as an LTI 1.3 tool**.
- Two learner-integrity gaps found on 10 Oct are closed in the app.

## Closes
`TYP-12` offline learning · `AI-03 ★` adaptive content · `AI-05 ★` ML assistant · `TEC-08 ★` LTI 1.1 / 1.3 with Deep Linking (the tool side).
**Linked fixes (not separate RFP IDs; they protect TYP-13 and CNT-01):**
- **(a)** The app opens presentations externally and "confirm done" (`POST me/lessons/{id}/complete`) skips both `min_view_percent` and `min_seconds_per_slide`.
- **(b)** `require_fullscreen` is enforced only on the web player.

## What exists today (verified 11 Oct 2026)
- **Offline (server).** Behind the `offline_mobile` flag: `GET me/courses/{registration}/offline-manifest` and `POST me/sync` (`app/Http/Controllers/Api/V1/Me/MyOfflineController.php` → `OfflineService`; idempotent by batch id; progress never goes down).
- **Offline (app).** `mobile/lib/core/offline_queue.dart` (merge, idempotency key, persistence) is built and tested. **Missing:**
  - downloading media and PDFs to the device, **encrypted** with an expiry;
  - playing or reading them offline;
  - **offline exams** (download, answer and submit later with integrity checks);
  - a "Downloads" screen to manage storage.

  `pubspec.yaml` has `dio`, `path_provider`, `flutter_secure_storage`, `video_player` and `file_picker`, but no crypto package and no PDF renderer.
- **Adaptive.** `app/Ai/Adaptive/{MasteryService,AdaptivePath,RemedialBuilder}.php` provide mastery per competency, test-out/skip and remedial rules, and a personal path. Assessment sections (`AssessmentService` lines ~21, 71–100) support `selection = fixed | random` with `difficulty_mix`. **Practice draws ignore mastery.** Remedial lessons are recommended, not tailored.
- **Assistant.** `app/Ai/Assistant/TraineeAssistant.php` and `app/Ai/Rag/{Chunker,Indexer,LocalEmbedder,Retriever}.php` use local 256-dimension hashed vectors stored as JSON in `embeddings` (migration `005300_ai_completion.php` line ~127), with cosine similarity in PHP.
  - `App\Services\Ai\AiGateway` (`text`, `json`, `complete`, `catalog`, `test`) routes tasks to configured connections (Azure OpenAI and others) through `AiModelSettings`.
  - **Missing:** pgvector, neural embeddings, provider streaming (the answer is generated whole and then chunked), a retrieval quality benchmark, and a run against a real in-country endpoint.
- **LTI.** `app/Services/Lti/{LtiService,Jwt}.php` and `LtiController`/`LtiToolController` implement TEDC **as a platform** (LTI 1.3 OIDC login, `id_token`, JWKS with rotation, Deep Linking 2.0, AGS, NRPS; LTI 1.1 launch and outcomes). **TEDC as a tool** (another LMS launching TEDC content) is not built.
- **App lesson player.** `mobile/lib/features/course/lesson_screen.dart`: `_VideoLesson` enforces seeking, speed, pause-on-background and (since 10 Oct) the pause limit. `_SlidesLesson` (line ~366) opens the file externally and calls `onConfirm` → `POST me/lessons/{id}/complete`.

## 1. Data model (migration `2026_01_01_006100_offline_adaptive_lti_tool.php`)
- **`assessments`:** add `allow_offline` (bool, default false), `offline_window_hours` (int, default 72) and `adaptive` (bool, default false; only for kinds `practice` and `quiz`).
- **`offline_exam_tokens`:** `id`, `assessment_id`, `registration_id`, `device_id`, `issued_at`, `expires_at`, `nonce` (unique), `question_ids` (json), `seed`, `used_at`, `attempt_id` (nullable).
- **`assessment_attempts`:** add `delivery` (already varchar(10): `online|offline`, verify the length), `device_elapsed_seconds` and `client_started_at`.
- **`embeddings`:** keep the JSON vectors. When the driver is PostgreSQL **and** `CREATE EXTENSION IF NOT EXISTS vector` succeeds, add a `vec vector(<dim>)` column and an HNSW index in a guarded migration that no-ops on SQLite. Store `model` and `dim` on each row, so mixed models are never compared.
- **`lti_platforms`** (TEDC as a tool): `id`, `name`, `issuer`, `client_id`, `deployment_ids` (json), `auth_login_url`, `auth_token_url`, `jwks_url`, `status`, `settings` (json: default role mapping, which programmes it may reach), timestamps. Unique (`issuer`, `client_id`).
- **`lti_tool_launches`:** `id`, `platform_id`, `sub`, `user_id`, `context_id`, `resource_link_id`, `target` (program/lesson id), `ags_lineitem` (nullable), `created_at`.

## 2. Rules and services
### Offline (TYP-12)
- **Manifest v2.** Each downloadable item carries a size, a SHA-256 checksum, an expiry (from settings, default 14 days) and a **per-device content key**. The key is wrapped with a key derived from the session and stored only in `flutter_secure_storage`. Items whose rights forbid download (library rights, `downloadable=false`) are never listed. Revoking a registration or a device makes the next sync delete its content.
- **App storage.**
  - Download with resume (dio ranges), verify the checksum, and encrypt at rest with AES-GCM (the `cryptography` package, chunked so large videos stream).
  - Playback decrypts to a private temporary file that is deleted on close (`video_player` needs a file).
  - PDFs render in the app (e.g. `pdfx`), page by page, reporting slide progress offline into the queue (this is linked fix **a**).
  - A **Downloads** screen shows per-course storage, expiry countdown, "remove", "download on Wi-Fi only" and the total device space used.
- **Offline exams** (only when `allow_offline` is on):
  - `POST me/assessments/{a}/offline-token` issues a signed package: questions **without answers**, a seed, the time limit, an expiry within `offline_window_hours`, and a single-use nonce bound to the device.
  - The app stores answers encrypted and records a monotonic elapsed time (not wall clock).
  - `POST me/attempts/offline` submits `{token, answers, device_elapsed_seconds, client_started_at, client_submitted_at}`. The server verifies the signature, the nonce, the device and the expiry, and that elapsed ≤ time limit + grace. It grades with the normal grading and flags implausible timing (elapsed far below the minimum, start after expiry) for the invigilation queue instead of silently accepting.
  - Each token gives exactly one attempt, attempt limits still apply, and essays go to manual grading as usual.
- **Sync** extends `OfflineService::sync` for slide-view and video-segment events recorded offline. The server applies the same crediting rules as online (max speed, seek lock, minimum seconds per slide) using the recorded timestamps, and rejects out-of-order or duplicate events idempotently.

### Adaptive practice (AI-03)
- For sections in an `adaptive` assessment: choose each next question's difficulty from the learner's mastery of that question's competency (`MasteryService`). Below 40 % mastery draws easy; 40–75 % medium; above 75 % hard. All thresholds are configurable.
- Step one level up after two correct answers in a row and one level down after a wrong one (a simple, explainable staircase). The attempt shows "why this question" in plain words.
- Remedial lessons drafted by `RemedialBuilder` are **assigned** to the learner who needs them (visible to them, not to everyone), and disappear from their path once the competency is mastered.
- Teachers see each learner's difficulty trajectory in the attempt review.

### Assistant (AI-05)
- **`EmbeddingStore` interface** with `JsonCosineStore` (today) and `PgVectorStore` (PostgreSQL with the extension), selected automatically and overridable in settings.
- **`Embedder` interface** with `LocalEmbedder` (today) and `ModelEmbedder` (the embeddings deployment configured in AI model settings; Azure OpenAI Qatar is the default target). `tedc:ai-reindex --model=` re-embeds in batches with progress and resumability.
- **Streaming.** Add `AiGateway::stream()` for providers that support server-sent streaming. `POST me/assistant/messages` streams over SSE (the realtime SSE driver exists) and falls back to whole answers. Cancel on disconnect.
- **Golden set.** `backend/resources/ai/golden-questions.json` holds 40 Arabic and English questions with expected source documents. `tedc:ai-eval` reports retrieval hit@3 / hit@5, citation accuracy and refusal correctness for off-topic questions. Thresholds go in settings, and CI runs it with the local embedder (deterministic).
- Residency and redaction rules from Phase 15 stay in force (`AiGuard`, `Redactor`, in-country by default).

### TEDC as an LTI tool (TEC-08)
- **Tool endpoints:**
  - `GET|POST lti/tool/login` (OIDC third-party initiation: validate the issuer and client against `lti_platforms`; state and nonce single-use, stored in `LtiState`);
  - `POST lti/tool/launch` (validate the platform `id_token` against its JWKS, `aud`, `azp`, `nonce`, `exp`/`iat` and the deployment id; map or create the user just in time by `sub` + email; open the target programme or lesson inside TEDC with an embedded-friendly layout);
  - `GET lti/tool/jwks` (TEDC's tool key set);
  - `POST lti/tool/deep-link` (content selection UI → signed `LtiDeepLinkingResponse` with selected programmes or lessons).
- **AGS and NRPS clients:** when the launch carries a line item, push TEDC scores and completion back with client-credentials JWT assertions; read the roster through NRPS when asked.
- **Registration UI:** *Settings → Integrations → LTI platforms* (name, issuer, client id, deployment ids, login, token and JWKS URLs, "copy tool configuration" with TEDC's URLs, JWKS and redirect URIs) and a test-launch button.
- **LTI 1.1** tool launch with OAuth 1.0a validation and outcomes, for old LMSs (reuse `LtiService::oauthSign/signature`).
- **Loopback test.** TEDC's own **platform** implementation launches TEDC's **tool** in tests (two registrations in one database). This proves the full flow without a third party.

### Linked fix b: full screen in the app
For lessons with `require_fullscreen`, the app's video plays in a dedicated landscape full-screen route. Leaving it pauses the video and is reported with `fullscreen=false` (the server already credits no time then).

## 3. API and permissions

| Method & path | Permission |
|---|---|
| `GET me/courses/{registration}/offline-manifest` (v2), `POST me/sync` (extended) | registered; flag `offline_mobile` |
| `POST me/assessments/{assessment}/offline-token`, `POST me/attempts/offline` | registered; `allow_offline`; flag `offline_mobile` |
| `PUT admin/assessments/{assessment}` (adds `allow_offline`, `adaptive`) | `assessments.manage` |
| `POST me/assistant/messages` (SSE when `Accept: text/event-stream`) | as today |
| `POST admin/ai/reindex`, `GET admin/ai/eval` | `ai.settings` (exists) |
| `GET/POST/PUT/DELETE admin/lti-platforms`, `POST admin/lti-platforms/{platform}/test` | `lti.manage` |
| `lti/tool/login`, `lti/tool/launch`, `lti/tool/jwks`, `lti/tool/deep-link` | public (signature-validated) + rate limit |

## 4. Web
- **Assessment builder:** "Allow offline in the app (window: N hours)" and "Adaptive practice" switches, with plain explanations and a warning that offline attempts are checked for timing.
- **Invigilation queue:** offline attempts flagged for implausible timing.
- **AI settings:** the embedder and store in use, a reindex button with progress, and the golden-set scores with a trend.
- **Assistant chat:** a streaming answer (token by token) with a stop button, keeping the citations.
- **Settings → Integrations → LTI platforms:** a list, a form, "copy configuration" and a test launch. An embedded layout for tool launches, without admin chrome.

## 5. Mobile
- **Downloads:** a course screen "Download for offline" with progress per item; a Downloads screen; offline playback and reading; an offline badge; Wi-Fi-only switch.
- **Offline exam:** take the exam with no network (a clear banner, timer by monotonic clock, autosave), submit when back online, and show the result or "awaiting grading".
- **Slides:** an in-app PDF viewer that reports each slide after the author's minimum time (online or queued offline). "Confirm done" is only for non-PDF files.
- **Full screen:** the landscape player for `require_fullscreen` lessons.
- **Adaptive practice:** "why this question" chip.

## 6. Tests (write first)
- **`OfflineLearningTest`:** the manifest lists only downloadable items, with checksum and expiry; a revoked registration gets a delete instruction; sync is idempotent and never lowers progress; offline slide events obey `min_seconds_per_slide`.
- **`OfflineExamTest`:**
  - a token is single-use and device-bound;
  - an expired token is refused;
  - an elapsed time over the limit is refused;
  - an implausibly short time is flagged;
  - answers are graded the same as online;
  - the attempt limit is respected;
  - the token never contains correct answers (assert on the payload).
- **`AdaptivePracticeTest`:** low mastery draws easy and high draws hard; the staircase moves up after 2 correct answers and down after a wrong one; remedial lessons are visible only to the learner they are assigned to and vanish when mastered.
- **`AssistantRetrievalTest`:** the store interface works with JSON on SQLite (PgVector is tested in the postgres CI job with the extension, or skipped with a clear message when unavailable); reindex is resumable; the golden-set evaluation meets thresholds with the local embedder; streaming emits SSE chunks and a final message with citations.
- **`LtiToolTest`:**
  - loopback launch (TEDC platform → TEDC tool) creates or maps the user and opens the lesson;
  - a bad signature, a wrong `aud`, a replayed nonce and an unknown deployment are refused;
  - deep linking returns a signed response;
  - AGS posts a score;
  - LTI 1.1 signature validation works.
- **Mobile:**
  - an encryption round-trip unit test; a corrupted download fails its checksum;
  - widget tests for the Downloads screen, the offline exam submit-later flow and the PDF slide reporting;
  - presentations no longer call `complete` for PDFs.

## Review focus
1. **Clock tampering offline.** Rely on monotonic elapsed time plus server plausibility checks, never on device wall-clock alone.
2. **Key handling.** Content keys never leave secure storage in clear and are wiped on logout or device revocation.
3. **Storage pressure.** Downloads fail gracefully on low space, and partial files are cleaned up.
4. **LTI identity mapping.** A launch must never take over an existing account whose email matches unless the platform is trusted for that domain (setting). Otherwise, link only after the user confirms.
5. **pgvector absent** (local SQLite, CI SQLite job): everything still works through the JSON store.

## Definition of Done
- **TYP-12:** encrypted downloads, offline playback and reading, offline exams with integrity checks, and safe sync, in the app; tests pass. → **Available**
- **AI-03:** adaptive practice difficulty and assigned remedial lessons, explained to the learner. → **Available**
- **AI-05:** pgvector store, model embeddings, streaming and the golden-set evaluation are built and pass with local and mock providers. → stays **Partial — Ready, waiting for:** the in-country model endpoint (Azure OpenAI Qatar or the Ministry's approved provider) and a golden-set run against it. It becomes Available after that run meets the thresholds.
- **TEC-08:** TEDC launches as an LTI 1.3 tool (with Deep Linking, AGS, NRPS) and as an LTI 1.1 tool; loopback tests pass. → **Available**
- **Linked fixes a and b** are done in the app.
- The gate is green, `docs/rfp/phase-21-learning-engine-completion.md` is written, the register is updated, and the change is pushed with everything green.
