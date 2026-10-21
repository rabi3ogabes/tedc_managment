# Going live with the real data: import the Ministry extract, remove the demo data

Live site: `https://tedc-managment.vercel.app` (Vercel, team `rabiisouai-gmailcoms-projects`, project `tedc-managment`) with the Supabase
project `wlgvhrmdooembvvoqjnc` (database `postgres`, Singapore).

## The way to do it: Settings → Data (البيانات والاستيراد), in the browser

The live `APP_KEY` encrypts the national IDs and is a write-only secret in Vercel, so the import must run **inside the live server**. The
screen does exactly that; no secret ever leaves Vercel. Sign in as a **super administrator**, open **الإعدادات → البيانات والاستيراد**.

### 1. استيراد بيانات الوزارة
1. Drop the three workbooks («…_1.xlsx», «…_2.xlsx», «…_3.xlsx») and press **رفع الملفات وبدء الاستيراد**.
2. The files go straight to private storage (signed uploads — the API itself cannot take large bodies), then the run advances one step per
   request: *setup* (read the files; schools, units, job titles), *people* in 6 parts, *courses* one file at a time. Each step stays well inside
   the 300-second limit of a request and is idempotent: if the page is closed or a step fails, **متابعة الاستيراد** continues where it stopped.
3. The report: rows, people (new / updated), employee numbers, schools (matched / new), courses (new / already there / rejected), hours, and
   *اكتمل الاستيراد وتطابقت الأرقام مع الملفات* when the numbers reconcile. Issue codes only — no names or IDs. The uploaded workbooks are
   deleted from storage when the run ends.

Expected for the extract of 18 Oct 2026 (rehearsed on PostgreSQL with the live demo data and the official school list): 142 302 rows,
31 502 people, 32 986 employee numbers, 718 198 hours; 232 schools matched to the official list, 31 new schools, 20 Ministry departments
(«جهة إدارية بالوزارة»).

### 2. إزالة البيانات التجريبية
1. The card shows what goes (every demo programme, registration, session, attendance, certificate, survey, kit, post, notification, task,
   payment, PLC record, trainer, log and every demo person and account) and what stays (the imported people and courses, the official schools,
   all the setup).
2. Enter the **administrator who stays** (name, e-mail, password — the password policy applies; this is the password they sign in with), type
   **حذف البيانات التجريبية** and press **حذف البيانات التجريبية نهائياً**.
3. Every deleted row is first written to a gzipped backup in private storage (`documents/backups/demo-purge-<date>.jsonl.gz`); the demo
   accounts are removed from Supabase Auth too; the switches «الحسابات التجريبية» and «سيناريو العرض التجريبي» are turned off; `demo_purged_at`
   is recorded so **no deployment adds demo data again**. If you were signed in with a demo account, it is gone: sign in with the new administrator.

### 3. Then
- In Vercel set `TEDC_DEMO_SITE` to `false` (or delete it) so the sign-in screen never offers demo accounts.
- Vercel → Settings → Environments → Production → **Branch Tracking = `main`** (so pushes to `main` deploy to production).

## The same from a terminal (when the live APP_KEY is at hand)

```bash
cd backend   # backend/.env.live (git-ignored) with APP_KEY, DB_URL, SUPABASE_SECRET_KEY of the live site
php artisan tedc:import-trainee-history "<…>_1.xlsx" "<…>_2.xlsx" "<…>_3.xlsx" --env=live --dry-run
php artisan tedc:import-trainee-history "<…>_1.xlsx" "<…>_2.xlsx" "<…>_3.xlsx" --env=live
php artisan tedc:purge-demo --env=live                       # dry run
php artisan tedc:purge-demo --env=live --force --admin=<e-mail> --admin-name="<name>" --backup=live-demo-backup.jsonl.gz
php artisan tedc:supabase-sync-users --env=live --email=<e-mail>
```

## Rehearsals

**SQLite** (copy of the demo database with the official list): import reconciled; 232 schools matched, 31 new; clean-up removed 11 578 demo rows;
foreign keys and integrity checked; a redeploy with `TEDC_SEED_DEMO=true` added nothing.

**PostgreSQL** (the live database type; local PostgreSQL in WSL with the demo data seeded exactly as live). SQLite does not check column lengths
or true/false types and quietly reads an unknown column name in quotes as text. The PostgreSQL rehearsal found and fixed:

- the importer wrote `0/1` into true/false columns (`schools.is_partner`, `employee_numbers.is_current`, `users.mfa_enabled`, `pd_activities.recognition_request`); now `true/false`;
- `schools.source` allowed 16 characters, `moe_training_extract` has 20 — widened to 32 (migration `…008400`);
- **live bug:** a PLC workshop's programme is of kind `plc_workshop` (12 characters) in a column of 8 — creating a PLC workshop failed on the live site; widened to 16 (migration `…008500`);
- **live bug:** the PLC dashboard grouped counts over a query that carried every column — PostgreSQL refused it; the counts now start from a clean column list;
- moving several trainees into a group with too few seats now follows the order the administrator selected (PostgreSQL returns rows in no fixed order);
- the school page read the job title from columns that do not exist (`title_ar/title_en`, the columns are `name_ar/name_en`).

Result on PostgreSQL: import reconciled; a second import creates nothing; clean-up removed 5 711 demo rows with backup and kept 31 502 people,
812 schools and the setup; a redeploy added nothing. The browser import (Settings → Data) was run end to end on the local copy with the three
real workbooks: 10 steps, the longest about a minute, report reconciled.
