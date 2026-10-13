# Close-out baseline (Preflight, 11 October 2026)

Recorded before Phase 19, on branch `claude/intelligent-brahmagupta-7pysbz`, with the Preflight changes applied.

| Check | Result |
|---|---|
| Backend tests (`php artisan test`) | 687 tests: 686 passed, 1 skipped (by design), 10,499 assertions |
| Code style (`vendor/bin/pint --test`) | clean |
| Web (`npm run lint`, `npm run build`) | lint passes (existing warnings only), build passes |
| App (`flutter analyze`, `flutter test`) | no issues, 53 tests pass |
| App strings (`scripts/check_dart_keys.py`) | Arabic and English keys match |
| Production `/api/v1/public/health/ready` | ok (database, cache, storage, queue) |
| Vercel deployments | succeeding again since 11 Oct (`67ac831`, `2b30bc2`) |

## Preflight decisions (from the owner, 11 Oct 2026)
- **Live site:** will hold real data later. Demo mode is now impossible in production (code), deployments never set passwords, the shared demo password is no longer in the web app, the mobile app, the workflows or the docs, and Settings → Security lists the accounts that may still use it, with suspend and random-password actions (`tedc:demo-accounts` on the command line). **The owner changes the production passwords** (starting with the administrator) and turns on two-step verification for staff.
- **Face presence in exams (Phase 22):** approved.
- **E-kit topics (Phase 27):** agreed by the centre.
- **Dependency updates (Phase 25):** patch-level updates of development-only packages may merge automatically after CI passes.

## Still open from the Preflight
- The container-scan exception for CVE-2026-78669 stays until FrankenPHP ships golang.org/x/net ≥ 0.60.0 (latest release 1.13.0 still has 0.59.0). It needs the owner's written risk acceptance (`docs/security/patching.md`).
- The repository is public. Making it private, and whether to rewrite its history, is the owner's decision.

## Preflight follow-up (13 October 2026)
- **The live site was still in demo mode.** The project's Vercel settings override `APP_ENV` with a value other than `production`, so every "never in production" guard was off on the live site: demo mode (and `GET /api/v1/public/mobile-config` returned the shared demo password), the fake payment and sign-in drivers, the risky tools' safe defaults, and the demo seeders on every deploy, which rewrote the test accounts' passwords. Fixed in `backend/scripts/vercel-env.php`: the production deployment (`VERCEL_ENV=production`) always runs as `production` (`VercelEnvTest`). After the deploy, confirm that `mobile-config` reports `demo_accounts: false` and no `demo_password`. Setting `APP_ENV=production` in Vercel → Settings → Environment Variables as well removes the contradiction.
- **The owner still has to change the production passwords** (the shared one is in the public repository history) or suspend those accounts from Settings → Security (`tedc:demo-accounts`).
- **Mondays:** the nightly AI job rebuilt forecasts on Mondays and wiped the sample forecasts, so CI failed every Monday. The sample seeder now runs it with `--skip-forecasts` (`SampleForecastsMondayTest`).
- Phase 19 was re-verified in the running app (material discussion with an Arabic-named PDF upload, a signed download link with the Arabic name, a material rating), with no console errors.

