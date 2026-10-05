# Phase 07 — Weighted passing rules and certificate types

**Closes:** PAS-01, PAS-05, PAS-07, PAS-09, PAS-10, PAS-13.

## Passing policy
- One policy per scope: **group → program → global** (the first that exists applies). With none, the old rule applies unchanged: every requirement of the program (attendance, required tasks, survey, course) must be met.
- Criteria: attendance, participation, tasks, assessments, e-course, evaluation. Each has a minimum, a weight and a "required" flag.
- Modes: *every criterion met*, or *weighted score* (weights add up to 100 %, pass mark, required minimums still apply). A score exactly at the pass mark passes.
- Participation = average of the enabled signals: lesson activity and the trainer's participation mark on the session roster.
- Assessments: the policy lists the counted assessments and their weights, otherwise every published final / quiz / post-test / comprehensive assessment of the program counts (the test-out test excluded).
- Screen: program → **Passing rules** (program or one group) and **Global policy** in the admin menu, with a live simulation on the current participants. Every change recomputes the registrations it covers.

## Task approval
`trainer` (as before) · `trainer_then_supervisor` (the trainer's approval waits as *pending final* until the supervisor — `tasks.final_approve` — approves, returns or rejects) · `auto` (tasks marked *self-assessed* are approved when submitted). Returns are counted; the trainee is told each time.

## Pass without attending (test-out)
With `allow_test_out`, the trainee starts the comprehensive assessment from *My progress* (`POST /me/programs/{p}/test-out/start`, a normal Phase 06 attempt). Passing marks `passed_via = test_out` and issues the pass certificate.

## Exceptions
`pass_exceptions.grant` (system admin) grants an exception for one criterion with a **mandatory reason and attachment**; it is audited, shown on the trainee's record and revocable (the status is recomputed). A pass that needed an exception shows `passed_via = exception`.

## Certificates
- Types `attendance`, `pass` or `both` per policy; one certificate of each type per registration. The attendance certificate needs the policy's minimum attendance; the pass certificate needs the policy to be met.
- Hours: total program hours or actual attended hours (rounded to half an hour), both stored on the certificate. Template per type; verification shows the type.
- The survey-before-download rule is a policy switch (on by default). Existing certificates keep working (`type = pass`).

## Permissions
`passing.manage`, `pass_exceptions.grant`, `tasks.final_approve`.

## Notes and limits
- `knowledge_transfer` as a criterion arrives with Phase 09 (it needs the knowledge-transfer records).
- Forum / PLC activity as a participation signal arrives with Phase 14 (the signal list is pluggable in `PassingPolicyService::participation`).
- The pass certificate notification keeps the existing key `certificate.available`; the attendance one is `certificate.attendance_issued`.
- Mobile: *My progress* (criteria, next steps, test-out) and the certificate wallet show both types.
