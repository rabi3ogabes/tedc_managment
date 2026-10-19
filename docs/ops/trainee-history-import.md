# Importing the Ministry's trainee extract — employees and the courses they attended

Date: 2026-10-19 · Command `php artisan tedc:import-trainee-history` · Service `App\Services\Import\TraineeHistoryImport`

The Ministry's extract of 18 Oct 2026 («بيانات الدورات التدريبية للعاملين بالمدارس الحكومية», three workbooks) is one row per **employee × course attended**: 142 302 rows, 31 502 people (by national ID), 32 984 employee numbers, 1 070 distinct courses from 2008 to 2026, 286 organisation names. This command loads it into the platform — the people, their schools and job titles, and every course as a training record.

> **Personal data.** The files carry national IDs, names, e-mails and phone numbers. They are read from where they are, never copied into the repository, never sent to any service, and the command's output and report contain **counts and issue codes only** (no names, IDs, e-mails). National IDs are stored encrypted (as for every employee) plus a keyed blind index for lookup. Keep the workbooks out of version control and delete working copies after the load.

## 1. How to run

```bash
cd backend
# 1. rehearse: reads everything, checks every row, writes nothing
php artisan tedc:import-trainee-history file_1.xlsx file_2.xlsx file_3.xlsx --dry-run --report=dry-run.json
# 2. load (back the database up first)
php artisan tedc:import-trainee-history file_1.xlsx file_2.xlsx file_3.xlsx --report=report.json
```

It takes about four minutes for the three files and a few megabytes of memory (the workbooks are streamed, not loaded). The exit code is non-zero if the numbers do not reconcile. Running it again — with the same files or with a newer extract that overlaps them — is safe: people are matched by the national ID's blind index and every training record by a hash of its content, so nothing is duplicated (the real data was loaded twice: the second run created 0 and found all 142 302 records already there).

## 2. What each column becomes

| Extract column | Goes to |
|---|---|
| IDNO (11 digits) | `employees.national_id` (encrypted) + `employees.national_id_hash` (HMAC-SHA256 blind index, unique) — the key of a person |
| EMPNO | `employees.employee_no` (the person's current number) and every number in the new `employee_numbers` table (re-hired staff have one per employment) |
| EMPNO_SEQ | `employees.employment_seq`, `employee_numbers.seq` |
| EMPNAME | `users.name` and `users.name_ar` (trimmed; the extract has Arabic names only) |
| NATNAME | `employees.nationality` — the adjective mapped to the country name the rules use (قطرية → Qatar, about 90 forms); unknown forms kept as written; «غير محددة الجنسية» → empty |
| SECAPPMDT | `employees.hire_date`; the earliest appointment of the person sets `experience_moe_years` / `experience_years` |
| ORGNNAME | `schools` (matched by Arabic name, created when new: government, stage and gender read from the name, region «غير محدد», `source = moe_training_extract`) |
| SUBORGNAME | `departments` (a unit under the school, e.g. the school's kindergarten wing) and `employees.sub_unit` as written |
| EQLEVEL_DESC | `employees.education_level` as written + `qualification` code (bachelor, master, phd, diploma, postgrad_diploma, secondary, preparatory, primary, none) |
| CERT_NAME, SP_CODE_DESC | `employees.certificate_name`, `employees.specialization` |
| JOBNAME (+ PROG_JOBNO, EMPJOBCLASS_DESC) | `job_titles` (matched by Arabic name, created when new: code `J<job number>` or a stable hash, category from the job class and the title) |
| EMPJOBCLASS_DESC, EMPCLASS_DESC, EMP_TYPE_DESC | `employees.job_class`, `employment_class`, `employee_kind` |
| SEX_DESC | `employees.gender` |
| SEC_EMAIL, MOBILE_NO | `users.email`, `users.phone` |
| COURSE_NAME_A | `pd_activities.title` |
| COURSE_DT_FROM / _END | `starts_on` / `ends_on` |
| DURATION | `duration_hours`, `computed_hours`, `approved_hours` (read as hours) |
| COURSE_TYPE_NAME_A, TRACK_DESCE | `pd_activities.course_type`, `track` |
| PROVIDER_NAME_A, COUNTRY_NAME_A | `provider`, `location` |
| PROG_JOBNO / _DESC | `pd_activities.external_ref` / `external_ref_desc` (the job the course was meant for) |
| EMPNO of the row | `pd_activities.source_empno` |

Every course is an **approved external professional-development record** of the type «دورة تدريبية (سجل الوزارة)» (`SEC_COURSE`), so it counts in the hours a trainee trained last year (the number shown beside every trainee on the program pages), in the annual-hours and PD ledgers and on the employee profile. No certificate is created: the extract says a course was attended, not that it was passed.

## 3. The columns that were added (migrations `…007800`, `…007900`)

- `employees`: `national_id_hash` (unique), `education_level`, `certificate_name`, `job_class`, `employment_class`, `employee_kind`, `employment_seq`, `sub_unit`, `source_system`, `imported_at`; indexes on school, job title, department and status.
- new table `employee_numbers` (employee, number, sequence, appointed on, employment class, organisation, current?).
- `pd_activities`: `source_system`, `row_hash` (unique), `source_empno`, `course_type`, `track`, `external_ref`, `external_ref_desc`; index on employee and start date.
- The admin employee search now also finds a person by **any employee number they have had** or by the **full 11-digit national ID** (through the blind index); the employee profile shows the job and qualification data, the numbers and the training history (totals by year and the latest 100 courses).

## 4. Rules applied (decisions to confirm)

1. **One person per national ID.** A person with several employee numbers (1 457 were re-hired, up to 10 numbers) is one employee; the current employment is the best one — not ended, in a real school, latest appointment, highest sequence — and gaps (e-mail, phone, qualification) are filled from the person's other employments. Every number is kept in `employee_numbers`; every course follows the person, whichever number it was filed under.
2. **Left or not working → inactive.** Employment classes استقالة, عدم تجديد تعاقد, انهاء خدمات, بلوغ السن القانونية, إعفاء من العمل, مؤقتين انهاء عقد, إنتداب إلى جهات أخرى, and the organisations «مجهزين للحذف» and «لا يعملون_مدارس», make the employee and the user `inactive` (802 people). «أجازة طويلة مدارس» (long leave) stays active. Their training history is kept. These three names are not schools and are not created as schools; the person's school is taken from their other employment when there is one.
3. **E-mail.** A person without a valid e-mail, or whose address another person already has, gets `<employee number>@no-email.moe.invalid` (reserved domain, 639 people): the account exists for reports and eligibility but nobody can sign in with it until the address is corrected. Accounts are created without a password and with the Employee role.
4. **DURATION is read as hours** (it is 1 for the 59 000 cyber-security awareness rows and 12–15 for multi-day courses). Total 718 198 hours.
5. **Provider** is the same for every row («المجلس الأعلى للتعليم», the former Supreme Education Council), country Qatar, type «دورة».
6. **Experience** is derived from the earliest appointment date (an assumption: years since first appointment).
7. **Names are Arabic only**; English screens show the Arabic name until English names are added.

## 5. Reconciliation of the real load (local database, 19 Oct 2026)

| | Source | Database |
|---|---:|---:|
| rows | 142 302 | 142 302 course records (0 rejected, 0 duplicates) |
| people (national IDs) | 31 502 | 31 502 employees, 31 502 users, 31 502 Employee-role grants |
| employee numbers | 32 984 | 32 984 (`employee_numbers`) |
| hours | 718 198 | 718 198 |
| schools | 283 real school names | 283 created (3 non-school organisations skipped) |
| job titles | 165 names | 160 created, 5 matched to existing |

Checked independently of the importer (from the Excel data with a separate script): for **every one of the 32 984 employee numbers** the number of records and their hours equal the source exactly; 400 random course rows were found field for field (title, dates, hours, track, job reference); no orphan record; no duplicate national ID, e-mail or employee number; `PRAGMA integrity_check` ok and no foreign-key violation; a second run of the same files created nothing.

**Two employee numbers are filed under two different national IDs in the source (4212 and 14949).** Both people are kept with all their courses; one holds the number, the other has it as an old number or a suffixed one. They are listed in the report (`empno_filed_under_two_ids`) for the Ministry to confirm.

## 6. Tests

`TraineeHistoryImportTest` (7 tests, synthetic workbook generated in the test; no real data in the repository): the streaming reader; people, schools, job titles and every course imported with all the rules above (a re-hired person with two numbers, a resigned principal, a unit prepared for deletion, missing and duplicate e-mails, tidy names, mapped nationalities); a second run that changes nothing; a dry run that writes nothing; bad rows reported (reversed dates, zero hours, no course name, no national ID) and the exact-duplicate row recognised; an employee number under two IDs; a file with missing columns refused with a clear message; the command end to end; search by national ID or old number and the profile payload (the ID never appears in a response).

## 7. Not done / to decide

- **No automatic rollback.** Back the database up before loading; everything the command writes carries `source_system = moe-training-records` (employees, training records, employee numbers) or `source = moe_training_extract` (schools).
- Production load: run the command on the server against the same files (the dry run first); the schools it creates have no region, type detail or map position until the Ministry's school register is imported.
- Linking the schools to the official school list (`moe_no`) when that list is available.

---
Full suite after this change (`php artisan test --compact`, Windows, 2026-10-19): 1202 tests, 1131 passed, 17224 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors) and the environment-bound social-files test noted earlier.
