# Phase 14 — Communities, forums, collaboration and gamification

Closes: TYP-10, PLC-01, PLC-02, PLC-04, PLC-05, COL-01, COL-05, COL-06, GAM-01…GAM-05 (available); PLC-03, COL-02, COL-03, COL-04 (partly — see limits).

Everything is behind feature flags that are **off by default**: `plc` (communities), `forums` (programme, group and lesson forums, trainers' channel, ask-the-trainer) and `gamification`. Switch them on in Settings → Features. Notes and ratings need no flag.

## Spaces

One model, five kinds (`spaces.type`): **community** (made by a person), **program_forum**, **group_forum**, **trainers_channel**, **lesson_thread** (made by the platform).

- Membership of platform spaces *follows registrations*: trainees of the programme/group are members; approved trainers, the coordinator and the group supervisor are moderators; the trainers' channel holds active trainers. `SpaceService::syncMembers` adds and removes people, so nobody is added by hand and a withdrawn trainee leaves.
- Communities: visibility `public_in_scope | members | private`; join policy `open | request | invite`; the creator is the owner; roles `owner | manager | moderator | member`; ban and approve; the last owner cannot leave.
- Staff with `communities.moderate` / `forums.moderate` can read and moderate every space of that kind. Ordinary roles (employee) get nothing extra.

## Conversation

- Posts: discussion, question (accepted answer by the asker or a moderator), announcement and meeting (moderators only), resource, poll (2–10 options, single or multiple, closing time, one changeable vote per person).
- Every body is sanitised (`HtmlSanitizer`); edits keep a history; pin, lock, hide; optional pre-moderation (posts wait for a moderator); anonymous questions (identity visible to moderators only).
- Comments with replies, reactions (like / insightful / thanks), `@mentions`, reports (spam, abuse, …) → moderators' queue → hide / warn / ban / dismiss. Rate limits: 12 posts and 30 comments per 10 minutes per person.
- Notifications: new post to members (respecting each person's choice: all / mentions / none), comments to the author and the person replied to, join requests, approvals, reports, events, and a **daily digest** (one message at the configured hour instead of one per post).
- Events with RSVP and one reminder 24 hours before.

## Around a lesson

- **Notes** — private to the learner.
- **Ask the trainer** — routed to the trainers of the person's group (else of the programme, else the coordinator); a reply deadline (default 48 h, Settings); a group-visible question is also posted in the group forum and answered there; overdue questions are chased once a day. Trainer inbox in the admin app.
- **Ratings and reviews** — 1–5 stars and a short review on lessons, materials, kits, library items and programmes; one per person; people must be registered in the programme; moderators can hide a review.
- **Lesson discussion** thread.

## Gamification

- **Rules** (admin-editable): lesson, programme, assessment passed (with a minimum score), post, comment, accepted answer, reaction received, approved PD activity, daily visit, challenge. Each is paid **once per source**, within daily/weekly caps. Reversals are negative ledger lines (a hidden or deleted post takes its points back). The total is always the sum of the ledger (`point_ledger`).
- **Levels** (default six; editable, must start at 0 and rise); level is decided by *earned* points so spending never drops a level.
- **Badges** (rule-based criteria: event, count, optional window; manual awards; SVG icons are checked by `SvgGuard`), **challenges** (goal, audience by school/role, reward points and/or badge, closed automatically), **rewards** (cost, minimum level, stock; redeeming is transactional; cancelling refunds points and stock).
- **Leaderboards** — week / month / term; ministry, school, region, programme; ties share a rank; people can hide themselves (opt-out); snapshots are stored.
- **Controls** — master flag, per-role and per-programme switches, leaderboard names on/off, manual point adjustments with a mandatory reason (audited).

## Permissions

`communities.create` (trainers, academic deputies, admins), `communities.moderate`, `forums.moderate`, `ratings.moderate`, `gamification.manage`, `rewards.manage` (admins, training supervisors, head of training). Employees receive none.

## Scheduler

`tedc:social-tick` every five minutes: event reminders, daily digest, overdue-question chase, closing ended challenges, leaderboard snapshots.

## Web / mobile

Web: Communities hub and space view (feed, composer with polls, events, members, moderation queue), lesson panel (rate, notes, ask, discussion), My achievements (level, badges, leaderboard, challenges, rewards), My questions, Trainer inbox (+ reviews + settings), Gamification studio (rules, levels, badges, challenges, rewards, redemptions, settings, adjust). Mobile: communities, space feed, post with comments, polls vote, ask the trainer, achievements. All screens are Arabic/English with RTL and respect the feature flags.

## Known limits

- File sharing in posts accepts attachment references (name + link); there is no upload widget in the composer yet (PLC-03, COL-04 partial).
- Ratings have an interface on lessons only; the API already serves materials, kits, library items and programmes (COL-03 partial). Notes and discussion exist for lessons, not for individual materials (COL-02 partial).
- School-group (as opposed to region) leaderboards and "school vs school" challenge tables are not built; `challenge.type = school` is stored but ranks people.
- Mobile cannot create communities, polls or events, nor moderate; those are web tasks.
- Flutter code is analysed only in GitHub CI (Flutter is not installed on the build machine).
