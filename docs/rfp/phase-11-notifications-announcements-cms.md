# Phase 11 — Notifications, announcements, events, Ministry feeds, homepage CMS

Closes: NTF-05, NTF-06, NTF-10, NTF-12, NTF-13, NTF-14, HOM-01, HOM-04, HOM-05 (available); NTF-03, NTF-09, HOM-03 (see "Needs the Ministry" below).

## What was built

**Delivery policy** (`App\Services\Notifications\DeliveryPolicy`) sits between `NotificationService` and the channels. For each person it decides whether the notification exists at all, through which channels, and not before when:

- *Rules* (`notification_rules`): per event (or `*`), program category, program and audience filter. The most specific rule wins (program, then category, then general; then priority). A rule can switch an event off, limit its channels, delay it and keep SMS / e-mail / push inside allowed days and hours (Doha time, overnight windows work).
- *User preferences* (`user_notification_preferences`): channels per event group, plus the chime. Events in `DeliveryPolicy::MANDATORY` ignore opt-out.
- Deferred messages are queued rows in `notification_deliveries` with `not_before`; `tedc:deliver-notifications` (every minute) sends them when due. Push that is deferred is queued the same way.

**Audience** (`AudienceResolver`): roles, job titles, schools, school groups, program participants (by registration status), trainers, supervisors and named people. Different filters narrow each other; values inside one widen it. A sender whose scope is a few schools only reaches those schools. `POST /admin/notifications/audience/preview` returns the count and a sample.

**Scheduling** (`scheduled_notifications`, `NotificationScheduler`): once, daily, weekly or monthly until a date; claimed atomically so overlapping runs never send twice; a late run never fires a backlog.

**Hudhud SMS**: new `hudhud` driver (`SmsGateway`) — configurable base URL, send path, API key or user/password, sender, Arabic as UCS-2 (hex of UTF-16BE, `encoding: UCS2`). The provider message id is stored; `POST /api/v1/integrations/sms/hudhud/receipt` (HMAC-SHA256 of the raw body in `X-Hudhud-Signature`) moves the row to `delivered` or `failed`.

**Delivery tracking** (`DeliveryReport`): one view over in-app, push, e-mail and SMS with status, reason, filters, campaign drill-down and Excel / PDF export. "Read" for a message sent outside the app means the person opened the same notification in the app.

**Announcements** (`AnnouncementLifecycle`): `draft → scheduled → published (inside starts_at..ends_at) → expired → archived`. Several live at once; pinned ones first in a chosen order; archive search (title/body, type, dates, case-insensitive, also on PostgreSQL); republish copies wording and media and starts a new window. Media: images, video, audio, files, links. Types: news, announcement, circular, activity, event. Events carry date, venue, online and registration links, capacity and in-platform RSVP with a waiting list that promotes when a seat frees, an "X hours before" reminder (default 24) and a public ICS calendar.

**Ministry website**: public feeds `GET /api/v1/public/feeds/{news.json|events.json|rss.xml|atom.xml|news.csv|…}` (only live, public items flagged `export_to_ministry`, both languages, absolute media addresses); manual export files; `MinistryExporter` pushes flagged items to a configured HTTPS endpoint with an API-key header, retries after 15 min, 1 h and 6 h, logs every attempt and alerts administrators once when it gives up. `tedc:ministry-push` runs every 15 minutes.

**Homepage CMS** (`CmsService`): blocks for `home` and `about`; working copy, numbered published versions, restore. Visitors get the last published version filtered by visibility, display window and audience (signed-in blocks only for signed-in visitors); live data (statistics, news, events) travels with the block. Rich text and safe HTML pass through a server-side sanitiser and are sanitised again in the browser. Until the first publish the site keeps the built-in design. Public statistics (`public_stats`) come from built-in sources or values / named indicators the centre defines, cached ten minutes (`tedc:stats-refresh`).

## Permissions

`notifications.rules`, `notifications.schedule`, `notifications.reports`, `announcements.publish`, `cms.manage`, `ministry_feed.manage`. Head of training and the centre admins hold all; the coordinator holds publish, schedule and reports. `announcements.manage` keeps working everywhere it did.

## Web, mobile

Web: Communication centre (announcements, events, send & schedule, scheduled, rules, templates, deliveries, campaigns, upcoming), announcement editor with media, Ministry dialog, homepage editor with live preview and statistics manager (`/admin/appearance/home`), public events and event detail with RSVP, news detail with audio/video/images, notification preferences (user menu), chime and pulsing bell. Mobile: notification preferences, events with RSVP, announcements with pinned first and media links.

## Jobs

`tedc:deliver-notifications` (every minute: scheduled sends + queued e-mail/SMS/push), `tedc:announcements-tick` (5 min: windows, event reminders, daily SMS-failure digest), `tedc:ministry-push` (15 min), `tedc:stats-refresh` (10 min).

## Needs the Ministry (cannot be finished from here)

- **Hudhud**: the real interface document (URL, authentication, request and receipt format, sender id). The driver's request shape is an assumption that is easy to adjust; nothing was tested against the real gateway.
- **Ministry website**: the receiving endpoint and key for the automatic push (feeds and files work without it).
- Real SMS delivery needs a provider contract in production; with the `log` driver nothing leaves.

## Known limits

- "Send now" is picked up by the next minute run, not instantly.
- Quiet hours are rules (not a per-user setting); push deferred by a rule is sent by the minute job.
- Search uses `LIKE` (no full-text index); fine for the archive size expected.
- Mobile: no custom local-notification sound or offline event cache; media opens in the system player/browser.
- Large media uploads are limited by the Vercel request size; use an address for big files.
- The homepage editor edits the structured blocks; fine-grained drag-and-drop between blocks is up/down buttons, and the built-in design is not editable block by block until it is published once.
