# Connecting the platform to Supabase

The platform uses Supabase for **PostgreSQL** (database), **Auth** (login), **Storage** (files, certificates,
materials) and **Realtime** (live notifications). Out of the box the project runs locally with SQLite and built-in
login; follow these steps to switch to Supabase.

## Step 1 — Create the Supabase project

1. Sign in at <https://supabase.com> → **New project**.
2. Choose a strong database password (save it) and the region closest to Qatar (e.g. *Middle East* / *Frankfurt*).

## Step 2 — Collect the keys

| Where in the Supabase dashboard | Example | Goes into |
|---|---|---|
| **Project Settings → Data API → Project URL** | `https://xxxx.supabase.co` | `SUPABASE_URL` |
| **Project Settings → API Keys → Publishable key** | `sb_publishable_…` | `SUPABASE_PUBLISHABLE_KEY` (API), `VITE_SUPABASE_PUBLISHABLE_KEY` (web), `SUPABASE_PUBLISHABLE_KEY` (mobile) |
| **Project Settings → API Keys → Secret key** | `sb_secret_…` — **server only** | `SUPABASE_SECRET_KEY` (API only) |
| **Project Settings → JWT Keys** (JWKS URL) | `https://xxxx.supabase.co/auth/v1/.well-known/jwks.json` | `SUPABASE_JWKS_URL` (optional — derived from the URL when empty) |
| **Connect** button (top bar) → **Session pooler** → URI | `postgresql://postgres.xxxx:[YOUR-PASSWORD]@aws-0-….pooler.supabase.com:5432/postgres` | `DB_URL` |

> Never put the **secret key** in the web or mobile app, in git, or in chat — it bypasses all security. If it was
> exposed, roll it (*API Keys → Secret keys → ⋯ → Roll*) and update `backend/.env`.
>
> Older projects that still show **anon** / **service_role** keys can use `SUPABASE_ANON_KEY`,
> `SUPABASE_SERVICE_ROLE_KEY` and `SUPABASE_JWT_SECRET` instead — both formats are supported.

## Step 3 — Configure the API (`backend/.env`)

```dotenv
DB_CONNECTION=pgsql
DB_URL="postgresql://postgres.xxxx:YOUR-DB-PASSWORD@aws-0-eu-central-1.pooler.supabase.com:5432/postgres?sslmode=require"

TEDC_AUTH_DRIVER=supabase
TEDC_STORAGE_DRIVER=supabase

SUPABASE_URL=https://xxxx.supabase.co
SUPABASE_PUBLISHABLE_KEY=sb_publishable_...
SUPABASE_SECRET_KEY=sb_secret_...          # server only
SUPABASE_JWKS_URL=https://xxxx.supabase.co/auth/v1/.well-known/jwks.json
SUPABASE_JWT_SECRET=                       # leave empty (tokens are verified with the JWKS keys)
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
php artisan tedc:supabase-sync-users --password='Tedc@2026!' --reset-password   # also set the password of users already in Supabase
```

On Windows PowerShell or CMD use double quotes: `--password="Tedc@2026!"`.

Users who later sign up directly in Supabase Auth are added automatically with the `employee` role
(`TEDC_AUTO_PROVISION_USERS=true`).

## Step 7 — Connect the web app (`web/.env`)

```dotenv
VITE_API_URL=https://api.your-domain.qa/api/v1   # or /api/v1 in local development
VITE_SUPABASE_URL=https://xxxx.supabase.co
VITE_SUPABASE_PUBLISHABLE_KEY=sb_publishable_...  # publishable key only
```

Also set `CORS_ALLOWED_ORIGINS` and `TEDC_WEB_URL` in `backend/.env` to the web app's address.

## Step 8 — Connect the mobile app

```bash
flutter run \
  --dart-define=API_URL=https://api.your-domain.qa/api/v1 \
  --dart-define=SUPABASE_URL=https://xxxx.supabase.co \
  --dart-define=SUPABASE_PUBLISHABLE_KEY=sb_publishable_...
```

## Step 9 — Check it works

0. `php artisan tedc:supabase-check` → checks that the API can reach Supabase over HTTPS with your keys.
1. `php artisan serve`, then log in on the web app as `admin@tedc.qa` → the login is now verified by Supabase Auth
   (you will see the users under *Authentication → Users* in the dashboard).
2. Upload a training material or issue a certificate → the files appear under **Storage**.
3. Approve a registration → the employee's bell icon updates instantly (Realtime).

## Troubleshooting

| Symptom | Fix |
|---|---|
| `cURL error 60: SSL certificate … unable to get local issuer certificate` | PHP has no trusted CA list (typical on Windows/XAMPP/Laragon). Update the code (`git pull`), run `composer install` (it adds the Mozilla CA bundle the API now uses automatically) and `php artisan optimize:clear`, then restart `php artisan serve` and run `php artisan tedc:supabase-check`. Behind a company proxy that inspects HTTPS, set `SUPABASE_CA_BUNDLE` to a `.pem` file that includes your company's root certificate. Never disable SSL verification. |
| `could not connect to server` / timeout | Use the **Session pooler** URI (port 5432) and add `?sslmode=require`. |
| Login says `بيانات الدخول غير صحيحة` / invalid credentials | The account does not exist in Supabase Auth or has another password. Run `php artisan tedc:supabase-sync-users --password="Tedc@2026!" --reset-password`. Run `php artisan tedc:supabase-check --email=admin@tedc.qa --password="Tedc@2026!"` for the exact reason and fix; the reason is also logged in `storage/logs/laravel.log`. |
| Login says the account is not enabled | Run `php artisan tedc:supabase-sync-users` — the e-mail must exist in both places. |
| `401` on every request after login | Leave `SUPABASE_JWT_SECRET` empty (new projects sign tokens with JWKS keys) and check `SUPABASE_JWKS_URL`. |
| File uploads fail | Check `SUPABASE_SECRET_KEY` and that `setup.sql` created the buckets. |
| No live notifications | Set `VITE_SUPABASE_*`, and make sure `setup.sql` ran (it adds `notifications` to Realtime). |
