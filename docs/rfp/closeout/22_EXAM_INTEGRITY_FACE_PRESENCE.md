# Phase 22 — Exam integrity: privacy-preserving face presence (EXM-10)

> Run after Phase 21. All rules in `00_MASTER_PROMPT.md` apply. Skills: `security-review`, `test-driven-development`, `frontend-design` + `impeccable` (consent and exam UI), `verification-before-completion`.

## ⚠ Decision gate: stop and ask the user before building
Detecting faces from a camera is **biometric processing of personal data** under Qatar's Personal Data Privacy Protection Law (Law No. 13 of 2016). Before writing any code, show the user the design below in a short summary and ask for an explicit **yes / no / changes** on these four points:
1. **What runs.** In-browser face **presence** detection only: counting faces (0, 1, more than 1) and whether the face is looking at the screen. This is **not** face recognition, and nothing identifies a person.
2. **What leaves the device.** Only counters and timestamps (`face_absent`, `multiple_faces`, `looking_away`). Images are sent only if the assessment **also** has snapshots switched on, exactly as today.
3. **Consent.** Explicit consent before the exam, an accessible alternative (an in-centre invigilated sitting) for anyone who refuses or has no camera, and a retention period for integrity data (default 90 days, configurable).
4. **Who decides.** Off by default, per assessment, with the feature flag `proctoring`. A DPIA note is added to `docs/security/` for the Ministry's data-protection officer.

If the user declines, record the decision in `docs/rfp/phase-22-exam-integrity.md`. Mark the `face_check` setting as unsupported (remove it from the API, with a test), and keep EXM-10 **Partial** with the reason "face recognition not adopted by decision of <date>". Stop the phase there.

## Goal (if approved)
Anti-cheating that tracks activity **and** checks that the right number of faces is present: one person, at the screen, for the whole exam. It runs privately in the browser and reviewers see clear, explainable flags.

## Closes
`EXM-10 ★` anti-cheating: activity tracking, **face recognition** (as face presence and gaze; see the decision gate). It completes mandatory item **27**.

## What exists today (verified 11 Oct 2026)
- **Settings.** `backend/app/Http/Controllers/Api/V1/Admin/AssessmentController.php` (line ~165) validates `proctoring.enabled`, `fullscreen`, `tab_switch_limit`, `fullscreen_exit_limit`, `paste_limit`, `action` (`flag|auto_submit`), `snapshots` and **`face_check`**. `face_check` is stored but **read by nothing** (found on 10 Oct).
- **Events.** `app/Services/Assessment/AttemptService.php`:
  - `EVENTS` (line ~25) include `face_absent`, but nothing raises it;
  - `event()` (lines ~215–235) counts events into `integrity.events`, logs the last 60, turns counts over the limits into `flags` and can auto-submit;
  - `snapshot()` (line ~240) stores consent-based JPEG snapshots.
- **Exam page.** `web/src/pages/portal/ExamRunner.tsx` sends integrity events and, when snapshots are on, takes camera snapshots after the browser's permission prompt (lines ~82–93).
- **Live view.** Invigilation is at `GET admin/assessments/{assessment}/live` (flags, extend, void).

## 1. Model and settings
- `assessments.proctoring` gains:
  - `face_check` (now real);
  - `face_absent_seconds` (default 10: how long no face counts as one event);
  - `multiple_faces_limit` (default 1 event);
  - `face_absent_limit` (default 3 events);
  - `looking_away_limit` (default 5);
  - `face_check_interval_ms` (default 1000; 500–3000).
- Retention: `ProctoringSettings` (`integrity_retention_days`, default 90) and a daily job that removes snapshots and integrity logs past the retention, keeping only the final flags on the attempt for the record.
- **Consent record** on the attempt: `integrity.consent = {given_at, version, camera: true|false}`.

## 2. Client detection (web)
- **The detector:** MediaPipe Tasks Vision **Face Detector** (BlazeFace short-range) running in a **Web Worker**.
  - Bundle the WASM and model with the app: `npm i @mediapipe/tasks-vision`, copy the `.wasm` and `blaze_face_short_range.tflite` into `web/public/vendor/mediapipe/`, record the model licence (Apache-2.0) in `docs/security/third-party-register.md`, and pin the versions. **No CDN at runtime.** Everything is served from TEDC's own origin, so it works in Azure Qatar and offline-restricted networks.
  - Sample one frame each `face_check_interval_ms` from a hidden `<video>` (no recording); count faces with score ≥ 0.6.
  - Derive "looking away" from the face box position and size relative to the frame (an explainable heuristic, documented) rather than identity.
  - Emit **debounced** events:
    - `face_absent` after `face_absent_seconds` without a face;
    - `multiple_faces` when more than one face appears for 3 seconds;
    - `looking_away` when the face stays outside the central region for 5 seconds.
  - Send through the existing event endpoint, adding these types to `AttemptService::EVENTS` and to the limits map.
- **Performance:** cap the CPU cost (worker + `requestVideoFrameCallback` / timer, downscaled frames). If the device is too slow (the frame budget is exceeded repeatedly), switch to a lower rate and log `face_check_degraded` once (a reviewer sees it).
- **Consent and alternatives:**
  - Before the exam, a clear bilingual consent card explains what is checked, what is never stored, the retention period and the alternative.
  - Refusing, having no camera or a blocked permission leads to the configured fallback: either "sit in the centre" (the attempt cannot start online, with a link to request an invigilated session) or "proceed flagged" (allowed but marked `no_camera`), chosen per assessment.
- **The learner's view:** a small status indicator ("camera check on"). A gentle, non-blocking banner appears when no face is seen ("We can't see you — please face the screen"). No video preview is shown unless the learner opens it.

## 3. Server
- `AttemptService::event()` accepts the new event types, applies the new limits, and turns them into flags (`face_absent`, `multiple_faces`, `looking_away`, `no_camera`) with the existing `flag|auto_submit` action.
- Starting an attempt with `face_check` **requires** a consent record (or the configured fallback). The server refuses to start otherwise, with a clear message.
- Live invigilation and attempt review show a **timeline** (minute by minute) of integrity events, flags with plain-language reasons, and snapshots when they exist. The reviewer clears or upholds a flag with a note (audited).
- Retention job: `tedc:proctoring-retention`, daily, `withoutOverlapping`.

## 4. Web admin
- In the assessment builder's proctoring panel: "Face presence check" with its thresholds, the fallback choice, a privacy explanation and a link to the DPIA note.
- In invigilation and review: the timeline and the flag review described above.

## 5. Mobile
The app does not run proctored exams that require face check: it shows "This exam must be taken on a computer with a camera" (or in the centre), unless a later phase adds native detection.

## 6. Tests (write first)
- **`FacePresenceTest`:**
  - starting without consent is refused when `face_check` is on;
  - the fallbacks work ("sit in centre" blocks the online start; "proceed flagged" sets `no_camera`);
  - the new events count toward the limits and raise flags;
  - `auto_submit` triggers on the configured limit;
  - unknown event types are refused;
  - a reviewer clears a flag (audited);
  - the retention job deletes snapshots and logs past the limit but keeps the flags;
  - `face_check` is off by default.
- **Web unit tests** for the detector's debounce logic: feed synthetic detection results and assert the emitted events (absent after N seconds, multiple faces after 3 seconds, no event flood).
- **E2E smoke** with Playwright's fake camera (`--use-fake-device-for-media-stream`): consent → start → events posted (no face) → flag visible in the live view.

## Review focus
1. **No image ever leaves the device for face check alone.** Assert in tests that the event payload has no image data.
2. **False positives** (poor lighting, glasses, headscarves): thresholds are conservative and debounced, and flags go to people. Nothing auto-fails a learner unless the assessment explicitly chooses `auto_submit`.
3. **Accessibility:** learners without a camera, or with disabilities, have a documented fair alternative.
4. **Arabic and English** consent wording is clear and short.
5. **Performance** on low-end laptops: the exam must stay responsive.

## Definition of Done
- **If approved:** face-presence detection in the browser with consent, fallbacks, thresholds, flags, review timeline and retention; the DPIA note is written; tests and the E2E smoke pass. → EXM-10 **Available** (evidence names it "face presence detection", not identification).
- **If declined:** the decision is recorded, `face_check` is removed with a test, and EXM-10 stays **Partial** with the reason.
- The gate is green, `docs/rfp/phase-22-exam-integrity.md` is written, the register is updated and the change is pushed with everything green.
