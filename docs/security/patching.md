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

## Open exceptions

| Finding | Where | Why accepted | Mitigation | Review by |
|---|---|---|---|---|
| CVE-2026-78669 (HIGH), golang.org/x/net 0.59.0 | FrankenPHP binary in the API image | Fixed upstream in x/net 0.60.0, but no FrankenPHP release ships it yet (1.13.0 still pins 0.59.0). | The container serves HTTP/1.1 only (`docker/Caddyfile`), so the HTTP/2 code is unreachable; TLS and HTTP/2 end at the platform edge. Dated entry in `.trivyignore`. | 2026-11-10 — or earlier, as soon as a fixed FrankenPHP image exists. **Needs the written risk acceptance this policy requires.** |
| GHSA-hp3w-g68c-fv3c, sprintf-js | Web dependency (mammoth) | No fixed version; runs only in the user's browser on a file they chose. | — | 2027-01-31 (`web/osv-scanner.toml`) |

