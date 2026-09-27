# Deploying to Vercel (web app and API)

One Vercel project serves both parts of the platform:

```
https://<project>.vercel.app/          → React web app (static, Vercel CDN)
https://<project>.vercel.app/api/v1/   → Laravel API (PHP serverless function, community runtime vercel-php)
```

How the root `vercel.json` sets it up:
- **Web app:** builds `web/` with `VITE_API_URL=/api/v1` and serves `web/dist`. Every page route falls back to
  `index.html`.
- **API:** runs as the function `api/index.php`, using `vercel-php` (PHP 8.4). Requests to `/api/*`, `/up` and
  `/files/*` go to it.
- **PHP packages:** the root `composer.json` installs `backend/` dependencies during the build, then
  `backend/scripts/vercel-prune.php` trims them so the function stays under Vercel's 250 MB limit.
- **Storage:** Laravel's storage and caches live in `/tmp`, the only writable folder on Vercel.
- **Scheduled tasks:** Vercel Cron calls `/api/v1/system/cron` once a day. It runs the program lifecycle, the
  session reminders and the impact surveys.

## 1. Import the project

Vercel → **Add New… → Project** → import `tedc_managment`, then:
- **Framework Preset**: *Other*.
- **Root Directory**: `./` (the repository root).
- Leave the build, output and install commands empty; `vercel.json` provides them.

## 2. Environment variables (4 secrets)

Public settings (the Supabase URL, publishable key and JWKS URL; the Supabase drivers; demo data) already have
defaults in `backend/scripts/vercel-env.php`. Only the secrets go into Vercel → Settings → **Environment Variables**
(tick *Production* and *Preview*):

| Name | Value |
|---|---|
| `APP_KEY` | `base64:` followed by 32 random bytes in base64, e.g. from `php artisan key:generate --show` |
| `DB_URL` | Supabase → **Connect** → pooler URI with the database password, e.g. `postgresql://postgres.<ref>:<password>@aws-0-<region>.pooler.supabase.com:6543/postgres`. A session-pooler URI (port 5432) is switched to the transaction pooler (6543) automatically. |
| `SUPABASE_SECRET_KEY` | `sb_secret_…` (Supabase → Project Settings → API Keys) |
| `CRON_SECRET` | a long random string; it protects `/api/v1/system/*` and the daily cron job |

Any other variable from `backend/.env.example` can still be set to override a default, for example
`TEDC_SEED_DEMO=false` for a production launch or `ANTHROPIC_API_KEY` for the AI assistant.

## 3. Deploy: the database sets itself up

Redeploy after saving the variables. The build (`backend/scripts/vercel-build.php`) then runs the migrations and
seeds an empty database: roles, reference data and, with `TEDC_SEED_DEMO`, the demo data. It also creates the
demo accounts in Supabase Auth with the password `Tedc@2026!`; existing accounts keep their password. Later
deployments only apply new migrations. The build never fails because of the database: check
`https://<project>.vercel.app/api/v1/public/health`. It lists any missing variable names and the database state.

Manual alternative, e.g. to reset the demo passwords:

```bash
curl -X POST https://<project>.vercel.app/api/v1/system/setup \
  -H "Authorization: Bearer <CRON_SECRET>" -H "Content-Type: application/json" \
  -d '{"sync_users_password": "Tedc@2026!"}'
```

## Limits on Vercel

| Limit | Effect |
|---|---|
| Request body 4.5 MB | Uploads (training materials, submissions, imports) larger than ~4 MB are rejected. Use Railway for larger files. |
| Cron on the Hobby plan: once a day | Reminders and lifecycle updates run daily at 05:00 UTC (08:00 Qatar). On Pro, change `schedule` in `vercel.json` to `0 * * * *` for hourly. |
| Function time 60 s | The first setup call on a large demo seed can take a while. If it times out, call it again: finished steps are skipped. |
| Cold starts | The first API request after idle time takes 1–3 s longer. |

## Troubleshooting

| Symptom | Fix |
|---|---|
| `404: NOT_FOUND` on every page | Redeploy the latest commit with Root Directory `./`, so `vercel.json` is used. |
| Diagnose any API error | Open `https://<project>.vercel.app/api/v1/public/health`. It shows the database driver, whether it connects, whether tables exist and a hint (SQLSTATE code only, no secrets). |
| API returns `500` right after deploying | Open the deployment → **Functions** logs. Usually a missing `APP_KEY` or `DB_URL`, or the setup call (step 3) has not run yet. |
| `SQLSTATE[08006]` / `prepared statement "pdo_stmt_…" already exists` | Use the transaction pooler (port 6543) **and** `DB_EMULATE_PREPARES=true`. |
| Login: `بيانات الدخول غير صحيحة` | Run the setup call with `sync_users_password` (step 3). |
| Build error `Function exceeds 250 MB` | Make sure the build log shows `vendor trimmed: … MB`; the root `composer.json` must be deployed. |

## Web app on Vercel, API elsewhere

To host only the web app on Vercel (for example with the API on Railway, see `DEPLOY-RAILWAY.md`):
1. Set **Root Directory** to `web`; `web/vercel.json` is then used.
2. Set `VITE_API_URL=https://<api-host>/api/v1`.
3. On the API, set `CORS_ALLOWED_ORIGINS=https://<project>.vercel.app`.
