# Deploying to Railway

The repository deploys to [Railway](https://railway.com) as **one service**: the root `Dockerfile` builds the
React web app and the Laravel API into a single image served by FrankenPHP (Caddy). `railway.json` tells Railway
to use it, with a health check on `/up`.

```
https://<your-app>.up.railway.app/          → web app (public site, dashboard, portal)
https://<your-app>.up.railway.app/api/v1/   → REST API
```

On every start the container runs `php artisan tedc:deploy`, which:
1. runs the database migrations;
2. seeds the database the first time (roles, permissions and reference data, plus demo data when
   `TEDC_SEED_DEMO=true`);
3. warms the config, route and view caches.

It then starts the Laravel scheduler in the background and the web server.

## 1. Create the service

1. Railway → **New Project** → **Deploy from GitHub repo** → pick `tedc_managment`.
2. Open the service → **Settings**:
   - **Source → Branch**: the branch you deploy (e.g. `main`, or `claude/intelligent-brahmagupta-7pysbz`).
   - **Root Directory**: leave **empty** (the `Dockerfile` is at the repository root).
   - **Networking → Generate Domain** to get the public `*.up.railway.app` address.

## 2. Variables

Service → **Variables** → **Raw Editor**, then paste and fill in:

```env
# Required
APP_KEY=base64:...            # generate locally: php artisan key:generate --show

# Database — Supabase Postgres. Use the *Session pooler* URI (Railway has no IPv6 route to the direct host).
DB_CONNECTION=pgsql
DB_URL=postgresql://postgres.<project-ref>:<db-password>@aws-0-<region>.pooler.supabase.com:5432/postgres?sslmode=require

# Supabase Auth and Storage
TEDC_AUTH_DRIVER=supabase
TEDC_STORAGE_DRIVER=supabase
SUPABASE_URL=https://<project-ref>.supabase.co
SUPABASE_PUBLISHABLE_KEY=sb_publishable_...
SUPABASE_SECRET_KEY=sb_secret_...
SUPABASE_JWKS_URL=https://<project-ref>.supabase.co/auth/v1/.well-known/jwks.json

# Web app realtime notifications (public values, built into the web app)
VITE_SUPABASE_URL=https://<project-ref>.supabase.co
VITE_SUPABASE_PUBLISHABLE_KEY=sb_publishable_...

# Optional
TEDC_SEED_DEMO=true           # demo schools, programs and the 8 demo accounts on the first deploy
VITE_SHOW_DEMO_ACCOUNTS=true  # demo accounts panel on the login page; false hides it
ANTHROPIC_API_KEY=            # AI assistant (rule-based fallback without it)
```

`APP_URL`, `TEDC_WEB_URL` and `CORS_ALLOWED_ORIGINS` default to the Railway domain automatically
(`RAILWAY_PUBLIC_DOMAIN`). Set them only for a custom domain.

> Keep `TEDC_STORAGE_DRIVER=supabase` in production: the container's disk is wiped on every deploy, so files
> stored with the `local` driver would be lost.

> Put secrets only in Railway variables, never in the repository.

## 3. Deploy, then create the Supabase Auth users

Railway builds and deploys on every push to the selected branch. When the deployment is green, create the
Supabase Auth accounts for the platform users once, from your computer, with the same variables in
`backend/.env`:

```bash
php artisan tedc:supabase-sync-users --password="Tedc@2026!" --reset-password
php artisan tedc:supabase-check --email=admin@tedc.qa --password="Tedc@2026!"
```

Or run them inside the running service with the Railway CLI: `railway ssh`, then `php artisan …`.

## Troubleshooting

| Symptom | Fix |
|---|---|
| Build log: `No start command could be found` / wrong builder | The service's **Root Directory** must be empty so Railway uses `railway.json` and the root `Dockerfile`. |
| Deploy log: `APP_KEY is not set` | Add `APP_KEY` (from `php artisan key:generate --show`). |
| Deploy log: `could not translate host name` / `Network is unreachable` for the database | Use the Supabase **Session pooler** URI in `DB_URL`, not the direct `db.<ref>.supabase.co` host. |
| Health check fails | Open **Deploy Logs**: the first failing line (migration, database connection, missing variable) is the cause. |
| Login: `بيانات الدخول غير صحيحة` | Run the sync command in step 3. |
