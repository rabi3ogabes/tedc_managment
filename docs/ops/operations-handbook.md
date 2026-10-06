# Operations handbook

**ملخص:** الأعمال اليومية والأسبوعية والشهرية لفريق التشغيل.

- **Daily:** check the Ops workbook (availability, p95, error rate, queue lag), `/admin/error-log`, SIEM alerts, the `payments` reconciliation result, data-subject requests due.
- **Weekly:** review Dependabot PRs and the vulnerability register, certificate expiry (alert at 30 days), backup job status, capacity trend.
- **Monthly:** automated restore test result, full DAST scan, access review of RBAC and platform roles, cost report.
- **Quarterly:** DR tabletop, capacity model update, third-party register review.
- **Release:** `deploy-azure.yml` (dev → staging with k6 smoke + ZAP → approval → prod with traffic splitting and automatic rollback). Migrations are backward-compatible (expand, then contract in a later release).
- **On call:** rota template — week-long shifts, L2 pager via the action group (e-mail, SMS, Teams); handover note each Sunday.
- **Break-glass:** two named administrators with local accounts (MFA required, audited, alert on use).
