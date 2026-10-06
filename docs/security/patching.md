# Patch and vulnerability management

**ملخص:** مواعيد المعالجة: حرج 48 ساعة، مرتفع 7 أيام، متوسط 30 يومًا، مع إعادة بناء أسبوعية للصور وتصحيح تلقائي للخدمات المُدارة.

| Severity | Fix within | Path |
|---|---|---|
| Critical (CVSS ≥ 9, exploited) | 48 hours | emergency release: hotfix branch → pipeline → prod with approval from the on-call lead |
| High | 7 days | Dependabot/PR → normal pipeline |
| Medium | 30 days | next scheduled release |
| Low | next quarter | backlog |

- **Images** are rebuilt weekly (and on any base-image advisory) so OS patches arrive without code changes; Trivy gates the push.
- **PaaS** (PostgreSQL, Redis, Container Apps, Application Gateway) are patched by Azure in maintenance windows set outside 06:00–16:00 Sun–Thu; zone redundancy and revisions keep users unaffected (TEC-16).
- **Tracking:** a register (issue label `vulnerability`) with discovery date, severity, owner, due date, status. Exceptions need a written risk acceptance.
- **Verification:** each fix is re-scanned; the monthly DAST full scan confirms no regression.
