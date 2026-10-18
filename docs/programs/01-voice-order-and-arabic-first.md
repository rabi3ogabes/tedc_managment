# Programmes — "برنامج جديد": Arabic first, the domain hidden, and the voice order

Date: 2026-10-18 · Branch: `claude/intelligent-brahmagupta-7pysbz`

Three changes to the new-programme form (`/admin/programs/new`, also the edit form where it applies): the English fields are off by default and appear behind one switch, the المجال (category) dropdown is hidden, and an administrator can speak (or type) the order to create a programme in Arabic or English; the system reads it into a clear, structured request they check, correct and start, which fills the form for them to verify and save. Nothing is created from the voice alone.

## 1. Files changed

New (backend)
- `app/Services/Programs/ProgramRequestParser.php` — the deterministic reading: Arabic normalisation (Arabic-Indic digits, tashkeel, alef / yaa / taa marbuta), number words in both languages including compounds ("اثنتي عشرة", "twenty five"), the topic between the lead ("أنشئ برنامج…", "create a programme on…") and the first real anchor (a bare "for" / "ل" counts only when an audience follows), the audience from the job titles in the database through a spoken-forms vocabulary (plural, feminine, definite, whole categories such as "قادة المدارس" → leadership) plus any title by its own stem, gender, experience ranges ("من 3 إلى 5 سنوات", "more than ten years", "أقل من 8 سنوات", "5+ years"), the trainer by fuzzy match against the active trainers with a score and the "if available" flag (explicit cues before a bare "with"), hours, days, seats, delivery mode, level, relative dates; everything reported with what is missing. The topic and the trainer words are mapped back onto the person's own spelling.
- `app/Services/Programs/ProgramVoiceAssistant.php` — the rules always run; when a model is allowed for the new AI feature `programs` (residency and switches in the AI settings), the model refines the reading (a polished title in both languages, a summary, the audience by code) and the two are merged with the database as the judge: job-title codes must exist, the trainer is matched locally, numbers are kept in range; the active trainers' names go to the redactor so the model never sees a person's name; the result is one clear sentence in Arabic and English, the structured draft, the missing items, notes (a trainer not in the list, the model skipped) and a confidence; and the programme form payload (code, titles, summary, mode, level, hours, capacity, start date, trainer, target groups, audience).
- `app/Http/Controllers/Api/V1/Admin/ProgramAssistantController.php` — `GET admin/programs/assistant` (availability and examples), `POST admin/programs/assistant/parse` (`programs.manage`, throttled, audited without the text).
- `tests/Feature/ProgramVoiceAssistantTest.php`.

Changed (backend)
- `ProgramController` — `title_en` optional (an empty English title falls back to the Arabic one so every screen has a name), `audience` accepted on create and update and turned into generated eligibility rules through `AudienceRules`; `AiPolicy::FEATURES` gains `programs`; routes.

New / changed (web)
- `src/pages/admin/program/VoiceComposer.tsx` — the dialog: the language of the request, the microphone (the browser's speech recognition, Arabic or English, live transcript) or the keyboard when the browser has none or the person prefers it, examples, "understand the request"; then the review: engine and confidence badges, what was heard, the understood sentence in both languages, the missing items and notes, and every field editable (title, audience chips, gender, experience, trainer from the list, hours, capacity, mode, start date); "start" applies it to the form.
- `ProgramEditor.tsx` — "إنشاء بالصوت" on the new form, the applied banner, the English switch (off for new programmes, on when an existing programme carries English), the hidden category field, the audience quick rules (gender, experience) saved with the programme; `i18n/ar.ts`, `en.ts` (`admin.programs.voice.*`, `englishToggle`, `englishHint`), `i18n/ai.ts` (the feature label).

## 2. What the administrator can do now

- Open "برنامج جديد", press "إنشاء بالصوت", say in Arabic or English what they want: the name, the audience, the experience range, the gender, the trainer (from the list, if available), hours, seats, mode, when; or type it.
- Read exactly what the system understood, in one sentence in both languages, with what is missing highlighted; correct any field; press start.
- Verify the filled form (name, summary, audience with its rules, trainer, hours, capacity, mode) and save, as before.
- Keep programmes Arabic only; switch the English version on only when needed.

## 3. Tests executed and actual results

- `php artisan test --filter="ProgramVoiceAssistantTest"` → passed, 3 tests, 56 assertions: an Arabic order with Arabic-Indic digits, a feminine number word, a feminine audience form and an optional trainer reads the topic (the person's own spelling), the teacher title, female, 3–5 years, the trainer with the flag, 12 hours, 25 trainees, in person, and produces the title, the trainer, the audience and the sentence; an English order with "school leaders and vice principals", "more than ten years", "twenty seats", "next month" and a trainer after a bare "with" reads the leadership category, the minimum, the seats, the first of next month and the English title; an unknown trainer and a missing audience are reported, never guessed; an employee is refused and a short text rejected; an external model is never called and the rules answer; a model inside Qatar refines the title and summary while the request leaves with the job-title codes and without the trainer's name; English optional with the Arabic copied, the audience saved as generated rules and replaced on update.
- Combined with `ProgramBuilderTest|ProgramsForMeTest|AuthAndRbacTest|SurveyAiTest|PlcAssistantTest` → 20 tests, 227 assertions, all passing.
- Full suite: see the line appended at the end of this report.
- Web: `tsc --noEmit` clean; `oxlint` reports only the pre-existing effect warnings.
- Playwright sweep in Arabic against the local servers: the new form with the switch off and the domain hidden, the composer, the review with the understood sentence and the editable fields, and the form filled after "start".

## 4. Security-sensitive changes

- The spoken text is parsed on the server; it reaches an AI model only when the `programs` feature is on and the model is allowed to see personal text under the AI policy's residency rule; the trainers' names are redacted before sending and restored after; the audit log records the parse (source, language, engine, length, confidence, missing) without the text.
- No programme is created by the assistant: the administrator saves the form through the existing endpoint and permission.

## 5. Known limitations

- Speech capture uses the browser's recognition (Chrome, Edge, Safari); Firefox users type the request. No audio is sent to the server.
- Dates are read as "next week", "next month", "in N days" or an ISO date; spoken calendar dates ("15 نوفمبر") are left for the person to set.
- The vocabulary knows the seeded job titles' spoken forms; a new job title is still matched by its own name.

## 6. Exact next step

If wanted: the same composer on the PLC workshop wizard, and a per-trainer availability check in the review (the trainer's calendar on the chosen start date).

---
Full suite after this change (`php artisan test --compact`, Windows, 2026-10-18): 1177 tests, 1104 passed, 15964 assertions. Besides the known Windows-only failures (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors), the run caught two date-dependent PLC tests whose day offsets collided after a weekend skip; every PLC test now counts training days, so two offsets never share a date, and the five PLC suites pass with the voice assistant suite (23 tests). `SocialFilesTest::test_dangerous_or_disguised_files_are_refused` fails in this environment today with a Windows temp-file read error; it fails identically on the previous commit, so it is independent of this change.
