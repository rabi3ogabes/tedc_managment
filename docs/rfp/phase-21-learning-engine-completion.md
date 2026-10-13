# Phase 21 — Offline learning, adaptive practice, the assistant's retrieval, TEDC as an LTI tool

Closes: `TYP-12` · `AI-03 ★` · `TEC-08 ★`; brings `AI-05 ★` to *Ready*. Also closes the two learner-integrity gaps found on 10 Oct
(**a**: presentations confirmed without being viewed; **b**: full screen enforced on the web only).
Prompt: `docs/rfp/closeout/21_LEARNING_ENGINE_COMPLETION.md`. Delivered in six commits: server parts 1–4, the web, the app.

## What was built

### Offline learning (`TYP-12`)
**Server** (migration `2026_01_01_006100_offline_learning.php`, `App\Services\Content\OfflineService`, `OfflineExamService`):
* **Manifest v2** (`GET me/courses/{registration}/offline-manifest?device_id=&platform=`): each downloadable item has its size, SHA-256,
  expiry (Settings → Standards → Offline, 14 days by default), the player's rules (seeking, speed, pauses when hidden, watch share, full
  screen, time per slide) and a signed address. Items the author keeps online are listed without an address and with the reason. Each phone
  gets its own random 256-bit content key, stored encrypted and sent only to that phone. A withdrawn registration answers `withdrawn` (delete
  that course); a phone removed from *my offline devices* answers `revoked` (delete everything, forget the key).
* **Sync** (`POST me/sync`): idempotent by batch id, never lowers progress; offline slide views are credited by the time the app recorded,
  against the minimum seconds per slide, and an older event is refused as out of order. The phone sends the courses it keeps (`held`) and
  hears which to delete (`purge`) and whether it was revoked (`device_revoked`).
* **Offline exams** (`allow_offline`, `offline_window_hours`): `POST me/assessments/{a}/offline-token` gives a single-use package bound to the
  phone, signed, without answers or explanations; `POST me/attempts/offline` checks signature, nonce, device and expiry, refuses an elapsed
  time over the limit plus grace, grades exactly as online and flags implausible timing (too fast, started or finished after expiry, a clock
  before the package existed) for the invigilators. Attempt limits count the packages still out. Adaptive and in-centre assessments are never
  offline.

**App** (`mobile/lib/core/offline/`):
* `OfflineStore`: downloads resume from the partial file (HTTP `Range`), are checked against the SHA-256 (a mismatch is retried once from
  zero, then refused and cleaned up), then sealed with **AES-256-GCM** in 1 MB chunks, each bound to its position and to being last
  (`ContentCipher`, format `TEDC1`), on another isolate. Articles are sealed too. Running out of space stops cleanly and deletes the partial
  file. Expiry is checked against the latest time the phone has seen (and the server's time when it answers), so turning the clock back keeps
  nothing longer. Content left without its key (a phone restored from a backup) is deleted, never shown or reused.
* Playback decrypts to a private temporary file that is deleted when the player closes (and swept at the next start if the app was killed).
* `OfflineSync`: the progress queue lives in the keystore; a batch keeps its key and its items until the server answers, so a retry never
  counts twice and new progress is never hidden behind a key the server already answered; batches of at most 500 events.
* `OfflineExams` + `ExamClock`: packages and answers sealed with a key of this phone's keystore; autosave every 5 seconds and when the app
  leaves the screen; time measured with the monotonic clock while the app is open, time away counted as an online timer would (a clock turned
  back adds nothing); when the time is up the answers are kept and handed in; a refused hand-in keeps only its reason.
* `OfflineCoordinator`: when the connection returns and when the app comes back to the screen, it sends the progress, hands in exams answered
  offline, applies the server's purge and deletes what expired.
* **Screens:** *Download for offline* on a course (progress per lesson, then what is kept and until when, *Update*, *Manage*), a mark on each
  lesson kept offline, **Downloads** (space used, each course with size and expiry countdown, *Remove*, *Wi-Fi only*, offline exams with
  their state), lessons opened from the phone with a clear offline banner, the offline exam runner (intro, banner, timer, autosave, *Hand in
  now*), and on *My assessments* a *Download* / *Take offline* / *Hand in now* strip for assessments that allow it.
* Signing out deletes every download, exam answer and key; when something learned or answered offline was not sent yet, the app says so
  first.

### Linked fix a — presentations in the app
PDFs open in the app page by page (`SlidesLesson`, pdfx). A slide is reported once it has been on screen for the lesson's minimum time
(`SlideReporter`), online (`POST me/lessons/{id}/slide`) or into the offline queue with its time; completion follows from the slides seen.
*Confirm done* remains only for files that cannot be shown page by page (PowerPoint). The server refuses `complete` for a PDF presentation with
known slides (`view_slides`), so older app versions cannot skip it either.

### Linked fix b — full screen in the app
A lesson that must be watched full screen plays only in a landscape, immersive player (`FullscreenVideoPage`). Heartbeats carry
`fullscreen: true` there and `false` outside; leaving the player pauses the video. The same rule applies to offline playback.

### Adaptive practice and assigned review lessons (`AI-03 ★`)
* Server (`App\Ai\Adaptive\AdaptivePractice`, `RemedialAssignments`, migration `…006110_adaptive_practice.php`): one question at a time;
  each competency starts from the learner's mastery (easy below 40 %, hard above 75 %, medium otherwise or without evidence), goes up after two
  correct answers in a row and down after a wrong one; competencies take turns; every question carries its reason in Arabic and English.
  A quiz without immediate feedback never says whether an answer was right. Stopping early scores out of the planned length. Review lessons
  are assigned below 60 % mastery and leave at 80 % (all thresholds in Settings → AI), appear only for that learner (course, path, offline
  manifest) and are never locked behind the course order.
* Web: the adaptive runner with the level, the competency, *why this question*, right or wrong where allowed and a path strip; the teacher's
  attempt review with the difficulty path (right to left in Arabic) and every reason; builder switches with explanations.
* App: the same one-question-at-a-time practice with the level and competency chips, *Why this question?*, *Check my answer* /
  *Next question*, the path strip and *Finish the practice*; review lessons marked *Review lesson for you* with the reason in the course outline.

### The assistant's retrieval (`AI-05 ★`, Ready)
Store and embedder interfaces (JSON cosine everywhere; pgvector with an HNSW index per embedder where PostgreSQL has the extension; the local
embedder, now 1024 numbers with light Arabic stemming and character trigrams; any embeddings model from Settings → AI models). Vectors record
their embedder, so models are never mixed, and the local vectors are always kept as the fallback. `tedc:ai-reindex` is resumable and switches
the embedder only when nothing is missing. Answers stream over server-sent events with a Stop button that keeps what was said, and personal
identifiers stay redacted across pieces. A golden set of 40 Arabic and English questions is measured by `tedc:ai-eval` (hit@3, hit@5,
citation accuracy, refusal of off-topic questions) in CI with the local embedder.

### TEDC as an LTI tool (`TEC-08 ★`)
LTI 1.3 (OIDC login, `id_token` checks, Deep Linking 2.0 with a signed response, AGS scores, NRPS roster) and LTI 1.1 (OAuth 1.0a launch,
Basic Outcomes), with a loopback test through TEDC's own platform side. Accounts map through the platform's id; an e-mail match is linked at
once only for a trusted domain, otherwise after the person signs in and confirms; staff accounts are never signed in by a launch. Settings →
Standards → *Platforms that launch TEDC* (configuration to copy, registration, programmes reached, trusted domains, roles, setup test).

## Decisions
* **Four migrations instead of one** (`006100` offline, `006110` adaptive, `006120` retrieval, `006130` LTI tool): each part shipped and was
  tested on its own.
* **The content key is not wrapped with a session-derived key.** It travels only over TLS to the one phone it belongs to and is kept only in the
  keystore (iOS Keychain *this device only*, Android Keystore); a session-derived wrap would make every download unreadable after each token
  refresh without adding protection at rest. It is deleted on sign-out and on revocation.
* **Offline exam answers use a key of the phone's own keystore**, separate from course content, so an exam does not depend on downloading a
  course.
* **`revoked` and `withdrawn` are now separate in the manifest** (both used to say `revoked`), and sync answers `device_revoked`: a withdrawn
  course deletes only that course; a revoked phone deletes everything and registers anew on its next download.
* **Time away from an offline exam counts**, as an online timer keeps running; the elapsed time sent is capped at the time limit when the time
  ran out on the phone, so an honest learner is never refused for it.
* **Launch pages may be framed only by the registered platform**, and TEDC opens in its own window; the rest of TEDC still refuses framing.
* **Pure-Dart AES-GCM** (the `cryptography` package) on a background isolate: no native code to maintain; see Limits for speed.

## Also fixed
* Every `throttle:N,M` route shared one counter per person, so a busy page made low-limit routes answer 429; each route now counts on its own
  (`ThrottlePerRoute`).
* `GET admin/assessments/{id}` always failed; tall modal windows had their top out of reach; learners saw option ids instead of option text in
  their result review; review lessons could be locked in sequential courses; an on-screen question left unanswered showed as "pending grading".

## Tests
* Server: `OfflineLearningTest` (7), `OfflineExamTest` (8), `AdaptivePracticeTest` (13), `AssistantRetrievalTest` (9; the pgvector test runs in
  the PostgreSQL CI job), `LtiToolTest` (9), `ThrottlePerRouteTest` (1).
* App: `offline_crypto_test` (round trip, chunked files, tampering, cut-off, wrong key), `offline_store_test` (10: encrypted at rest, checksum
  refusal with clean-up, resume from the partial file, no space, Wi-Fi only, withdrawn, revoked, expiry and the clock turned back, content
  without its key, sign-out), `offline_queue_test` (6), `offline_sync_test` (5), `offline_exams_test` (7, with the exam clock),
  `slides_lesson_test` (4: minimum time per slide, PDF without *confirm*, offline queue, other files), `offline_screens_test` (Downloads; an
  offline exam saved, waiting without a connection, handed in later).
* The web screens were exercised in the running app in Arabic and English, desktop and phone width.

## Limits
* **The app's offline flows were proved by unit and widget tests, not on a phone in flight mode**: there is no device or emulator here, the
  Android APK is built by CI, and the iOS build has not been run (no Xcode). A run on a real phone is part of user acceptance.
* **Speed of sealing and opening large videos was not measured on a phone.** Pure-Dart AES is several times slower than native code; a
  long video takes noticeably longer to prepare before it plays offline (the screen says it is preparing it).
* HTML5 and H5P packages are listed by the manifest but still play online in the app; PowerPoint files need a connection (only PDFs are shown
  page by page offline).
* Offline playback enforces seeking, speed and full screen in the app's player; the pause limit is counted by the server and so applies online
  only.
* **AI-05 stays Partial — Ready, waiting for:** the in-country model endpoint (Azure OpenAI in Qatar or the Ministry's approved provider) and a
  golden-set run against it; it becomes Available when that run meets the thresholds.
* The LTI tool was proved by the loopback test; a launch from the Ministry's actual LMS (Moodle, Blackboard or Canvas) is part of go-live.
