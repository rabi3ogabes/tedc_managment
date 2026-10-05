# Phase 06 — Assessment engine and interactive learning

**Closes:** TYP-04, TYP-07, TYP-08, TYP-09, TYP-11, TYP-13, PAS-02, PAS-06, EXM-01, EXM-04, EXM-05, EXM-06, EXM-07, EXM-09, EXM-10, EXM-11.

## Question bank
- 14 question types behind one registry (`App\Services\Assessment\QuestionTypes`): single choice, multiple select, true/false, dropdown-in-sentence, matrix, essay, short answer, fill the blanks, matching, ordering, categorization, hotspot, numeric, H5P. Each type validates its payload, hides the answer key from trainees, and grades itself (Arabic text is normalised: diacritics, alef/ya/ta-marbuta forms, Arabic-Indic digits).
- Banks, categories, tags, difficulty, explanations, skill links. Editing a question's material fields creates a new version and retires the old one (answers already given keep pointing at the version that was shown). Bulk actions, CSV/XLSX import with a per-line error report, export, duplicate detection.
- Screen: **Question banks** (`/admin/question-banks`).

## Assessments
- Kinds: final, quiz, diagnostic, pre-test, post-test, comprehensive skills test, practice. Sections draw fixed or random questions with a difficulty mix; the builder checks bank coverage before publishing.
- Attempts freeze the drawn questions; the timer is owned by the server (`expires_at` + extra minutes), answers autosave, a dropped connection resumes the same attempt.
- In-centre delivery needs an access code: static (stored hashed) or rotating every minute (TOTP-style); codes can be limited to a group or room.
- Integrity (only when the assessment turns it on and `proctoring.enabled` is true): tab switch, full-screen exit, paste/copy, multiple tabs are recorded; thresholds flag the attempt or submit it. Optional camera snapshots need the trainee's browser consent.
- Manual grading queue with rubrics; regrade with question replacement (audited); results release; analytics (difficulty index, discrimination, distractors, by group); pre/post **knowledge gain** against the 35 % target.
- Diagnostic results feed `employee_skills`; failing can require re-studying the lessons before a retry; a lesson can gate on an assessment.
- Existing lesson quizzes keep working; `php artisan tedc:migrate-lesson-quizzes` copies them into banks.

## Interactive video
- Questions, reflections, notes and checkpoints at moments of a lesson video. A blocking interaction stops credited progress at its moment until it is answered; the server clamps the heartbeat. The `visible` heartbeat flag and lesson settings (`require_visible`, `require_fullscreen`, `lock_pause`, `min_seconds_per_slide`) are the anti-distraction rules.

## Permissions
`banks.manage`, `assessments.manage`, `assessments.grade`, `assessments.invigilate`, `assessments.analytics`.

## Known limits
- H5P answers are reported by the player (the server cannot verify them).
- Face check and camera snapshots are best-effort and need browser consent.
- Mobile app: list, resume, single/multiple choice, true/false, short answer, numeric, essay, timer, autosave, result. Matching, ordering, categorization, matrix, hotspot, dropdown, fill-the-blanks and H5P are answered on the web (the app says so). Interactive video overlays are web-only for now.
