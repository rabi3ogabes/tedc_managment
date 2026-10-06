# VAPT readiness

**ملخص:** قائمة الجاهزية لاختبار الاختراق والمراجعة الأمنية المعتمدة، مع قالب لتتبّع المعالجة.

## Scope document for the tester
Staging environment (identical to production), one synthetic account per role (provided through the secure channel), the public site, the API (`/api/v1`), the mobile apps, partner callback endpoints (payments, Hudhud receipts, inbound webhooks), file upload/download, SSO. Out of scope: Azure platform itself, third-party SaaS.

## Checklist (OWASP Top 10 2021 / API Top 10 2023)
| Item | Where addressed |
|---|---|
| A01/API1,5 Broken access control | permission middleware + `AccessScope`; cross-school tests |
| A02 Cryptographic failures | TLS 1.2+, HSTS, encrypted fields, Key Vault |
| A03 Injection | Eloquent bindings; report builder whitelists fields; HTML sanitiser; CSP |
| A04 Insecure design | threat model, feature flags, rate limits |
| A05 Misconfiguration | hardened headers, policy guard rails, private endpoints |
| A06 Vulnerable components | audit/OSV/Trivy/Dependabot |
| A07 Authentication failures | lock-out, MFA, session registry, WAF login rate rule |
| A08 Integrity failures | signed callbacks, SRI-free bundles built in CI, pinned actions |
| A09 Logging failures | audit + security events + SIEM |
| A10/API7 SSRF | outbound allow-list at NAT/firewall; URL fields validated |
| API2/3/4 Auth, property exposure, resource consumption | throttles per route, resource whitelists, pagination caps |
| API6,8,9,10 | business-flow limits (payments, registration), inventory in `docs/API.md` |

## Remediation tracker (template)
| ID | Finding | Severity | Owner | Found | Due | Status | Evidence of fix |
|---|---|---|---|---|---|---|---|
| — | — | — | — | — | — | — | — |

Retest is requested after each critical/high fix. The clearance request pack = this file + HLD/LLD + data classification + threat model + SSDLC + patching + third-party register + compliance matrix + the latest scan reports.
