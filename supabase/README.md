# Connecting the platform to Supabase

The platform uses Supabase for **PostgreSQL** (database), **Auth** (login), **Storage** (files, certificates,
materials) and **Realtime** (live notifications). Out of the box the project runs locally with SQLite and built-in
login; follow these steps to switch to Supabase.

## Step 1 — Create the Supabase project

1. Sign in at <https://supabase.com> → **New project**.
2. Choose a strong database password (save it) and the region closest to Qatar (e.g. *Middle East* / *Frankfurt*).

## Step 2 — Collect the keys

| Where in the Supabase dashboard | Value | Goes into |
|---|---|---|
| **Project Settings → API → Project URL** | `https://xxxx.supabase.co` | `SUPABASE_URL` |
| **Project Settings → API Keys → anon / publishable** | public key | `SUPABASE_ANON_KEY` (API, web, mobile) |
| **Project Settings → API Keys → service_role** (use the *Legacy API keys* tab if shown) | secret key — **server only** | `SUPABASE_SERVICE_ROLE_KEY` |
| **Project Settings → JWT Keys → Legacy JWT secret** (only if your project still uses HS256) | secret | `SUPABASE_JWT_SECRET` (leave empty for new projects — the API then validates tokens with the project's public JWKS keys) |
| **Connect → Session pooler → URI** | `postgresql://postgres.xxxx:[PASSWORD]@aws-0-….pooler.supabase.com:5432/postgres` | `DB_URL` |

> Never put the `service_role` key in the web or mobile app — it bypasses all security.

## Step 3 — Configure the API (`backend/.env`)

```dotenv
DB_CONNECTION=pgsql
DB_URL="postgresql://postgres.xxxx:YOUR-DB-PASSWORD@aws-0-eu-central-1.pooler.supabase.com:5432/postgres?sslmode=require"

TEDC_AUTH_DRIVER=supabase
TEDC_STORAGE_DRIVER=supabase

SUPABASE_URL=https://xxxx.supabase.co
SUPABASE_ANON_KEY=eyJ...            # anon / publishable key
SUPABASE_SERVICE_ROLE_KEY=eyJ...    # service_role key (server only)
SUPABASE_JWT_SECRET=                # legacy HS256 secret, or empty to use JWKS
```

Then clear the config cache: `php artisan config:clear`.

## Step 4 — Create the tables and data

```bash
cd backend
php artisan migrate --force                      # creates all tables in Supabase Postgres
php artisan db:seed --force                      # roles, permissions, reference data
php artisan db:seed --class=DemoDataSeeder --force   # optional: Qatar demo data
```

## Step 5 — Apply security, storage buckets and realtime

Run [`setup.sql`](setup.sql) once, either:

* **Dashboard:** *SQL Editor → New query* → paste the file → **Run**, or
* **Terminal:** `psql "$DB_URL" -f supabase/setup.sql`

It enables Row Level Security on every table, lets users read only their own notifications, creates the storage
buckets (`public-assets` public; `materials`, `submissions`, `certificates`, `documents` private) and turns on
Realtime for notifications. Re-run it after future migrations that add tables.

## Step 6 — Create the users in Supabase Auth

Every platform user needs a Supabase Auth account with the same e-mail. One command creates / links them all:

```bash
php artisan tedc:supabase-sync-users --password='Tedc@2026!'   # demo: same initial password for everyone
php artisan tedc:supabase-sync-users                            # production: users receive a "set your password" e-mail
php artisan tedc:supabase-sync-users --email=new.teacher@schools.edu.qa   # a single user
```

Users who later sign up directly in Supabase Auth are added automatically with the `employee` role
(`TEDC_AUTO_PROVISION_USERS=true`).

## Step 7 — Connect the web app (`web/.env`)

```dotenv
VITE_API_URL=https://api.your-domain.qa/api/v1   # or /api/v1 in local development
VITE_SUPABASE_URL=https://xxxx.supabase.co
VITE_SUPABASE_ANON_KEY=eyJ...                     # anon key only
```

Also set `CORS_ALLOWED_ORIGINS` and `TEDC_WEB_URL` in `backend/.env` to the web app's address.

## Step 8 — Connect the mobile app

```bash
flutter run \
  --dart-define=API_URL=https://api.your-domain.qa/api/v1 \
  --dart-define=SUPABASE_URL=https://xxxx.supabase.co \
  --dart-define=SUPABASE_ANON_KEY=eyJ...
```

## Step 9 — Check it works

1. `php artisan serve`, then log in on the web app as `admin@tedc.qa` → the login is now verified by Supabase Auth
   (you will see the users under *Authentication → Users* in the dashboard).
2. Upload a training material or issue a certificate → the files appear under **Storage**.
3. Approve a registration → the employee's bell icon updates instantly (Realtime).

## Troubleshooting

| Symptom | Fix |
|---|---|
| `could not connect to server` / timeout | Use the **Session pooler** URI (port 5432) and add `?sslmode=require`. |
| Login says the account is not enabled | Run `php artisan tedc:supabase-sync-users` — the e-mail must exist in both places. |
| `401` on every request after login | `SUPABASE_JWT_SECRET` is wrong: copy the legacy secret exactly, or leave it empty so JWKS is used. |
| File uploads fail | Check `SUPABASE_SERVICE_ROLE_KEY` and that `setup.sql` created the buckets. |
| No live notifications | Set `VITE_SUPABASE_*`, and make sure `setup.sql` ran (it adds `notifications` to Realtime). |
