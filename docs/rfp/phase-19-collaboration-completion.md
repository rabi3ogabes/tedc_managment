# Phase 19 — Collaboration completion: files, material discussions, ratings everywhere, the TEDC chime

Closes: `PLC-03 ★` · `COL-04` · `COL-02` · `COL-03` · `NTF-09` (mandatory item 25, Professional learning communities).
Prompt: `docs/rfp/closeout/19_COLLABORATION_COMPLETION.md`.

## What was built

### Real files in posts and comments (`PLC-03`, `COL-04`)
* **Storage model.** `social_uploads` (migration `2026_01_01_005900_social_files.php`): uploader, space, private path, thumbnail path, original
  name, served type, size, image size, status `uploading → pending → attached` (or removed) and what it is attached to. Posts and comments keep a
  tagged list: `{type:'file', upload_id, …}` or `{type:'link', url, name}`; old `{url, name}` items still read as links.
* **Checks** (`App\Social\SocialFiles`): allowed types PDF, JPG/PNG/WebP/GIF, Word/Excel/PowerPoint (old and new formats), MP4, MP3/M4A and ZIP.
  The **real content type** must match the extension (a renamed `.php`, an `.exe`, SVG and HTML are refused; Office files must be real OOXML
  packages). Images are **re-encoded** (EXIF and GPS removed, orientation applied, 40-megapixel ceiling) and get a 480 px WebP thumbnail.
* **Limits** (`SocialSettings`, editable in *Trainee questions → Settings*): 10 MB per file, 50 MB per post, 10 files per post, 5 per comment.
  Each space may allow fewer files per post (`settings.max_files`, *Space → Settings*), and `allow_files=false` refuses files and links alike.
* **Upload flow.** Files up to 4 MB: `POST social/uploads` (multipart). Bigger files: `POST social/uploads/sign` → the browser or the app sends the
  bytes straight to storage → `POST social/uploads/{upload}/complete` (the server re-reads and re-checks them). Uploads stay `pending` until the
  uploader's own post or comment uses them; an hourly job removes uploads never attached within 24 hours, with their stored objects.
  Rate limit 30 uploads per 10 minutes per person.
* **Downloads** are always short-lived signed links (15 minutes in a feed, 5 minutes from `GET social/files/{upload}`), issued only to people who
  may read the post or comment: a hidden or removed post hides its files, and a hidden comment no longer lists its files to ordinary members.
  Arabic file names are kept and downloaded with an RFC 5987 `Content-Disposition`. Storage paths never leave the server.
* **Web.** The composer (posts and comments) has *Attach file*, *Add link*, drag and drop, and paste of images; each file shows a chip with a
  progress ring, cancel (the upload is aborted) and retry; type and size are checked before sending. Posts show a gallery (1, 2, 3 or 4+ layouts,
  space reserved from the stored image size) with a viewer (keyboard, swipe, focus kept inside), inline video and audio players, and file
  cards that fetch a fresh link when opened.
* **App.** The new-post sheet and the comment box have *Attach file* (file picker), *Take a photo* (camera) and *Add link*, with a progress
  ring, retry and size and type checks. Posts and comments show an image grid with a full-screen viewer (swipe, pinch to zoom) and file cards
  that download to the app's temporary folder and open the file.

### Discussion and notes on materials (`COL-02`)
* A material's discussion is a `material_thread` space made on first use (`SpaceService::materialThread`), with members synced from the
  programme's registrations, trainers and supervisor. Address: `GET social/materials/{material}/thread` (also `spaces/resolve?type=material`).
* Private notes now attach to lessons **or** materials (`lesson_notes.subject_type` / `subject_id`, back-filled; `GET/POST/DELETE social/notes`);
  `social/lesson-notes` keeps working.
* **Web:** *My training → Materials* opens a two-pane window: the materials list and, for the chosen one, *Discussion*, *My notes* and *Rating*.
  **App:** a material screen with the same three tabs; the lesson screen gains *My notes*, *Rating* and *Lesson discussion* buttons.

### Ratings everywhere (`COL-03`)
* One rating per person per subject (lesson, material, kit, library item, programme). Who may rate: people registered in the programme behind
  the subject or its trainers; the library follows the item's audience; a kit needs kit access or a shared file of that kit. People who may not
  rate a lesson, material, kit or library item cannot read its reviews either (moderators can). The summary tells the client `can_rate`.
* `GET social/ratings/{type}/{id}`: average, count, the 5 → 1 distribution, the newest reviews and the person's own rating (with its status).
* **Moderation:** hiding a review needs a reason and is audited (`rating_moderated`, with the reason and IP). A hidden review **stays hidden when its
  author edits it**; the author is told it was hidden.
* The library's separate reviews table was merged into `content_ratings`; the library endpoints read and write the same ratings.
* **Web:** one `RatingSummary` card on lessons, materials, the kit studio overview, kits shared with a person (from a shared kit file),
  library items and the public programme page. Visitors who are not signed in see only the average and count (`meta.rating` on
  `GET public/programs/{code}`; no review text, no names).
* **App:** a rating card and a rating sheet on lessons, materials, library items and programmes.

### The TEDC chime on the phone (`NTF-09`)
* A short (0.9 s) two-note chime matching the web chime (660 Hz then 880 Hz, soft bell partials, peak −6 dBFS), generated by
  `mobile/tool/make_chime.py`: `android/app/src/main/res/raw/tedc_chime.wav` (kept from resource shrinking by `res/raw/keep.xml`) and
  `ios/Runner/tedc_chime.caf` (in the Runner bundle).
* **Android channels** (a channel's sound cannot change once it exists, so each sound has its own channel):
  `tedc_alerts` — the TEDC chime (also the manifest default); `tedc_quiet` — silent, for people who switched the sound off;
  `tedc_general` — the channel of app versions from before the chime, kept so their notifications still arrive.
* **Server:** `FcmClient` sends each device `tedc_alerts` + `sound: tedc_chime` + APNs `tedc_chime.caf` when the person's *sound* preference is on,
  and `tedc_quiet` with no sound when it is off.
* **Foreground:** the in-app banner slides in and the app plays the chime itself, only when the preference is on; Android stays silent in silent or
  vibrate mode and under Do Not Disturb, iOS plays it as a system sound so the Ring/Silent switch mutes it. iOS no longer adds the push sound
  in the foreground, so it never plays twice. *Notification settings* has a *Hear the chime* preview.

## Tests
* Backend: `SocialFilesTest` (14) and `MaterialSocialTest` (12), plus the updated `CollaborationTest`, `PushNotificationsTest` and
  `FeatureSamplesTest` (sample shared files). Tests were written before or with the code; where a test passed on its first run, the line it
  guards was reverted to confirm the test then fails (hidden comment files, foreground chime, attachment limits, rating payload).
* App: `test/push_sound_test.dart` (the chime only plays when the preference is on; data-only messages are silent) and
  `test/collaboration_test.dart` (an accepted file shows a chip, a too-large or disallowed file is refused, a failed upload can be retried and
  holds the post, the rating sheet sends the stars and the review).
* Exercised in the running web app (local API and web): uploading a photo and a PDF with an Arabic name, a comment with only a file, a refused
  `.exe`, the viewer, the per-space file limit, rating and hiding a review with a reason on the public programme page, and the materials window as
  a trainee (discussion, notes, rating), in Arabic and English, at desktop and phone width, with no console errors.

## Also fixed
* Seven colour shades used across the web app (`navy-50/200/300/400`, `gold-50/200/800/900`, about 180 places) were never defined, so hairlines drew
  near-black and light tints were missing. They are now part of the default palette and of the Brand Studio's generated theme.

## Limits
* The Android code is compiled by the **Mobile APK** workflow; this Mac has neither the Android SDK nor Xcode, so the Kotlin was not compiled
  locally and the Swift (`AppDelegate.swift`) was only syntax-checked. The app screens are covered by the analyser and widget tests but were not
  run on a device or simulator in this phase.
* The chime file is a WAV on Android (no OGG encoder was available); the resource name `tedc_chime` is what the push payload uses, so the format
  does not change the server side.
