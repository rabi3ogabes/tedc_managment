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

## 2. Environment variables

Settings → **Environment Variables**. Add the following for *Production* (and *Preview* if you use it):

| Name | Value |
|---|---|
| `APP_KEY` | `base64:…`, generated with `php artisan key:generate --show` (or any `base64:` of 32 random bytes) |
| `DB_CONNECTION` | `pgsql` |
| `DB_URL` | Supabase **Transaction pooler** URI, port **6543**: `postgresql://postgres.<ref>:<db-password>@aws-0-<region>.pooler.supabase.com:6543/postgres?sslmode=require` |
| `DB_EMULATE_PREPARES` | `true` (required by the transaction pooler) |
| `TEDC_AUTH_DRIVER` | `supabase` |
| `TEDC_STORAGE_DRIVER` | `supabase` (**required**: Vercel has no persistent disk) |
| `SUPABASE_URL` | `https://<ref>.supabase.co` |
| `SUPABASE_PUBLISHABLE_KEY` | `sb_publishable_…` |
| `SUPABASE_SECRET_KEY` | `sb_secret_…` (keep it only here) |
| `SUPABASE_JWKS_URL` | `https://<ref>.supabase.co/auth/v1/.well-known/jwks.json` |
| `CRON_SECRET` | a long random string (e.g. `openssl rand -hex 32`), which protects `/api/v1/system/*` |
| `VITE_SUPABASE_URL` | `https://<ref>.supabase.co` (live notifications) |
| `VITE_SUPABASE_PUBLISHABLE_KEY` | `sb_publishable_…` (never the secret key) |
| `TEDC_SEED_DEMO` | `true` for the demo data and the 8 demo accounts (optional) |
| `VITE_SHOW_DEMO_ACCOUNTS` | the login page shows the demo accounts panel by default; set `false` to hide it before a real launch |
| `ANTHROPIC_API_KEY` | optional: AI assistant |

`APP_URL` defaults to the production domain automatically. Then **Deploy**, or redeploy if the project already
exists.

## 3. One-time setup (database and users)

After the first successful deployment, create the tables and the Supabase Auth accounts. The request is
protected by `CRON_SECRET`:

```bash
curl -X POST https://<project>.vercel.app/api/v1/system/setup \
  -H "Authorization: Bearer <CRON_SECRET>" \
  -H "Content-Type: application/json" \
  -d '{"sync_users_password": "Tedc@2026!"}'
```

On Windows PowerShell, use `curl.exe` and escape the JSON quotes, or run the same call from any HTTP client
(Postman, Insomnia).

The response lists what ran: the migrations, the seeding on an empty database, and the users created in Supabase
Auth with that password. Run it again after a release that adds migrations; leave out `sync_users_password` then.

Then open `https://<project>.vercel.app/login` and sign in as `admin@tedc.qa`.

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
