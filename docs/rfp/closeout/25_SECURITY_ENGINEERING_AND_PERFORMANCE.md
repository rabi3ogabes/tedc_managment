# Phase 25 — Security engineering and performance: clean scans, typed code, a governed API gateway, patch evidence, measured capacity

> Run after Phase 24. All rules in `00_MASTER_PROMPT.md` apply. Skills: `security-review` (throughout), `systematic-debugging`, `test-driven-development`, `verification-before-completion`, `requesting-code-review`.

## Goal
Turn the security and performance **documents** into **evidence**:
- static analysis at Larastan's top level and every scanner clean;
- DAST running in CI against a real local stack;
- an OpenAPI description of the whole API, published into the API-gateway code with partner quotas;
- an automatic patch history;
- a pre-VAPT self-assessment with fixes;
- performance measured and tuned on a production-like local stack, ready for the 10,000-user run on Azure staging.

## Closes
`NFR-16 ★` secure SDLC (code analysis) · `NFR-17 ★` API security incl. API gateway · `NFR-18 ★` patch and vulnerability management · `NFR-15 ★` VAPT, threat model, security docs · `TEC-02 ★` 10,000 concurrent users, < 1.5 s · `TEC-03 ★` 20–30 % yearly growth.

## What exists today (verified 11 Oct 2026)
- **Security workflow** (`.github/workflows/security.yml`): gitleaks; `composer audit --locked`; `npm audit --audit-level=high`; OSV (both lockfiles); Semgrep (`p/php p/typescript p/owasp-top-ten p/secrets`, ERROR only); Trivy image (HIGH/CRITICAL, `ignore-unfixed`, `.trivyignore`) and IaC.
  - **Missing:** Larastan/PHPStan (not installed; the master plan names "Larastan max level"); DAST in CI (an OWASP ZAP baseline exists **only** in `deploy-azure.yml`); Semgrep WARNING-level findings are not reviewed.
- **Dependencies.** Dependabot (`.github/dependabot.yml`: composer, npm, pub, actions, docker, weekly). Patching SLAs are in `docs/security/patching.md`, which also holds an **open exception table** (CVE-2026-78669, expires 2026-11-10). There is **no operating history**: no log of advisories, PRs and time to fix.
- **API.** Throttles and security headers; `docs/API.md` is a hand-written endpoint list. **There is no OpenAPI description.** The APIM resource exists in `infra/` (Bicep `edge.bicep`/`compute.bicep`), but **no API definitions, products, subscriptions or quotas**.
- **VAPT.** `docs/security/{vapt-readiness,threat-model,ssdlc,compliance-matrix}.md`. No accredited test has been done.
- **Performance.** k6 in `perf/` (`scenarios.js` ramps to PEAK with browse 50 %, portal 35 %, registration and check-in bursts and an exam wave; `lib.js` thresholds p95 < 1.5 s). `perf/README.md` notes that the perf-user seeder does not exist. `docs/ops/performance-plan.md` is a template. **Nothing has been run.** Phase 17 also listed Octane/FrankenPHP worker mode, a read replica and PgBouncer tuning as not done.

## 1. Static analysis to the top level (NFR-16)
- Add `larastan/larastan` (dev) and `phpstan.neon` (paths `app`, `routes`, `database/seeders`; Laravel extension). Start at level 5 and fix **all** findings, then raise level by level to **max**. Fix the code properly: types, null-safety, generics in docblocks for collections. **Never** use a growing baseline file; if a finding is a genuine false positive, use a narrow, commented `@phpstan-ignore` with a reason.
- Add a `static` job to `ci.yml` (`vendor/bin/phpstan analyse --memory-limit=1G`).
- **Semgrep:** run WARNING level once, triage every finding (fix it, or document a false positive in `.semgrepignore` with the reason), then gate on WARNING for `p/owasp-top-ten`.
- **TypeScript:** add the `typescript-eslint` "strict-type-checked" rules progressively, or confirm the existing lint covers them. Fix the existing `react(set-state-in-effect)` and `react(refs)` warnings in touched files.

## 2. DAST in CI (NFR-16 / NFR-15)
- **Job `dast`** in `security.yml`:
  1. Build the Docker image.
  2. Start it with PostgreSQL in service containers.
  3. Migrate and seed **demo data only**.
  4. Run the **OWASP ZAP baseline** against the web and API, plus an **authenticated API scan** driven by the OpenAPI file from §3 with a demo token.
  5. Fail on High.
  6. Upload the HTML report as an artifact.
- Fix every finding (headers, cookie flags, CSP, CORS, error leakage, rate limits). Record accepted Low/Informational findings with reasons in `docs/security/dast-triage.md`.

## 3. OpenAPI and the API gateway (NFR-17)
- **Generate OpenAPI 3.1** from the code: `dedoc/scramble`, or a route-reflection command if Scramble cannot read the validation style. Fill in summaries, permission notes, error models (`{message, code, details}` / `{message, errors}`), security schemes (bearer, LTI JWT, xAPI basic, HMAC webhooks) and tags per module.
  - Publish `docs/openapi.json` and a rendered reference page (admin-only or docs site).
  - **A CI test fails when a route is missing from the spec.**
- **APIM in IaC:** import the spec into the gateway (`infra/modules/apim.bicep` or the existing edge module):
  - **products** "Partner integrations" (xAPI, LTI, inbound webhooks, Ministry systems) and "Internal";
  - subscription keys;
  - **policies**: rate limit and quota per subscription, JWT validation for bearer APIs, IP filtering for Ministry systems, request size limits, mTLS client-certificate validation for system-to-system partners (ties to NFR-08), CORS, and removal of server headers;
  - named values from Key Vault.
- **Contract test:** the spec validates (spectral lint in CI); a sample of responses validates against the schemas in feature tests.

## 4. Patch and vulnerability operations (NFR-18)
- **`tedc:security-report --month=`** (or a scheduled GitHub workflow) collects from the GitHub API: Dependabot alerts and PRs (opened, merged, time to merge), security workflow results, Trivy findings and open exceptions. It writes `docs/security/patch-log/<yyyy-mm>.md` with SLA compliance per severity (Critical 7 d, High 30 d, … from `patching.md`) and overdue items.
- Group Dependabot PRs with auto-merge for **patch-level** dev dependencies **only** after CI is green (only if the user agrees: ask at the Decision gate). Keep majors manual.
- Run the report once for October 2026 to start the history.

## 5. Pre-VAPT self-assessment (NFR-15)
- Run the OWASP ASVS 4.0 Level 2 checklist against the app (auth, session, access control, validation, crypto, errors and logging, data protection, API, files, configuration) and record each control with evidence in `docs/security/asvs-l2.md`. Fix the gaps found.
- Refresh the threat model with the new surfaces from Phases 19–24 (uploads, offline exams, LTI tool, SAML, the edge connector, contract overrides).
- Prepare the **VAPT engagement pack**: scope (URLs, roles, test accounts to create **in staging only**), rules of engagement, out-of-scope list, timelines, retest process, and the report template the Ministry expects.

## 6. Performance on a production-like local stack (TEC-02 / TEC-03)
- `perf/stack/docker-compose.yml`: the production image (FrankenPHP), PostgreSQL 16 with production-like settings, Redis for cache and queue, and the built web, with resource limits documented.
- **`php artisan tedc:seed-perf --users=20000`** (refuses to run when `APP_ENV=production`): synthetic schools, employees, programmes, groups, registrations, sessions and an exam, with `@perf.test` accounts and a password from an environment variable.
- **Run k6 locally** at a scale the machine supports (e.g. PEAK 500–1,000):
  - find the hot spots with Laravel query logging and the slow-query log (missing indexes, N+1 queries, heavy dashboards);
  - fix them: indexes, eager loading, caching of dashboards and public pages, queueing heavy work;
  - consider FrankenPHP **worker mode** (`laravel/octane`) and measure the gain;
  - re-run and record before/after p50/p95/p99, throughput and error rate in `docs/ops/performance-plan.md`.
- **Capacity model:** extrapolate from the measured per-instance throughput to 10,000 concurrent users and +20–30 %/year for three years (instances, database tier, Redis, bandwidth). Feed the numbers into `docs/architecture/BOM-and-sizing.md` (Phase 27 prices it).
- **Never** load-test production or the shared Supabase project.

## 7. Tests (write first where it is code)
- **`OpenApiCoverageTest`:** every route in `route:list` (except internal ones) appears in `docs/openapi.json` with the right method and security.
- **`SecurityHeadersTest`:** headers, cookies and CORS as configured (extend the existing test if there is one).
- **`PerfSeederGuardTest`:** the perf seeder refuses to run in production.
- **`SecurityReportTest`:** GitHub API fixtures → correct SLA maths and overdue items.
- The CI jobs `static`, `dast` and spectral lint are green.

## Review focus
1. **Larastan fixes must not change behaviour.** Rely on the full test suite after each level.
2. **DAST must never point at production** (the URL is hard-wired to the CI service container).
3. **OpenAPI must not leak internal-only endpoints** to the partner product.
4. **Perf seed data** stays out of production and out of committed files (no generated SQL in git).
5. **Worker mode** (if adopted) must reset state between requests (singletons, auth). Test a request that follows a different user.

## Definition of Done
- **NFR-16:** Larastan at max with no baseline, Semgrep WARNING triaged and gated, DAST in CI, all green. → **Available**
- **NFR-17:** OpenAPI 3.1 complete and tested; gateway products, policies and quotas in IaC; mTLS option. → **Available** (as code; the deployed instance is part of Phase 26).
- **NFR-18:** an automated monthly patch log with SLA tracking, and the October report generated. → **Available**
- **NFR-15:** ASVS L2 self-assessment with fixes, a refreshed threat model and the VAPT pack. → **Partial — Not run yet:** the accredited VAPT by the Ministry-approved firm.
- **TEC-02 / TEC-03:** the local production-like stack is measured, tuned and recorded, and the capacity model is built on measured data. → **Partial — Not run yet:** the 10,000-user test on Azure staging (Phase 26).
- The gate is green (with the new CI jobs), `docs/rfp/phase-25-security-and-performance.md` is written, the register is updated and the change is pushed with everything green.
