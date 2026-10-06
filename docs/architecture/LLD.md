# Low-level design

**ملخص:** تفاصيل التنفيذ: الشبكة والشرائح والأدوار والمقاييس والمسارات.

## Network (`infra/modules/network.bicep`)
Spoke VNet `10.40.0.0/16`: `edge` /24 (Application Gateway), `app` /23 (Container Apps, NAT gateway), `data` /24 (private endpoints), `postgres` /24 (delegated), `apim` /27, `mgmt` /27. NSGs per subnet (edge: 443 + GatewayManager; app: edge only; data: app subnet on 443/5432/6380/5671; everything else denied). Private DNS zones for PostgreSQL, Blob, Key Vault, Redis, Service Bus, ACR. Optional peering to the Ministry hub.

## Workloads (`infra/modules/compute.bicep`)
One image, `TEDC_ROLE` selects the command: **api** (HTTP, scale on 80 concurrent requests/replica), **worker** (`queue:work`, scale on CPU), **scheduler** (`schedule:work`, exactly one), **realtime** (SSE, scale on 400 connections). Min 2 / max 40 replicas in production. Probes: startup 150 s, liveness 10 s, readiness 10 s. Non-root, read-only root file system, `LOG_CHANNEL=json`.

## Data
PostgreSQL 16, extensions `vector`, `pg_trgm`, `pgcrypto`, `uuid-ossp`; PgBouncer on the flexible server port 6432 for the API; reporting reads may use a read replica (`DB_REPORTING_*`, to be enabled when load requires). Redis for cache, sessions, locks and queues. Service Bus queues `default`, `outbox`, `notifications` for the integration outbox at scale.

## Identity and secrets
One user-assigned managed identity: Key Vault Secrets User, Storage Blob Data Contributor and Delegator (user-delegation SAS), Service Bus Data Owner. Secrets in Key Vault: `app-key`, `db-password`, `jwt-private-key`, integration credentials (stored encrypted in the database by the Integration Hub, key from Key Vault).

## Request path and correlation
Gateway → API; every request carries `X-Request-Id` (generated if absent), written to every log line (`request_id`), returned to the caller and stored on security events, so one request can be followed through the gateway, the API and the SIEM.

## Logging and SIEM
JSON logs to stderr → Log Analytics. Audit records, security events (sign-in success/failure, lock-out, second-factor failure, permission denial, personal-data export, integration failure) are queued in `siem_outbox` and sent in batches to Microsoft Sentinel (Logs Ingestion API) or Splunk HEC, configured in Settings → Integrations (`siem`). A SIEM outage never slows a request and never reports itself.

## Storage driver
`TEDC_STORAGE_DRIVER=azure`: server reads/writes with the workload identity's token; browsers receive 10-minute read links and 30-minute upload links (SAS; user-delegation SAS in production). `php artisan tedc:storage-migrate` copies objects from Supabase or local storage with MD5 verification.
