# Phase 05 — Attendance, leave and absence, rooms and logistics

**Closes:** ATT-03, ATT-06, ATT-08, ATT-09, ATT-10, ROM-04, ROM-06, ROM-07, ROM-09 · ATT-05 partly (see the gap register).

## Attendance methods
- QR (session code) with check-in / check-out windows (per session or in Settings → Attendance), signature kiosk, staff scan of a personal QR, fingerprint devices, manual entry (limited to N minutes for non-centre staff when configured), paper (printable blank sheet with a QR).
- Trainer attendance: `trainer_attendance` by QR, staff scan, device or the supervisor.
- Fingerprint: `POST /api/v1/integrations/fingerprint/{device}/punches` with `X-Signature` = HMAC-SHA256 of the raw body using the device secret; JSON `{punches:[{person_ref, punched_at, direction}]}` or ZKTeco ATTLOG text. CSV import per device.

## Absence, excuses, leave
- `tedc:operations-hourly` raises absence alerts (warning, breach) once each, and escalates overdue logistics requests.
- Excuses: trainee → direct manager (Approvals inbox → Absence excuses). Leave: supervisor records minutes, deducted from attendance.

## Rooms and logistics
- Places → buildings → rooms with capacity limits; bookings; occupancy calendar; seating plans; logistics board (`/admin/logistics`).

## Permissions
`attendance.devices`, `excuses.decide`, `leaves.manage`, `seating.manage`, `places.manage` (plus the existing `rooms.book` and `logistics.manage`).

## Known limits
- Fingerprint devices that need a vendor pull SDK are not reachable from the cloud; use the push webhook or the CSV import (ZKTeco ADMS push is understood).
- Teams meeting attendance arrives with Phase 13.
- Mobile: personal QR, trainer self-scan and excuse submission are in the app; scanning other people's QR (staff mode) is on the web (paste) for now.
