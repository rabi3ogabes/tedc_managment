# Changing the APP_KEY (key rotation) — when the old key cannot be read

**Use this only when the live APP_KEY is not available to copy** (Vercel hides "Sensitive" values for good). If you can still find the
old key, put it on the new server and skip all of this: it is simpler and changes no data.

## What the key protects, and what a new key does

`APP_KEY` locks values in the database: national IDs of employees and trainers, MFA secrets, integration / SMS / push / AI / LTI
secrets, device keys, import data. It also keys two search fingerprints (the national-ID blind index and the registration
duplicate guard). A new key alone makes all of that unreadable, so the platform has a tool that **opens each value with the old
key and locks it again with the new one**, in place, then recomputes the fingerprints. It runs on the old live site, because that
is the only place that still holds the old key.

- Every locked value is found by its shape in every text / JSON column of every table (also inside JSON documents), so nothing is
  missed and a column added later is covered. Nothing else about the data changes.
- It can be stopped and re-run: a value already under the new key is skipped; each write only applies if the row still holds the
  value that was read; a value that neither key opens is **left untouched** and counted.
- It prints counts only: never a key, never a value.
- It does not exist unless `TEDC_NEW_APP_KEY` is set. Remove that variable when done.

**What people notice:** everyone is signed out once (browser sessions are locked with the key). Passwords, Supabase sign-in, files
and certificates are not affected.

## Before you start

1. Merge the release that contains this tool and let both sites deploy (it is inert until step 4).
2. **Pick a quiet hour** (the run takes minutes; announce a 15-minute pause for editing). Writes made while it runs are caught by
   the final check, but anything saved between that check and step 7 would be locked with the old key.
3. **Create the new key and save it in two safe places (password manager + a printed copy) before anything else.** Losing this key
   later is the same problem again. On your computer, in the `backend` folder:
   ```bash
   php artisan key:generate --show
   ```
   It prints `base64:…` (51 characters). Do not paste it into chat or an email.
4. **Backup the live database** (the rollback). From the Athar server (SixPanel → the site → Cron jobs, as one-off task) or any
   machine with `pg_dump` 17+:
   ```bash
   pg_dump "<Supabase session-pooler URL>" --schema=public --no-owner --no-privileges --format=custom --file=tedc-before-rotation.dump
   ```
   Keep it until step 9. Without the old key it can only restore the *structure and the old-locked data*, so it is a safety net for
   a failed run, not a way back after step 7.
5. On Vercel → the TEDC project → Settings → Environment Variables: add **`TEDC_NEW_APP_KEY`** = the new key (Production,
   Sensitive), then **Redeploy** the latest production deployment so the endpoint exists.

## Run it (all from your computer; it asks for CRON_SECRET without showing it)

```bash
read -s -p "CRON_SECRET: " S; echo
call() { curl -sS -X POST https://tedc-managment.vercel.app/api/v1/system/rotate-key -H "Authorization: Bearer $S" -H "Content-Type: application/json" -d "$1"; echo; }
```

6. **Dry run (changes nothing):** `call '{"mode":"dry"}'`
   Read `found`, `rotated` (how many will be moved), `unreadable` (must be **0**; if not, stop and show it to the engineer),
   `blocked` (must be 0), `finished` (true). Note the time in `seconds`.
7. **Run:** `call '{"mode":"run","confirm":"ROTATE-APP-KEY"}'` — repeat while `finished` is `false`.
8. **Check it is done:** `call '{"mode":"dry"}'` again. You want `rotated: 0`, `unreadable: 0`, `blocked: 0`, `complete: true`, and
   `already_new` equal to `found` (every locked value now opens with the new key). If `rotated` is above 0 someone saved something
   meanwhile: run step 7 again.
9. **Switch the platform to the new key (the point of no return):** on Vercel set **`APP_KEY`** = the new key, **delete
   `TEDC_NEW_APP_KEY`**, redeploy. Then sign in, open an employee, and search an employee by national ID (the blind index) to
   confirm. Everyone signs in again once.
10. **Athar server:** put the same key in the site's `APP_KEY` (the panel re-adds empty example lines when you save: ask for them to be removed), copy the
    now-rotated live data over (see DEPLOY-ATHAR.md, "Moving the live data"), then check on the server:
    ```bash
    php artisan tedc:rotate-app-key --mode=verify      # every locked value opens and every fingerprint matches this APP_KEY
    php artisan tedc:app-key-check                     # national IDs decrypt and match
    ```
    Both must print "Complete" / "is the key of the stored data". Then delete the backup file from step 4.

## If something goes wrong

- **Before step 9** the old key is still on Vercel as `APP_KEY`, so nothing is lost: if the dry run shows `unreadable` values or a
  run fails halfway, stop, keep `TEDC_NEW_APP_KEY` or remove it, and fix; re-running is safe. To undo a finished run before
  step 9, restore the step 4 backup into Supabase (`pg_restore --clean --if-exists --no-owner --no-privileges`).
- **After step 9** the old key no longer exists anywhere; the new key is the only one. This is why step 3 comes first.
- A value reported `unreadable` was already unreadable before (written under a key that is neither the old nor the new one); it
  is left as it was.

## What the first real run showed (2026-11-10)

- **Size and time:** 31,504 locked values (31,502 national IDs and 2 settings values), found in 18 s by a dry run and re-locked in 128 s by the run. Zero unreadable, zero blocked. A second dry run right after showed every value "already new".
- **The window:** from the end of the run until Vercel is redeployed with the new `APP_KEY` (3–5 minutes), the old site cannot read national IDs: screens that show them and the search by national ID fail. Do the run in a quiet hour and have the Vercel variables page open.
- **Hosting pitfalls met on the way:**
  - A **Sensitive** Vercel variable keeps its old value when edited. Delete it and add it again, then Redeploy. The route answers 422 with the key's form and length (never its value) when `TEDC_NEW_APP_KEY` is not `base64:` + 44 characters.
  - The `CRON_SECRET` on Vercel cannot be read back either; set a new value (Vercel's own cron job picks up the current one by itself).
  - The panel adds an empty line for every setting of `.env.example` each time a field is saved, and an empty `APP_TIMEZONE=` takes the whole site down: remove those lines after every save.
- **Generate the key without clipboard mix-ups:** `php artisan key:generate --show > file`, check the file is 51 characters starting with `base64:`, copy from the file, store it in a password manager and on paper, then delete the file.
- **Checking the Athar server afterwards:** `php artisan config:clear` (the panel caches the configuration), then `tedc:app-key-check --sample=500` and `tedc:rotate-app-key --mode=verify`; both must report success.
