# Phase 02 — Training structure, groups and the annual plan

**Closes:** STR-02, STR-03, STR-05, NDS-07, NDS-09, NDS-11, NDS-12, NDS-13, NDS-14 · partly TYP-20 and NDS-08 (see the gap register).

## What was built
- **Structure:** category → main program → optional sub-program (one level) → training group → sessions. Axes, objectives and units live on the program (`program_units`).
- **Groups (`training_groups`):** own dates, seats, supervisor, room, trainers, status (planned → registration open → ongoing → completed, plus incomplete / postponed / cancelled) with mandatory reasons and notifications. Every program has at least one group; single-group programs keep the old behaviour. Registration, waiting list, attendance and certificates are group-aware. Hourly `tedc:group-lifecycle` moves multi-group programs automatically.
- **Trainer assignment:** propose a trainer (schedule conflicts checked) → the trainer fills the assignment form (web `/admin/my-assignments`, app profile → My proposals) → leadership approves with the competent authority reference or rejects with a reason. Kit developers are assigned with a due date.
- **Annual plan:** `/admin/plans` — rules per year, generation from approved needs with an explained score, review (return with comment), approval (baseline snapshot), activation, change log with mandatory reasons after approval, execution view (% execution, % changed, emergency share, deviations), Excel and PDF export. Daily `tedc:plan-deviations` (07:00) notifies planning staff.
- **Internal workshops:** `/admin/internal-workshops` — a school submits, the centre approves, the school registers its staff (by employee number) and is granted attendance and notification rights for that workshop only.
- **Status board:** `/admin/groups` — kanban + table, drag to change status.

## Permissions added
`groups.manage`, `groups.status`, `workshops.approve`, `trainers.respond` (plus `plans.approve` for centre leadership).

## Notifications added
`trainer.assignment_proposed`, `trainer.assignment_decided`, `kit.developer_assigned`, `plan.submitted|returned|approved|deviation_detected`, `internal_workshop.submitted|decided`, `group.status_changed|postponed|cancelled`.

## Known limits
- The signed plan PDF is stored as a reference, not uploaded through the UI yet.
- Plan rules for program types, mandatory categories and total seat/hour caps are stored but not enforced in generation (NDS-08 stays partial).
- Internal workshop certificates from a centre-approved template and PD hours depend on Phases 07 and 09 (TYP-20 stays partial).
- Word (.docx) export of the plan arrives with the document tooling in Phase 18.
