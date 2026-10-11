# TEDC Educational Training Portal Constitution

## Core Principles

### I. Arabic-First Bilingual
Every screen, message, notification, PDF, export and e-mail MUST exist in Arabic and English, with
Arabic as the primary language. Layouts MUST mirror correctly for RTL and LTR, mixed-direction text
MUST be isolated (`<bdi>`), and numbers MUST use Western digits. No user-facing string may be
hard-coded outside the translation files.
Rationale: the Ministry serves Arabic-speaking staff first, and the RFP requires full bilingual parity.

### II. Test-First (NON-NEGOTIABLE)
Every behaviour change and every bug fix MUST start with an automated test that fails for the
expected reason, then the minimal code that makes it pass. A change is not done until the full
backend suite, the web build and lint, and the mobile analyzer and tests pass.
Rationale: a test that never failed proves nothing, and the platform is audited requirement by
requirement.

### III. Least Privilege and Scope
Every endpoint MUST check a permission, and every list, export, report and dashboard MUST be
filtered by the active role and its scope (Ministry, school group, school, department). The UI MUST
hide or disable what the person may not do, but it MUST NOT be the only check.
Rationale: one account can hold several roles across several schools, and leaks across schools are
unacceptable.

### IV. Audit Everything Sensitive
Approvals, rejections, overrides, exceptions, role and scope changes, settings changes, security
actions and personal-data exports MUST write an audit record with the actor, the old and new values,
the reason where one is required, and the IP address.
Rationale: the Ministry must be able to reconstruct who decided what, and Law 13/2016 requires
accountability for personal data.

### V. Integrations Through Adapters
Every external system (identity, Microsoft 365, Ministry systems, SMS, payment, devices, content
providers, AI) MUST sit behind an adapter with a real driver and a fake driver for development and
tests, encrypted settings, a health check, a call log and a test action. A fake driver MUST NEVER
be the production default.
Rationale: the real systems arrive late and change; adapters make going live a configuration step.

### VI. Configurable Policy
Thresholds, windows, weights, limits and workflows that the RFP describes as policy MUST be
editable from the administration screens without code, with safe defaults (the RFP's example values),
validation and an audit trail.
Rationale: the centre changes its rules every year and must not wait for a release.

### VII. Honest Status
No requirement may be reported as available before its Definition of Done is met. Work that depends
on an outside input is reported as "ready, waiting for" that exact input; a test, drill or audit
that has not run is reported as "not run yet". No result may be claimed without evidence.
Rationale: the compliance sheet is part of a government bid, and overstating it is a liability.

### VIII. Production Safety
Tests, scans, load tests and drills MUST NEVER target production. Cloud resources MUST NOT be
created or changed without the owner's explicit approval for that action. Production MUST NEVER run
demo mode, ship the shared demo password to clients, or have its passwords set by a deployment.
Rationale: production holds real people's data and costs real money.

### IX. Accessible, Luxurious Interface
Interfaces MUST use the existing theme tokens (never hard-coded brand colours), meet WCAG AA contrast,
label every control, keep focus visible, support the keyboard, and provide loading, empty, error and
success states. Motion MUST be purposeful and respect reduced-motion preferences.
Rationale: the product represents the Qatar Government identity and must be usable by everyone.

### X. PostgreSQL-Correct Data
Migrations MUST be additive with a working `down()`, use UUID keys, and be valid on PostgreSQL as
well as SQLite (UUID columns, varchar lengths, integer types). Every new table MUST have valid sample
data in the non-production seeders and a classification for every column.
Rationale: production runs PostgreSQL, which rejects what SQLite tolerates.

## Technology & Compliance Constraints

- Stack: Laravel 13 (PHP 8.4) API, React 19 + TypeScript + Tailwind v4 web, Flutter 3 mobile app;
  Supabase/Vercel today, Azure Qatar Central as the target platform.
- Data residency and privacy follow Qatar Law No. 13 of 2016; biometric processing (such as face
  presence in exams) requires explicit consent, a fair alternative and a documented retention period.
- Routes MUST be controller classes (no closures), because deployments cache routes.
- Files go through the storage service with server-side type and size validation; large browser
  uploads use signed direct uploads.
- Secrets MUST NEVER be committed; settings screens store credentials encrypted.

## Development Workflow & Quality Gates

- Each phase starts from its prompt in `docs/rfp/closeout/` and the open items list, and ends with an
  updated gap register (`scripts/rfp_mark.py`, `scripts/rfp_sync.py`, `php artisan tedc:rfp-status`).
- Gate before reporting a phase: `php artisan test`, `vendor/bin/pint --test`, the regenerated data
  classification register, `npm run lint && npm run build`, `flutter analyze && flutter test`, and the
  Arabic/English key check for the app.
- Every new or changed screen is exercised in the running app as each affected role, in Arabic and
  English, at desktop and phone width, light and dark, with no console errors.
- Changes are committed and pushed after each phase; CI, the security workflow and the deployment
  MUST be green before the phase is called done.
- Decisions that belong to the owner (security posture, privacy trade-offs, paid resources, outward
  actions such as releases) are asked, never assumed.

## Governance

This constitution supersedes other practices in this repository. Every plan, specification and task
list produced with Spec Kit MUST be checked against it, and reviews MUST reject work that breaks a
principle. Amendments are made by editing this file with a version bump (MAJOR for removing or
redefining a principle, MINOR for adding one or materially expanding guidance, PATCH for wording), a
short rationale in the commit message, and an update of any guidance that depends on the changed
rule. Runtime development guidance lives in `docs/rfp/closeout/00_MASTER_PROMPT.md`.

**Version**: 1.0.0 | **Ratified**: 2026-10-11 | **Last Amended**: 2026-10-11
