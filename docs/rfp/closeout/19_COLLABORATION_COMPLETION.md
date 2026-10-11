# Phase 19 — Collaboration completion: files, material discussions, ratings everywhere, notification sound

> Run after the Preflight. All rules in `00_MASTER_PROMPT.md` apply. Skills: `test-driven-development`, `frontend-design` + `impeccable` (composer, galleries, rating UI), `mobile-native`, `verification-before-completion`.

## Goal
Finish the knowledge-sharing experience the RFP describes. People share **real files** in communities and discussions, comment on and take notes on **any material** (not only lessons), and **rate** lessons, materials, kits, library items and programmes on web and app. A new notification **sounds** like TEDC on the phone. This closes mandatory item **25 (Professional learning communities)**.

## Closes
`PLC-03 ★` votes, polls, questions, comments, **file sharing** · `COL-04` file sharing inside discussions · `COL-02` comments and notes on lessons **and materials** · `COL-03` ratings and reviews by trainees and trainers · `NTF-09` sound or visual alert on a new notification.

## What exists today (verified 11 Oct 2026)
- **Posts and comments.** Communities, posts and comments live in `backend/app/Social/{PostService,SpaceService}.php` and `app/Http/Controllers/Api/V1/Social/PostController.php`. Posts accept `attachments: [{url, name}]` (max 10) and comments up to 5, honouring the space setting `allow_files` (`SpaceService` line ~49). **Only links**: there is no upload endpoint, and the web composer (`web/src/components/social/Composer.tsx`, 49 lines) has **no attachment UI at all**, not even links. The app's community screens (`mobile/lib/features/community/community_screens.dart`) have none either.
- **Lesson discussion and notes.** A lesson thread is a `Space` of type `lesson_thread` with `subject_type='lesson'` (`SpaceService::lessonThread`). Private notes live in `lesson_notes` (`user_id`, `lesson_id`, `body`), served by `GET/POST/DELETE social/lesson-notes`. Materials (`App\Models\Material`, served by `GET /me/materials/{id}/download`, listed in `web/src/pages/portal/MyTraining.tsx`) have neither.
- **Ratings.** `App\Social\RatingService::SUBJECTS = lesson | material | kit | library | program`, with routes `POST social/ratings`, `GET social/ratings/{type}/{id}` and `POST social/ratings/{rating}/moderate` (permission `ratings.moderate`). The **only** interface is `web/src/components/social/StarRating.tsx` inside `LessonSocial.tsx`. The app has no ratings.
- **Sound.** On the web, a soft chime plays when a pop-up arrives (per-user switch). In the app:
  - Android uses FCM channel `tedc_general` with `sound: default` (`backend/app/Services/Push/FcmClient.php` line ~81) and `AndroidManifest.xml`.
  - In the foreground, `mobile/lib/core/push/push_service.dart::_onForeground` shows an in-app banner **silently**.
  - The per-user preference `sound` exists (`DeliveryPolicy::SOUND`, `notification_prefs_screen.dart`) but nothing plays a TEDC sound.
- **Storage.** `App\Services\FileStorage` offers `upload()`, `signedUpload()` (direct browser upload) and `temporaryUrl()`. `EvaluationService::evidenceItems()` shows the validation pattern (MIME allow-list, 10 MB). `web/src/components/EvidenceInput.tsx` is a file-and-link picker you can learn from (do not copy it blindly).

## 1. Data model
- **`social_uploads`** (migration `2026_01_01_005900_social_files.php`): `id`, `user_id`, `path`, `name` (original, ≤ 200), `mime`, `size` (bytes), `width`/`height` (images, nullable), `status` (`pending|attached|removed`), `attached_type` (`post|comment|material_comment`, nullable), `attached_id` (uuid nullable), timestamps. Index `(user_id, status)` and `(attached_type, attached_id)`.
- **Attachment items** in `posts.attachments` / `post_comments.attachments` become a tagged union. Old items stay valid (backward compatible):
  `{type:'link', url, name}` | `{type:'file', upload_id, name, mime, size, width?, height?}`.
- **`lesson_notes` → any content.** Add `subject_type` (`lesson|material`, default `lesson`) and `subject_id` (uuid). Backfill `subject_id = lesson_id`, make `lesson_id` nullable, and index `(user_id, subject_type, subject_id)`. Keep the old routes working.
- **Material threads** reuse `spaces` (type `material_thread`, `subject_type='material'`), created on first use exactly like `lessonThread`, with members synced from the programme's registrations, trainers and supervisor.
- Classify the new columns (`config/data_classification.php`: file names and paths are Internal; uploaded content is Confidential) and regenerate the register.

## 2. Rules and services
- **`App\Social\SocialFiles`:**
  - Allowed types: PDF, images (jpg/png/webp/gif), Office (doc/docx/xls/xlsx/ppt/pptx), mp4, mp3/m4a and zip; anything else, including executables, scripts, SVG and HTML, is refused.
  - Limits, all in a `SocialSettings` screen: 10 MB per file through the API (stay under Vercel's ≈ 4.5 MB request limit by using `signedUpload()` + `complete` for files over 4 MB), 50 MB per post in total, 5 files per comment and 10 per post.
  - Check the **real** MIME from the file content as well as the extension; strip image EXIF (GPS); generate a 480 px WebP thumbnail for images.
- **Upload flow:**
  1. `POST social/uploads` (small files, multipart), or `POST social/uploads/sign` → `PUT` to storage → `POST social/uploads/{upload}/complete` (the server re-checks size and type).
  2. The upload is `pending` until a post or comment references it, then `attached`. Only the uploader can attach it.
  3. A daily job removes `pending` uploads older than 24 h and their stored objects.
- **Downloads** are always signed and short-lived (`GET social/files/{upload}`, 5 min) and are allowed **only** to people who may read the post or comment (space membership or staff override). A hidden or removed post hides its files; a ban removes nothing silently (everything is audited).
- **Space setting `allow_files`** is enforced for files and links alike. The space settings screen gets per-space limits (on/off, max per post).
- **Material discussion and notes:** `CourseSocial::materialThread(Material)`, plus notes on `subject_type=material`. Only people registered in the material's programme, its trainers, the supervisor and staff with `forums.moderate` may read or post.
- **Ratings everywhere:** enforce the existing rule (one rating per person per subject; the person must be registered in the programme behind the subject, or be its trainer; the library follows the item's audience). Show the average and count, the distribution (5 → 1 bars) and the newest reviews; moderators can hide a review with a reason.
- **App notification sound:**
  1. Ship a short (≤ 1 s), gentle TEDC chime: `mobile/android/app/src/main/res/raw/tedc_chime.ogg` and an iOS `tedc_chime.caf` in the Runner bundle.
  2. Android 8+ fixes a channel's sound when the channel is created, so create a **new** channel `tedc_alerts` with that sound and keep `tedc_general` for people who turned the sound off.
  3. `FcmClient` sends `channel_id` per recipient (`tedc_alerts` when their `sound` preference is on, else `tedc_general`) and `apns.aps.sound = tedc_chime.caf` or none.
  4. In the foreground, `_onForeground` plays the chime (respecting the preference and the phone's silent mode) using a light audio plugin (e.g. `audioplayers`), and the banner animates in.
  5. Update `AndroidManifest.xml`'s default channel and document the channel ids.

## 3. API and permissions

| Method & path | Permission / rule |
|---|---|
| `POST social/uploads`, `POST social/uploads/sign`, `POST social/uploads/{upload}/complete`, `DELETE social/uploads/{upload}` | signed in; the space allows files; rate limit 30 / 10 min per person |
| `GET social/files/{upload}` | may read the parent post or comment |
| `GET/POST social/materials/{material}/thread`, `GET/POST/DELETE social/notes?subject_type=&subject_id=` | registered / trainer / supervisor / `forums.moderate` |
| `GET social/ratings/{type}/{id}?with=distribution,reviews`, `POST social/ratings` (unchanged) | as today |

Add `upload` (and `material` if not present) to the UUID route pattern list. Keep `social/lesson-notes` working as an alias. The new routes follow the **same feature flags** as their neighbours: `plc` for community spaces and `forums` for programme, lesson and material threads, however the existing social controllers check them (look before you add). Uploads to a space are refused when its flag is off.

## 4. Web
- **Composer.** Replace `Composer.tsx` with a polished composer:
  - an attach button plus drag-and-drop and paste-from-clipboard (images);
  - chips with an upload progress ring, cancel and retry, and a link field;
  - client-side type and size checks with clear messages, keyboard support and an accessible file input. The same component serves comments.
- **Post and comment display.** Images show as a responsive gallery (1, 2, 3 or 4+ layouts) with a lightbox (keyboard, swipe, focus trap). Other files show as cards (icon by type, name, size, download). Video and audio get inline players.
- **Materials.** In the portal's materials list, and wherever a material opens, add a side panel with **Discussion**, **My notes** and **Rating**, using the same components as `LessonSocial.tsx`.
- **Ratings.** Add a `RatingSummary` (average, count, 5-bar distribution, newest reviews, "write a review") to the lesson, material, kit (studio and shared view), library item and public programme pages. Let moderators hide a review with a reason.
- **Strings.** All new strings in AR and EN.

## 5. Mobile
- **Communities:** the composer gets "attach" (`file_picker`, already a dependency; camera for images) with progress and size checks; posts show image grids and file cards (open with `open_filex`).
- **Materials:** the material screen gets discussion, notes and rating tabs.
- **Ratings:** a rating sheet (stars and a review) on lesson, material, library and programme screens.
- **Sound:** the chime, the channels and the foreground behaviour described in §2. The notification preferences screen previews the chime.

## 6. Tests (write first; watch them fail)
- **`SocialFilesTest`:**
  - an allowed upload is accepted;
  - an `.exe`, an `.svg`, a renamed `.php` and a file whose content MIME disagrees with its extension are refused;
  - a file over the limit is refused, and so is a post over the total;
  - a pending upload cannot be attached by someone else;
  - an `allow_files=false` space refuses files and links;
  - a non-member gets 403 on `social/files/{id}` and a member gets a signed URL;
  - a hidden post's file is refused;
  - the cleanup job removes 24 h-old pending uploads and keeps attached ones;
  - EXIF GPS is stripped (a fixture JPEG with GPS);
  - the thumbnail is produced;
  - old `{url,name}` link items still render.
- **`MaterialSocialTest`:** registered trainees and the trainer can read and post in the material thread and an outsider cannot; notes are private to their author; notes backfilled from `lesson_notes` still list on the lesson; ratings on a material obey one-per-person and registration.
- **`RatingEverywhereTest`:** the summary has the distribution; a moderator hides a review with a reason (audited); non-registered users cannot rate a programme.
- **`PushSoundTest`:** the FCM payload uses `tedc_alerts` + `tedc_chime.caf` when the person's `sound` is on, and `tedc_general` with no sound when it is off.
- **Mobile widget tests:** the composer shows the upload chip and refuses a too-large file; the rating sheet submits; `_onForeground` does not play the chime when the preference is off (inject a fake player).

## Review focus (most likely to bite)
1. **Large files on Vercel.** A 6 MB PDF must go through the signed direct upload, not the API body. Test the 4 MB boundary.
2. **Leaking files.** Every file URL is signed, short-lived and permission-checked. Never use public buckets for social files.
3. **Mixed old and new attachments** in one post (link + file) render and edit correctly.
4. **Arabic file names** (`تقرير الورشة.pdf`) are kept, displayed RTL, and downloaded with a correct `Content-Disposition` (RFC 5987).
5. **Android channel sound immutability.** Existing installs must get the new channel, and turning the sound off must really silence it.

## Definition of Done
- **PLC-03 / COL-04:** members upload real files (web and app) in posts and comments, files are protected, limits are configurable, and tests pass. → **Available**
- **COL-02:** discussion **and** private notes on lessons **and** materials, web and app. → **Available**
- **COL-03:** a rating interface on lessons, materials, kits, library items and programmes (web) plus the app screens, with moderation. → **Available**
- **NTF-09:** the TEDC chime on web and app (background and foreground), with the per-user switch respected. → **Available**
- The gate is green, `docs/rfp/phase-19-collaboration-completion.md` is written, the register is updated with `rfp_mark` + `rfp_sync` + `tedc:rfp-status`, and the change is pushed with CI, Security and Vercel all green.
