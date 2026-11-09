# Running on the Athar Qatar server (tedc.atharqatar.net)

The platform runs on the Athar Qatar server, managed with **SixPanel** (Ubuntu, nginx, PHP-FPM, PostgreSQL, Cloudflare in
front). One site, `tedc-managment`, serves the web app and the API from the same address:

```
https://tedc.atharqatar.net/          → the React web app (public site, dashboard, portal)
https://tedc.atharqatar.net/api/v1/   → the Laravel API
```

## How a release reaches the server

```
push to main ──▶ GitHub Action "Release for Athar" ──▶ branch `release/athar` ──▶ panel deploy ──▶ live
```

- `scripts/build-release.sh` builds the folder the server runs: the **backend at the root** (committed files, without
  tests) and the **built web app in `public/`** (`index.html` becomes `app.html`; Laravel answers every page route with
  it, see `backend/routes/web.php`). `vendor/` is not in the branch: the panel runs `composer install`.
- The panel's site is pointed at `release/athar`. Press **Deploy** on the site's Deploys page (or turn push-to-deploy on).
  Each deploy: fetch → composer → place the files → run `php artisan tedc:deploy` (migrations, seeding an empty database
  once, caches, `storage:link`) → go live. A failed step leaves the old release live.
- Run the build by hand (to publish without GitHub): `bash scripts/build-release.sh`, then commit the `release-out/`
  folder as a fresh repository to `release/athar` (force-push).

## The site's settings (already made)

| Setting | Value |
|---|---|
| Kind / PHP | PHP site, PHP 8.5 pool, own user `site-tedc-managment` |
| Served folder (`root`) | `public`, front controller **on** |
| Kept across deploys | `storage/` |
| Deploy command | `php artisan tedc:deploy` |
| Scheduled task | every minute: `cd /var/www/_sites/tedc-managment/app && php artisan schedule:run --no-interaction` |
| Database | PostgreSQL `site_tedc_managment_pg` (the panel names its variables `PG_DB_*`; `config/database.php` reads them first) |
| PHP extensions | `pdo_pgsql` and `pgsql` must be **on for the web PHP** (Software → PHP → 8.5 → Extensions) |

Environment (the site's `.env`, edited in the panel): `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, `TEDC_WEB_URL` and
`CORS_ALLOWED_ORIGINS` = `https://tedc.atharqatar.net`, `DB_CONNECTION=pgsql`, `CACHE_STORE=file`, `SESSION_DRIVER=cookie`,
`QUEUE_CONNECTION=sync`, `LOG_CHANNEL=daily`, `TEDC_AUTH_DRIVER=supabase`, `TEDC_STORAGE_DRIVER=supabase`, the three public Supabase
values, and the secrets **`APP_KEY`**, **`SUPABASE_SECRET_KEY`** and **`CRON_SECRET`**.

> **`APP_KEY` must be the live site's key** before real data is restored: encrypted columns (national ids and others)
> cannot be read with another key. Anything written under a different key is lost when the live database is restored.

## Moving the live data (do it once, at a quiet hour)

1. **Freeze** the old site: stop people using it (an announcement, or switch off sign-in on the old site) so nothing changes
   during the copy.
2. **Dump** the live application tables from Supabase (the `public` schema only; Supabase keeps its own auth and storage
   schemas), on the Athar server:
   ```bash
   pg_dump "<Supabase session-pooler URL>" --schema=public --no-owner --no-privileges --format=custom --file=/tmp/tedc.dump
   ```
3. **Set the live `APP_KEY` and `SUPABASE_SECRET_KEY`** in the site's environment (panel → the site → Settings → Environment).
4. **Restore** into the site's database (its password is on the site's Database page):
   ```bash
   PGPASSWORD='<site database password>' pg_restore -h 127.0.0.1 -U site_tedc_managment_pg -d site_tedc_managment_pg \
     --clean --if-exists --no-owner --no-privileges /tmp/tedc.dump
   ```
5. **Prepare the release** (adds anything newer than the dump, rebuilds the caches), then delete the dump:
   ```bash
   sudo -u site-tedc-managment bash -c 'cd /var/www/_sites/tedc-managment/app && php artisan tedc:deploy'
   shred -u /tmp/tedc.dump
   ```
6. **Check**: sign in with a real account, open a programme, a certificate PDF, a file upload, the error log, and
   `php artisan tedc:supabase-check`. Files stay in Supabase Storage (`TEDC_STORAGE_DRIVER=supabase`), so none are copied.

Files are untouched by the move; only the database is copied. The Supabase project keeps sign-in (Auth) and file storage.

## After the move

- **Sign-in and callbacks**: in Supabase → Authentication → URL configuration add `https://tedc.atharqatar.net` (site URL and
  redirect URLs); in the Ministry single sign-on and Microsoft Teams app registrations replace the old address with
  `https://tedc.atharqatar.net/api/v1/...` (the same paths).
- **The mobile app** has the address built in (`API_URL`, `mobile/lib/core/config.dart`): publish a new build pointing at
  `https://tedc.atharqatar.net/api/v1`, and keep the old Vercel site up (or redirecting) until people have updated.
- **Backups** (Backups page): schedule database + files, add an off-server storage, save the backup password, and test a restore.
- **Hardening**: finish the panel's two-factor login, and consider "Allow traffic from Cloudflare only" (Server → Firewall).
- **Retire Vercel** only after a few quiet weeks. Rollback until then is "announce the old address again".

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Pages load but `/api/...` and `/up` answer 500; log says `could not find driver` | Switch on `pdo_pgsql` and `pgsql` for the web PHP (see above), then deploy again or reload PHP. |
| Deploy fails at "Run the deploy commands" | The panel prints the first failing line (a migration, the database connection, a missing variable). |
| Blank page after a deploy | The release has no `public/app.html`: run the Release workflow (or `scripts/build-release.sh`) again. |
| Scheduled tasks do not run | The site's Cron jobs page shows the last answer of `schedule:run`. |
