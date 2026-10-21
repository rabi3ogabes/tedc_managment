# Going live with the real data: import the Ministry extract, remove the demo data

Live site: `https://tedc-managment.vercel.app` (Vercel, team `rabiisouai-gmailcoms-projects`, project `tedc-managment`) with the Supabase
project `wlgvhrmdooembvvoqjnc` (database `postgres`, Singapore).

The order matters: the people go in **before** the demo data comes out (the clean-up refuses to run when nobody has been imported), and
the demo accounts — whose shared password is shown on the sign-in screen — must be gone **before** anyone relies on the site, because the
imported data is personal data.

## 0. Prerequisites

1. **Vercel → Settings → Git → Production Branch = `main`.** Since the old branch was deleted, pushes to `main` only make previews.
2. Deploy `main` (it brings migrations `…008000`–`…008300` and the commands below).
3. The production values for the commands, in `backend/.env.live` (git-ignored): `APP_KEY`, `DB_URL`, `SUPABASE_SECRET_KEY` and
   `APP_ENV=production`, `TEDC_AUTH_DRIVER=supabase`, `SUPABASE_URL=https://wlgvhrmdooembvvoqjnc.supabase.co`. The **APP_KEY must be the live
   one**: national IDs are encrypted and indexed with it. Delete the file when finished.

## 1. Import (≈ 4–15 min over the network)

```bash
cd backend
php artisan tedc:import-trainee-history "<…>_1.xlsx" "<…>_2.xlsx" "<…>_3.xlsx" --env=live --dry-run
php artisan tedc:import-trainee-history "<…>_1.xlsx" "<…>_2.xlsx" "<…>_3.xlsx" --env=live --report=live-import.json
```

Expected (rehearsed on a copy of the demo database with the official school list, 21 Oct 2026): 142 302 rows, 31 502 people, 32 984
employee numbers, 718 198 hours, *reconciled: yes*, 0 rejected; **schools: 232 matched to the official list, 31 new schools and 20 Ministry
departments** (typed «جهة إدارية بالوزارة»). Safe to repeat.

School names are matched first as written, then with the usual variations removed — «مدرسة», «المستقلة», the article, «بنين/للبنين/للبنبن»,
«ابتدائي/الابتدائية» — when exactly one school fits; boys and girls are never mixed and a kindergarten («روضة مدرسة …») never matches its school.

## 2. Remove the demo data

```bash
php artisan tedc:purge-demo --env=live                                    # dry run: the table of what would go
php artisan tedc:purge-demo --env=live --force --admin=<admin e-mail> --admin-name="<name>" --backup=live-demo-backup.jsonl.gz
php artisan tedc:supabase-sync-users --env=live --email=<admin e-mail>     # the administrator gets Supabase's "set your password" e-mail
```

What goes: every programme, group, session, registration, attendance, certificate, survey and answer, kit, post, notification, task,
assessment, payment, PLC record, trainer, log and every demo person and account (also removed from Supabase Auth). What stays: roles and
permissions, settings and theme, reference lists, help centre, report definitions, certificate and evaluation templates, holidays, public
pages, training rooms, the official school list, the imported people and courses, and the one administrator. Every deleted row is first
written to the backup file. Afterwards `demo_purged_at` is set and **no deployment adds demo data again** (and `TEDC_SEED_DEMO` now
defaults to false).

Rehearsal: 11 816 demo rows in 180 tables removed; foreign keys and integrity checked; a redeploy with `TEDC_SEED_DEMO=true` added nothing.

## 3. Check

`/api/v1/public/health` is ok; sign in as the administrator; «الموظفون» shows 31 502 people; «المدارس» shows the schools with their staff
and training timeline. Then set `TEDC_DEMO_SITE=false` in Vercel (no demo accounts on the sign-in screen) and delete `backend/.env.live`.

**Full suite (21 Oct 2026):** 1205 tests, 1134 passed; the failures are the known Windows-only set (VercelEnvTest x2, ContractDriverTest mTLS, IntegrationDocsTest line endings, SocialFilesTest) and 54 openssl errors.

## 4. Rehearsed on PostgreSQL (the live database type)

The first rehearsals ran on SQLite, which does not check column lengths or true/false types and quietly reads an unknown column name in quotes as text. A second rehearsal on a local PostgreSQL (WSL), with the demo data seeded exactly as live and the official school list, found and fixed:

- the importer wrote `0/1` into true/false columns (`schools.is_partner`, `employee_numbers.is_current`, `users.mfa_enabled`, `pd_activities.recognition_request`) — PostgreSQL refuses them; now `true/false`;
- `schools.source` allowed 16 characters, `moe_training_extract` has 20 — widened to 32 (migration `…008400`);
- **live bug:** a PLC workshop's programme is of kind `plc_workshop` (12 characters) in a column of 8 — creating a PLC workshop failed on the live site; widened to 16 (migration `…008500`);
- **live bug:** the PLC dashboard grouped counts over a query that carried every column — PostgreSQL refused it; the counts now start from a clean column list;
- moving several trainees into a group with too few seats now follows the order the administrator selected (PostgreSQL returns rows in no fixed order);
- the school page read the job title from columns that do not exist (`title_ar/title_en`, the columns are `name_ar/name_en`) — SQLite showed the column name as text; fixed and covered by the test.

Result on PostgreSQL: import 142 302 rows / 31 502 people / 718 198 hours, reconciled; a second import creates nothing; clean-up removes 5 711 demo rows (with backup) and keeps 31 502 people, 812 schools (20 of them Ministry departments) and the setup; a redeploy adds nothing. All 57 tests of the recent features pass on PostgreSQL and on SQLite.
