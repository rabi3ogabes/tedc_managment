# DPIA note — camera presence check in remote exams (EXM-10)

**For:** the Ministry's data-protection officer. **Status:** prepared 13 Oct 2026 for review; the feature is **off by default** and stays off
until the Ministry decides to use it. **Law:** Qatar Personal Data Privacy Protection Law (Law No. 13 of 2016).

**ملخص:** يتحقق المتصفح أثناء الاختبار عن بُعد من وجود شخص واحد أمام الشاشة دون التعرّف على هويته. تُعالَج صور الكاميرا داخل جهاز
المتدرب فقط ولا تُرسل؛ يُرسل اسم الحدث ووقته فقط. يتطلب موافقة صريحة، مع بديل عادل (الأداء في المركز)، وتُحذف البيانات بعد مدة الحفظ.

## 1. What the processing is, and what it is not
| | |
|---|---|
| **Purpose** | Integrity of remote exams: one person at the screen, facing it, for the whole exam. |
| **What runs** | In the learner's browser, a face **detector** (MediaPipe BlazeFace short-range) counts faces in one small frame a second (none, one, more than one) and estimates from the face box and three keypoints whether the face is turned away. |
| **What it is not** | **Not face recognition.** No template, embedding or identity is computed, compared or stored. Nothing says *who* is in front of the camera. |
| **What leaves the device** | Only event names and their time: `face_absent`, `multiple_faces`, `looking_away`, `camera_off`, `face_check_degraded`. The server refuses any event that carries anything else (`MyAssessmentController::event`, tested). |
| **Images** | None for the face check. Camera **snapshots** exist as a separate, older setting (`proctoring.snapshots`); they are sent only when that setting is on for the assessment, and the consent card says so. |
| **Where it runs** | The library and model are served from TEDC's own origin (no CDN) and run in a Web Worker; frames are dropped after each count. |

## 2. Lawful basis, consent and the alternative
* **Explicit consent** before the exam (`integrity.consent = {given_at, version, camera}` on the attempt). The consent card states what is checked,
  what never happens, the retention period and the alternative, in Arabic and English. The server refuses to start without it.
* **A fair alternative** for anyone who refuses, has no camera or has a disability that makes it unsuitable, chosen per assessment:
  * *Sit it in the centre* — the online attempt cannot start; the learner asks for an invigilated sitting; or
  * *Continue, marked* — the attempt goes ahead and is marked `no_camera` for the invigilator; nothing is decided automatically.
* **Off by default:** the `proctoring` feature must be on, and the switch is set per assessment by its author.

## 3. Minimisation, retention and access
* Data kept: per attempt, the counts of events, a minute-by-minute timeline, the last 60 events, the flags, reviewers' decisions and the consent record.
* **Retention:** 90 days by default (Settings → Security, 7–3650 days). Every night `tedc:proctoring-retention` deletes snapshots, the event log,
  the timeline and the counts; the attempt keeps only its final flags, the review decisions and the consent record.
* **Access:** people with the invigilation, grading or assessment-management right; an attempt's full review and flag decisions are limited to
  the reviewer's scope (school, programme). Every flag decision is audited
  (`integrity_flag_reviewed`) with the reviewer and the reason. Integrity data is classified *Confidential* and masked in exports.

## 4. Risks and how they are handled
| Risk | Mitigation |
|---|---|
| False positives (lighting, glasses, headscarves, skin tone, camera quality) | Conservative defaults (10 s without a face = one event; flag only after more events than the limit), debounced per episode, a gentle on-screen hint so the learner can correct it, and every flag goes to a person who clears or upholds it with a reason. Nothing fails a learner unless the author explicitly chooses automatic submission. |
| Function creep (identification) | No identity computation exists in the code; the detector cannot identify. A change to recognition would need a new DPIA and the Ministry's decision. |
| Surveillance of the home environment | No video, no recording, no images for the face check; the preview is shown only if the learner opens it. |
| Slow or old devices | The rate halves automatically (down to one frame every 3 s); the reviewer sees "check slowed". If the browser cannot run the check, the exam continues and the reviewer is told. |
| Accessibility | The alternative above; keyboard-accessible consent card; the exam never blocks on the hint. |
| Security of the record | Integrity data sits on the attempt in the main database (Azure, Qatar), classified Confidential, included in the backup and retention policies. |

## 5. Data-subject rights
Learners see their exam results as before; access, correction and objection requests go through **My data** (Data-subject requests). Objecting to
the camera check is handled by the alternative in §2.

## 6. Decision and sign-off
| | |
|---|---|
| Recommended | Use only for high-stakes remote exams where an in-centre sitting is impractical; keep automatic submission off. |
| Residual risk | Low, with human review of every flag. |
| DPO decision | *Pending* |

**Technical references:** `app/Services/Assessment/Proctoring.php`, `AttemptService::faceConsent()` and `event()`, `ProctoringRetention`,
`web/src/lib/proctoring/` (detector, worker, debounce rules), `web/src/components/assessment/FaceCheck.tsx`, tests `FacePresenceTest`,
`web/src/lib/proctoring/faceMonitor.test.ts`, `web/e2e/face-presence.spec.ts`. Help-centre article `exam-camera-check`.
