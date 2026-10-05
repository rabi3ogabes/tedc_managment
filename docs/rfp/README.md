# RFP traceability

This folder tracks how the platform meets the Ministry's RFP (*مشروع بوابة التدريب التربوي*, May 2026).

| File | What it is | Edited by |
|---|---|---|
| `gap-register.md` | The 278 requirement IDs with status, evidence and the phase that closes each one. **Source of truth.** | People — after every phase |
| `compliance-sheet.md` | The RFP's mandatory tables (31 main items, Infrastructure & Security, Technical, KPIs) pre-filled from the register, with a *Proposal page* column. | Generated |
| `BASELINE.md` | Test counts, versions and how to run the verification gate at the start of the programme. | People |
| `../../backend/resources/rfp/status.json` | The register as data, read by **Settings → RFP Compliance**. | Generated |

## Updating after a phase

1. In `gap-register.md` change the status of every closed requirement (🔴/🟡 → ✅ Available) in the **Full register by RFP module** table, keep the evidence column truthful (feature, screen, endpoint, test), and tick the box under **Open gaps by phase**.
2. Update the totals table at the top of the register.
3. Regenerate the data and the sheet:

   ```bash
   cd backend && php artisan tedc:rfp-status
   ```

4. Commit the register, `compliance-sheet.md` and `status.json` together. `RfpStatusTest` fails if `status.json` is out of step with the register, and checks that the register still lists 278 unique IDs.

## Feature flags

Risky or optional features sit behind flags (Settings → Features, `config/features.php`). In production the four demonstration/support tools (`impersonation`, `test_accounts`, `demo_scenarios`, `self_heal`) start **off**; switching one on needs the *manage users* permission and a written reason, is audited, and shows a banner to every administrator.

Demo seeders only run outside production. A demonstration deployment can opt in with `TEDC_ALLOW_DEMO_IN_PRODUCTION=true` (together with `TEDC_SEED_DEMO=true`).
