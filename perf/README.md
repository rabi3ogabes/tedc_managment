# Load tests (k6)

`smoke.js` runs for a minute on every staging release. `scenarios.js` ramps to 10,000 virtual users (browse 50 %, signed-in portal 35 %, a registration burst, a QR check-in burst at 08:00, 10 % of users in an exam) with the RFP thresholds: p95 < 1.5 s, errors < 0.1 %.

```bash
k6 run -e BASE_URL=https://staging.<domain>/api/v1 -e PERF_PASSWORD=<from Key Vault> -e PEAK=2000 perf/scenarios.js
```

Run only against **staging** with the synthetic perf accounts (`php artisan tedc:seed-perf-users` is not created yet — seed `trainee01@perf.test …` with the demo seeder). Never against production. Results and the extrapolation to 10,000 users go to `docs/ops/performance-report.md`.
