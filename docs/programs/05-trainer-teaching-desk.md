# Programmes — the trainer's teaching desk: assignment notice, protected kit reader, presenter

Date: 2026-10-19 · Branch: `claude/intelligent-brahmagupta-7pysbz` · Follows [04-kits-instruments-and-announcements.md](04-kits-instruments-and-announcements.md).

**The training kit is the property of the training centre** (الحقيبة التدريبية ملك المركز التدريبي). The sentence is on the assignment notification, the popup, the desk, the reader, the first-open acknowledgement, the presenter, the watermark, the blacked-out cover, the admin page and the clipboard a copy leaves behind.

## 1. What a trainer gets

**Assignment notice.** When a trainer is attached to a programme — on the programme form, by the smart programme builder, on a session, or by an approved group assignment — they receive one notification (push, e-mail, SMS, as each channel and person allows): *"تم إسنادك مدرباً لبرنامج «…» من {date/time} إلى {date/time}، للمجموعة «…»، في القاعة …، والمشرف … . الحقيبة التدريبية ملك المركز التدريبي."* The dates are the first and last session of the group (the programme's dates when it has no sessions yet), the room is the rooms of those sessions (or "online"), the supervisor is the group's (or the programme coordinator's). One notice per trainer and group (`trainer_notices`, unique), so saving a programme again never repeats it. The event is `trainer.assigned`; its template now reads `{{detail}}`.

**Popup on the home.** On the trainer's landing page (`/admin/teaching`), `/admin`, `/admin/programs` and `/portal` the same notice opens as a popup; with several, a slider (arrows, dots, swipe, keyboard). "لا تعرض هذا مرة أخرى" removes one for good (and "لا تعرض أي منها" all); "لاحقاً" hides them until the next sign-in. Programmes that are cancelled, archived or completed drop out.

**Teaching desk** (`/admin/teaching`, trainer's landing page). The programmes they teach with when / group / room / supervisor, the next session with a countdown and **Start session** (open from an hour before it begins until it ends; sets it live), and the centre's kits attached to each programme.

**Protected reader** (`/admin/teaching/kits/:kit`). Only approved or published kits attached to a programme the person teaches (centre staff can open any attached kit to see what a trainer sees). Decks, PDFs, Word documents, images, audio and video are drawn from bytes streamed through the API — inline, `Cache-Control: no-store`, `nosniff`, ranges for video — with no storage address, no download route, no PDF text layer, and a first-open acknowledgement of ownership. Every page carries the trainer's name, number and time as a tiled watermark.

**Presenter** (`/admin/teaching/present/:kit/:file`) for studio decks and PDFs — a presenter view in the browser: the slide, the next slide, the kit's speaker notes (size adjustable) and the trainer's own private notes per slide (kept in the browser), the session clock (elapsed and remaining, amber then red), live / rehearsal state with *Start the session now*, slide sorter, thumbnail strip, jump by number, laser, spotlight, pen, highlighter, magnifier, clear ink, black and white screens, break timers (5/10/15 min shown to the room), a random trainee from the programme's approved roster, a second **audience window** that follows live (BroadcastChannel; drag it to the room screen), and **present on this screen** full screen with an auto-hiding dock. Shortcuts: arrows / space / PgUp / PgDn, B, W, L, S, P, H, Z, E, G, R, F, digits + wait, Esc. The place reached is remembered per file.

## 2. Protection — what it does and what it cannot

Switches in **حماية الحقائب** (`/admin/kit-protection`, `kits.manage`, `GET|PUT admin/kits/protection`, audited): watermark; black-out on capture shortcuts; hide the reader when the window loses focus; hide the presenter when it loses focus (off by default — the audience screen is another window); block print / save / copy / cut / drag / context menu / developer-tools shortcuts; ownership acknowledgement; logging; notifying the centre (and the alert interval).

- **Black screenshot.** The cover is switched on in the same tick as the key event. Holding Win+Shift or Cmd+Shift (the first two keys of Win+Shift+S and Cmd+Shift+3/4/5) already blacks the content, so the picture the operating system takes is black; a window blur in that moment counts as a capture attempt. PrintScreen is only reported by the browser on key release, so it is detected after the fact: the content goes black, the clipboard is overwritten with the ownership line, and the attempt is logged.
- **Trail.** Every attempt is a row in `kit_access_events` (trainer, kit, file, programme, session, key, IP, browser) and the centre's staff get an in-app notice (`kit.capture_attempt`, e-mail and SMS off by default), once per trainer and kit per window. The admin page shows opened / presented / attempts in the last 30 days and the filtered trail; the kit's own trail is `GET admin/kits/{kit}/access-events`.
- **Print and copy** are refused; `@media print` hides the page; `beforeprint` blacks it and reports it. Tampering with the watermark in the developer tools blacks the content until it is back.
- **Limit (stated plainly).** A web page cannot stop the operating system, a phone camera or a capture card from taking a picture; PrintScreen alone is seen only after it happens. The protection is a deterrent with a trail: the black cover for the shortcuts above, the trainer's name on every page so a leaked picture names its source, and a log. A fully blocking screenshot guard exists only in a native app (the mobile app could use the platform's secure-window flag) — not done here.

## 3. Files

Backend, new — migration `2026_01_01_007600_trainer_teaching.php` (`trainer_notices`, `kit_access_events`); models `TrainerNotice`, `KitAccessEvent`; `Services/Teaching/{TrainerNotices,TeachingAccess,KitProtection}.php`; `Me/TeachingController` (`me/teaching`, `…/notices`, `…/programs/{p}`, `…/kits/{k}`, `…/files/{f}` and `/stream`, `…/assets/{a}`, `…/acknowledge`, `…/events`, `…/sessions/{s}/start`); `Admin/Kits/KitProtectionController`; `tests/Feature/TeachingDeskTest.php`.
Backend, changed — `ProgramController` / `ProgramBuilderController` / `SessionController` / `TrainerAssignmentService` call `TrainerNotices::sync`; notification catalogue (`trainer.assigned` → `{{detail}}`, new `kit.capture_attempt`, catalogue-level channel defaults); the trainer's landing page is `/admin/teaching`; messages.
Web, new — `pages/admin/teaching/*` (`TeachingHub`, `KitReader`, `viewers`, `shield`, `Ownership`, `KitProtectionAdmin`, `present/{PresentationStudio,Audience,Stage,source,state}`), `components/teaching/AssignmentPopup.tsx`, `i18n/teaching.ts`; routes, menu entries (مكتب التدريس, حماية الحقائب) and the popup in the layout.

## 4. Tests executed and actual results

- `TeachingDeskTest` → 3 tests passed: one notice per trainer and group with dates, room, supervisor, the ownership line and e-mail + SMS deliveries; no repeat on saving; a second trainer added later; the popup list, dismissal per person; an approved group assignment; the desk lists the approved kit only; no storage address in any payload; stream headers and range; no download route; other trainers, draft kits and employees refused; capture attempts logged and the centre told once, in-app only; the admin trail and settings; starting a session inside its window only.
- `ProgramKitsAndAnnouncementsTest` adjusted (a draft now rightly tells its trainer about the assignment) and passing; programme, trainer, notification, session, kit and routing suites pass.
- Playwright against the local servers (Arabic, trainer and admin accounts): the popup on the landing page; the hub; the acknowledgement gate; a deck in the reader with the watermark; PrintScreen → black cover and the clipboard replaced; a PDF; the presenter (notes, next slide, ink, picker, timers); the audience window following the presenter; mobile popup; the admin page listing the attempts and the admin's notification. No page errors.
- `tsc --noEmit` clean; `oxlint` reports only effect-style warnings; pint clean.
- Full suite: see the line appended at the end of this report.

## 5. Security notes

- Kit routes are scoped to programmes the person teaches and to approved or published kits; assets are checked against the kit; nothing returns a storage address; responses are uncached.
- Events accept a fixed set of kinds and small metadata only; the log never stores content.
- The watermark and the log hold the trainer's name and number; the log is visible to centre staff with `kits.manage`.

## 6. Known limitations and next steps

- Presenting works for studio decks and PDFs; a PowerPoint file that was uploaded but never opened in the studio says it has not been prepared for presenting.
- Videos inside a deck play through blob URLs from the protected route.
- The audience window needs pop-ups allowed for the site.
- Not included: a native screenshot guard for the mobile app; re-notifying a trainer when their programme's dates or room change after the first notice.

---
Full suite after this change (`php artisan test --compact`, Windows, 2026-10-19): 1187 tests, 1116 passed, 16327 assertions. The only failures are the known Windows-only ones (VercelEnvTest ×2, ContractDriverTest mTLS byte count, IntegrationDocsTest line endings, 54 openssl errors) and the environment-bound social-files test noted earlier; the data-classification register was regenerated for the two new tables and passes.
