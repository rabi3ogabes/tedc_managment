# Phase 22 — Exam integrity: privacy-preserving face presence (EXM-10)

Closes: `EXM-10 ★` (completes mandatory item 27). Prompt: `docs/rfp/closeout/22_EXAM_INTEGRITY_FACE_PRESENCE.md`.

## Decision gate
The project owner approved the design before the phase (close-out decisions): **face presence, never recognition**; only counters and times
leave the device; explicit consent with an in-centre alternative and a retention period; off by default, per assessment, behind the
`proctoring` feature, with a DPIA note for the Ministry's data-protection officer (`docs/security/dpia-face-presence.md`).

## What was built

### Server
* **Rules** (`App\Services\Assessment\Proctoring`): `face_check` (now real), `face_absent_seconds` (10), `face_absent_limit` (3),
  `multiple_faces_limit` (1), `looking_away_limit` (5), `face_check_interval_ms` (1000; 500–3000) and `face_fallback` (`centre` | `flagged`),
  validated in the builder API and returned with their defaults. The check applies only when proctoring and the switch are on **and** the
  `proctoring` feature is enabled.
* **Consent:** starting a remote attempt with the check needs `consent {given, camera, version}`; without it the server answers
  `face_consent_required` with what the consent card needs (fallback, retention days, consent version). Without a camera, `centre` refuses the
  online start (`face_check_in_centre`) and `flagged` starts it with the `no_camera` flag. The consent is kept on the attempt. In-centre
  attempts (access code) are never asked.
* **Events:** `face_absent`, `multiple_faces`, `looking_away`, `camera_off` and `face_check_degraded` join the integrity events; they count only
  while the check is on, raise the flags `face_absent`, `multiple_faces`, `looking_away` and `no_camera` over their limits, and follow the
  existing `flag | auto_submit` action. A slow device (`face_check_degraded`) is noted for the reviewer and never flagged. An event is its type
  and nothing else: unknown types and any extra field (an image, for one) are refused. A minute-by-minute timeline is kept per attempt.
* **Review:** the attempt review returns the integrity record (flags, decisions, counts, consent, timeline, recent events, signed snapshot
  links); the live view shows each flag's decision. `POST admin/attempts/{attempt}/flags/{flag}/review` clears or upholds a flag with a reason,
  within the reviewer's scope, audited (`integrity_flag_reviewed`).
* **Retention:** `ProctoringSettings.integrity_retention_days` (90; Settings → Security) and `tedc:proctoring-retention` (daily 02:40,
  without overlapping) delete snapshots, the event log, the timeline and the counts past the period; the attempt keeps its flags, decisions and
  consent (`purged_at`).
* An exam with the check is never handed out offline (the phone cannot run it).

### Web
* **Detector:** MediaPipe Tasks Vision 1.0.1 (BlazeFace short-range, Apache-2.0) in a **Web Worker**, served from TEDC's own origin:
  `scripts/copy-mediapipe.mjs` copies the module-worker WebAssembly from `node_modules` before dev and build, and the 230 KB model is committed
  (`web/public/vendor/mediapipe/`). One 320-pixel frame per interval; faces with a score ≥ 0.6 are counted; no frame is kept.
* **Debounce rules** (`faceMonitor.ts`, explainable): no face for `face_absent_seconds` → one `face_absent`; more than one face for 3 s → one
  `multiple_faces`; one face off the middle of the frame (60 % × 70 %), turned aside (nose more than 0.6 eye-distances from between the eyes)
  or very small for 5 s → one `looking_away`; one event per episode whatever the frame rate; a pause in sampling (hidden tab) starts again.
* **Performance:** a frame is sent only when the previous one is answered; if counting takes more than half the interval five times, the rate
  halves (down to every 3 s) and `face_check_degraded` is reported once. A browser that cannot run the detector carries on and tells the
  reviewer.
* **Exam page:** the consent card (what is checked, what never happens, the retention period, the alternative), the browser's camera prompt,
  the fallback paths, a "camera check on" pill with an optional preview, and a gentle "we cannot see you" hint after 3 s that never blocks.
* **Builder:** the face-check switch with its thresholds and fallback, a plain explanation and a link to the help article
  `exam-camera-check`; a notice when the feature is off. **Review:** an integrity panel with consent, each flag with its plain reason, clear /
  uphold with a reason, the minute-by-minute timeline and snapshots. **Settings → Security:** the retention period.

### App
Exams with the check show "take it in a browser on a computer with a camera (or in the centre)" instead of Start.

### Sample data
"Questioning check (camera presence)" with one finished attempt whose `face_absent` flag a reviewer cleared with a reason; a local-only
fixture `E2EFaceCheckSeeder` (refuses production) for the browser test.

## Tests
* `FacePresenceTest` (10): off by default with conservative defaults; consent required and recorded; the two fallbacks; face events raise
  flags over their limits (and the timeline); `auto_submit` on the limit; unknown events and events with an image are refused; ignored when the
  switch or the feature is off; a reviewer clears a flag (audited, scope, forbidden for learners); retention deletes snapshots and logs past
  the period and keeps the flags; the list says when the check applies and the exam is never offline.
* Web unit tests (`node --test`, in CI): six debounce cases — absent only after the configured time and once per absence, flicker is not
  absence, three seconds of two faces, looking away by position or head turn, no flood at any frame rate, a sampling gap restarts.
* **Browser smoke test** (`npm run e2e`, Playwright with Chrome's fake camera): consent → the real detector starts in the worker → one
  `face_absent` event whose body is exactly `{type}` → the hint → the flag visible to the invigilator. Passed against the dev server and against
  the production build.
* App: `assessments_face_check_test`. `FeatureSamplesTest` checks the sample exam and its reviewed flag.

## Limits
* **No real face was tested.** The fake camera shows a test pattern, so the smoke test proves the "no face" path; detection of real faces,
  glasses, headscarves and poor lighting needs a pilot with volunteers before the Ministry relies on it. Flags always go to people.
* "Looking away" is a heuristic from the face box and three keypoints, not gaze tracking.
* Old browsers without WebAssembly SIMD (Safari before 16.4) cannot run the detector; the exam continues and the reviewer sees it.
* The browser smoke test runs locally (it needs the API, the web app and Chrome); CI runs the unit and API tests.
* The DPIA note awaits the data-protection officer's decision.
