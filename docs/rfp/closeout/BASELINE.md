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
