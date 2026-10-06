# Disaster recovery runbook

**ملخص:** أهداف التعافي المقترحة: نقطة التعافي 15 دقيقة، زمن التعافي 4 ساعات (تُعتمد من الوزارة). التعافي من خلل منطقة توافر تلقائي؛ التعافي من فقد البيانات عبر الاستعادة إلى نقطة زمنية.

**Proposed targets (to be confirmed by the Ministry): RPO ≤ 15 min, RTO ≤ 4 h.**

## Scenarios
| Scenario | Mechanism | RPO / RTO |
|---|---|---|
| A zone fails | zone-redundant gateway, apps, Redis; PostgreSQL HA fails over automatically | ≈ 0 / minutes |
| Bad release | revision rollback (`release.sh` does it automatically; manual: `az containerapp ingress traffic set`) | 0 / minutes |
| Data corruption or deletion | PostgreSQL PITR (35 days) to a new server; Blob versioning/soft delete (30 days) | ≤ 5 min / 1–2 h |
| Regional loss | **restore from the zone-redundant monthly vault into a second Qatar-resident environment** (no second Qatar region is assumed; confirm with Microsoft what in-country secondary exists) | per backup / ≤ 4 h target needs a warm secondary — decision pending |
| Key Vault loss | soft delete 90 days + purge protection | — |
| Ransomware / insider | immutable vault copies, RBAC separation, SIEM alerts | per backup |

## Restore procedure (PostgreSQL)
1. Declare the incident (on-call lead), open the bridge, freeze deployments.
2. `az postgres flexible-server restore -g rg-tedc-prod -n tedc-prod-pg-restore --source-server tedc-prod-pg --restore-time <UTC time>`
3. Point a staging-sized `api` revision at the restored server, run `php artisan tedc:health` style checks and the smoke test, compare row counts with the manifest.
4. Switch the production `DB_HOST` secret (Key Vault) and restart revisions; announce.
5. Record the timeline in the post-incident report.

## Monthly automated restore test
`.github/workflows/restore-test.yml` (monthly) restores the latest backup into an isolated resource group, runs the smoke test, reports RPO achieved and duration, and deletes the environment. A failure pages the on-call.

## Drills
Annual full DR drill with the Ministry; quarterly tabletop. Results feed `bcp.md`.
