# Operations handbook

**ملخص:** الأعمال اليومية والأسبوعية والشهرية لفريق التشغيل.

- **Daily:** check the Ops workbook (availability, p95, error rate, queue lag), `/admin/error-log`, SIEM alerts, the `payments` reconciliation result, data-subject requests due.
- **Weekly:** review Dependabot PRs and the vulnerability register, certificate expiry (alert at 30 days), backup job status, capacity trend.
- **Monthly:** automated restore test result, full DAST scan, access review of RBAC and platform roles, cost report.
- **Quarterly:** DR tabletop, capacity model update, third-party register review.
- **Release:** `deploy-azure.yml` (dev → staging with k6 smoke + ZAP → approval → prod with traffic splitting and automatic rollback). Migrations are backward-compatible (expand, then contract in a later release).
- **On call:** rota template — week-long shifts, L2 pager via the action group (e-mail, SMS, Teams); handover note each Sunday.
- **Break-glass:** two named administrators with local accounts (MFA required, audited, alert on use).

## Survey Studio v2: retention and privacy (Gate 7)

- **What runs:** `php artisan tedc:surveys-retention` every day at 03:30 (Laravel scheduler) and inside the shared Vercel cron (`/api/v1/system/cron`, once a day at 05:00). Rehearse with `--dry-run`; the panel الخصوصية والحفظ on the surveys list shows the same numbers and can run it by hand.
- **Order of work:** expired drafts and unbound files (30 days) → invitations 90 days after close → pseudonym keys 30 days after close (drafts under pseudonymous keys go with them) → answers after each survey's retention period (36 months by default): the summary, per-question statistics and needs ranking are frozen on the survey, then submissions, edits, files, drafts, participants and invitations are deleted and public links revoked.
- **Legal hold:** a switch in the survey's settings; held surveys are listed in the panel and never purged until lifted.
- **Periods:** `TEDC_SURVEY_RETENTION_DAYS`, `TEDC_SURVEY_INVITATION_RETENTION_DAYS`, `TEDC_SURVEY_DRAFT_TTL_DAYS`, `TEDC_SURVEY_KEY_GRACE_DAYS` (`config/tedc.php`, `surveys`).
- **Evidence:** every key deletion, purge and erasure writes a survey event (`key_deleted`, `answers_purged`, `subject_erased`), an audit row and a SIEM security event (`survey_key_deleted`, `survey_answers_purged`, `survey_data_erased`).
- **Data-subject requests:** the handlers' queue (/admin/privacy) shows a requester's survey data; erasure detaches the person from identified answers (answers stay anonymous) and deletes their files, drafts and participations. The "my data" download includes survey participations and identified answers.
- **DPIA:** `docs/security/dpia-survey-studio.md`.
