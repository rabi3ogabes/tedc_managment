# Baseline — start of the RFP compliance programme (5 Oct 2026)

| Area | Version / result |
|---|---|
| Laravel | 13.34.0 (PHP 8.4) |
| React web | React 19, TypeScript strict, Vite, Tailwind CSS v4 |
| Backend tests | `php artisan test` → **201 passed**, 1,932 assertions (before Phase 00) |
| Code style | `vendor/bin/pint --test` → passed |
| Web | `npm run build` (tsc -b + vite) passed; `npm run lint` → no errors |
| Mobile | `flutter analyze` and `flutter test` run in GitHub Actions (`mobile-apk.yml`, `ci.yml`) — green at the baseline commit |
| Requirements | 278 · 71 available · 89 partial · 118 missing (42 % coverage) |

## Running the verification gate

```bash
cd backend && php artisan migrate:fresh --seed && php artisan test && vendor/bin/pint --test
cd web && npm run lint && npm run build
cd mobile && flutter analyze && flutter test   # CI runs these on every push
```

Notes

* `phpunit.xml` sets `memory_limit` to 768 MB: the PDF tests need more than PHP's 128 MB default.
* Tests run on in-memory SQLite; production uses PostgreSQL (Supabase).
* The deployment on Vercel runs `route:cache`: **no closure routes** (use controllers).
