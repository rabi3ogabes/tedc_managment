# Phase 24 — Ministry systems and external providers: contract-first adapters, ready for go-live

> Run after Phase 23. All rules in `00_MASTER_PROMPT.md` apply. Skills: `test-driven-development`, `systematic-debugging`, `security-review` (signatures, webhooks, payment), `frontend-design` (integration screens), `verification-before-completion`.

## Goal
Every external system the RFP names should become a **configuration task, not a coding task**, on the day the Ministry provides access. For each system:
- a **contract** (request and response schemas, field mapping, authentication, signature);
- a **configurable driver** that adapts to the real specification through settings (templates, field maps, signing algorithm) rather than new code;
- a **local mock server** that behaves like the system for UAT;
- **contract tests**;
- a written **go-live checklist** with the exact inputs needed.

Where software alone can finish a requirement (support contacts, HR profile-change routing, a fingerprint edge connector), finish it.

## Closes
`TEC-07 ★` Ministry integrations · `CAR-05` HR integration (experience, grades, appraisals) · `NTF-03 ★` SMS through Hudhud · `PAY-02` Ministry e-payment gateway · `ATT-05` fingerprint attendance integration · `CNT-05` Maktabati and Qatar National Library · `TYP-14` external platforms via APIs · `TEC-13 ★` trusted content providers · `UX-03` Lusail typeface · `UTR-03` support channels.

## What exists today (verified 11 Oct 2026)
- **Integration hub.** `app/Integrations/{IntegrationManager,IntegrationRegistry,EventBus,InboundRouter,WebhookSignature}.php`: one card per system with encrypted settings, health checks every 15 minutes, call logs, retries, a circuit breaker and signed inbound webhooks. **Ministry adapters:** `app/Integrations/Ministry/{HrSync,LicenceSync,NsisSync,QnedsPublisher,SaeedTickets,SijilArchive,MinistryHttp}.php`. **Every request and response shape is an assumption**; none was run against a real system.
- **HR / Mawared.** `HrSync` does full and delta sync with a conflict policy (`hr_wins` / fill blanks), deactivates leavers and applies signed inbound changes. **Profile change requests** (`app/Services/Account/AccountService.php`, `ProfileChangeRequest`) are approved inside TEDC only. **Routing them to HR is not built.**
- **SMS.** `app/Services/Channels/{SmsGateway,ChannelSettings}.php` has a Hudhud driver (Arabic UCS-2 and a receipt webhook at `HudhudReceiptController`) built on an **assumed** request shape, plus Twilio, Unifonic, custom HTTP and log drivers.
- **Payments.** `app/Payments/Gateways/{PaymentGateway,MoeEpayGateway,FakeGateway,GatewayFactory}.php`: a hosted-page redirect, a signed, idempotent server-to-server callback (`payments/callback/{gateway}`), refunds and reconciliation, built on an **assumed** MoE e-pay specification and tested against a mock.
- **Fingerprint.** `app/Services/FingerprintGateway.php`, `AttendanceDeviceController` and `POST integrations/fingerprint/{device}/punches` accept a generic signed webhook, the **ZKTeco ADMS (push) ATTLOG** format and CSV import. **Devices that must be pulled** (most ZKTeco and other terminals on a LAN without ADMS) **cannot be reached from the cloud**.
- **External libraries.** `app/Services/Library/ExternalLibraryService.php` (Maktabati, QNL) offers a deep-link search URL or a generic JSON API, plus a demo driver.
- **Content providers.** `app/Services/Library/ExternalLearningService.php` and `ExternalLearningController`: a generic REST adapter (catalogue path, completions path) plus a demo driver for Coursera, edX, Udemy Business and LinkedIn Learning. **No provider-specific driver exists.**
- **Fonts (UX-03).** Lusail is first in every stack and loaded from `web/public/fonts/lusail/` when the licensed files exist (`web/src/lib/fonts.ts`, `web/public/fonts/lusail-README.md`). **No files yet.** The mPDF and Flutter registrations also wait for them.
- **Support (UTR-03).** `config/tedc.php` `support` (~line 153) reads `TEDC_SUPPORT_PHONE`, `TEDC_SUPPORT_EMAIL` and `TEDC_SUPPORT_SAAED_URL` **from the environment only**. The help centre shows "Set by the centre" until they are set.

## 1. The contract kit (shared foundation)
- `backend/resources/contracts/<system>/`:
  - `contract.json`: the operations, an auth scheme, request and response JSON Schemas, and the error model;
  - `mapping.json`: the default field map from the system's fields to TEDC fields, with transforms (date formats, code tables, Arabic/English name pairs);
  - `fixtures/`: recorded example payloads.
- **`App\Integrations\Contracts\ContractDriver`:** a generic HTTP driver that executes an operation from the contract:
  - URL template; method; headers;
  - auth (OAuth2 client credentials / API key / basic / mTLS / HMAC);
  - a body template with placeholders; a response JSONPath map; pagination; rate limit; idempotency key.

  An administrator can **override the contract and the mapping in the integration card** (versioned, audited, with diff and rollback), so a different real specification is adapted **without a deploy**.
- **`php artisan tedc:mock-ministry {system} --port=`** starts a local mock that implements the contract from its fixtures (including errors and slow responses), for UAT and demos. In tests, the mock is replaced by `Http::fake` built from the same fixtures.
- **Contract tests** per system: every operation's request matches the schema, every fixture response maps to the expected TEDC records, and errors map to clear messages.
- **Go-live checklist** per system in `docs/integrations/<system>.md` (Arabic and English):
  - what the Ministry provides (URL, auth type and credentials, IP allow-list, certificate, sample payloads, test accounts);
  - the TEDC settings to fill;
  - the test cases to run with the Ministry, with expected results;
  - the rollback plan.

## 2. Per system
| ID | Build now | Waits for |
|---|---|---|
| **TEC-07** (licences, NSIS, QNEDS, HR/Mawared, AD, Saaed, Sijil, Ministry website) | Move every adapter in `app/Integrations/Ministry/` onto the contract kit, with contracts, mappings, fixtures, mocks, contract tests and checklists. The Ministry website **push** of news and events uses the same kit (the feeds exist). The integration screen gets a "Contract" tab (view, edit and diff the mapping) and "Run test case" buttons. | Specifications, credentials, network access, a joint test session |
| **CAR-05** | **HR routing of profile changes:** when an employee's change request touches HR-mastered fields (name, job title, grade, qualification, experience dates), approval sends a `profile_change` request to HR through the contract (queued, with retries) and tracks `sent → accepted / rejected by HR` back on the request via the signed inbound webhook. The employee sees the status. Fields HR masters become read-only in TEDC when HR wins, with an explanation. Experience, grades and appraisals already sync; add a reconciliation report "TEDC vs HR differences". | The HR interface specification for the change request |
| **NTF-03** | Turn the Hudhud driver into a **template-driven SMS driver**: request template, encoding (UCS-2 / GSM-7 auto), sender id, auth, response success rule (JSONPath + expected value), message id path and delivery-receipt mapping (status code table). Ship presets for three common gateway shapes (JSON POST, form POST, GET query) **plus the current Hudhud assumption**. Add a **sandbox send** that shows the exact HTTP request (secrets masked) and the parsed response. Delivery receipts update the delivery log. | The Hudhud interface document and credentials |
| **PAY-02** | Make `MoeEpayGateway` contract-driven: field map, signature algorithm choice (HMAC-SHA256/512, RSA-SHA256 with the Ministry's public key), return/callback verification, amount formatting (fils, 3 decimals for QAR), language parameter and status code table. Write a **certification test plan** (successful payment, decline, cancel, timeout, duplicate callback, amount mismatch, refund, partial refund, reconciliation file import) and run all of it against the mock. | The Ministry e-payment gateway specification and sandbox |
| **ATT-05** | **Edge connector** for pull-based devices: `tools/attendance-connector/`, a small CLI (PHP 8.4 or Go; pick one and justify it), packaged as a Docker image **and** a Windows service. It runs inside the Ministry LAN, polls terminals with the **ZKTeco TCP/UDP protocol (port 4370)** and a generic SDK-free CSV/HTTP mode, buffers punches locally (SQLite, encrypted), and pushes **signed** batches to `integrations/fingerprint/{device}/punches`, with retry, clock-drift reporting and a health heartbeat to the device registry. Include a **device simulator** for tests. | The Ministry's device models, firmware, network access and an on-site test |
| **CNT-05** | Research the public search interfaces of **Qatar National Library** and **Maktabati** on their official sites (for example SRU, OpenSearch, a discovery-layer API or OAI-PMH). Record the sources in the phase doc. Implement a driver for each documented interface found; keep the deep-link driver as the fallback. Imported items keep their source link and rights. **Do not guess endpoints:** if no public interface is documented, say so. | Official API access or partnership terms |
| **TYP-14 / TEC-13** | Provider-specific drivers following each provider's **official** API documentation (Coursera for Business / Coursera API, Udemy Business API, LinkedIn Learning API, Open edX REST API; check each one's current docs and auth), mapping catalogue → programme and completions → hours, with fixtures. Plus a provider marketplace screen: connection status, last sync, the catalogue import with search, and completion sync errors. | Each provider's credentials and contract |
| **UX-03** | `php artisan tedc:fonts-check` verifies the Lusail files on the web (woff2 weights 400/500/700), in mPDF (TTF registered for Arabic) and in the app (`pubspec.yaml` assets), and reports what is missing. A `FONTS.md` drop-in guide. A visual-regression screenshot (Playwright) of three key pages with Lusail vs the fallback, to approve line heights. | The licensed Lusail font files |
| **UTR-03** | Move the support contacts into **Settings → Help & support** (phone, e-mail, Saaed portal link, working hours in Arabic and English, service levels), validated and audited, with the environment variables kept only as defaults. Show them in the help centre, the app and e-mail footers. | The centre entering the values (data, not code) |

## 3. API and permissions
- `GET/PUT admin/integrations/{key}/contract` (view and override contract and mapping, versioned) · `POST admin/integrations/{key}/test-case` · `GET admin/integrations/{key}/reconciliation`: all `integrations.manage`.
- `POST admin/sms/sandbox-send`: `integrations.manage`, rate-limited, to the admin's own number only.
- `GET/PUT admin/settings/support`: `settings.manage`.
- Connector: `POST integrations/fingerprint/{device}/heartbeat` (signed).
- Profile-change routing reuses the existing endpoints and adds HR status fields to `GET me/account/requests`.

## 4. Web and app screens
- **Integration cards gain tabs:** *Overview* (health, last calls), *Contract* (a schema viewer, mapping editor with live preview from a fixture, version history and diff), *Test cases* (run, results) and *Go-live checklist* (ticks with who and when).
- **Payment settings:** gateway mode, signing algorithm, keys, a "run certification suite against the mock" button.
- **SMS settings:** preset picker, template editor, sandbox send with request/response viewer.
- **Attendance devices:** connector status, last heartbeat, buffered count, clock drift.
- **Settings → Help & support:** the contact form described above.
- **App:** support contacts on the help screen; the HR status on profile change requests.

## 5. Tests (write first)
- **`ContractKitTest`:** templates render; auth schemes are applied (HMAC signature known-answer test); pagination; the JSONPath map; an override is versioned and can be rolled back; an invalid override is refused.
- **Per system** (`HrContractTest`, `LicencesContractTest`, `NsisContractTest`, `QnedsContractTest`, `SaeedContractTest`, `SijilContractTest`, `MinistrySiteContractTest`): every fixture maps correctly, errors surface, and the circuit breaker opens and recovers.
- **`HrProfileChangeRoutingTest`:** an approval of an HR-mastered field sends to HR; HR acceptance and rejection come back via signed webhook; an unsigned webhook is refused; the employee is notified.
- **`TemplateSmsDriverTest`:** UCS-2 for Arabic and GSM-7 for Latin; the success rule; delivery-receipt mapping; secrets are masked in the sandbox output.
- **`MoeEpayCertificationTest`:** every case in the certification plan runs against the mock.
- **`AttendanceConnectorTest`** (in the connector's own test suite, run in CI): the simulator emits punches → buffered → signed push → retried after a failure → deduplicated by the server.
- **`ProvidersDriverTest`** (fixtures per provider), **`ExternalLibraryDriverTest`**, **`FontsCheckTest`**, **`SupportSettingsTest`**.

## Review focus
1. **Secrets** never appear in logs, sandbox output, contract exports or error messages.
2. **Mapping overrides** cannot inject code; templates are data with a safe placeholder language.
3. **Idempotency** for inbound webhooks (HR, payment callbacks, receipts): a duplicate is harmless.
4. **Arabic text** survives every hop (UTF-8 vs UCS-2, mPDF, CSV with BOM).
5. **The connector** buffers safely across power loss and never sends a punch twice without a dedup key.

## Definition of Done
- **UTR-03:** contacts are editable in Settings and shown everywhere. **Available** once the centre enters them in production; until then **Partial — Ready, waiting for: the centre's phone, e-mail and Saaed link**.
- **TEC-07, CAR-05, NTF-03, PAY-02, ATT-05, CNT-05, TYP-14, TEC-13, UX-03:** contract kit, drivers, mocks, contract tests, screens and go-live checklists are done. Each stays **Partial — Ready, waiting for: <the exact Ministry input>** until it is tested with the real system. Write that input in its evidence.
- The gate is green (plus the connector's CI job), `docs/rfp/phase-24-ministry-systems-and-providers.md` and `docs/integrations/*.md` are written, the register is updated and the change is pushed with everything green.
