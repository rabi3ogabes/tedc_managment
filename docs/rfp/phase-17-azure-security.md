# Phase 17 — Azure Qatar hosting, security and compliance

This phase is mostly infrastructure and documentation, and **nothing here has been deployed to Azure from this repository** (no Azure subscription, Bicep, Docker or k6 were available to the build). What is real and tested, and what is prepared but unproven, is stated plainly below.

## Built and tested in code
- **Probes** `/public/health/live` and `/ready` (database, cache, storage, queue lag; degraded status; no secrets).
- **Azure Blob storage driver** (`TEDC_STORAGE_DRIVER=azure`): private containers, SAS read/upload links, managed-identity path with user-delegation SAS, copy, checksums; `php artisan tedc:storage-migrate` from local or Supabase with MD5 verification. A CI job runs the driver against the **Azurite** emulator; the managed-identity/user-delegation path has been tested against a mock only.
- **SIEM forwarding** (Settings → Integrations → *siem*): audit records and security events (sign-ins, failures, lock-outs, MFA failures, permission denials, personal-data exports, integration failures) queued in an outbox and sent to Splunk HEC or Microsoft Sentinel (Logs Ingestion API); structured JSON logs with a request id carried from the gateway to the SIEM. Tested with HTTP mocks.
- **Data classification** of every table and column (`config/data_classification.php`, generated register `docs/security/data-classification.md`, a test fails when a column is unclassified or the register is stale); application-level encryption confirmed for national IDs, MFA secrets, device configs, webhook and LTI secrets, integration settings.
- **No production data outside production**: `php artisan tedc:anonymise-export` (approver with `security.policy`, written reason, audit entry; Restricted columns dropped, Confidential pseudonymised per export, joins preserved).
- **Data-subject rights** (Qatar Law 13/2016): self-service download of all personal data (step-up protected, audited), correction/erasure/restriction/objection requests with 30-day tracking, handlers' queue, reminders, answers by notification.
- **Realtime abstraction**: `TEDC_REALTIME = supabase | sse | polling`; the SSE driver streams a person's notifications from the API (web uses it; the mobile app keeps polling/Supabase until a stream client is added).

## Prepared, not deployed or measured
- `infra/` Bicep (network, security, data, compute, edge WAF, monitoring, policy, backup, main + dev/staging/prod parameters); `.github/workflows/infra.yml` builds them in CI. Service/SKU availability in Qatar Central must be confirmed with Microsoft.
- Deploy pipeline template with blue-green revisions and automatic rollback (`deploy-azure.yml`, `deploy/azure/release.sh`), monthly restore test template, Helm chart for on-premises Kubernetes.
- Security workflow: gitleaks, composer/npm/OSV audits, Semgrep, Trivy (image and IaC); Dependabot.
- k6 scripts (`perf/`) — **not run**; `docs/ops/performance-plan.md` is a template and makes no performance claim.
- Documentation pack: HLD, LLD, BOM and sizing, environments, SSDLC, threat model, patching, third-party register, compliance matrix, VAPT readiness, DR runbook, BCP, operations handbook. Written in English with an Arabic summary on top; **full Arabic translation is outstanding**.

## Not done
- Octane/FrankenPHP runtime (needs `laravel/octane` and an image change); Reverb / Web PubSub drivers; read replica wiring in the app; Defender for Storage configuration; APIM API definitions; PgBouncer tuning; any real load, restore or failover test; Larastan at max level.
- Application-level encryption of further personal fields (phone, birth date) — classified Confidential, protected by masking and access control, not encrypted per column.

## What the Ministry must supply
Azure subscription(s) and Qatar Central quota/SKU confirmation, the SIEM endpoint (Sentinel workspace/DCR or Splunk HEC URL and token), DPAs with Google (FCM) and any AI provider, the DPO's privacy notice and retention periods, agreement on RPO/RTO and the in-country secondary strategy, accredited VAPT provider, and DNS/TLS certificate for the public name.
