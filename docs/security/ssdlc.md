# Secure software development lifecycle

**ملخص:** دورة تطوير آمنة: معيار ترميز، نمذجة تهديدات، مراجعة شيفرة، فحص ثابت وديناميكي وللاعتماديات والأسرار والحاويات في كل تغيير، مع تحديثات أسبوعية آلية.

## Standards
OWASP ASVS Level 2 is the target (mapping in `compliance-matrix.md`); OWASP Top 10 and API Top 10 drive the review checklist (`vapt-readiness.md`).

## Pipeline (all in GitHub Actions)
| Stage | Tool | Gate |
|---|---|---|
| Style / quality | Pint, ESLint, `flutter analyze` | blocking (CI) |
| Unit / feature tests | PHPUnit on SQLite **and PostgreSQL**, Azurite for the Azure driver | blocking |
| Secrets | gitleaks (`security.yml`) | blocking |
| Dependencies | `composer audit`, `npm audit --audit-level=high`, OSV scanner | blocking on high/critical |
| SAST | Semgrep (PHP, TypeScript, OWASP, secrets rules); Larastan level to be raised in steps | blocking on ERROR |
| Container | Trivy on the built image | blocking on high/critical with a fix |
| IaC | `az bicep build`, Trivy config, location policy check | blocking (build), report (config) |
| DAST | OWASP ZAP baseline on staging per release (`deploy-azure.yml`); full scan monthly | blocking on high |
| Updates | Dependabot weekly for composer, npm, pub, Actions, Docker; patch updates merged after CI | — |
| Licences | `composer licenses` / `npm ls` report quarterly | review |

## Practices
Threat model per major module (`threat-model.md`) updated when a module changes; code review with the checklist below; no secrets in code or env files in production; least-privilege roles with scope; every privileged action audited; feature flags default off for new risk; production changes only through the pipeline.

## Review checklist
Input validation and output encoding · authorisation on every route (permission + scope) · no raw SQL with user input · file uploads typed, size-limited, scanned · secrets via Key Vault · logging without personal data · rate limits · tests for the unhappy path and for cross-tenant access.
