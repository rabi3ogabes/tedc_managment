# Threat model (STRIDE) — summary

**ملخص:** نموذج التهديدات للوحدات الرئيسية بمنهجية STRIDE مع الضوابط الحالية والمخاطر المتبقية.

| Module | Threat | Control in place | Residual |
|---|---|---|---|
| Sign-in / sessions | Spoofing, credential stuffing | password policy, lock-out, TOTP/e-mail MFA, server-side sessions with idle/absolute limits, WAF rate rule on login, SIEM events | phishing of users — awareness |
| Roles and scope | Elevation of privilege, cross-school access | permission middleware, `AccessScope` on every dataset, active-role switching, tests with two schools | custom-role misconfiguration — review in audits |
| Attendance QR/biometric | Spoofing, replay | rotating signed QR, geofence, device signatures, attempts log | shared screenshots within the window |
| Assessments | Tampering, cheating | server-side grading, answer snapshots, proctoring signals, void/extend audited | collusion off-platform |
| Payments | Tampering, repudiation | hosted gateway page, HMAC-signed callbacks, idempotent events, amount check, reconciliation, no card data | gateway outage — queued/retried |
| File storage | Information disclosure | private containers, short SAS links, no shared keys, upload scanning (Defender for Storage) | link sharing within TTL |
| AI features | Disclosure to external models | residency guard, redaction, no prompt storage, per-feature switches | allowed external use by decision |
| Integrations / webhooks | Spoofing, replay | signed inbound messages, outbound HMAC, circuit breakers, secrets encrypted | partner key leakage — rotation |
| Admin tools | Abuse of dangerous tools | unsafe tools off by default with reason, audit, banner | insider misuse — SIEM alerts on admin actions |
| Data subject rights | Excessive disclosure | step-up for export, audit, own data only | session hijack — step-up mitigates |
| Infrastructure | Lateral movement, exposure | private endpoints, NSGs, WAF, managed identities, policy deny public access | platform zero-days — patching SLAs |
