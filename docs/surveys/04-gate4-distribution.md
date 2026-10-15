# Survey Studio (ستوديو الاستبانات) — Gate 4 report: distribution, QR, public respondents and files

Date: 2026-10-15 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Builds on [Gate 3](03-gate3-studio.md).

Gate 4 takes a survey beyond the signed-in audience: one public link per survey with its QR code and a printable card, a duplicate rule chosen by the author (one answer per device, verified e-mail, or open), a maximum number of answers, personal single-use invitations with reminders, a public respondent page that needs no account, and real file answers stored in the private evidence bucket. Everything stays behind the flag `survey_studio_v2`.

## 1. Files changed

New (backend)
- `database/migrations/2026_01_01_006800_create_survey_distribution_tables.php` — `survey_distributions` (channel, 24-character slug, duplicate rule, maximum, active flag, QR version, submissions count, revocation), `survey_invitations` (e-mail, name, token hash, send status and error, sent / reminded / opened / responded / expires), `survey_files` (respondent key, question, submission, bucket, path, original name, sniffed MIME, size).
- `app/Models/SurveyDistribution.php`, `SurveyInvitation.php`, `SurveyFile.php`.
- `app/Services/Surveys/SurveyDistributor.php` — public link (one per survey), update, regenerate (new slug, QR version + 1, the old link dies), QR as SVG or PNG through `chillerlan/php-qrcode`, resolution of a public slug (`survey_link_closed`, `survey_link_full`) and of an invitation token (hash compared, expiry and single use), the respondent key (HMAC with the survey's own pseudonym key over `device:…`, `email:…` or `invitation:…`), e-mail codes (six digits, 15 minutes, cache only) exchanged for a three-hour respondent token, invitations (48-character single-use tokens shown once when mail is not configured), reminders (a fresh token per reminder).
- `app/Services/Surveys/SurveyFiles.php` — upload with the real MIME sniffed from the bytes against the question's allowed kinds, size against the question's limit, count against the question's maximum, stored under `surveys/{survey}` in the `evidence` bucket; answers are checked against the files the same respondent uploaded; orphans are deleted when the answer is bound; a 300-second temporary URL for people with `surveys.results`.
- `app/Http/Controllers/Api/V1/Public/PublicSurveyController.php` — the public page's API: definition, send code, verify code, resume, draft, upload, submit by slug; the same by invitation token.
- `app/Http/Controllers/Api/V1/Admin/SurveyDistributionController.php` — the studio's distribution tab: overview, create public link, update, regenerate, QR, invite, remind, file link.
- `tests/Feature/SurveyDistributionTest.php`.

Changed (backend)
- `app/Services/Surveys/SurveyCollector.php` — drafts and submissions keyed by a respondent key for public and invitation channels (dedupe when the rule is not open, counter on new answers only, invitation marked answered) next to the existing user-keyed flow; file answers checked and bound inside the same transaction.
- `Me/MySurveyController::upload` and `routes/api.php` (`POST me/studio-surveys/{studioSurvey}/files`; `admin/surveys/{studioSurvey}/distributions*`, `/invitations*`, `/files/{surveyFile}`; `public/surveys/*` behind the feature flag, the `public` throttle, `external-code` on code requests and `external-submit` on writes).
- `AppServiceProvider` (route bindings `distribution`, `surveyFile`), `SurveySubmission::SOURCES` (`public`, `invitation`, `qr`), `lang/{ar,en}/messages.php` (`surveys.*` messages for files, links, invitations, codes).
- `docs/security/data-classification.md` regenerated.

New (web)
- `src/pages/admin/surveys/DistributionTab.tsx` — the studio tab الروابط والدعوات: create the public link, the QR preview, SVG / PNG download, the printable card, copy, active toggle, duplicate rule with an explanation of each, maximum answers, regenerate with a warning; invitations pasted as `name <mail>` or plain addresses, send, manual links shown once when mail is off, remind the unanswered, counts and the list with status / opened / answered.
- `src/pages/public/PublicSurvey.tsx` — `/s/:slug` and `/s/i/:token`: the engine form without an account, the device id kept in the browser, the e-mail code step when the rule asks for it, resume of a draft, the answered state with edit when the policy allows, clear messages for a closed, full, expired or used link.

Changed (web)
- `src/components/surveys/EngineForm.tsx` — a real file control (choose, upload through the page's callback, list, remove, rules, errors) replaces the placeholder; the pending autosave is cancelled when a submission starts so a late save cannot recreate the draft the server just deleted.
- `src/pages/portal/SurveyRespond.tsx` (uploads through `me/studio-surveys/{id}/files`), `src/pages/admin/surveys/Studio.tsx` (the new tab), `src/App.tsx` (the two public routes inside the public layout), `src/i18n/studio2.ts` (`ss.dist.*`, `ss.public.*`, file strings).

## 2. What the person can do now

- An author opens الروابط والدعوات, creates the public link, prints the card or downloads the QR, picks the duplicate rule, caps the answers, pauses or regenerates the link.
- An author pastes e-mail addresses; each gets a private single-use link by mail, or, when mail is not configured on the server, the links are shown once for manual sharing. Reminders go to the unanswered.
- Anyone with the link or the QR answers on a phone without an account, uploads a file where the question allows it, resumes a draft, and sees their answered state on return (with edit until close when the policy allows).
- Under the e-mail rule the person verifies their address with a six-digit code; the address is never stored with the answer.

## 3. Tests executed and actual results

- `php artisan test --filter="SurveyDistributionTest|SurveyEngineTest"` → passed, 10 tests, 265 assertions. The four new tests cover: the device rule with the cap and regeneration and the open rule; the e-mail code rule (code required, wrong code refused, one answer per address, mail-off reported); invitations with manual links, single use, reminders; file answers (wrong kind, too large, too many, foreign file refused; bound on submit; results link needs `surveys.results`).
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only the pre-existing setState-in-effect warning in the studio loader.
- Playwright sweep in Arabic (administrator at 1366×900, respondent at 430×900): the distribution tab before and after answers, the printable card, the public welcome, the form with a selected option and an uploaded file, the thank-you, the return visit showing the answered state, and an invitation page. The first run found two defects that were fixed and re-verified: the survey header sat under the public site's fixed header, and a late autosave recreated the draft after submission.
- Not done: the Flutter form (the mobile app still answers legacy surveys only); automated browser tests.

## 4. Security-sensitive changes

- New unauthenticated endpoints under `public/surveys/*`: behind the feature flag and the shared `public` rate limit (120/min per IP); code requests limited per IP and per address; drafts, uploads and submissions limited per IP. Invitation tokens must match `[A-Za-z0-9]{48}`; device ids must match `[A-Za-z0-9_-]{16,64}`.
- No public identity is stored: respondent keys are HMACs with the survey's encrypted pseudonym key; e-mail codes and respondent tokens live only in the cache; invitation tokens are stored hashed and never reused after a reminder.
- Files: MIME sniffed from bytes, size and count caps from the question, private bucket, no public URL; an answer may only reference files uploaded by the same respondent key for the same question; viewing needs `surveys.results` and yields a 300-second link.
- Regenerating a link is the only way to revoke what was printed; the old slug returns "closed", and the QR version lets printed cards be told apart.

## 5. Known limitations

- Sending mail depends on the Channels e-mail settings; without them, invitations and the e-mail code rule degrade honestly (manual links; `survey_mail_off` to the respondent). No mail is faked.
- PNG QR needs the GD extension; without it the API answers `qr_png_unavailable` and the SVG still works.
- Files are not virus-scanned (`scanned_at` is reserved for a later scanner); kinds are limited to the question's allowed list.
- The printable card is one layout; a Ministry-branded template can replace it later.

## 6. Exact next step

Gate 5 — migrate the training workflows onto the engine (needs surveys, programme evaluation forms, impact surveys) with their triggers, keeping the legacy pages readable. Report in `docs/surveys/05-gate5-workflows.md`.

---
Full suite after Gate 4 (`php artisan test --compact`, Windows): 1161 tests, 1091 passed, 15278 assertions. The 4 failures and 54 errors are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, openssl).
