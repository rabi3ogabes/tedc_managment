# Data migration strategy (RFP delivery item DLV-08)

**Scope**: employees, trainers, programs, past registrations, attendance summaries, certificates and professional-development hours, brought in from the legacy system as CSV or Excel (a database or API source can be exported to these templates).

1. **Validation** — every row is checked for required fields, formats (e-mail, dates in several spellings and Excel serial numbers, percentages), references (school, job title, program, employee must exist — hence the import order employees → trainers → programs → registrations → attendance → certificates → PD), duplicates inside the file (the first wins) and against existing data (an existing record is updated, not duplicated). Problems are listed per row and per kind and can be downloaded as a CSV to correct and re-upload.
2. **Cleansing** — Arabic text is unified for matching (alef/yeh/teh-marbuta variants, diacritics, tatweel), Arabic-Indic digits become digits, codes are upper-cased, e-mails lower-cased, phone numbers and genders normalised, Windows-1256 files read correctly.
3. **Transformation** — the column mapping (auto-detected from Arabic and English header names, editable), value conversions per field (e.g. "معلمة" → `TEACHER`) and default values for empty cells.
4. **Rehearsal** — a dry run applies the import inside a transaction that is rolled back and reports what would be created, updated or fail.
5. **Import** — in chunks of 200 rows, one transaction per chunk and a savepoint per row, so a bad row is isolated and reported without losing the rest.
6. **Reconciliation** — every source row is accounted for (valid + invalid + duplicate = source), the keys that went in are compared to the keys that were meant to (SHA-256 over the sorted keys), and the target table counts are shown.
7. **Rollback** — a whole batch can be undone: created records are removed, updated ones restored from the stored previous values.
8. **Security** — only `migration.run`; uploaded rows are stored encrypted; each upload records the file's SHA-256; upload, import and rollback are written to the audit log; the data is deleted automatically 14 days after upload (`tedc:migration-purge`); import and rollback ask for a second-factor confirmation for people who use one.
