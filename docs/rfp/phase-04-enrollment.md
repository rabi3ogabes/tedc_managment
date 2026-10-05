# Phase 04 — Enrollment engine, withdrawals and external registration

**Closes:** ENR-01, ENR-02, ENR-04, ENR-06, ENR-08, REG-02, REG-06, REG-07, WDR-01, WDR-02, WDR-03, WDR-05, EXT-01, EXT-02, EXT-03.

## Behaviour
- **Seats:** `group_seat_allocations` per school / school group / department / job group; whatever is not allocated is the open pool. A registration draws from the person's school, then school group, department, job group, then the open pool; if all are full the person is waitlisted. `tedc:seats-release` (hourly) gives unused seats of due allocations to the open pool and promotes waiting people.
- **Priority:** rule (group → program → global → defaults) ranks applicants and the waiting list; `priority_score` and an explanation are stored on the registration.
- **Repeat / equivalents:** `program_equivalences` + `programs.repeat_policy`.
- **Clashes:** `ConflictService` compares sessions with approved registrations (or every seat-holding one when the group disallows overlap until approval).
- **Approval:** `pending_manager` → `pending` (the centre stage; the stored value stays `pending` so existing screens keep working) → `approved`. Manager = employee's supervisor, else the school's academic deputy; none found → straight to the centre. Centre approval of self-registrations waits for `registration_closes_at` unless an override reason is given.
- **Withdrawal:** direct (before manager approval, window open) or a request to the manager and, for approved seats, the supervisor. Policy `withdrawal.policy` and reasons are editable.
- **External registration:** `/join/{slug}` → e-mail code → request `REQ-yy-nnnn` with a PDF snapshot → review → account + activation link (`POST /auth/activate`).

## Permissions
`seats.manage`, `priority.manage`, `registrations.approve_manager`, `registrations.approve_center`, `withdrawals.decide`, `withdrawals.policy`, `external_forms.manage`, `external_requests.review`.

## Known limits
- Two registrations racing for the last seat are serialised by a row lock on the program; the automated test suite runs on a single connection and cannot exercise true parallelism.
- Mobile: withdrawal sends a reason but cannot attach a document yet (the web form can); managers approve registrations in the app, withdrawals and external requests stay on the web.
- The e-mail goes through the mail server chosen in Settings → Channels; nothing is sent until one is configured.
