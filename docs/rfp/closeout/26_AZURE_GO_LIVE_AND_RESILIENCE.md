# Phase 26 — Azure Qatar go-live and resilience evidence

> Run after Phase 25. All rules in `00_MASTER_PROMPT.md` apply. Skills: `security-review`, `systematic-debugging`, `verification-before-completion`.

## ⚠ Gate: this phase creates paid cloud resources
Before any command that creates, changes or deletes Azure resources, **stop and ask the user**, showing:
- the subscription and tenant;
- the resource groups and regions (Qatar Central only);
- the services and SKUs from `infra/parameters/<env>.bicepparam`;
- the **estimated monthly cost** (from the Azure Retail Prices API; see Phase 27 §3);
- what will be torn down after drills.

Proceed only on an explicit "yes" **for that environment**. Without a subscription, do only §1 (the parts that need no cloud) and report that the phase is blocked.

## Goal
Deploy TEDC to **Azure Qatar Central** in three identical environments and **prove**, with recorded evidence:
- high availability;
- backup and restore within the RPO/RTO;
- disaster recovery;
- monitoring with alerts that really fire;
- zero-downtime patching with automatic rollback;
- 24/7 stability over an observation window.

## Closes
`NFR-01 ★` HA design (99.9 % SLA) · `NFR-02 ★` hosting on Azure Qatar · `NFR-03 ★` prod, staging and dev environments · `NFR-19 ★` enterprise backup and recovery (RPO/RTO) · `NFR-20 ★` monitoring with real-time alerts · `TEC-01 ★` stable 24/7 · `TEC-05 ★` DR and business continuity with automation · `TEC-16 ★` automatic patching without user impact. The 10,000-user run for `TEC-02/TEC-03` also happens here (staging), using Phase 25's k6 suite.

## What exists today (verified 11 Oct 2026)
- **IaC.** `infra/main.bicep` with modules `network, security, data, compute, edge, monitoring, policy, backup` and `infra/parameters/{dev,staging,prod}.bicepparam`; `.github/workflows/infra.yml` builds it in CI.
- **Pipelines.**
  - `deploy-azure.yml`: build → ACR (Qatar Central) → dev → staging (smoke + k6) → approval → prod with blue-green revisions and automatic rollback through `deploy/azure/release.sh`, using OIDC federation. **Never run.**
  - `restore-test.yml`: a **monthly cron** that restores the production PostgreSQL into an isolated group, runs smoke and cleans up. **It needs `AZURE_*` secrets and `vars.PROD_PG_ID`, which do not exist, so its scheduled run will fail on the 1st of every month** until they do.
- **Probes and app support.** `/public/health/live` and `/ready`; the Azure Blob driver (`TEDC_STORAGE_DRIVER=azure`, Azurite-tested) and `tedc:storage-migrate`; SIEM forwarding; a Helm chart in `deploy/helm/tedc` (on-premises option).
- **Runbooks.** `docs/ops/{dr-runbook,bcp,operations-handbook,performance-plan}.md` and `docs/architecture/*`.
- **Production today** runs on Vercel + Supabase (Frankfurt/EU regions; check the exact region). Moving the data into Qatar is part of this phase.

## 1. Without a subscription (do this always)
1. **Guard the scheduled workflows.** `restore-test.yml` (and any other scheduled Azure job) must skip cleanly when the secrets or vars are missing: add a first step that checks `vars.PROD_PG_ID` and the OIDC secrets and exits success with a notice. Make sure no red scheduled runs appear.
2. **Validate the IaC.** `az bicep build` (already in CI), plus `what-if` templates; check every SKU's **availability in Qatar Central** against Microsoft's documentation and record the source. Replace anything unavailable with the nearest Qatar Central option; note any service that must run in a paired region and why (residency impact).
3. **Policy as code.** Azure Policy assignments for allowed locations (Qatar Central only), required tags, diagnostic settings, TLS minimums and private endpoints. They are part of `policy.bicep`.
4. **Migration plan (Supabase → Azure PostgreSQL Flexible Server, storage → Blob).**
   - Write the cut-over runbook: freeze window, `pg_dump`/`pg_restore` or Azure DMS, verification queries (row counts per table, checksums of key tables), the storage copy with `tedc:storage-migrate`, DNS cut-over, rollback.
   - Rehearse it locally (PostgreSQL → PostgreSQL in Docker, local storage → Azurite) and record the timings.
5. **Evidence templates.** `docs/ops/evidence/` with one template per drill: what was done, when, who, expected vs measured, screenshots/log extracts, and the pass/fail verdict.

## 2. With the subscription and the user's "yes"
Do these in order; each step ends with the evidence file filled.
1. **Federation.** Create the OIDC federated credentials and the GitHub environments `dev`, `staging` and `production` (with required reviewers); set the secrets and vars. **The user does the steps that need their Azure or GitHub admin rights**; give them the exact commands.
2. **Deploy dev, then staging, then prod** with `infra.yml` / `deploy-azure.yml`. Run the smoke test after each. Check data residency with Azure Resource Graph: every resource is in `qatarcentral`, and the policy compliance report has no violations (**NFR-02**).
3. **HA drill (NFR-01).** In staging:
   - kill a Container Apps replica during a k6 smoke run: no failed requests beyond the threshold;
   - trigger a **zone-redundant PostgreSQL failover**: measure the downtime and error window;
   - restart Redis.
   Record RTO, request errors and recovery. Compare with the 99.9 % budget (≈ 43 min/month).
4. **Backup and restore (NFR-19).** Run `restore-test.yml` manually against staging, then production's backup into an isolated group. Measure the **RPO** (latest restorable point vs the failure time) and the **RTO** (start → app healthy on the restored database). Verify the blob soft-delete and versioning restore of a deleted file. Compare with the targets in `dr-runbook.md`.
5. **DR / BCP (TEC-05).** A tabletop exercise, then a technical DR drill following `dr-runbook.md` (restore into a clean environment from IaC + backups, switch DNS in staging), timed. Record the lessons and update the runbooks.
6. **Monitoring (NFR-20).** Fire every alert on purpose in staging: availability (stop the app), 5xx burst, p95 latency (k6), queue lag, DB CPU and storage, certificate expiry. Each must reach the action group (e-mail/SMS/Teams) within its target. Record the timings. Wire SIEM forwarding to the Ministry's Sentinel workspace if provided.
7. **Patching without user impact (TEC-16).** Run a blue-green release **under k6 load**, then a deliberately failing revision that must **roll back automatically**. Record zero (or within-threshold) failed requests. Schedule the weekly image rebuild.
8. **10,000-user test (TEC-02/TEC-03).** Seed staging with the perf seeder (Phase 25), run `perf/scenarios.js` at PEAK 10,000, and record p50/p95/p99, errors, throughput and resource saturation. Tune and re-run until p95 < 1.5 s or the limits are explained. Update the capacity model with the measured data.
9. **24/7 observation (TEC-01).** Track availability and incidents for at least 30 days of production operation and publish a monthly availability report from Azure Monitor. Until 30 days have passed, TEC-01 stays Partial.
10. **Cut-over** from Vercel/Supabase to Azure production following the migration runbook, **only on the user's go**. Keep Vercel as the rollback for a defined period, then decommission it with the user's approval.

## 3. App changes likely needed (build and test them as you meet them)
- Health and readiness probes that cover Redis and the queue on Azure (they exist; extend them if Redis replaces the database cache).
- Session and cache stores on Redis with the Container Apps scale rules; check that file uploads go to Blob only.
- Trusted proxies and headers for Front Door / Application Gateway (client IP, HTTPS detection). Write a test for the IP used by rate limits.
- Background workers (queue) as a separate Container App with the scheduler, not a web replica.

## 4. Tests
- **CI:** the workflow guard (§1.1) is covered by a dry-run job; the Bicep `what-if` is run in `infra.yml`; a `TrustedProxiesTest` checks client-IP detection behind the gateway headers.
- Everything else here is **evidence**, not unit tests: each drill's evidence file must contain measured values, timestamps and logs.

## Review focus
1. **Costs.** Tear down drill resources (restore groups, DR copies) every time; the runbooks say how.
2. **Secrets.** Only OIDC federation and Key Vault: no long-lived keys in GitHub, no secrets in Bicep parameters.
3. **Residency.** No resource, backup or log leaves Qatar Central; check the Log Analytics and backup vault locations explicitly.
4. **Cut-over data integrity.** Row counts and checksums must match before switching DNS.
5. **Never test on production** except the read-only availability observation and the restore into an isolated group.

## Definition of Done
- **Without a subscription:** §1 is done (workflow guards, validated IaC with Qatar Central availability notes, policies, migration runbook rehearsed locally, evidence templates). All eight IDs stay **Partial — Not run yet**, and the evidence names the missing subscription.
- **With a subscription:** each ID becomes **Available** only when its drill evidence shows the target met:
  - NFR-02 / NFR-03: deployed, policy-compliant;
  - NFR-01: HA drill passed;
  - NFR-19: RPO/RTO measured within target;
  - TEC-05: DR drill passed;
  - NFR-20: every alert fired;
  - TEC-16: blue-green with rollback under load;
  - TEC-01: after 30 days of measured availability.
- The gate is green, `docs/rfp/phase-26-azure-go-live.md` and `docs/ops/evidence/*` are written, the register is updated and the change is pushed with everything green.
