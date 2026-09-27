# Supabase setup

The platform uses Supabase for **PostgreSQL**, **Auth**, **Storage** and **Realtime**.

## 1. Create the project

1. Create a Supabase project (region closest to Qatar, e.g. `me-central-1` / `eu-central-1`).
2. Collect from *Project Settings → API*:
   - `SUPABASE_URL`, `SUPABASE_ANON_KEY`, `SUPABASE_SERVICE_ROLE_KEY`
   - `SUPABASE_JWT_SECRET` (legacy HS256 secret). If the project uses asymmetric JWT
     signing keys, leave it empty; the API validates tokens against the project JWKS.
3. Collect the Postgres connection string (*Project Settings → Database*, use the
   **session pooler** for Laravel).

## 2. Create the schema

```bash
cd backend
cp .env.example .env    # fill DB_URL / SUPABASE_* values
php artisan migrate --force
php artisan db:seed --force          # roles, permissions, reference data
TEDC_SEED_DEMO=true php artisan db:seed --class=DemoDataSeeder --force   # optional demo data
```

## 3. Apply security, storage and realtime

```bash
psql "$SUPABASE_DB_URL" -f supabase/setup.sql
```

This script:

- enables **Row Level Security** on every table (default deny for `anon`/`authenticated`);
- adds narrow policies so users can read **only their own notifications** (used by Realtime) and
  everyone can read **published public news**;
- makes `audit_logs` append-only;
- adds `notifications` to the `supabase_realtime` publication;
- creates the storage buckets (`public-assets` public; `materials`, `submissions`, `certificates`,
  `documents` private — reachable only through short-lived signed URLs issued by the API).

## 4. Auth

Set `TEDC_AUTH_DRIVER=supabase` in the API. Users sign in through the API (`POST /api/v1/auth/login`),
which exchanges credentials with Supabase Auth (GoTrue) and links `users.auth_id` to `auth.users.id`.
Every API request carries the Supabase access token and is verified by `App\Auth\JwtVerifier`.

Create platform users from the admin console (or the seeder), then invite them in Supabase Auth with the
same e-mail. Users that sign up directly in Supabase are provisioned just-in-time with the `employee`
role (`TEDC_AUTO_PROVISION_USERS=true`).
