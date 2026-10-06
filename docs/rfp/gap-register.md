# TEDC × RFP — Gap Register

Single source of truth for the RFP gap analysis (same data as `TEDC_RFP_Gap_Analysis_Report.docx`).
Every phase prompt closes the IDs listed under it. Keep this file in the repository at `docs/rfp/gap-register.md`
and update the **Status** column as phases are delivered.

| Total | Available | Partial | Missing | Open gaps |
|---:|---:|---:|---:|---:|
| 278 | 78 | 88 | 112 | 200 |

Legend: ✅ Available · 🟡 Partial · 🔴 Missing · ★ RFP mandatory (knock-out) · **Ph** = implementation phase.

## Open gaps by phase

### Phase 00 — Foundation & Traceability  (1)

- [x] **DLV-09** — Compliance sheet and RFP traceability matrix  
  _Now:_ 🔴 Missing — Not prepared.

### Phase 01 — Roles, Scopes & UX Essentials  (11)

- [ ] **UX-03** — Approved Lusail typeface and readable font sizes  
  _Now:_ 🟡 Partial — Ships Qatar Sans / El Messiri / Tajawal; Lusail is not bundled or default (Brand Studio can upload fonts).
- [x] **UX-04** — Few steps per task, clear navigation, quick search for content & functions  
  _Now:_ 🟡 Partial — Clear navigation; Ctrl/⌘K switcher exists only inside Settings — no global search across programs, people, content.
- [x] **UX-08** ★ — Seamless switching between a user’s roles (trainer / trainee / manager)  
  _Now:_ 🔴 Missing — Users can hold several roles but there is no role switcher; permissions are merged.
- [ ] **RBA-03** — PD Officer (Academic Deputy): approve PD records & nominations, run internal workshops  
  _Now:_ 🔴 Missing — No dedicated role; School Admin covers nomination only.
- [x] **RBA-04** — Head of Training Department  
  _Now:_ 🔴 Missing — Not modelled (assign supervisors, grant attendance rights, approve kits).
- [x] **RBA-05** — Training Supervisor  
  _Now:_ 🟡 Partial — Program Coordinator covers most duties; per-program grants (attendance, notifications) missing.
- [ ] **RBA-06** — Centre Leadership & Policy Makers  
  _Now:_ 🟡 Partial — Executive role + dashboard; trainer-assignment approval and satisfaction alerts missing.
- [ ] **RBA-10** — Head of Planning and Planning Specialist  
  _Now:_ 🔴 Missing — Not modelled (needs tools, plan approval, evaluation forms).
- [ ] **RBA-11** — Logistics Support Officer  
  _Now:_ 🔴 Missing — Not modelled (room data, non-training bookings, logistics requests).
- [x] **RBA-12** — Create new roles and permissions when needed  
  _Now:_ 🟡 Partial — Existing role permissions are editable; new roles cannot be created from the UI.
- [x] **RBA-13** ★ — Permission scope: Ministry / school group / single school  
  _Now:_ 🟡 Partial — School-level scoping only; no school-group (cluster) or department scope.

### Phase 02 — Training Structure & Annual Plan  (11)

- [x] **TYP-20** — School internal workshops approved by the centre: create, register, attendance, results, certificates  
  _Now:_ 🟡 Partial — Internal workshops (`/admin/internal-workshops`): the school submits, the centre approves with a reason (`workshops.approve`), the school registers its own staff and receives attendance and notification rights on the workshop. Certificates from a centre-approved internal template and PD hours follow in Phases 07 and 09.
- [x] **STR-02** — Main program → optional sub-programs  
  _Now:_ ✅ Available — Main program → sub-program (one level, enforced) with roll-ups: `POST /admin/programs/{id}/sub-programs`, `GET .../tree`; program page → Structure tab.
- [x] **STR-03** — Training groups (cohorts) under a program with own dates, trainers, seats  
  _Now:_ ✅ Available — Training groups with own dates, seats, supervisor, room, trainers, sessions and status (`training_groups`, `TrainingGroupService`); group-aware registration, waiting list, attendance and certificates; program page → Groups tab; mobile group picker.
- [x] **STR-05** — Assign trainers and kit developers per group / program with an assignment form and leadership approval  
  _Now:_ ✅ Available — Trainer proposal per group → trainer fills the assignment form (web + app) → leadership approves with the competent authority reference (`TrainerAssignmentService`); kit developers assigned with a due date (`POST /admin/programs/{id}/kit-developers`).
- [x] **NDS-07** ★ — Generate the annual plan (program, audience, priority) with review and approval  
  _Now:_ ✅ Available — Annual plan (`training_plans`): generated from approved needs with an explained score per item, reviewed, returned or approved by the right role, baseline snapshot, signed copy reference, Excel/PDF export (`AnnualPlanService`, `/admin/plans`).
- [x] **NDS-08** — Yearly planning rules and program types (ترخيص، تمكين، تمهين، تخصيص، تخيير)  
  _Now:_ 🟡 Partial — Yearly rules per plan (priority weights, quarter per priority, max seats and hours per group, minimum fill, carry-over) drive generation. Program-type scope, mandatory categories and total seat/hour caps are stored but not yet enforced.
- [x] **NDS-09** — Program objectives, axes, units, competencies and summary  
  _Now:_ ✅ Available — Program axes, objectives and units with hours (`program_units`), edited on the program Structure tab; competencies through the existing skills.
- [x] **NDS-11** — Publish / cancel programs and edit group details  
  _Now:_ ✅ Available — Groups are created, edited, cloned, published/unpublished and cancelled with a mandatory reason and notifications to registrants, supervisor and managers; the public catalogue lists only published groups.
- [x] **NDS-12** — Flag emergency (unplanned) programs for reporting  
  _Now:_ ✅ Available — Groups and plan items can be flagged emergency with a reason; the plan execution view splits planned and emergency work and lists unplanned groups as deviations.
- [x] **NDS-13** — Central status board: planned, ongoing, incomplete, postponed, cancelled, completed  
  _Now:_ ✅ Available — Status board (`/admin/groups`): planned, registration open, ongoing, incomplete, postponed, cancelled, completed; drag a card to change status with the reason dialog; table view and filters; hourly lifecycle job.
- [x] **NDS-14** — Real-time plan execution tracking and deviation detection  
  _Now:_ ✅ Available — Plan execution (`GET /admin/plans/{id}/execution`): planned vs created vs executed groups, seats and hours, % execution, % changed after approval, emergency share and a deviation list (late, under-filled, cancelled, postponed, unplanned); daily job `tedc:plan-deviations` notifies planning staff.

### Phase 03 — Needs Assessment & Gap Analysis  (7)

- [x] **NDS-01** ★ — Needs-assessment toolset that feeds the annual plan  
  _Now:_ ✅ Available — Needs workspace (`/admin/needs-hub`): cycle + proposals, manager requests, staff needs, performance data, rules, gap analysis and the competency framework; accepted items and gaps flow into the annual plan draft.
- [x] **NDS-02** — Department program-proposal form in a time window (groups, axes, days/hours, kit, trainers, priority)  
  _Now:_ ✅ Available — Yearly needs cycle with an opening and closing window (`needs_cycles`); department/school proposals carry every RFP field (groups, axes, target jobs, days, hours, kit availability, trainer nominations, importance, justification); submissions outside the window are refused; reminders and auto-close (`tedc:needs-cycles`).
- [x] **NDS-03** — Manager request form for institutional needs with objectives  
  _Now:_ ✅ Available — Direct-manager requests for their own staff (`institutional_requests`): need degree, objectives, employees, preferred window; reviewed (accept / merge / reject) and converted to plan items.
- [x] **NDS-04** — Individual needs surveys linked to job competencies + manager approval  
  _Now:_ ✅ Available — Employees declare needs (`POST /me/needs`) and surveys create needs from low self-ratings; the direct manager approves or rejects in bulk with a note, and a configurable auto-approval after N days is audited.
- [x] **NDS-05** — Rule-based needs: new hires, annual appraisals, classroom observations, specialisation, competencies  
  _Now:_ ✅ Available — Needs rules (`needs_rules`) run nightly and on demand for new hires, appraisals, classroom observations and specialisation/stage; every need carries an explanation and re-runs never duplicate. Licence and test triggers are wired for Phases 06 and 09.
- [x] **NDS-06** — Automatic gap analysis vs competency framework & licence requirements, prioritised  
  _Now:_ ✅ Available — Competency framework with level descriptors and required levels per job (stage/subject overrides); current level blended from verified level, manager rating, observations and self rating with editable weights and shown evidence; gaps ranked by gap × people × licence weight with explanations, uncovered gaps listed and sent to the plan.
- [x] **NDS-15** — Approve needs and evaluation instruments before they are distributed  
  _Now:_ ✅ Available — Needs surveys cannot be published until the planning head approves them (`instruments.approve`); returned with a note, audited and notified. Evaluation forms join the same flow in Phase 08.

### Phase 04 — Enrollment, Withdrawal & External Users  (15)

- [x] **EXT-01** ★ — Public e-form for non-Ministry users, shareable by link  
  _Now:_ ✅ Available — Public form `/join/{slug}` for people outside the Ministry: bilingual, step by step, with e-mail verification code (rate limited), conditions (allowed domains) and a shareable link.
- [x] **EXT-02** ★ — Approval workflow: notify admin, review, approve / reject with reason, email result  
  _Now:_ ✅ Available — Requests are reviewed with all their data: approve (creates the account and, for trainers, the trainer profile with an activation link), reject with a reason, or ask for more information; applicants and duplicates (e-mail, national ID) are checked.
- [x] **EXT-03** — Configurable form fields, target categories and extra conditions  
  _Now:_ ✅ Available — Applicants are told by e-mail at every step; each submission has a number and an immutable PDF snapshot kept as the official record.
- [x] **ENR-01** ★ — Beneficiary entities per program and seat allocation per entity  
  _Now:_ ✅ Available — Seats per group split across schools, school groups, departments and job groups with an open pool (`group_seat_allocations`, `SeatAllocationService`); the total never exceeds capacity, full entities fall back to the open pool then the waiting list, and unused seats are released hourly (`tedc:seats-release`). Program page → Admission tab.
- [x] **ENR-02** ★ — Configurable registration-priority rules  
  _Now:_ ✅ Available — Configurable priority rules (`registration_priority_rules`: plan-targeted, approved need, time without training, appraisal, entity priority, job titles, registration date) rank applicants and promote the waiting list, with an explanation per person and a live preview in Admission rules.
- [x] **ENR-04** — Block repeated or equivalent courses; configurable equivalents  
  _Now:_ ✅ Available — Equivalent programs (one- or two-way) and a per-program repeat policy (block / warn / allow); staff can override with a reason.
- [x] **ENR-06** ★ — Prevent time-conflicting registrations (switchable per course)  
  _Now:_ ✅ Available — Time clashes are blocked at registration against approved programs and at approval of a second overlapping one; the group setting `allow_overlap_until_approved` controls registering in two overlapping groups before either is approved.
- [x] **ENR-08** — Extra criteria: experience in/out Ministry & in current title, licence, grade/subject, 3-year appraisal  
  _Now:_ ✅ Available — Rule editor and audience builder gained Ministry / outside experience, years in the current title, job grade, subjects, grades taught, appraisal min/avg over 3 years and an equivalent-completed field; the licence criterion is hooked for Phase 09.
- [x] **REG-02** — Two-stage approval: direct manager (within entity seats) → training centre  
  _Now:_ ✅ Available — Self-registration goes to the direct manager (supervisor, else the school's academic deputy) and then the training centre; manager registrations skip the first stage; admin imports are approved; the group `approval_mode` can be center_only or auto; the approval path is shown to the trainee on web and in the app.
- [x] **REG-06** — Acceptance tools: priority and prior-training analysis  
  _Now:_ ✅ Available — Candidate list per group with priority score and explanation, completed programs in 12 months, hours this year vs the annual minimum, same-category completions and attendance; bulk acceptance stops at the seats.
- [x] **REG-07** — No approval before the registration period closes  
  _Now:_ ✅ Available — Centre approval of self-registrations is blocked until the registration window closes (`approve_after_window`), with a clear message and an audited override reason.
- [x] **WDR-01** ★ — After manager approval, withdrawal needs the manager’s approval  
  _Now:_ ✅ Available — A trainee withdraws freely before the manager approved while registration is open (seat released, waiting list promoted).
- [x] **WDR-02** ★ — After centre acceptance: manager then supervisor approval + reason form with attachments  
  _Now:_ ✅ Available — After the manager approved, withdrawing is a request the direct manager decides.
- [x] **WDR-03** — Record timing: during window / before start / after start  
  _Now:_ ✅ Available — For an approved seat the request goes to the manager and then the program supervisor, with a reason, optional attachments, and rejection notes.
- [x] **WDR-05** ★ — Withdrawal rules configurable without code  
  _Now:_ ✅ Available — Withdrawal policy and reasons are settings (minimum days before start, allow after start, reasons that require attachments); timing is recorded (during window / before start / after start) and late withdrawals are flagged.

### Phase 05 — Attendance, Rooms & Logistics  (10)

- [x] **ATT-03** — Electronic signature on a tablet  
  _Now:_ ✅ Available — Tablet kiosk (`/kiosk/sessions/{id}`): large touch targets, the trainee finds their name and signs on screen to check in or out; signatures are stored privately with the record, the kiosk opening is audited, and manual entry by non-centre staff can be limited to the first N minutes.
- [x] **ATT-05** — Fingerprint attendance-system integration (trainees and trainers)  
  _Now:_ 🟡 Partial — Fingerprint gateway (`FingerprintGateway`): signed webhook (generic HTTP and the ZKTeco ADMS ATTLOG push format) and CSV import match punches to the person and the session running in the device's room, ignore duplicates and report unmatched ones; device registry, test and log on `/admin/absence`. A vendor-specific pull SDK needs the Ministry's device model and network access.
- [x] **ATT-06** — Trainer attendance and staff scanning of trainee / trainer QR  
  _Now:_ ✅ Available — Trainers record attendance by the session QR, by a staff scan of their personal QR (`/me/attendance-qr`, rotates daily), or by the supervisor; staff scan trainees the same way (grant `attendance.mark` required); QR check-in/out windows per session or globally; trainer minutes feed the hours report.
- [x] **ATT-08** — Absence-threshold alert to supervisor; email to trainee & manager with notes  
  _Now:_ ✅ Available — After each session the hourly job computes absence per trainee, announces a warning and a breach once each (levels configurable), tells the supervisor and the trainee and, on breach, the direct manager; the supervisor adds a note and resends from the Absence page.
- [x] **ATT-09** — Absence excuses with documents and manager approval workflow  
  _Now:_ ✅ Available — Trainees send absence excuses with documents (web and app); the direct manager approves or rejects; approved excuses mark the days `excused` and, by policy, either leave them out of the maths or count them as attended.
- [x] **ATT-10** — Leave / permission (استئذان) entry with attachments and notification  
  _Now:_ ✅ Available — Supervisors record late arrival, early leave or temporary leave with minutes, reason and attachments; the minutes are deducted from attendance, the trainee is notified (policy) and a leave can be removed to restore them.
- [x] **ROM-04** — Book rooms for non-training use by authorised users  
  _Now:_ ✅ Available — Authorised staff book rooms for meetings, exams, events or maintenance (`/admin/room-bookings`); conflicts are checked against sessions and other bookings and show who holds the room; the occupancy calendar shows sessions and bookings together.
- [x] **ROM-06** — Never approve more trainees than room capacity; capacity per room / place / building  
  _Now:_ ✅ Available — Effective capacity = the lowest of the room, its building and its place; assigning a room to a session and approving registrations both refuse to exceed it (override with a reason); places → buildings → rooms hierarchy.
- [x] **ROM-07** — Seating plan inside the room  
  _Now:_ ✅ Available — Seating designer per room and session or group: grid with blocked seats, manual assignment, automatic assignment (alphabetical, by school, random) with an 'insufficient seats' check, and a printable plan.
- [x] **ROM-09** — Logistics requirements routed automatically to the logistics team  
  _Now:_ ✅ Available — Logistics requests (equipment, catering, printing, IT, arrangement) go to the logistics team's queue, are tracked New → In progress → Done with notifications to the requester, and overdue ones escalate hourly.

### Phase 06 — Assessment Engine & Interactive Learning  (16)

- [x] **TYP-04** ★ — Pre- and post-program assessment of the trainee’s level  
  _Now:_ ✅ Available — Question bank + assessments with 14 types — docs/rfp/phase-06-assessment.md
- [x] **TYP-07** ★ — Interactive video: in-video questions / comments, pop-up control, progress gating  
  _Now:_ ✅ Available — Interactive video interactions with blocking and anti-distraction rules
- [x] **TYP-08** — Chapter quizzes from a random bank, auto-graded, gate the next chapter  
  _Now:_ ✅ Available — Assessment builder: sections, random draw, difficulty mix, timer, attempts
- [x] **TYP-09** — Final exams with retry rules and re-study after failure  
  _Now:_ ✅ Available — Diagnostic and comprehensive skills tests; results feed employee skills
- [x] **TYP-11** ★ — Exams taken remotely or in-centre via a secret access code  
  _Now:_ ✅ Available — Pre/post tests with knowledge gain against the 35% target
- [x] **TYP-13** ★ — Anti-distraction: prevent pause, seek or minimise during video  
  _Now:_ ✅ Available — Lesson quizzes migrated to the bank; lesson gating by assessment
- [x] **PAS-02** — Test builder: MC, multi-select, dropdown, matrix, image/video, drag-and-drop  
  _Now:_ ✅ Available — Pass mark, attempts, cooldown, restudy rule per assessment
- [x] **PAS-06** — Objective questions auto-graded; essays graded manually  
  _Now:_ ✅ Available — Manual grading queue, regrade with replacement, release of results
- [x] **EXM-01** ★ — Final, short and diagnostic tests  
  _Now:_ ✅ Available — 14 question types incl. essay, matching, ordering, hotspot, numeric
- [x] **EXM-04** ★ — Question types: essay, matching, ordering, fill-in, categorisation, H5P, extensible  
  _Now:_ ✅ Available — Question banks with categories, tags, difficulty, versions, import/export
- [x] **EXM-05** ★ — Question banks by course / unit / difficulty, reusable, with media  
  _Now:_ ✅ Available — Random draw by category and difficulty mix; shuffling
- [x] **EXM-06** — Random selection from a bank  
  _Now:_ ✅ Available — Server-owned timer, autosave, resume, extra time
- [x] **EXM-07** — Auto + manual grading, immediate / deferred feedback, question weights  
  _Now:_ ✅ Available — Static and rotating access codes for in-centre exams
- [x] **EXM-09** — Access codes and submission timestamps  
  _Now:_ 🟡 Partial — Integrity events, thresholds, snapshots (browser consent); face check best-effort
- [x] **EXM-10** ★ — Anti-cheating: activity tracking, face recognition  
  _Now:_ ✅ Available — Live invigilation: attempts, flags, extend, void
- [x] **EXM-11** — Result analytics per trainee, group and program  
  _Now:_ ✅ Available — Item analysis, difficulty and discrimination, distractors, by group

### Phase 07 — Passing Rules & Certificates  (6)

- [x] **PAS-01** ★ — Pass criteria with relative weights (attendance, participation, tasks, tests)  
  _Now:_ ✅ Available — Weighted/all-required passing policy per group, program or global — docs/rfp/phase-07-passing-rules.md
- [x] **PAS-05** — Final approval by course supervisor; auto-approval for self-learning  
  _Now:_ ✅ Available — Trainer-then-supervisor task approval; automatic for self-assessed tasks
- [x] **PAS-07** — Pass via a comprehensive skills test without attending  
  _Now:_ ✅ Available — Pass by the comprehensive skills test without attending (test-out)
- [x] **PAS-09** — Certificate hours: total vs actually attended  
  _Now:_ ✅ Available — Total or actual attended hours on the certificate
- [x] **PAS-10** ★ — Attendance certificate vs pass certificate (or both)  
  _Now:_ ✅ Available — Attendance, pass or both certificate types with their own templates
- [x] **PAS-13** — Manual exception from the attendance condition, documented  
  _Now:_ ✅ Available — Documented, audited, revocable exceptions with reason and attachment

### Phase 08 — Evaluation, Surveys & Impact  (11)

- [x] **SRV-03** — Planning-team program evaluation form  
  _Now:_ ✅ Available — Planning-team evaluation form assigned by the planning head — docs/rfp/phase-08-evaluation.md
- [x] **SRV-04** — Trainer self-reflection form  
  _Now:_ ✅ Available — Trainer self-reflection assigned at group end; results notify planning
- [x] **SRV-08** ★ — Export results to Excel, PDF and Word  
  _Now:_ ✅ Available — Excel / PDF / Word / CSV export for satisfaction, evaluation forms and needs surveys
- [x] **EVL-01** ★ — Trainee impact form after ≥ 1.5 months, with evidence uploads  
  _Now:_ ✅ Available — Trainee impact form at 45 days (configurable) with evidence
- [x] **EVL-02** ★ — Manager impact form with evidence  
  _Now:_ ✅ Available — Manager impact form requested at 60 days (configurable) with evidence
- [x] **EVL-04** — Program-supervisor feedback  
  _Now:_ ✅ Available — Program supervisor feedback form
- [x] **EVL-05** — Planning-specialist feedback  
  _Now:_ ✅ Available — Planning specialist feedback form
- [x] **EVL-06** ★ — Pre / post comparative analysis (knowledge gain)  
  _Now:_ ✅ Available — Pre/post comparison, knowledge gain vs 35% target, distribution, per-skill, significance hint
- [x] **EVL-07** — Personal interviews log  
  _Now:_ ✅ Available — Interviews log with sentiment and attachments
- [x] **EVL-08** — Evaluation report: KPIs + classification (successful / needs review / weak) + recommendations  
  _Now:_ ✅ Available — Program evaluation report with classification, recommendations, approval and export
- [x] **RPT-10** — Low-satisfaction alert (< 50 % once ≥ 80 % responded, editable thresholds)  
  _Now:_ ✅ Available — Low-satisfaction alert (response ≥80% and average <50%, editable), once per group

### Phase 09 — Career Paths, Licences & CPD  (11)

- [x] **TYP-19** — Indirect training (knowledge transfer): indirect beneficiaries, transferred hours, evidence uploads within a deadline  
  _Now:_ ✅ Available — Knowledge transfer with beneficiaries, hours, evidence, deadline and review
- [x] **CAR-01** ★ — Promotion paths linked to experience, grade and annual appraisal  
  _Now:_ ✅ Available — Promotion/specialisation paths with levels and conditions — docs/rfp/phase-09-career-pd.md
- [x] **CAR-02** — Professional-licence programs for the four licence levels  
  _Now:_ ✅ Available — Four-level licence paths with validity and renewal
- [x] **CAR-03** — Conditions per program / licence block progress until met  
  _Now:_ ✅ Available — Conditions engine in eligibility syntax; blocks level-restricted programs
- [x] **CAR-04** — Path-compliance dashboards with automatic gain/loss notifications  
  _Now:_ ✅ Available — Compliance funnel/matrix with export; gain and loss notifications to employee and manager
- [x] **CPD-01** ★ — Log external PD activities with details and evidence  
  _Now:_ ✅ Available — External PD records with evidence
- [x] **CPD-02** ★ — Hours calculated by activity type and participation level  
  _Now:_ ✅ Available — Hour rules per type and participation level with caps
- [x] **CPD-03** ★ — Direct-manager approval of activities  
  _Now:_ ✅ Available — Direct-manager approval (approve / reject / return)
- [x] **CPD-04** — Reports: total hours, distribution by domain / competency, approval rates  
  _Now:_ ✅ Available — PD reports: hours, domain, approval rates, shortfalls; Excel/PDF/Word
- [x] **CPD-05** — Annual minimum-hours tracking per employee  
  _Now:_ ✅ Available — Annual minimum hours with alerts, calendar or fiscal year
- [x] **CPD-06** — Request recognition of external courses (centre sets hours / equivalent programs)  
  _Now:_ ✅ Available — Recognition requests with recognised hours and equivalent programs

### Phase 10 — Content Standards, Library & Offline  (15)

- [x] **TYP-12** — Offline learning: watched content offline, sync on reconnect, resume exams  
  _Now:_ 🟡 Partial — Offline manifest and idempotent sync done; encrypted downloads and offline exams in the app not built
- [x] **TYP-14** — Integrate external platforms (Coursera, edX, Udemy, LinkedIn Learning) via APIs  
  _Now:_ 🟡 Partial — External provider courses become programs with completion sync; needs real provider access
- [x] **TYP-15** ★ — SCORM and H5P support with tracking and reuse  
  _Now:_ ✅ Available — SCORM 1.2/2004 and H5P packages upload, play, track — docs/rfp/phase-10-content-standards.md (generated-package tests; vendor packages to be tried)
- [x] **TYP-17** — Advertise and register for programs on other platforms (e.g., I-earn)  
  _Now:_ ✅ Available — Programs on other platforms: launch, evidence upload, centre review, hours counted
- [x] **CNT-01** — SCORM and xAPI compliance for upload and playback  
  _Now:_ ✅ Available — SCORM runtime and xAPI LRS with native activity recorded as xAPI
- [x] **CNT-03** — Content versioning, periodic updates and long-term archiving  
  _Now:_ ✅ Available — Lesson versions: publish, keep/move learners, diff, restore, archive (quiz questions not pinned)
- [x] **CNT-04** — Digital library (books, journals, AV, kits) with IP rights, audience rules, search, download  
  _Now:_ ✅ Available — Digital library with rights, audience rules, Arabic search, reader, shelves, ratings
- [x] **CNT-05** — External libraries: Maktabati and Qatar National Library  
  _Now:_ 🟡 Partial — External library search (deep link / JSON API) and import; real Maktabati/QNL endpoints to be configured
- [x] **KIT-03** — Supervisor approval, then assignment to one or more programs  
  _Now:_ ✅ Available — Kits assigned to several programs with pinned version
- [x] **KIT-05** — Share resources per course and with job groups (principals, teachers…)  
  _Now:_ ✅ Available — Job groups as sharing targets
- [x] **KIT-06** — Sharing-permission settings that protect IP  
  _Now:_ ✅ Available — Per-role sharing policies, view-only protection, audited shares
- [x] **TEC-08** ★ — LTI 1.1 and LTI 1.3 with Deep Linking  
  _Now:_ 🟡 Partial — LTI 1.1/1.3 platform with Deep Linking, AGS, NRPS done; TEDC as an LTI tool not built
- [x] **TEC-09** ★ — xAPI, IMS Caliper, SCORM, QTI 1.1 / 2 / 2.1, cmi5  
  _Now:_ ✅ Available — xAPI LRS, Caliper 1.2, SCORM, QTI 2.1/1.2, cmi5 implemented (ADL conformance suite still to run)
- [x] **TEC-11** ★ — Common Cartridge import (full or selected parts)  
  _Now:_ ✅ Available — Common Cartridge 1.1-1.3 / thin CC preview and selective import
- [x] **TEC-13** ★ — Trusted content-provider integration  
  _Now:_ 🟡 Partial — Provider adapter framework (generic REST + demo driver); real Coursera/edX/Udemy/LinkedIn APIs need credentials

### Phase 11 — Notifications, Announcements & CMS  (12)

- [x] **HOM-01** — Add / edit / delete news, activities and events  
  _Now:_ ✅ Available — News, circulars, activities and events managed in one board; events have date, venue, online and registration links, capacity, in-platform RSVP with waiting list, reminders, public events page and ICS calendar (Phase 11).
- [x] **HOM-03** — Export news / events to the Ministry website (API or file)  
  _Now:_ ✅ Available — Public JSON, RSS, Atom and CSV feeds (only items flagged for export), manual export files, and an automatic push adapter with retries, log and failure alerts. The push needs the Ministry site's real endpoint and key (Phase 11).
- [x] **HOM-04** — Fully dynamic homepage editable by admin (texts, images, links, ads)  
  _Now:_ ✅ Available — Block-based homepage and About editor (hero, statistics, programs, news, events, rich text, call to action, logos, FAQ, video, safe HTML) with visibility windows, audience, live desktop/phone preview in both languages, versioned publish and restore; the built-in design stays until the first publish (Phase 11).
- [x] **HOM-05** — Dynamic public statistics (users, courses, centre-defined figures)  
  _Now:_ ✅ Available — Public statistics from built-in sources (users, programs, groups held, certificates, hours, schools) or values and named indicators the centre defines; cached and refreshed every ten minutes (Phase 11).
- [x] **NTF-03** ★ — SMS through the Hudhud system  
  _Now:_ 🟡 Partial — Hudhud driver with Arabic UCS-2 encoding, signed delivery-receipt webhook, test button, per-event SMS switches (Phase 11). The request/receipt shape follows a configurable assumption (base URL, path, key or user, receipt secret) and must be matched to the Ministry's Hudhud interface document before go-live.
- [x] **NTF-05** ★ — Target by user type, job title, program, school  
  _Now:_ ✅ Available — Audience builder: roles, job titles, schools, school groups, program participants (by registration status), trainers, supervisors, named people; live count and sample; senders only reach their own scope (Phase 11).
- [x] **NTF-06** — Scheduled notifications and allowed send times / days  
  _Now:_ ✅ Available — One-off and daily/weekly/monthly scheduled sends processed every minute; per-rule and per-channel allowed days and hours (Doha time) defer SMS, e-mail and push to the next window; delay minutes (Phase 11).
- [x] **NTF-09** — Sound or visual alert on a new notification  
  _Now:_ 🟡 Partial — Web: pop-up with a soft chime (per-user switch, browser autoplay rules respected) and a pulsing bell; mobile: system push sound and an in-app preference. No custom local-notification sound on mobile yet (Phase 11).
- [x] **NTF-10** — Enable / disable types per user category; rules per program  
  _Now:_ ✅ Available — Notification rules per event, program category, program and audience (most specific wins), channel limits, switch-off; users choose optional channels per event group; mandatory events ignore opt-out (Phase 11).
- [x] **NTF-12** — Delivery status sent / read / failed; export PDF / Excel  
  _Now:_ ✅ Available — Delivery tracking per person and channel (queued, sent, delivered, read, failed, skipped, with reasons), filters, drill-down by campaign, donut summary, Excel and PDF export in Arabic and English (Phase 11).
- [x] **NTF-13** — Announcements: start/end window, several at once, pin, archive, republish, search  
  _Now:_ ✅ Available — Announcement lifecycle draft, scheduled, published, expired, archived with display window, several live at once, ordered pinning, searchable archive, republish with media copied and window reset, push/e-mail on publish (Phase 11).
- [x] **NTF-14** ★ — Multimedia announcements (link, video, audio, text, image)  
  _Now:_ ✅ Available — Announcements carry images, video, audio (player on web and mobile), files and links; audience filters; optional push and e-mail (Phase 11).

### Phase 12 — Reports, Dashboards & KPIs  (12)

- [x] **HOM-06** ★ — Dashboards for every user category, driven by role  
  _Now:_ ✅ Available — A dashboard for every role from a widget registry (trainee, principal, deputy, head of training, supervisor, leadership, executive, trainer, admin, kit developer, QA, planning, logistics): scope-aware, date range, drill-down to the report, personal hide/reorder, presets editable by administrators; on web and in the app home (Phase 12).
- [x] **ATT-11** — Attendance records and reports; Excel and PDF export  
  _Now:_ ✅ Available — Detailed attendance and absence per trainee, per group with leave minutes, printable group sheets and the trainee's own attendance, all exportable to Excel, PDF and Word (Phase 12).
- [x] **RPT-01** — Role-specific reports + self-service dynamic report builder  
  _Now:_ ✅ Available — Report builder for non-technical people: whitelisted datasets, columns with aggregates, plain-word filters, grouping, sorting, chart, live preview, save and share, schedule; nothing typed by a user reaches SQL (Phase 12).
- [x] **RPT-02** — Export reports to Excel, PDF and Word  
  _Now:_ ✅ Available — Excel (RTL, totals, one sheet per table), PDF (shaped Arabic, charts) and Word from one document model; large runs are produced by the minute job; downloads of personal data are audited; signed expiring links for scheduled sends (Phase 12).
- [x] **RPT-03** — System-admin reports (employees, courses, paths, lookup, attendance, licence matrix, trainers, results, hours, periodic stats)  
  _Now:_ 🟡 Partial — Employee data, employee courses, programs-groups, courses by employee number, detailed attendance, programs/licences matrix, trainer follow-up, multi-table achievement statistics, process tracking, trainee results, programs by job category, satisfaction, approved vs actual hours, periodic statistics (Phase 12). Not covered: the 'path' column is the program category, appraisal and licence filters on the employee report, and a separate quarterly view (use month grouping).
- [x] **RPT-04** — Supervisor reports (printable sheets, workshop calendar, supervised programs, attendance & leave)  
  _Now:_ ✅ Available — Printable attendance sheets per group, weekly/monthly workshop calendar, groups supervised in a period, attendance/absence/leave per group (Phase 12).
- [x] **RPT-05** — Trainer reports (workshop calendar, delivered programs, process tracking)  
  _Now:_ ✅ Available — Trainer's workshop calendar, statistics of programs and groups delivered, process tracking of their groups (Phase 12).
- [x] **RPT-06** — Direct-manager reports (team courses, nominations & approval flow, attendance)  
  _Now:_ ✅ Available — Courses obtained by staff in a period, nominations and approval flow, staff attendance and absence — limited to the manager's own staff (Phase 12).
- [x] **RPT-07** — Trainee reports (calendar, annual / fiscal hours dashboard, eligible programs, history)  
  _Now:_ ✅ Available — Approved workshop calendar, courses and hours by year or academic year, programs I can apply for, completed courses, my attendance, statement of courses in a period (PDF) — always about the person asking; also in the app (Phase 12).
- [x] **RPT-08** — QA and kit-developer reports  
  _Now:_ ✅ Available — Approved kits with their programs and supervisors (QA) and a kit developer's own kits and approval status (Phase 12).
- [x] **RPT-09** — Leadership dashboard: plan execution %, plan changes %, high / low satisfaction groups  
  _Now:_ ✅ Available — Leadership dashboard: plan execution %, changes after approval %, achievement by school and job category, highest and lowest satisfaction, low-satisfaction alerts, drill-down to reports (Phase 12).
- [x] **TEC-17** — Live KPI dashboard: response time, concurrency, uptime, completion, active users, satisfaction, knowledge gain, security  
  _Now:_ ✅ Available — Live KPI dashboard of the twelve RFP indicators against editable targets with 30-day trends, breach alerts to administrators, data-integrity detail and a monthly PDF/Word report. Concurrency shows current and peak users, not a proven capacity; uptime comes from an in-app probe (Phase 12).

### Phase 13 — Integrations & Enterprise Identity  (12)

- [x] **TYP-05** ★ — Synchronous remote training via Microsoft Teams  
  _Now:_ ✅ Available — Teams meetings created automatically for online sessions, updated and cancelled with the schedule, join link delivered to trainees, group teams and files. Built on the documented Graph API; not run against a live tenant (Phase 13).
- [x] **CAR-05** — HR integration for experience, grades and appraisals  
  _Now:_ 🟡 Partial — HR / Mawared full and delta sync with conflict policy, leaver deactivation and signed inbound changes. Routing profile change requests to HR is not built; needs the HR interface specification (Phase 13).
- [x] **ATT-07** ★ — Teams attendance % from total participation time  
  _Now:_ ✅ Available — Attendance computed from time in the Teams call (intervals, leave and re-join, minimum presence, late), feeding the attendance percentage and absence rules; trainer marks are kept (Phase 13).
- [x] **UTR-04** — In-portal issue-reporting page linked to Saaed  
  _Now:_ ✅ Available — Report-a-problem on web and in the app: category, priority, description, screenshot, page and context captured; tickets reach Saaed (queued and retried), status and number come back as notifications. Saaed's API shape is assumed (Phase 13).
- [x] **NFR-07** ★ — Single sign-on and IAM integration  
  _Now:_ 🟡 Partial — OpenID Connect single sign-on with Microsoft Entra ID (PKCE, strict token validation, just-in-time accounts, group-to-role mapping with scopes, single logout, break-glass), tested against a mock IdP. SAML 2.0 is not built natively and mobile SSO is not wired; needs the Ministry tenant and app registration (Phase 13).
- [x] **NFR-08** ★ — Auth schemes: AD, LDAP, Kerberos, certificates, tokens, OTP  
  _Now:_ 🟡 Partial — LDAP / Active Directory bind-and-search (TLS required) and TOTP, e-mail and SMS one-time codes with recovery codes. Kerberos, certificates and smart-card/FIDO2 are provided by the identity provider (documented, not coded); LDAP needs PHP's ldap extension on the host (Phase 13).
- [x] **NFR-09** ★ — Configurable password policy and account lockout  
  _Now:_ ✅ Available — Configurable password policy (length, classes, history, expiry, breach check) and lockout with administrator unlock, audited, applied to activation and password change (Phase 13).
- [x] **NFR-11** ★ — Automatic session termination after inactivity  
  _Now:_ ✅ Available — Server-side sessions with idle and absolute lifetime; expired, ended or terminated sessions are refused immediately and cannot be refreshed; administrators list and terminate sessions (Phase 13).
- [x] **TEC-06** ★ — Real-time message-based sync between systems  
  _Now:_ ✅ Available — Outbox of domain events delivered as signed webhooks with retries, dead-letter and replay; signed idempotent inbound messages; subscriptions managed in the hub. A broker adapter (Azure Service Bus) follows in Phase 17 (Phase 13).
- [x] **TEC-07** ★ — Ministry integrations: Licences, NSIS, QNEDS, HR / Mawared, AD, Saaed, Sijil, Ministry website  
  _Now:_ 🟡 Partial — Integration hub with monitored adapters (health, logs, retries, circuit breaker) for HR, Mawared, licences, NSIS, QNEDS, Saaed, Sijil and the Ministry site. The real system specifications are needed; none was run against the real systems (Phase 13).
- [x] **TEC-12** ★ — Advanced Teams: Office 365 forms, structure sync, file sharing, live streaming  
  _Now:_ 🟡 Partial — Meetings, attendance by duration, Team per group with membership sync, channel files and Forms results import (CSV export). Live events are link-only and Teams activity-feed notifications are not wired (Phase 13).
- [x] **DLV-08** — Data-migration strategy and tooling (validation, cleansing, transformation, secure transfer)  
  _Now:_ ✅ Available — Data-migration toolkit for employees, trainers, programs, registrations, attendance, certificates and PD: templates, mapping and value conversion, Arabic-aware cleansing, validation report, dry run, chunked import, reconciliation and rollback, audited and secured; strategy in docs/rfp/data-migration.md (Phase 13).

### Phase 14 — Collaboration, PLCs & Gamification  (17)

- [x] **TYP-10** — Contact the trainer and ask questions from inside the course  
  _Now:_ ✅ Available — Ask the trainer from any lesson (web and app): routed to the group's trainers with a reply deadline, trainer inbox, answer notifies the learner. Phase 14.
- [x] **PLC-01** ★ — Create flexible communities by subject, interest or team  
  _Now:_ ✅ Available — Communities by subject, interest or team, with visibility and join policy, behind the `plc` flag. Phase 14.
- [x] **PLC-02** ★ — Member roles (manager, moderator, member) with permissions  
  _Now:_ ✅ Available — Owner / manager / moderator / member roles, approval, ban, staff override permissions. Phase 14.
- [x] **PLC-03** ★ — Votes, polls, open questions, comments, file sharing  
  _Now:_ 🟡 Partial — Polls, questions, comments and reactions are built; file sharing is by attachment reference (name + link) — no upload widget in the composer yet. Phase 14.
- [x] **PLC-04** ★ — Meetings and events scheduling with automatic notifications  
  _Now:_ ✅ Available — Space events with RSVP, notification on creation and a reminder 24 h before. Phase 14.
- [x] **PLC-05** ★ — Instant alerts on new topics and updates  
  _Now:_ ✅ Available — Instant notification on new posts, comments, mentions, join requests, plus a daily digest; each person chooses all / mentions / none. Phase 14.
- [x] **COL-01** — Discussion forums per program and per group  
  _Now:_ ✅ Available — Forums per programme and per group, membership following registrations; trainers moderate. Phase 14.
- [x] **COL-02** — Comments and notes on lessons and materials  
  _Now:_ 🟡 Partial — Private notes and a discussion thread on every lesson; not on individual materials. Phase 14.
- [x] **COL-03** — Content rating and reviews by trainees and trainers  
  _Now:_ 🟡 Partial — 1–5 stars and reviews with moderation for lessons, materials, kits, library items and programmes (API); the interface is on lessons only. Phase 14.
- [x] **COL-04** — File sharing inside discussions  
  _Now:_ 🟡 Partial — Attachments can be referenced in posts and comments; no upload widget yet. Phase 14.
- [x] **COL-05** — Private trainers’ knowledge channel  
  _Now:_ ✅ Available — Private trainers' channel for active trainers, moderated by the training team. Phase 14.
- [x] **COL-06** — Instant notifications on posts; permissions per role and level  
  _Now:_ ✅ Available — Notifications on posts, per-person preferences, permissions per role (`communities.*`, `forums.moderate`). Phase 14.
- [x] **GAM-01** — Points system and leaderboard  
  _Now:_ ✅ Available — Points ledger with rules, caps and reversals; weekly / monthly / term leaderboards by ministry, school, region, programme; opt-out. Phase 14.
- [x] **GAM-02** — Badges and achievements  
  _Now:_ ✅ Available — Rule-based and manual badges with safe SVG icons. Phase 14.
- [x] **GAM-03** — Levels (beginner → expert)  
  _Now:_ ✅ Available — Six editable levels from newcomer to pioneer; level-up notification. Phase 14.
- [x] **GAM-04** — Timed challenges and rewards  
  _Now:_ ✅ Available — Timed challenges with audience, goal and reward; rewards shop with level, stock and refunds. Phase 14.
- [x] **GAM-05** — Personal progress dashboard; admin-configurable; can be switched on / off  
  _Now:_ ✅ Available — Personal achievements page; studio for admins; master flag plus per-role and per-programme switches. Phase 14.

### Phase 15 — AI Completion  (6)

- [x] **RPT-11** — Predictive analytics and reports  
  _Now:_ 🟡 Partial — Forecast and risk pages with explanations and plan hand-off; Excel/PDF export of forecasts is not built. Phase 15.
- [x] **AI-01** ★ — Behavioural analytics and personalised recommendations  
  _Now:_ ✅ Available — Hybrid recommender: rule score + colleagues' completions (item similarity) + own behaviour + same-job ratings + date clashes; explained; weights, A/B test and feedback loop; falls back to the rule engine. Phase 15.
- [x] **AI-02** ★ — Smart assessment with instant feedback from answer analysis  
  _Now:_ ✅ Available — Instant objective feedback (distractor analysis, competency, what to review) and essay drafts for the grader (rubric criteria, suggested score); a draft never becomes a grade by itself. Phase 15.
- [x] **AI-03** ★ — Adaptive content that adjusts to each learner  
  _Now:_ 🟡 Partial — Mastery per competency, skip-module (test-out, audited) and remedial-lesson rules, personal path with reasons, AI-drafted remedial lessons reviewed by the trainer; difficulty of practice draws is not adapted. Phase 15.
- [x] **AI-04** ★ — Predictive reports on future PD needs  
  _Now:_ ✅ Available — Next-year forecasts by competency, job title and school (Holt smoothing with range and explanation) and risk lists (hours, licences, under-filled groups, satisfaction); suggested items added to a draft plan. Phase 15.
- [x] **AI-05** ★ — ML assistant that answers trainees’ questions  
  _Now:_ 🟡 Partial — Assistant answering from permitted lessons, programmes and library with citations plus the person's own records, declines off-topic, hands over to trainer or support, in-country by default; retrieval uses local hashed embeddings (no pgvector) and generating answers needs a connected model (extractive answers otherwise); not run against a real Azure endpoint. Phase 15.

### Phase 16 — Course Purchasing & Payments  (4)

- [ ] **PAY-01** — Browse catalogue, add courses to a cart, check out  
  _Now:_ 🔴 Missing — Not available.
- [ ] **PAY-02** — Pay through the Ministry e-payment gateway  
  _Now:_ 🔴 Missing — Not available.
- [ ] **PAY-03** — Entities buy course bundles for their staff  
  _Now:_ 🔴 Missing — Not available.
- [ ] **PAY-04** — Paid / free pricing per trainee category  
  _Now:_ 🔴 Missing — Not available.

### Phase 17 — Azure Qatar, Security & Compliance  (19)

- [ ] **NFR-01** ★ — Secure, scalable, resilient multi-layer HA design (99.9 % SLA)  
  _Now:_ 🟡 Partial — Stateless API in Docker; no HA / DR topology.
- [ ] **NFR-02** ★ — Hosting on Azure Qatar (data residency, Law 13/2016)  
  _Now:_ 🔴 Missing — Runs on Railway / Vercel / Supabase.
- [ ] **NFR-03** ★ — Production, staging (prod-identical) and development environments  
  _Now:_ 🟡 Partial — Local + one deployment; CI pipeline exists.
- [ ] **NFR-04** ★ — HLD / LLD, bill of materials, sizing and bandwidth design  
  _Now:_ 🔴 Missing — docs/ARCHITECTURE.md is a logical overview only.
- [ ] **NFR-05** ★ — Encryption at rest and in transit; joint data classification  
  _Now:_ 🟡 Partial — HTTPS, encrypted national IDs, private storage; no full classification.
- [ ] **NFR-13** ★ — Forward logs to SIEM (e.g., Splunk)  
  _Now:_ 🟡 Partial — Syslog handler available but not configured.
- [ ] **NFR-14** ★ — No production data in dev / test / training; masking  
  _Now:_ 🔴 Missing — No masking process.
- [ ] **NFR-15** ★ — VAPT, accredited code review, threat model, risk assessment, security docs  
  _Now:_ 🔴 Missing — Not prepared.
- [ ] **NFR-16** ★ — Secure SDLC: secure coding, threat modelling, code analysis  
  _Now:_ 🟡 Partial — CI runs tests, lint and Pint; no SAST / dependency scanning.
- [ ] **NFR-17** ★ — API security: encryption, validation, auth, API gateway  
  _Now:_ 🟡 Partial — Validation, JWT, throttling, security headers; no API gateway.
- [ ] **NFR-18** ★ — Patch and vulnerability management incl. third-party libraries  
  _Now:_ 🔴 Missing — No automated dependency updates.
- [ ] **NFR-19** ★ — Enterprise backup and recovery in-country (RPO / RTO)  
  _Now:_ 🔴 Missing — Relies on provider backups.
- [ ] **NFR-20** ★ — Monitoring with real-time alerts  
  _Now:_ 🟡 Partial — Health endpoint, error log with self-heal, scheduled live checks; no APM / alerting.
- [ ] **NFR-21** ★ — Third-party risk management  
  _Now:_ 🔴 Missing — External SaaS not assessed.
- [ ] **TEC-01** ★ — Stable 24/7  
  _Now:_ 🟡 Partial — Keep-warm and live checks; no HA.
- [ ] **TEC-02** ★ — 10,000 concurrent users; response time < 1.5 s  
  _Now:_ 🟡 Partial — No load-test evidence.
- [ ] **TEC-03** ★ — 20–30 % yearly user growth without performance loss  
  _Now:_ 🟡 Partial — Horizontal scaling not designed.
- [ ] **TEC-05** ★ — Disaster recovery and business continuity with automation  
  _Now:_ 🔴 Missing — Not available.
- [ ] **TEC-16** ★ — Automatic patching without user impact  
  _Now:_ 🟡 Partial — Zero-downtime deployment not documented.

### Phase 18 — Adoption, Help Centre & Deliverables  (11)

- [ ] **UTR-01** — Role-tailored manuals with screenshots, videos and a downloadable PDF  
  _Now:_ 🔴 Missing — Only a demo scenario guide for admins.
- [ ] **UTR-02** — Staff training and Train-the-Trainer plan  
  _Now:_ 🔴 Missing — Service deliverable not prepared.
- [ ] **UTR-03** — Support channels (phone, email, Saaed)  
  _Now:_ 🔴 Missing — Not defined in the product.
- [ ] **EKT-01** — Two interactive e-learning kits produced with the centre for phase 1  
  _Now:_ 🟡 Partial — Sample-kit generator and demo courses exist; the two interactive (SCORM / H5P) kits are not produced.
- [ ] **DLV-01** — As-Is and To-Be process analysis documents  
  _Now:_ 🔴 Missing — Not prepared.
- [ ] **DLV-02** — Needs assessment, scope document, project plan, BRD  
  _Now:_ 🔴 Missing — Not prepared.
- [ ] **DLV-03** — UX design and system architecture  
  _Now:_ 🟡 Partial — ARCHITECTURE.md and API.md exist.
- [ ] **DLV-04** — Alpha, Beta and Final releases  
  _Now:_ 🟡 Partial — Working build and APK releases; no formal release gates.
- [ ] **DLV-05** — User manuals and training & adoption plan  
  _Now:_ 🔴 Missing — Not prepared.
- [ ] **DLV-06** — Test plan, test reports, bug tracker  
  _Now:_ 🟡 Partial — 199 automated tests in 40 feature files + CI; no formal plan or report.
- [ ] **DLV-07** — Go-live plan, handover report, QA certificate, SLA  
  _Now:_ 🔴 Missing — Not prepared.

## Full register by RFP module

### UX · User Interface & Experience — واجهة المستخدم

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| UX-01 | Simple, clear UI; understandable icons with short text labels | ✅ Available | Luxury government design system, labelled lucide icons, glass cards. | — |
| UX-02 | Approved visual-identity colours and modern design elements | ✅ Available | Qatar Government brand (Al Adaam maroon, Dune) + live Brand Studio. | — |
| UX-03 | Approved Lusail typeface and readable font sizes | 🟡 Partial | Lusail is first in every font stack and registered automatically from `web/public/fonts/lusail/` when the licensed files are added (see `lib/fonts.ts`, `public/fonts/lusail-README.md`); falls back to Qatar Sans → Tajawal. Body line height ≥ 1.65. **The Ministry must supply the font files** (web, mPDF, app). | 1 |
| UX-04 | Few steps per task, clear navigation, quick search for content & functions | ✅ Available | Ctrl/⌘ K global search on every page (`components/search/GlobalSearch.tsx`, `GET /search`): programs, people, trainers, kits, certificates, news and functions, permission- and scope-aware, with recent searches; app search screen (`features/search`). | — |
| UX-05 ★ | Full Arabic/English with instant switch, correct RTL/LTR, professional translation | ✅ Available | i18next (web), Flutter l10n, API X-Locale; all content stored as *_ar / *_en. | — |
| UX-06 ★ | Each user keeps a preferred language, switchable without re-login or data loss | ✅ Available | users.locale persisted; switching is instant. | — |
| UX-07 ★ | Responsive on desktop, tablet and phone, all browsers | ✅ Available | Tailwind responsive web + Flutter mobile app. | — |
| UX-08 ★ | Seamless switching between a user’s roles (trainer / trainee / manager) | ✅ Available | Role switcher in the account menu (web) and on the profile screen (app): `POST /auth/active-role`, `X-Active-Role` header, only the active role's permissions and scope count (`ActiveRole`, `ResolveActiveRole`); remembered per device; `ActiveRoleTest`. | — |

### HOM · Portal Homepage & Dashboards — الصفحة الرئيسية للبوابة

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| HOM-01 | Add / edit / delete news, activities and events | ✅ Available | News, circulars, activities and events managed in one board; events have date, venue, online and registration links, capacity, in-platform RSVP with waiting list, reminders, public events page and ICS calendar (Phase 11). | — |
| HOM-02 | Signed-in users see news and events | ✅ Available | Public news pages and portal notification centre. | — |
| HOM-03 | Export news / events to the Ministry website (API or file) | ✅ Available | Public JSON, RSS, Atom and CSV feeds (only items flagged for export), manual export files, and an automatic push adapter with retries, log and failure alerts. The push needs the Ministry site's real endpoint and key (Phase 11). | — |
| HOM-04 | Fully dynamic homepage editable by admin (texts, images, links, ads) | ✅ Available | Block-based homepage and About editor (hero, statistics, programs, news, events, rich text, call to action, logos, FAQ, video, safe HTML) with visibility windows, audience, live desktop/phone preview in both languages, versioned publish and restore; the built-in design stays until the first publish (Phase 11). | — |
| HOM-05 | Dynamic public statistics (users, courses, centre-defined figures) | ✅ Available | Public statistics from built-in sources (users, programs, groups held, certificates, hours, schools) or values and named indicators the centre defines; cached and refreshed every ten minutes (Phase 11). | — |
| HOM-06 ★ | Dashboards for every user category, driven by role | ✅ Available | A dashboard for every role from a widget registry (trainee, principal, deputy, head of training, supervisor, leadership, executive, trainer, admin, kit developer, QA, planning, logistics): scope-aware, date range, drill-down to the report, personal hide/reorder, presets editable by administrators; on web and in the app home (Phase 12). | — |

### RBA · Roles, Responsibilities & Permissions — الأدوار والمسؤوليات والصلاحيات

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| RBA-01 | Trainee | ✅ Available | Employee role + self-service web portal and mobile app. | — |
| RBA-02 | School Principal | ✅ Available | School Admin role, school-scoped data. | — |
| RBA-03 | PD Officer (Academic Deputy): approve PD records & nominations, run internal workshops | 🟡 Partial | Role `academic_deputy` (school / group / department scope, permissions `pd.approve`, `workshops.internal`, nominations). PD-record approval (Phase 09) and internal workshops (Phase 02) arrive with those phases. | 1 |
| RBA-04 | Head of Training Department | ✅ Available | Role `training_head`: assigns the program supervisor, grants per-program rights (`program_grants.manage`), approves kits (`kits.review`, `kits.publish`); `RolesManagementTest`, `ProgramGrantsTest`. | — |
| RBA-05 | Training Supervisor | ✅ Available | Role renamed «مشرف التدريب / Training Supervisor»; per-program grants for attendance, notifications, task review and kit assignment (Program → Staff & grants, `ProgramGrantService`). | — |
| RBA-06 | Centre Leadership & Policy Makers | 🟡 Partial | Role `center_leadership` with leadership dashboards and `trainers.approve`; trainer-assignment approval (Phase 02) and satisfaction alerts (Phase 08) come with those phases. | 1 |
| RBA-07 | Trainer | ✅ Available | Attendance, materials, task review. | — |
| RBA-08 | System Administrator | ✅ Available | Super Admin / Centre Admin with full permissions. | — |
| RBA-09 | Kit Developer and Quality Assurance | ✅ Available | Dedicated roles with the full kit review workflow. | — |
| RBA-10 | Head of Planning and Planning Specialist | 🟡 Partial | Roles `planning_head` and `planning_specialist` with plan, needs and instrument permissions (`plans.*`, `instruments.approve`); the tools themselves arrive in Phases 02, 03 and 08. | 1 |
| RBA-11 | Logistics Support Officer | 🟡 Partial | Role `logistics_officer` (rooms, `rooms.book`, `logistics.manage`); non-training bookings and logistics requests arrive in Phase 05. | 1 |
| RBA-12 | Create new roles and permissions when needed | ✅ Available | Settings → Roles & permissions: create a role from scratch or clone one, edit its scopes and landing page, permission matrix with diff preview, delete when unused (`RoleAdminController`, audited). | — |
| RBA-13 ★ | Permission scope: Ministry / school group / single school | ✅ Available | Roles are granted at Ministry / school group / school / department scope with an optional end date (`role_user` scope columns, `AccessScope`); school groups managed in Settings → School groups (CSV import); every list, dashboard and search is scoped (`AccessScopeTest`). | — |

### TYP · Training Types — إدارة أنواع التدريب

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| TYP-01 | In-person: registration, acceptance, attendance | ✅ Available | Full lifecycle with QR attendance. | — |
| TYP-02 | In-person: training-room allocation | ✅ Available | Room conflict check and best-fit room suggestion. | — |
| TYP-03 | In-person: pass recording and certificates | ✅ Available | Smart Certificate Engine. | — |
| TYP-04 ★ | Pre- and post-program assessment of the trainee’s level | ✅ Available | Question bank + assessments with 14 types — docs/rfp/phase-06-assessment.md | — |
| TYP-05 ★ | Synchronous remote training via Microsoft Teams | ✅ Available | Teams meetings created automatically for online sessions, updated and cancelled with the schedule, join link delivered to trainees, group teams and files. Built on the documented Graph API; not run against a live tenant (Phase 13). | — |
| TYP-06 | E-learning hierarchy: categories, programs, chapters, topics, recorded video | ✅ Available | Category → Program → Module → Lesson (video, slides, quiz, survey, article). | — |
| TYP-07 ★ | Interactive video: in-video questions / comments, pop-up control, progress gating | ✅ Available | Interactive video interactions with blocking and anti-distraction rules | — |
| TYP-08 | Chapter quizzes from a random bank, auto-graded, gate the next chapter | ✅ Available | Assessment builder: sections, random draw, difficulty mix, timer, attempts | — |
| TYP-09 | Final exams with retry rules and re-study after failure | ✅ Available | Diagnostic and comprehensive skills tests; results feed employee skills | — |
| TYP-10 | Contact the trainer and ask questions from inside the course | ✅ Available | Ask the trainer from any lesson (web and app): routed to the group's trainers with a reply deadline, trainer inbox, answer notifies the learner. Phase 14. | — |
| TYP-11 ★ | Exams taken remotely or in-centre via a secret access code | ✅ Available | Pre/post tests with knowledge gain against the 35% target | — |
| TYP-12 | Offline learning: watched content offline, sync on reconnect, resume exams | 🟡 Partial | Offline manifest and idempotent sync done; encrypted downloads and offline exams in the app not built | 10 |
| TYP-13 ★ | Anti-distraction: prevent pause, seek or minimise during video | ✅ Available | Lesson quizzes migrated to the bank; lesson gating by assessment | — |
| TYP-14 | Integrate external platforms (Coursera, edX, Udemy, LinkedIn Learning) via APIs | 🟡 Partial | External provider courses become programs with completion sync; needs real provider access | 10 |
| TYP-15 ★ | SCORM and H5P support with tracking and reuse | ✅ Available | SCORM 1.2/2004 and H5P packages upload, play, track — docs/rfp/phase-10-content-standards.md (generated-package tests; vendor packages to be tried) | — |
| TYP-16 | Admin suggests / assigns programs by history, job title or job group | ✅ Available | Recommendation engine, audience builder, centre nomination. | — |
| TYP-17 | Advertise and register for programs on other platforms (e.g., I-earn) | ✅ Available | Programs on other platforms: launch, evidence upload, centre review, hours counted | — |
| TYP-18 ★ | Blended programs (in-person + synchronous + self-paced) | ✅ Available | Per-session mode (in-person / online) plus an attached e-course. | — |
| TYP-19 | Indirect training (knowledge transfer): indirect beneficiaries, transferred hours, evidence uploads within a deadline | ✅ Available | Knowledge transfer with beneficiaries, hours, evidence, deadline and review | — |
| TYP-20 | School internal workshops approved by the centre: create, register, attendance, results, certificates | 🟡 Partial | Internal workshops (`/admin/internal-workshops`): the school submits, the centre approves with a reason (`workshops.approve`), the school registers its own staff and receives attendance and notification rights on the workshop. Certificates from a centre-approved internal template and PD hours follow in Phases 07 and 09. | 2 |

### STR · Training Structure — هيكلية التدريب

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| STR-01 | Category level | ✅ Available | Configurable program categories. | — |
| STR-02 | Main program → optional sub-programs | ✅ Available | Main program → sub-program (one level, enforced) with roll-ups: `POST /admin/programs/{id}/sub-programs`, `GET .../tree`; program page → Structure tab. | — |
| STR-03 | Training groups (cohorts) under a program with own dates, trainers, seats | ✅ Available | Training groups with own dates, seats, supervisor, room, trainers, sessions and status (`training_groups`, `TrainingGroupService`); group-aware registration, waiting list, attendance and certificates; program page → Groups tab; mobile group picker. | — |
| STR-04 | Workshop / training-day level | ✅ Available | Program sessions act as training days. | — |
| STR-05 | Assign trainers and kit developers per group / program with an assignment form and leadership approval | ✅ Available | Trainer proposal per group → trainer fills the assignment form (web + app) → leadership approves with the competent authority reference (`TrainerAssignmentService`); kit developers assigned with a due date (`POST /admin/programs/{id}/kit-developers`). | — |

### CAR · Career Paths & Professional Licences — المسارات التدريبية والترقي الوظيفي

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| CAR-01 ★ | Promotion paths linked to experience, grade and annual appraisal | ✅ Available | Promotion/specialisation paths with levels and conditions — docs/rfp/phase-09-career-pd.md | — |
| CAR-02 | Professional-licence programs for the four licence levels | ✅ Available | Four-level licence paths with validity and renewal | — |
| CAR-03 | Conditions per program / licence block progress until met | ✅ Available | Conditions engine in eligibility syntax; blocks level-restricted programs | — |
| CAR-04 | Path-compliance dashboards with automatic gain/loss notifications | ✅ Available | Compliance funnel/matrix with export; gain and loss notifications to employee and manager | — |
| CAR-05 | HR integration for experience, grades and appraisals | 🟡 Partial | HR / Mawared full and delta sync with conflict policy, leaver deactivation and signed inbound changes. Routing profile change requests to HR is not built; needs the HR interface specification (Phase 13). | 13 |

### EXT · External User Registration — نموذج تسجيل مستخدمين من خارج الوزارة

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| EXT-01 ★ | Public e-form for non-Ministry users, shareable by link | ✅ Available | Public form `/join/{slug}` for people outside the Ministry: bilingual, step by step, with e-mail verification code (rate limited), conditions (allowed domains) and a shareable link. | — |
| EXT-02 ★ | Approval workflow: notify admin, review, approve / reject with reason, email result | ✅ Available | Requests are reviewed with all their data: approve (creates the account and, for trainers, the trainer profile with an activation link), reject with a reason, or ask for more information; applicants and duplicates (e-mail, national ID) are checked. | — |
| EXT-03 | Configurable form fields, target categories and extra conditions | ✅ Available | Applicants are told by e-mail at every step; each submission has a number and an immutable PDF snapshot kept as the official record. | — |

### NDS · Needs Assessment & Annual Plan — حصر الاحتياجات وبناء الخطة التدريبية السنوية

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| NDS-01 ★ | Needs-assessment toolset that feeds the annual plan | ✅ Available | Needs workspace (`/admin/needs-hub`): cycle + proposals, manager requests, staff needs, performance data, rules, gap analysis and the competency framework; accepted items and gaps flow into the annual plan draft. | — |
| NDS-02 | Department program-proposal form in a time window (groups, axes, days/hours, kit, trainers, priority) | ✅ Available | Yearly needs cycle with an opening and closing window (`needs_cycles`); department/school proposals carry every RFP field (groups, axes, target jobs, days, hours, kit availability, trainer nominations, importance, justification); submissions outside the window are refused; reminders and auto-close (`tedc:needs-cycles`). | — |
| NDS-03 | Manager request form for institutional needs with objectives | ✅ Available | Direct-manager requests for their own staff (`institutional_requests`): need degree, objectives, employees, preferred window; reviewed (accept / merge / reject) and converted to plan items. | — |
| NDS-04 | Individual needs surveys linked to job competencies + manager approval | ✅ Available | Employees declare needs (`POST /me/needs`) and surveys create needs from low self-ratings; the direct manager approves or rejects in bulk with a note, and a configurable auto-approval after N days is audited. | — |
| NDS-05 | Rule-based needs: new hires, annual appraisals, classroom observations, specialisation, competencies | ✅ Available | Needs rules (`needs_rules`) run nightly and on demand for new hires, appraisals, classroom observations and specialisation/stage; every need carries an explanation and re-runs never duplicate. Licence and test triggers are wired for Phases 06 and 09. | — |
| NDS-06 | Automatic gap analysis vs competency framework & licence requirements, prioritised | ✅ Available | Competency framework with level descriptors and required levels per job (stage/subject overrides); current level blended from verified level, manager rating, observations and self rating with editable weights and shown evidence; gaps ranked by gap × people × licence weight with explanations, uncovered gaps listed and sent to the plan. | — |
| NDS-07 ★ | Generate the annual plan (program, audience, priority) with review and approval | ✅ Available | Annual plan (`training_plans`): generated from approved needs with an explained score per item, reviewed, returned or approved by the right role, baseline snapshot, signed copy reference, Excel/PDF export (`AnnualPlanService`, `/admin/plans`). | — |
| NDS-08 | Yearly planning rules and program types (ترخيص، تمكين، تمهين، تخصيص، تخيير) | 🟡 Partial | Yearly rules per plan (priority weights, quarter per priority, max seats and hours per group, minimum fill, carry-over) drive generation. Program-type scope, mandatory categories and total seat/hour caps are stored but not yet enforced. | 2 |
| NDS-09 | Program objectives, axes, units, competencies and summary | ✅ Available | Program axes, objectives and units with hours (`program_units`), edited on the program Structure tab; competencies through the existing skills. | — |
| NDS-10 | Program & group catalogue with tabs per category and full details | ✅ Available | Public catalogue with categories, details and eligibility check. | — |
| NDS-11 | Publish / cancel programs and edit group details | ✅ Available | Groups are created, edited, cloned, published/unpublished and cancelled with a mandatory reason and notifications to registrants, supervisor and managers; the public catalogue lists only published groups. | — |
| NDS-12 | Flag emergency (unplanned) programs for reporting | ✅ Available | Groups and plan items can be flagged emergency with a reason; the plan execution view splits planned and emergency work and lists unplanned groups as deviations. | — |
| NDS-13 | Central status board: planned, ongoing, incomplete, postponed, cancelled, completed | ✅ Available | Status board (`/admin/groups`): planned, registration open, ongoing, incomplete, postponed, cancelled, completed; drag a card to change status with the reason dialog; table view and filters; hourly lifecycle job. | — |
| NDS-14 | Real-time plan execution tracking and deviation detection | ✅ Available | Plan execution (`GET /admin/plans/{id}/execution`): planned vs created vs executed groups, seats and hours, % execution, % changed after approval, emergency share and a deviation list (late, under-filled, cancelled, postponed, unplanned); daily job `tedc:plan-deviations` notifies planning staff. | — |
| NDS-15 | Approve needs and evaluation instruments before they are distributed | ✅ Available | Needs surveys cannot be published until the planning head approves them (`instruments.approve`); returned with a note, audited and notified. Evaluation forms join the same flow in Phase 08. | — |

### ENR · Course Management & Admission Rules — إدارة الدورات التدريبية وضوابط الالتحاق

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| ENR-01 ★ | Beneficiary entities per program and seat allocation per entity | ✅ Available | Seats per group split across schools, school groups, departments and job groups with an open pool (`group_seat_allocations`, `SeatAllocationService`); the total never exceeds capacity, full entities fall back to the open pool then the waiting list, and unused seats are released hourly (`tedc:seats-release`). Program page → Admission tab. | — |
| ENR-02 ★ | Configurable registration-priority rules | ✅ Available | Configurable priority rules (`registration_priority_rules`: plan-targeted, approved need, time without training, appraisal, entity priority, job titles, registration date) rank applicants and promote the waiting list, with an explanation per person and a live preview in Admission rules. | — |
| ENR-03 ★ | Waiting list with automatic promotion | ✅ Available | FIFO waiting list, auto-promotion when a seat frees up. | — |
| ENR-04 | Block repeated or equivalent courses; configurable equivalents | ✅ Available | Equivalent programs (one- or two-way) and a per-program repeat policy (block / warn / allow); staff can override with a reason. | — |
| ENR-05 | Prerequisites per course, editable by admin | ✅ Available | Eligibility rules completed / not-completed. | — |
| ENR-06 ★ | Prevent time-conflicting registrations (switchable per course) | ✅ Available | Time clashes are blocked at registration against approved programs and at approval of a second overlapping one; the group setting `allow_overlap_until_approved` controls registering in two overlapping groups before either is approved. | — |
| ENR-07 ★ | Target criteria: gender, entity, school, job title, job group, experience, nationality, stage, specialisation | ✅ Available | Smart Eligibility Engine with per-rule explanations. | — |
| ENR-08 | Extra criteria: experience in/out Ministry & in current title, licence, grade/subject, 3-year appraisal | ✅ Available | Rule editor and audience builder gained Ministry / outside experience, years in the current title, job grade, subjects, grades taught, appraisal min/avg over 3 years and an equivalent-completed field; the licence criterion is hooked for Phase 09. | — |

### REG · Registration Mechanisms — آليات التسجيل في البرامج التدريبية

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| REG-01 ★ | Employee self-registration within the registration window | ✅ Available | Window enforced, eligibility explained. | — |
| REG-02 | Two-stage approval: direct manager (within entity seats) → training centre | ✅ Available | Self-registration goes to the direct manager (supervisor, else the school's academic deputy) and then the training centre; manager registrations skip the first stage; admin imports are approved; the group `approval_mode` can be center_only or auto; the approval path is shown to the trainee on web and in the app. | — |
| REG-03 ★ | Registration by direct manager, then centre approval | ✅ Available | School nomination → pending → centre approval. | — |
| REG-04 ★ | Registration by system admin, auto-approved, status editable later | ✅ Available | Centre nomination with audited override. | — |
| REG-05 | Bulk registration by Excel import | ✅ Available | Excel / CSV import through the same rules. | — |
| REG-06 | Acceptance tools: priority and prior-training analysis | ✅ Available | Candidate list per group with priority score and explanation, completed programs in 12 months, hours this year vs the annual minimum, same-category completions and attendance; bulk acceptance stops at the seats. | — |
| REG-07 | No approval before the registration period closes | ✅ Available | Centre approval of self-registrations is blocked until the registration window closes (`approve_after_window`), with a clear message and an audited override reason. | — |
| REG-08 | Approval / cancellation notices with program details and pass conditions | ✅ Available | Event templates per status. | — |
| REG-09 | Configurable automatic notification rules (register, cancel, missing tasks, completion) | ✅ Available | Template per event with channel toggles. | — |

### ATT · Attendance, Leave & Absence — الحضور والانصراف والاستئذان

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| ATT-01 | Paper sign-in sheets, then manual entry by an authorised supervisor | ✅ Available | Manual marking with recorded_by. | — |
| ATT-02 ★ | Direct marking by trainer / supervisor, audited | ✅ Available | Session attendance screen. | — |
| ATT-03 | Electronic signature on a tablet | ✅ Available | Tablet kiosk (`/kiosk/sessions/{id}`): large touch targets, the trainee finds their name and signs on screen to check in or out; signatures are stored privately with the record, the kiosk opening is audited, and manual entry by non-centre staff can be limited to the first N minutes. | — |
| ATT-04 | QR code per workshop / day with a configurable time window | ✅ Available | HMAC-signed QR rotating every 30 s, check-in/out, lateness. | — |
| ATT-05 | Fingerprint attendance-system integration (trainees and trainers) | 🟡 Partial | Fingerprint gateway (`FingerprintGateway`): signed webhook (generic HTTP and the ZKTeco ADMS ATTLOG push format) and CSV import match punches to the person and the session running in the device's room, ignore duplicates and report unmatched ones; device registry, test and log on `/admin/absence`. A vendor-specific pull SDK needs the Ministry's device model and network access. | 5 |
| ATT-06 | Trainer attendance and staff scanning of trainee / trainer QR | ✅ Available | Trainers record attendance by the session QR, by a staff scan of their personal QR (`/me/attendance-qr`, rotates daily), or by the supervisor; staff scan trainees the same way (grant `attendance.mark` required); QR check-in/out windows per session or globally; trainer minutes feed the hours report. | — |
| ATT-07 ★ | Teams attendance % from total participation time | ✅ Available | Attendance computed from time in the Teams call (intervals, leave and re-join, minimum presence, late), feeding the attendance percentage and absence rules; trainer marks are kept (Phase 13). | — |
| ATT-08 | Absence-threshold alert to supervisor; email to trainee & manager with notes | ✅ Available | After each session the hourly job computes absence per trainee, announces a warning and a breach once each (levels configurable), tells the supervisor and the trainee and, on breach, the direct manager; the supervisor adds a note and resends from the Absence page. | — |
| ATT-09 | Absence excuses with documents and manager approval workflow | ✅ Available | Trainees send absence excuses with documents (web and app); the direct manager approves or rejects; approved excuses mark the days `excused` and, by policy, either leave them out of the maths or count them as attended. | — |
| ATT-10 | Leave / permission (استئذان) entry with attachments and notification | ✅ Available | Supervisors record late arrival, early leave or temporary leave with minutes, reason and attachments; the minutes are deducted from attendance, the trainee is notified (policy) and a leave can be removed to restore them. | — |
| ATT-11 | Attendance records and reports; Excel and PDF export | ✅ Available | Detailed attendance and absence per trainee, per group with leave minutes, printable group sheets and the trainee's own attendance, all exportable to Excel, PDF and Word (Phase 12). | — |

### CNT · Content Management & Digital Library — إدارة المحتوى التدريبي

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| CNT-01 | SCORM and xAPI compliance for upload and playback | ✅ Available | SCORM runtime and xAPI LRS with native activity recorded as xAPI | — |
| CNT-02 ★ | Dedicated content admin panel: create, edit, share with permissions | ✅ Available | Online course builder + Training Kit Studio. | — |
| CNT-03 | Content versioning, periodic updates and long-term archiving | ✅ Available | Lesson versions: publish, keep/move learners, diff, restore, archive (quiz questions not pinned) | — |
| CNT-04 | Digital library (books, journals, AV, kits) with IP rights, audience rules, search, download | ✅ Available | Digital library with rights, audience rules, Arabic search, reader, shelves, ratings | — |
| CNT-05 | External libraries: Maktabati and Qatar National Library | 🟡 Partial | External library search (deep link / JSON API) and import; real Maktabati/QNL endpoints to be configured | 10 |

### PAS · Passing & Certificates — اجتياز البرامج التدريبية وإصدار الشهادات

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| PAS-01 ★ | Pass criteria with relative weights (attendance, participation, tasks, tests) | ✅ Available | Weighted/all-required passing policy per group, program or global — docs/rfp/phase-07-passing-rules.md | — |
| PAS-02 | Test builder: MC, multi-select, dropdown, matrix, image/video, drag-and-drop | ✅ Available | Pass mark, attempts, cooldown, restudy rule per assessment | — |
| PAS-03 | Required tasks set per course and submitted electronically | ✅ Available | Tasks with file / text submissions and versions. | — |
| PAS-04 | Trainer approves, rejects or returns tasks with notes | ✅ Available | Submission review. | — |
| PAS-05 | Final approval by course supervisor; auto-approval for self-learning | ✅ Available | Trainer-then-supervisor task approval; automatic for self-assessed tasks | — |
| PAS-06 | Objective questions auto-graded; essays graded manually | ✅ Available | Manual grading queue, regrade with replacement, release of results | — |
| PAS-07 | Pass via a comprehensive skills test without attending | ✅ Available | Pass by the comprehensive skills test without attending (test-out) | — |
| PAS-08 ★ | Certificate designer: logos, background, watermark, text, e-signature | ✅ Available | Designer from PDF / image templates, bilingual PDF (mPDF). | — |
| PAS-09 | Certificate hours: total vs actually attended | ✅ Available | Total or actual attended hours on the certificate | — |
| PAS-10 ★ | Attendance certificate vs pass certificate (or both) | ✅ Available | Attendance, pass or both certificate types with their own templates | — |
| PAS-11 | Satisfaction survey required before viewing / printing | ✅ Available | Download unlocked after the survey. | — |
| PAS-12 | Keep graduates’ records after they leave; archive and reprint | ✅ Available | Records retained; certificates re-renderable. | — |
| PAS-13 | Manual exception from the attendance condition, documented | ✅ Available | Documented, audited, revocable exceptions with reason and attachment | — |
| PAS-14 ★ | Public verification by certificate number or QR | ✅ Available | /verify page with QR. | — |

### ROM · Training Rooms & Logistics — إدارة القاعات التدريبية

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| ROM-01 | Training places with map / website link | ✅ Available | Coordinates and location details. | — |
| ROM-02 | Room details: name, type, capacity, description, floor/building, equipment | ✅ Available | Rich room catalogue. | — |
| ROM-03 | Allocate rooms to workshops by schedule | ✅ Available | Session room assignment. | — |
| ROM-04 | Book rooms for non-training use by authorised users | ✅ Available | Authorised staff book rooms for meetings, exams, events or maintenance (`/admin/room-bookings`); conflicts are checked against sessions and other bookings and show who holds the room; the occupancy calendar shows sessions and bookings together. | — |
| ROM-05 | Block double booking and show the occupying program | ✅ Available | Conflict error lists the occupying sessions. | — |
| ROM-06 | Never approve more trainees than room capacity; capacity per room / place / building | ✅ Available | Effective capacity = the lowest of the room, its building and its place; assigning a room to a session and approving registrations both refuse to exceed it (override with a reason); places → buildings → rooms hierarchy. | — |
| ROM-07 | Seating plan inside the room | ✅ Available | Seating designer per room and session or group: grid with blocked seats, manual assignment, automatic assignment (alphabetical, by school, random) with an 'insufficient seats' check, and a printable plan. | — |
| ROM-08 ★ | Weekly / monthly occupancy calendar, live free / booked view | ✅ Available | Room wall, availability view, door screens. | — |
| ROM-09 | Logistics requirements routed automatically to the logistics team | ✅ Available | Logistics requests (equipment, catering, printing, IT, arrangement) go to the logistics team's queue, are tracked New → In progress → Done with notifications to the requester, and overdue ones escalate hourly. | — |

### WDR · Withdrawal Paths — مسارات الانسحاب

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| WDR-01 ★ | After manager approval, withdrawal needs the manager’s approval | ✅ Available | A trainee withdraws freely before the manager approved while registration is open (seat released, waiting list promoted). | — |
| WDR-02 ★ | After centre acceptance: manager then supervisor approval + reason form with attachments | ✅ Available | After the manager approved, withdrawing is a request the direct manager decides. | — |
| WDR-03 | Record timing: during window / before start / after start | ✅ Available | For an approved seat the request goes to the manager and then the program supervisor, with a reason, optional attachments, and rejection notes. | — |
| WDR-04 | Free withdrawal while not yet approved | ✅ Available | Trainee can cancel. | — |
| WDR-05 ★ | Withdrawal rules configurable without code | ✅ Available | Withdrawal policy and reasons are settings (minimum days before start, allow after start, reasons that require attachments); timing is recorded (during window / before start / after start) and late withdrawals are flagged. | — |

### SRV · Surveys & Questionnaires — إدارة استطلاعات الرأي والاستبيانات

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| SRV-01 ★ | Trainee satisfaction survey | ✅ Available | Program survey (auto / manual opening) + evaluation. | — |
| SRV-02 ★ | Training-impact surveys | ✅ Available | 30 / 60 / 90-day surveys. | — |
| SRV-03 | Planning-team program evaluation form | ✅ Available | Planning-team evaluation form assigned by the planning head — docs/rfp/phase-08-evaluation.md | — |
| SRV-04 | Trainer self-reflection form | ✅ Available | Trainer self-reflection assigned at group end; results notify planning | — |
| SRV-05 | Edit questions; create surveys for any purpose | ✅ Available | Survey Studio with templates. | — |
| SRV-06 | Question types: choice, rating / stars, open, etc. | ✅ Available | Rating, NPS, choice, multiple, text. | — |
| SRV-07 ★ | Results per option with charts and tables | ✅ Available | Survey report with charts. | — |
| SRV-08 ★ | Export results to Excel, PDF and Word | ✅ Available | Excel / PDF / Word / CSV export for satisfaction, evaluation forms and needs surveys | — |

### EXM · Exams & Question Banks — إدارة الاختبارات

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| EXM-01 ★ | Final, short and diagnostic tests | ✅ Available | 14 question types incl. essay, matching, ordering, hotspot, numeric | — |
| EXM-02 ★ | Timed and open-duration tests | ✅ Available | Time limit per quiz. | — |
| EXM-03 ★ | Question types: multiple choice, multi-select, true/false | ✅ Available | Supported. | — |
| EXM-04 ★ | Question types: essay, matching, ordering, fill-in, categorisation, H5P, extensible | ✅ Available | Question banks with categories, tags, difficulty, versions, import/export | — |
| EXM-05 ★ | Question banks by course / unit / difficulty, reusable, with media | ✅ Available | Random draw by category and difficulty mix; shuffling | — |
| EXM-06 | Random selection from a bank | ✅ Available | Server-owned timer, autosave, resume, extra time | — |
| EXM-07 | Auto + manual grading, immediate / deferred feedback, question weights | ✅ Available | Static and rotating access codes for in-centre exams | — |
| EXM-08 | Attempts, time limit, show / hide results | ✅ Available | Supported. | — |
| EXM-09 | Access codes and submission timestamps | 🟡 Partial | Integrity events, thresholds, snapshots (browser consent); face check best-effort | 6 |
| EXM-10 ★ | Anti-cheating: activity tracking, face recognition | ✅ Available | Live invigilation: attempts, flags, extend, void | — |
| EXM-11 | Result analytics per trainee, group and program | ✅ Available | Item analysis, difficulty and discrimination, distractors, by group | — |

### NTF · Notifications & Announcements — إدارة الإشعارات

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| NTF-01 ★ | In-app inbox and pop-up notifications | ✅ Available | Notification centre, Supabase realtime, Firebase push. | — |
| NTF-02 ★ | Email notifications | ✅ Available | SMTP channel. | — |
| NTF-03 ★ | SMS through the Hudhud system | 🟡 Partial | Hudhud driver with Arabic UCS-2 encoding, signed delivery-receipt webhook, test button, per-event SMS switches (Phase 11). The request/receipt shape follows a configurable assumption (base URL, path, key or user, receipt secret) and must be matched to the Ministry's Hudhud interface document before go-live. | 11 |
| NTF-04 | Templates with branding and dynamic variables | ✅ Available | Bilingual templates with variables and preview. | — |
| NTF-05 ★ | Target by user type, job title, program, school | ✅ Available | Audience builder: roles, job titles, schools, school groups, program participants (by registration status), trainers, supervisors, named people; live count and sample; senders only reach their own scope (Phase 11). | — |
| NTF-06 | Scheduled notifications and allowed send times / days | ✅ Available | One-off and daily/weekly/monthly scheduled sends processed every minute; per-rule and per-channel allowed days and hours (Doha time) defer SMS, e-mail and push to the next window; delay minutes (Phase 11). | — |
| NTF-07 ★ | Automatic event-driven notifications | ✅ Available | Event catalogue + scheduler jobs. | — |
| NTF-08 | Manual notifications by admins | ✅ Available | Send dialog with audience picker. | — |
| NTF-09 | Sound or visual alert on a new notification | 🟡 Partial | Web: pop-up with a soft chime (per-user switch, browser autoplay rules respected) and a pulsing bell; mobile: system push sound and an in-app preference. No custom local-notification sound on mobile yet (Phase 11). | 11 |
| NTF-10 | Enable / disable types per user category; rules per program | ✅ Available | Notification rules per event, program category, program and audience (most specific wins), channel limits, switch-off; users choose optional channels per event group; mandatory events ignore opt-out (Phase 11). | — |
| NTF-11 | Read / unread centre and sent log (recipient, date, type) | ✅ Available | Campaign tracking. | — |
| NTF-12 | Delivery status sent / read / failed; export PDF / Excel | ✅ Available | Delivery tracking per person and channel (queued, sent, delivered, read, failed, skipped, with reasons), filters, drill-down by campaign, donut summary, Excel and PDF export in Arabic and English (Phase 11). | — |
| NTF-13 | Announcements: start/end window, several at once, pin, archive, republish, search | ✅ Available | Announcement lifecycle draft, scheduled, published, expired, archived with display window, several live at once, ordered pinning, searchable archive, republish with media copied and window reset, push/e-mail on publish (Phase 11). | — |
| NTF-14 ★ | Multimedia announcements (link, video, audio, text, image) | ✅ Available | Announcements carry images, video, audio (player on web and mobile), files and links; audience filters; optional push and e-mail (Phase 11). | — |

### KIT · Training Kits & Content Sharing — أرشفة الحقائب التدريبية ومشاركة المحتوى

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| KIT-01 ★ | Archive kits with all versions, linked to related courses | ✅ Available | Versions, restore, archive. | — |
| KIT-02 | Kit-developer account uploads Word, PDF, PowerPoint, video, images, audio | ✅ Available | Training Kit Studio. | — |
| KIT-03 | Supervisor approval, then assignment to one or more programs | ✅ Available | Kits assigned to several programs with pinned version | — |
| KIT-04 | Upload and view many resource types incl. web links | ✅ Available | PDF, DOCX, PPTX, media viewers. | — |
| KIT-05 | Share resources per course and with job groups (principals, teachers…) | ✅ Available | Job groups as sharing targets | — |
| KIT-06 | Sharing-permission settings that protect IP | ✅ Available | Per-role sharing policies, view-only protection, audited shares | — |

### PLC · Professional Learning Communities — إدارة مجتمعات التعلم المهنية

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| PLC-01 ★ | Create flexible communities by subject, interest or team | ✅ Available | Communities by subject, interest or team, with visibility and join policy, behind the `plc` flag. Phase 14. | — |
| PLC-02 ★ | Member roles (manager, moderator, member) with permissions | ✅ Available | Owner / manager / moderator / member roles, approval, ban, staff override permissions. Phase 14. | — |
| PLC-03 ★ | Votes, polls, open questions, comments, file sharing | 🟡 Partial | Polls, questions, comments and reactions are built; file sharing is by attachment reference (name + link) — no upload widget in the composer yet. Phase 14. | 14 |
| PLC-04 ★ | Meetings and events scheduling with automatic notifications | ✅ Available | Space events with RSVP, notification on creation and a reminder 24 h before. Phase 14. | — |
| PLC-05 ★ | Instant alerts on new topics and updates | ✅ Available | Instant notification on new posts, comments, mentions, join requests, plus a daily digest; each person chooses all / mentions / none. Phase 14. | — |

### EVL · Training Evaluation & Impact — تقييم التدريب وقياس الأثر

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| EVL-01 ★ | Trainee impact form after ≥ 1.5 months, with evidence uploads | ✅ Available | Trainee impact form at 45 days (configurable) with evidence | — |
| EVL-02 ★ | Manager impact form with evidence | ✅ Available | Manager impact form requested at 60 days (configurable) with evidence | — |
| EVL-03 ★ | Trainee satisfaction | ✅ Available | Program survey. | — |
| EVL-04 | Program-supervisor feedback | ✅ Available | Program supervisor feedback form | — |
| EVL-05 | Planning-specialist feedback | ✅ Available | Planning specialist feedback form | — |
| EVL-06 ★ | Pre / post comparative analysis (knowledge gain) | ✅ Available | Pre/post comparison, knowledge gain vs 35% target, distribution, per-skill, significance hint | — |
| EVL-07 | Personal interviews log | ✅ Available | Interviews log with sentiment and attachments | — |
| EVL-08 | Evaluation report: KPIs + classification (successful / needs review / weak) + recommendations | ✅ Available | Program evaluation report with classification, recommendations, approval and export | — |

### CPD · Comprehensive Professional Development — منظومة التطوير المهني الشامل

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| CPD-01 ★ | Log external PD activities with details and evidence | ✅ Available | External PD records with evidence | — |
| CPD-02 ★ | Hours calculated by activity type and participation level | ✅ Available | Hour rules per type and participation level with caps | — |
| CPD-03 ★ | Direct-manager approval of activities | ✅ Available | Direct-manager approval (approve / reject / return) | — |
| CPD-04 | Reports: total hours, distribution by domain / competency, approval rates | ✅ Available | PD reports: hours, domain, approval rates, shortfalls; Excel/PDF/Word | — |
| CPD-05 | Annual minimum-hours tracking per employee | ✅ Available | Annual minimum hours with alerts, calendar or fiscal year | — |
| CPD-06 | Request recognition of external courses (centre sets hours / equivalent programs) | ✅ Available | Recognition requests with recognised hours and equivalent programs | — |

### RPT · Reports & Analytics — التقارير والإحصائيات

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| RPT-01 | Role-specific reports + self-service dynamic report builder | ✅ Available | Report builder for non-technical people: whitelisted datasets, columns with aggregates, plain-word filters, grouping, sorting, chart, live preview, save and share, schedule; nothing typed by a user reaches SQL (Phase 12). | — |
| RPT-02 | Export reports to Excel, PDF and Word | ✅ Available | Excel (RTL, totals, one sheet per table), PDF (shaped Arabic, charts) and Word from one document model; large runs are produced by the minute job; downloads of personal data are audited; signed expiring links for scheduled sends (Phase 12). | — |
| RPT-03 | System-admin reports (employees, courses, paths, lookup, attendance, licence matrix, trainers, results, hours, periodic stats) | 🟡 Partial | Employee data, employee courses, programs-groups, courses by employee number, detailed attendance, programs/licences matrix, trainer follow-up, multi-table achievement statistics, process tracking, trainee results, programs by job category, satisfaction, approved vs actual hours, periodic statistics (Phase 12). Not covered: the 'path' column is the program category, appraisal and licence filters on the employee report, and a separate quarterly view (use month grouping). | 12 |
| RPT-04 | Supervisor reports (printable sheets, workshop calendar, supervised programs, attendance & leave) | ✅ Available | Printable attendance sheets per group, weekly/monthly workshop calendar, groups supervised in a period, attendance/absence/leave per group (Phase 12). | — |
| RPT-05 | Trainer reports (workshop calendar, delivered programs, process tracking) | ✅ Available | Trainer's workshop calendar, statistics of programs and groups delivered, process tracking of their groups (Phase 12). | — |
| RPT-06 | Direct-manager reports (team courses, nominations & approval flow, attendance) | ✅ Available | Courses obtained by staff in a period, nominations and approval flow, staff attendance and absence — limited to the manager's own staff (Phase 12). | — |
| RPT-07 | Trainee reports (calendar, annual / fiscal hours dashboard, eligible programs, history) | ✅ Available | Approved workshop calendar, courses and hours by year or academic year, programs I can apply for, completed courses, my attendance, statement of courses in a period (PDF) — always about the person asking; also in the app (Phase 12). | — |
| RPT-08 | QA and kit-developer reports | ✅ Available | Approved kits with their programs and supervisors (QA) and a kit developer's own kits and approval status (Phase 12). | — |
| RPT-09 | Leadership dashboard: plan execution %, plan changes %, high / low satisfaction groups | ✅ Available | Leadership dashboard: plan execution %, changes after approval %, achievement by school and job category, highest and lowest satisfaction, low-satisfaction alerts, drill-down to reports (Phase 12). | — |
| RPT-10 | Low-satisfaction alert (< 50 % once ≥ 80 % responded, editable thresholds) | ✅ Available | Low-satisfaction alert (response ≥80% and average <50%, editable), once per group | — |
| RPT-11 | Predictive analytics and reports | 🟡 Partial | Forecast and risk pages with explanations and plan hand-off; Excel/PDF export of forecasts is not built. Phase 15. | 15 |

### UTR · User Training, Help & Support — تدريب المستخدمين والدعم الفني

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| UTR-01 | Role-tailored manuals with screenshots, videos and a downloadable PDF | 🔴 Missing | Only a demo scenario guide for admins. | 18 |
| UTR-02 | Staff training and Train-the-Trainer plan | 🔴 Missing | Service deliverable not prepared. | 18 |
| UTR-03 | Support channels (phone, email, Saaed) | 🔴 Missing | Not defined in the product. | 18 |
| UTR-04 | In-portal issue-reporting page linked to Saaed | ✅ Available | Report-a-problem on web and in the app: category, priority, description, screenshot, page and context captured; tickets reach Saaed (queued and retried), status and number come back as notifications. Saaed's API shape is assumed (Phase 13). | — |

### EKT · Interactive e-Learning Kits — الحقائب الإلكترونية

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| EKT-01 | Two interactive e-learning kits produced with the centre for phase 1 | 🟡 Partial | Sample-kit generator and demo courses exist; the two interactive (SCORM / H5P) kits are not produced. | 18 |

### APP · Phase 2 · Multi-platform App — المرحلة الثانية · توفير تطبيق

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| APP-01 | Fast multi-platform app, fully responsive on phones, tablets, computers | ✅ Available | Flutter app (Android APK via CI, iOS-ready) + responsive web. | — |

### PAY · Phase 2 · Course Purchasing — المرحلة الثانية · شراء الدورات

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| PAY-01 | Browse catalogue, add courses to a cart, check out | 🔴 Missing | Not available. | 16 |
| PAY-02 | Pay through the Ministry e-payment gateway | 🔴 Missing | Not available. | 16 |
| PAY-03 | Entities buy course bundles for their staff | 🔴 Missing | Not available. | 16 |
| PAY-04 | Paid / free pricing per trainee category | 🔴 Missing | Not available. | 16 |

### COL · Phase 2 · Collaboration & Knowledge Sharing — المرحلة الثانية · التعاون والتواصل المعرفي

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| COL-01 | Discussion forums per program and per group | ✅ Available | Forums per programme and per group, membership following registrations; trainers moderate. Phase 14. | — |
| COL-02 | Comments and notes on lessons and materials | 🟡 Partial | Private notes and a discussion thread on every lesson; not on individual materials. Phase 14. | 14 |
| COL-03 | Content rating and reviews by trainees and trainers | 🟡 Partial | 1–5 stars and reviews with moderation for lessons, materials, kits, library items and programmes (API); the interface is on lessons only. Phase 14. | 14 |
| COL-04 | File sharing inside discussions | 🟡 Partial | Attachments can be referenced in posts and comments; no upload widget yet. Phase 14. | 14 |
| COL-05 | Private trainers’ knowledge channel | ✅ Available | Private trainers' channel for active trainers, moderated by the training team. Phase 14. | — |
| COL-06 | Instant notifications on posts; permissions per role and level | ✅ Available | Notifications on posts, per-person preferences, permissions per role (`communities.*`, `forums.moderate`). Phase 14. | — |

### AI · Phase 2 · Artificial Intelligence — المرحلة الثانية · الذكاء الاصطناعي

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| AI-01 ★ | Behavioural analytics and personalised recommendations | ✅ Available | Hybrid recommender: rule score + colleagues' completions (item similarity) + own behaviour + same-job ratings + date clashes; explained; weights, A/B test and feedback loop; falls back to the rule engine. Phase 15. | — |
| AI-02 ★ | Smart assessment with instant feedback from answer analysis | ✅ Available | Instant objective feedback (distractor analysis, competency, what to review) and essay drafts for the grader (rubric criteria, suggested score); a draft never becomes a grade by itself. Phase 15. | — |
| AI-03 ★ | Adaptive content that adjusts to each learner | 🟡 Partial | Mastery per competency, skip-module (test-out, audited) and remedial-lesson rules, personal path with reasons, AI-drafted remedial lessons reviewed by the trainer; difficulty of practice draws is not adapted. Phase 15. | 15 |
| AI-04 ★ | Predictive reports on future PD needs | ✅ Available | Next-year forecasts by competency, job title and school (Holt smoothing with range and explanation) and risk lists (hours, licences, under-filled groups, satisfaction); suggested items added to a draft plan. Phase 15. | — |
| AI-05 ★ | ML assistant that answers trainees’ questions | 🟡 Partial | Assistant answering from permitted lessons, programmes and library with citations plus the person's own records, declines off-topic, hands over to trainer or support, in-country by default; retrieval uses local hashed embeddings (no pgvector) and generating answers needs a connected model (extractive answers otherwise); not run against a real Azure endpoint. Phase 15. | 15 |

### GAM · Phase 2 · Gamification — المرحلة الثانية · التلعيب

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| GAM-01 | Points system and leaderboard | ✅ Available | Points ledger with rules, caps and reversals; weekly / monthly / term leaderboards by ministry, school, region, programme; opt-out. Phase 14. | — |
| GAM-02 | Badges and achievements | ✅ Available | Rule-based and manual badges with safe SVG icons. Phase 14. | — |
| GAM-03 | Levels (beginner → expert) | ✅ Available | Six editable levels from newcomer to pioneer; level-up notification. Phase 14. | — |
| GAM-04 | Timed challenges and rewards | ✅ Available | Timed challenges with audience, goal and reward; rewards shop with level, stock and refunds. Phase 14. | — |
| GAM-05 | Personal progress dashboard; admin-configurable; can be switched on / off | ✅ Available | Personal achievements page; studio for admins; master flag plus per-role and per-programme switches. Phase 14. | — |

### NFR · Infrastructure, Security & Backup — المتطلبات غير الوظيفية

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| NFR-01 ★ | Secure, scalable, resilient multi-layer HA design (99.9 % SLA) | 🟡 Partial | Stateless API in Docker; no HA / DR topology. | 17 |
| NFR-02 ★ | Hosting on Azure Qatar (data residency, Law 13/2016) | 🔴 Missing | Runs on Railway / Vercel / Supabase. | 17 |
| NFR-03 ★ | Production, staging (prod-identical) and development environments | 🟡 Partial | Local + one deployment; CI pipeline exists. | 17 |
| NFR-04 ★ | HLD / LLD, bill of materials, sizing and bandwidth design | 🔴 Missing | docs/ARCHITECTURE.md is a logical overview only. | 17 |
| NFR-05 ★ | Encryption at rest and in transit; joint data classification | 🟡 Partial | HTTPS, encrypted national IDs, private storage; no full classification. | 17 |
| NFR-06 ★ | Role-based access control | ✅ Available | 10 roles, 43 permissions, Supabase RLS. | — |
| NFR-07 ★ | Single sign-on and IAM integration | 🟡 Partial | OpenID Connect single sign-on with Microsoft Entra ID (PKCE, strict token validation, just-in-time accounts, group-to-role mapping with scopes, single logout, break-glass), tested against a mock IdP. SAML 2.0 is not built natively and mobile SSO is not wired; needs the Ministry tenant and app registration (Phase 13). | 13 |
| NFR-08 ★ | Auth schemes: AD, LDAP, Kerberos, certificates, tokens, OTP | 🟡 Partial | LDAP / Active Directory bind-and-search (TLS required) and TOTP, e-mail and SMS one-time codes with recovery codes. Kerberos, certificates and smart-card/FIDO2 are provided by the identity provider (documented, not coded); LDAP needs PHP's ldap extension on the host (Phase 13). | 13 |
| NFR-09 ★ | Configurable password policy and account lockout | ✅ Available | Configurable password policy (length, classes, history, expiry, breach check) and lockout with administrator unlock, audited, applied to activation and password change (Phase 13). | — |
| NFR-10 ★ | User-specific administration accounts | ✅ Available | Per-user admin accounts with roles. | — |
| NFR-11 ★ | Automatic session termination after inactivity | ✅ Available | Server-side sessions with idle and absolute lifetime; expired, ended or terminated sessions are refused immediately and cannot be refreshed; administrators list and terminate sessions (Phase 13). | — |
| NFR-12 ★ | Secure audit logs with permission-based access; login trail | ✅ Available | Append-only audit log + presence sessions. | — |
| NFR-13 ★ | Forward logs to SIEM (e.g., Splunk) | 🟡 Partial | Syslog handler available but not configured. | 17 |
| NFR-14 ★ | No production data in dev / test / training; masking | 🔴 Missing | No masking process. | 17 |
| NFR-15 ★ | VAPT, accredited code review, threat model, risk assessment, security docs | 🔴 Missing | Not prepared. | 17 |
| NFR-16 ★ | Secure SDLC: secure coding, threat modelling, code analysis | 🟡 Partial | CI runs tests, lint and Pint; no SAST / dependency scanning. | 17 |
| NFR-17 ★ | API security: encryption, validation, auth, API gateway | 🟡 Partial | Validation, JWT, throttling, security headers; no API gateway. | 17 |
| NFR-18 ★ | Patch and vulnerability management incl. third-party libraries | 🔴 Missing | No automated dependency updates. | 17 |
| NFR-19 ★ | Enterprise backup and recovery in-country (RPO / RTO) | 🔴 Missing | Relies on provider backups. | 17 |
| NFR-20 ★ | Monitoring with real-time alerts | 🟡 Partial | Health endpoint, error log with self-heal, scheduled live checks; no APM / alerting. | 17 |
| NFR-21 ★ | Third-party risk management | 🔴 Missing | External SaaS not assessed. | 17 |

### TEC · Technical Requirements & Integrations — المتطلبات التقنية والتكامل

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| TEC-01 ★ | Stable 24/7 | 🟡 Partial | Keep-warm and live checks; no HA. | 17 |
| TEC-02 ★ | 10,000 concurrent users; response time < 1.5 s | 🟡 Partial | No load-test evidence. | 17 |
| TEC-03 ★ | 20–30 % yearly user growth without performance loss | 🟡 Partial | Horizontal scaling not designed. | 17 |
| TEC-04 ★ | Central browser-based architecture for internal and external users | ✅ Available | SPA + REST API. | — |
| TEC-05 ★ | Disaster recovery and business continuity with automation | 🔴 Missing | Not available. | 17 |
| TEC-06 ★ | Real-time message-based sync between systems | ✅ Available | Outbox of domain events delivered as signed webhooks with retries, dead-letter and replay; signed idempotent inbound messages; subscriptions managed in the hub. A broker adapter (Azure Service Bus) follows in Phase 17 (Phase 13). | — |
| TEC-07 ★ | Ministry integrations: Licences, NSIS, QNEDS, HR / Mawared, AD, Saaed, Sijil, Ministry website | 🟡 Partial | Integration hub with monitored adapters (health, logs, retries, circuit breaker) for HR, Mawared, licences, NSIS, QNEDS, Saaed, Sijil and the Ministry site. The real system specifications are needed; none was run against the real systems (Phase 13). | 13 |
| TEC-08 ★ | LTI 1.1 and LTI 1.3 with Deep Linking | 🟡 Partial | LTI 1.1/1.3 platform with Deep Linking, AGS, NRPS done; TEDC as an LTI tool not built | 10 |
| TEC-09 ★ | xAPI, IMS Caliper, SCORM, QTI 1.1 / 2 / 2.1, cmi5 | ✅ Available | xAPI LRS, Caliper 1.2, SCORM, QTI 2.1/1.2, cmi5 implemented (ADL conformance suite still to run) | — |
| TEC-10 ★ | HTML5 content | ✅ Available | HTML5 video, slides and articles. | — |
| TEC-11 ★ | Common Cartridge import (full or selected parts) | ✅ Available | Common Cartridge 1.1-1.3 / thin CC preview and selective import | — |
| TEC-12 ★ | Advanced Teams: Office 365 forms, structure sync, file sharing, live streaming | 🟡 Partial | Meetings, attendance by duration, Team per group with membership sync, channel files and Forms results import (CSV export). Live events are link-only and Teams activity-feed notifications are not wired (Phase 13). | 13 |
| TEC-13 ★ | Trusted content-provider integration | 🟡 Partial | Provider adapter framework (generic REST + demo driver); real Coursera/edX/Udemy/LinkedIn APIs need credentials | 10 |
| TEC-14 ★ | Compatible with phones and tablets | ✅ Available | Responsive web + Flutter app. | — |
| TEC-15 ★ | Cost-effective licensing (perpetual preferred) | ✅ Available | Custom-built, owned source code; no per-user licence. | — |
| TEC-16 ★ | Automatic patching without user impact | 🟡 Partial | Zero-downtime deployment not documented. | 17 |
| TEC-17 | Live KPI dashboard: response time, concurrency, uptime, completion, active users, satisfaction, knowledge gain, security | ✅ Available | Live KPI dashboard of the twelve RFP indicators against editable targets with 30-day trends, breach alerts to administrators, data-integrity detail and a monthly PDF/Word report. Concurrency shows current and peak users, not a proven capacity; uptime comes from an in-app probe (Phase 12). | — |

### DLV · Project Deliverables — مخرجات المشروع والمتسلمات

| ID | Requirement | Status | Evidence / gap | Ph |
|---|---|---|---|---:|
| DLV-01 | As-Is and To-Be process analysis documents | 🔴 Missing | Not prepared. | 18 |
| DLV-02 | Needs assessment, scope document, project plan, BRD | 🔴 Missing | Not prepared. | 18 |
| DLV-03 | UX design and system architecture | 🟡 Partial | ARCHITECTURE.md and API.md exist. | 18 |
| DLV-04 | Alpha, Beta and Final releases | 🟡 Partial | Working build and APK releases; no formal release gates. | 18 |
| DLV-05 | User manuals and training & adoption plan | 🔴 Missing | Not prepared. | 18 |
| DLV-06 | Test plan, test reports, bug tracker | 🟡 Partial | 199 automated tests in 40 feature files + CI; no formal plan or report. | 18 |
| DLV-07 | Go-live plan, handover report, QA certificate, SLA | 🔴 Missing | Not prepared. | 18 |
| DLV-08 | Data-migration strategy and tooling (validation, cleansing, transformation, secure transfer) | ✅ Available | Data-migration toolkit for employees, trainers, programs, registrations, attendance, certificates and PD: templates, mapping and value conversion, Arabic-aware cleansing, validation report, dry run, chunked import, reconciliation and rollback, audited and secured; strategy in docs/rfp/data-migration.md (Phase 13). | — |
| DLV-09 | Compliance sheet and RFP traceability matrix | ✅ Available | Settings → RFP Compliance (`pages/admin/RfpCompliance.tsx`, `GET /admin/rfp-status`), `docs/rfp/compliance-sheet.md`, `php artisan tedc:rfp-status`, `RfpStatusTest`. | — |

## The 31 mandatory items (المتطلبات الرئيسية)

| # | Requirement | Status | Notes |
|---:|---|---|---|
| 1 | Ready, customisable product | ⚪ Vendor | Vendor qualification — answered in the proposal, not by the software. |
| 2 | Support team, developers and PM based in Qatar | ⚪ Vendor | Vendor qualification. |
| 3 | ≥ 10 years’ experience with ministry-scale clients | ⚪ Vendor | Vendor qualification. |
| 4 | ≥ 5 similar projects delivered | ⚪ Vendor | Vendor qualification. |
| 5 | Core business is digital solutions | ⚪ Vendor | Vendor qualification. |
| 6 | Company office in Qatar | ⚪ Vendor | Vendor qualification. |
| 7 | Bilingual, responsive, all browsers, seamless role switching | 🟡 Partial | Everything except role switching (UX-08). |
| 8 | Role-based dashboards with interactive indicators | 🟡 Partial | Missing for 6 roles (HOM-06). |
| 9 | Pre/post assessment of trainee level (in-person) | 🟡 Partial | Self-typed scores, no real tests (TYP-04). |
| 10 | Synchronous training via Microsoft Teams | 🟡 Partial | Links only, no Graph integration (TYP-05). |
| 11 | Interactive video, access-code exams, anti-distraction, SCORM / H5P | 🔴 Missing | Only the anti-distraction controls exist (TYP-07/11/13/15). |
| 12 | Blended training | ✅ Available | Mixed session modes + e-course (TYP-18). |
| 13 | Tool to build career-promotion training paths | 🔴 Missing | CAR-01. |
| 14 | E-form to register users from outside the Ministry | 🔴 Missing | EXT-01..03. |
| 15 | Individual & institutional needs tools + annual plan | 🟡 Partial | Surveys yes, plan no (NDS-01, NDS-07). |
| 16 | Entities, seats, priority, waiting list, targeting, time-conflict prevention | 🟡 Partial | Waiting list & targeting yes; seats, priority, conflicts no (ENR). |
| 17 | Self, manager and admin registration | ✅ Available | All three channels + Excel import (REG-01..05). |
| 18 | Direct attendance by trainer + Teams duration-based attendance | 🟡 Partial | Teams part missing (ATT-07). |
| 19 | Content admin panel: create, edit, share | ✅ Available | Course builder + Kit Studio (CNT-02). |
| 20 | Pass rules, multiple certificate types, QR / number verification | 🟡 Partial | Weights and certificate types missing (PAS-01, PAS-10). |
| 21 | Room occupancy calendar (week / month) | ✅ Available | ROM-08. |
| 22 | Flexible withdrawal rules | 🔴 Missing | WDR-01..05. |
| 23 | Survey types, per-option analytics, Excel / PDF / Word export | 🟡 Partial | CSV export only (SRV-08). |
| 24 | Kit archiving with versions linked to courses | ✅ Available | KIT-01. |
| 25 | Professional learning communities | 🔴 Missing | PLC-01..05. |
| 26 | Impact measurement with trainee & manager forms, pre/post comparison | 🟡 Partial | Forms partial, comparison missing (EVL). |
| 27 | Exam variety, categorised banks, anti-cheating | 🟡 Partial | Banks and anti-cheating missing (EXM). |
| 28 | Integrated notifications + multimedia scheduled announcements | 🟡 Partial | Scheduling, Hudhud, audio missing (NTF). |
| 29 | Comprehensive PD records outside the Ministry | 🔴 Missing | CPD-01..06. |
| 30 | Permissions at Ministry / school-group / school level | 🟡 Partial | No school-group scope (RBA-13). |
| 31 | AI: behaviour analytics, smart feedback, adaptive content, predictive reports, ML assistant | 🟡 Partial | 2 of 5 partially present (AI-01..05). |
