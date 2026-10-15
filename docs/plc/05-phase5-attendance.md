# PLC (مجتمعات التعلم المهنية) — Phase 5 report: attendance

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Phase 4](04-phase4-scheduling.md).

Phase 5 puts attendance on the engine's attendance service and adds what the brief asks for on top: the rotating check-in / check-out code per session with revocation, late and left-early under the PLC policy, one channel per person in hybrid sessions (the code at the venue or the remote join), corrections that always carry a reason, a lock per session that writes down absentees, a live roster with the rejected attempts, the workshop report with export, and the operational states moved by the clock (ready a day before, in progress at the first session, completed after the last with every record locked). The mobile app needs no change: it scans the same code and joins the same way, and the server now accepts both for hybrid sessions. Everything stays behind `plc`.

## 1. Files changed

New (backend)
- `database/migrations/2026_01_01_007300_plc_attendance_channels_and_locks.php` — `attendance.channel`, `correction_reason`, `corrected_by`, `corrected_at`; `program_sessions.attendance_locked_at`, `attendance_locked_by`.
- `app/Services/Plc/WorkshopAttendance.php` — `roster()` (seated people with their record, status including `expected` before the end and `absent` after it, channel, late minutes, corrections; counts; the rotating code for venue and hybrid sessions; the last rejected attempts; what the viewer may do), `issueCode()` (the first code of a scheduled workshop makes it ready), `revoke()` (a new secret; every shown or photographed code dies), `correct()` (reason required; `left_early` with its minutes; refused after the lock unless the person holds `plc.manage`; the before and after recorded), `lock()` (absentees written down, then the record is fixed), `unlock()` (centre, with a reason), `report()` and `csv()` (every seated person against every live session, rates per session, overall and per school, how many reached the programme's minimum).
- `app/Services/Plc/WorkshopLifecycle.php` and `app/Console/Commands/PlcTick.php` — `tedc:plc-tick` hourly and in the Vercel cron: scheduled → ready 24 hours before the first session, ready → in progress at its start, in progress → completed after the last session ends with every session locked; each move is a decision by the coordinator (or the author) on the system's behalf.
- `app/Http/Controllers/Api/V1/Admin/Plc/WorkshopAttendanceController.php` — `GET workshops/{plcWorkshop}/sessions/{plcSession}/attendance`, `POST …/attendance/code|revoke|correct|lock|unlock`, `GET workshops/{plcWorkshop}/attendance` and `…/attendance/export` (`plc.attendance.manage|plc.manage|attendance.manage|attendance.mark`).
- `tests/Feature/PlcAttendanceTest.php`.

Changed (backend)
- `AttendanceService` — PLC workshops follow the PLC policy for "late" and gain "left early" on check-out or remote leave before the policy's margin; a code check-in records the `in_person` channel and a remote join the `remote` one; hybrid sessions accept both but one person holds one channel only (`channel_taken`); remote join now accepts hybrid sessions. Non-PLC programmes keep the platform's behaviour (the existing attendance suites pass unchanged).
- `Registration::attendanceFor()`, `ProgramSession::attendanceLocker()`, fillables and casts; routes; `routes/console.php`; `SystemController` (cron list) and its test; `lang/{ar,en}/messages.php` (`plc.code_mode`, `correction_status`, `correction_reason`, `attendance_locked`; `attendance.channel_taken`).

New / changed (web)
- `src/pages/admin/plc/Attendance.tsx` — the session page: counters, the roster refreshing every ten seconds with status, channel, lateness, in/out and minutes, the rotating code on request with revocation, corrections in a dialog with the reason, lock and unlock, the rejected attempts; the workshop report page: the matrix of people against sessions with a sticky name column, the rate per session, overall and per school, CSV export.
- `ScheduleStep.tsx` (an attendance button per session and a report card once invited), `WorkshopDetail.tsx` (a link to the report), `App.tsx` (two routes), `i18n/plc.ts` (`plc.attendance.*`).

## 2. What the person can do now

- Open a session's attendance page, show the rotating code on the venue screen, revoke it if it leaked, and watch the roster fill in live: who is in, through which channel, who came late, who left early, who tried to scan and was refused and why.
- Correct a record by hand with a reason (present, late, left early with minutes, absent, excused); lock the session so absentees are written down and nothing changes without the centre.
- Read the workshop's report: every person against every session, the rate per session, overall and per school, and export it.
- Trainees keep using the mobile app exactly as before: scan at the venue, or join remotely for remote and hybrid sessions.

## 3. Tests executed and actual results

- `php artisan test --filter="PlcAttendanceTest"` → passed, 1 test: a scheduled workshop with three seated people and two sessions (in person, hybrid); a plain employee cannot read the roster; the clock leaves it scheduled 30 hours before and makes it ready 20 hours before; in progress once the first session started; a check-in 20 minutes after the start is late under the PLC policy and recorded as `in_person`; the roster counts; the revoked code is refused and the fresh one accepted; a check-out well before the end is `left_early`; a correction without a reason is refused and with a reason recorded as a decision, as is the revocation; in the hybrid session the remote join records the `remote` channel, the same person's scan is refused (`channel_taken`), another person's scan is in person and their join refused; the clock completes the workshop after the last session, locks it and writes the absentee down; the centre may still correct after the lock; unlocking needs a reason; the report lists both sessions with the right statuses and rates and the CSV carries the names.
- Combined with `AttendanceAndCertificateTest|AttendanceMethodsTest|GeofenceAttendanceTest|SystemEndpointsTest` → 33 tests, 257 assertions, all passing (the shared attendance engine's behaviour for ordinary programmes is unchanged).
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only pre-existing warnings.
- Playwright sweep in Arabic against the local servers: the session page with the code shown, the correction dialog, and the report page.

## 4. Security-sensitive changes

- Codes remain the engine's rotating HMAC payloads bound to a session secret; revocation rotates the secret. Codes are returned only to people allowed to manage attendance.
- Every correction, revocation, lock and unlock is a decision with the actor and reason; corrections keep the previous values.
- Session links and passcodes are handed out by the engine only inside the join window to seated people, as before.

## 5. Known limitations

- "Ready" is reached by the clock or by the first code shown; the brief's "T-24h" is the clock's rule and is fixed at 24 hours (not yet a setting).
- Attendance by a trainer's own code (staff scan) and leaves of absence keep their existing screens outside the PLC pages.
- The report counts the programme's minimum attendance but does not yet drive certificates or evaluation eligibility (Phase 6).

## 6. Exact next step

Phase 6 — evaluation: suggestion rules per source and mode, timing, session and trainer targets, deduplication, tracking and impact follow-ups on the Survey Studio engine. Report in `docs/plc/06-phase6-evaluation.md`.
