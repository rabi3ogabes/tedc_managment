# TEDC close-out prompt pack: the last 50 RFP requirements

Ready-to-run prompts for **Claude Code** that close every RFP requirement still open after the 10 October 2026 verification: **278 requirements, 228 available, 50 partial, 0 missing**. It continues the original pack (phases 00–18) with **phases 19–27**.

## Files

| File | What it is |
|---|---|
| [`OPEN_ITEMS.md`](OPEN_ITEMS.md) | **The list.** Each of the 50 partial requirements, with what is built today, exactly what is missing, who unblocks it, the phase that closes it, and the expected status afterwards. It also lists 8 problems found during the check that are not RFP items but must be fixed. |
| [`00_MASTER_PROMPT.md`](00_MASTER_PROMPT.md) | Rules, conventions (as the code really is today), status honesty, skills to use, the workflow (Spec Kit + test-first + verification gate), safety gates, and the **Preflight** to run first. |
| `19` … `27` | One prompt per phase: goal, IDs closed, current state with real file paths, data model, rules, API and permissions, web, mobile, tests to write first, review focus, Definition of Done. |

## How to run

1. Open the `tedc_managment` repository in Claude Code (this folder is `docs/rfp/closeout/`).
2. Paste **`00_MASTER_PROMPT.md`**. It loads the rules and runs the **Preflight**, which starts with a decision about the live site's security (see below).
3. Paste the phase files **in order**: `19` → `27`. Start a phase only after the previous one reports a green verification gate, and CI, Security and Vercel are all green.
4. **New session?** Paste `00_MASTER_PROMPT.md`, then the line `RULES ONLY — the Preflight is done; do not run section 9.`, then the next phase file.
5. After each phase, read its report (IDs closed with evidence, what is still waiting and for what) and review the diff.

## Order and dependencies

| Phase | Title | Needs from outside | Runs without waiting? |
|---:|---|---|---|
| — | Preflight | your decision on the live site; the Vercel build log if it still fails | yes |
| 19 | Collaboration completion | — | yes |
| 20 | Planning rules, reports, forecasts | — | yes |
| 21 | Learning engine completion | (AI-05 only: an in-country model endpoint, to finish) | yes |
| 22 | Exam integrity: face presence | **your decision** (privacy) | after you decide |
| 23 | Identity and Microsoft 365 | (to finish: the Ministry tenant, IdP metadata, Graph consent) | yes, builds readiness |
| 24 | Ministry systems and providers | (to finish: specifications and credentials) | yes, builds readiness |
| 25 | Security engineering and performance | (to finish: an accredited VAPT firm) | yes |
| 26 | Azure go-live and resilience | **an Azure Qatar subscription and your approval for each environment** | §1 only, until then |
| 27 | Adoption content and deliverables | (to finish: centre workshops, topic agreement, sign-offs) | yes |

## Decision gates (Claude will stop and ask you)

- **Preflight §9.0, the live site.** The repository is public, the shared demo password is in 20 files and in the web bundle, and production runs in demo mode. Its login page offers one-tap sign-in as the super administrator. *Is the live site a public demo, or will it hold real data?*
- **Phase 22, face presence in exams.** This is biometric processing under Qatar's data-protection law. Approve, decline or change the design.
- **Phase 25:** auto-merging patch-level dependency updates.
- **Phase 26:** every Azure environment (cost and scope shown first) and the production cut-over.
- **Phase 27:** creating git tags and releases, repository labels, and anything sent to the Ministry.

## Expected outcome

| | Today | After 19–27 with no outside input | When the Ministry's inputs arrive |
|---|---:|---:|---:|
| Available | 228 | **247** (248 if face presence is approved) | up to **278** |
| Partial | 50 | 31 (30) | 0 |
| Mandatory software items met (of 25) | 21 | 22 (23 with face presence) | 25 |

The 31 that stay partial are "ready, waiting for" a named input: a specification, credentials, the subscription, a real test run or a sign-off. Going live on each is configuration plus a test, not new code.

## What the Ministry and the centre must provide

- **Brand:** the licensed **Lusail** font files (web, PDF, app).
- **Identity:** the Entra ID tenant, app registration and IdP metadata (OIDC or SAML); AD/LDAP details; Microsoft Graph admin consent; uploading the TEDC Teams app.
- **Ministry systems:** specifications, credentials and network access for HR/Mawared, licences, NSIS, QNEDS, Saaed, Sijil and the Ministry website; the Hudhud SMS interface and sender id; the e-payment gateway specification and sandbox.
- **Devices and content:** fingerprint terminal models and LAN access; Maktabati / Qatar National Library API access; Coursera, edX, Udemy and LinkedIn Learning credentials; an in-country AI model endpoint (Azure OpenAI Qatar or the approved provider).
- **Infrastructure and security:** an Azure Qatar Central subscription with approvals; an accredited VAPT firm; the data-protection officer's review of the face-presence design.
- **The centre:** support phone, e-mail and Saaed link; As-Is and needs workshops; agreement on the two e-kit topics; release and go-live dates; sign-offs.
