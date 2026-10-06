# Compliance matrix

**ملخص:** مطابقة ضوابط المنصة مع NIAP وQCSF 2022 وISO 27001/27017/27018 والقانون القطري رقم 13 لسنة 2016 وISO/IEC 19796-1 وISO/IEC 25000. الحالة: مغطى / جزئي / يحتاج إجراءً من الوزارة.

| Framework area | Control in the platform | Evidence | Status |
|---|---|---|---|
| Access control (ISO 27001 A.5.15–18, QCSF) | roles with scope, MFA, session policy, step-up, SSO | Phase 01/13 docs, tests | Covered |
| Cryptography (A.8.24) | TLS 1.2+, HSTS, encrypted columns, Key Vault, CMK option | `data-classification.md`, `infra/` | Covered |
| Logging and monitoring (A.8.15–16) | audit log, security events, SIEM forwarding, KPI | Phase 12/17 | Covered (SIEM endpoint to be supplied) |
| Secure development (A.8.25–29) | SSDLC pipeline | `ssdlc.md`, workflows | Covered |
| Vulnerability management (A.8.8) | patching SLAs, scans | `patching.md` | Covered |
| Backup (A.8.13) | PITR 35 d, monthly 7-year vault, restore tests | `dr-runbook.md`, `infra/modules/backup.bicep` | Covered by design — first restore test on staging pending |
| Supplier relationships (A.5.19–23) | third-party register | `third-party-register.md` | Partial — DPAs to be confirmed |
| ISO 27017 (cloud) | tenant isolation by environment, policy guard rails, private endpoints | `infra/` | Covered |
| ISO 27018 (PII in cloud) | classification, masking, minimisation, no production data outside prod, DSR flows | Phase 17 | Covered |
| Qatar Law 13/2016 | purpose limitation (classification), data-subject rights (access/correction/erasure/objection, 30-day tracking), security measures, cross-border control (AI residency guard) | `/me/privacy`, `data_subject_requests` | Covered; privacy notice text and consent wording to be supplied by the Ministry's DPO |
| NIAP / QCSF 2022 | controls above mapped; official assessment is performed by the Ministry/NCSA | this matrix | Needs the Ministry's assessment |
| ISO/IEC 19796-1 (e-learning quality) | needs analysis → design → delivery → evaluation → improvement across Phases 03–12 | RFP phase docs | Covered |
| ISO/IEC 25010 (SQuaRE) | functional suitability (tests), performance efficiency (k6 plan), reliability (HA/DR), security, maintainability (CI, docs), usability (RTL/AR/EN), portability (Azure/K8s) | CI, `perf/` | Covered; performance evidence pending a staging run |
