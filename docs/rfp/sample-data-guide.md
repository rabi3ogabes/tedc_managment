# Sample data for every feature — how to try them

Run once on a demonstration database (it needs the demo organisation, and does nothing in production unless the demonstration switch is on):

```bash
php artisan tedc:samples          # safe to run again: a part that already has its sample is left alone
```

It also runs with `php artisan migrate:fresh --seed` when `TEDC_SEED_DEMO=true`, and on each release of a demonstration deployment.
Switch on the optional features first (Settings → Features): payments, gamification, communities (plc) and forums are off until you do.

## Accounts (password for all: `Tedc@2026!`)
| Role | Account | Start here |
|---|---|---|
| System admin | admin@tedc.qa | everything |
| Training centre admin | center@tedc.qa | dashboard |
| Coordinator | coordinator@tedc.qa | programs, groups |
| Head of training | head@tedc.qa | approvals, plans |
| Academic deputy | deputy@tedc.qa | nominations, workshops |
| Centre leadership | leadership@tedc.qa | executive analytics |
| Head of planning | planning@tedc.qa | annual plan, needs |
| Planning specialist | planner@tedc.qa | needs, evaluation |
| Logistics officer | logistics@tedc.qa | rooms, logistics requests |
| Finance officer | finance@tedc.qa | finance |
| Trainer | trainer@tedc.qa | my sessions, inbox |
| School administrator | school@tedc.qa | approvals, staff needs |
| Trainees | trainee1…4@tedc.qa | portal |
| Kit developer / QA | kits@tedc.qa / qa@tedc.qa | Kit Studio |
| Executive | executive@tedc.qa | executive analytics |

## What each feature has
| Feature | Where | Sample |
|---|---|---|
| Communities, forums, trainers' channel | Communities | two communities, a program and a group forum, questions with an accepted answer, a poll, an event with RSVPs, a reported post |
| Achievements | Achievements / Gamification | points history, badges, a running challenge, rewards and a redemption, leaderboard |
| Payments | Finance, My orders | price lists, discount codes WELCOME10 / SPRING50, paid orders with invoices, a pending order, a refund request, an entity account with seat vouchers |
| Smart assistant, forecasts, risks | AI, Forecasts | a conversation with a cited answer, forecasts, risk flags, an adaptive rule |
| Annual plan, needs cycle | Plans, Needs hub | a plan in review with items and a change, a needs cycle with proposals, an institutional request, individual needs |
| Nominations, withdrawals, open forms | Approvals | a nomination, a withdrawal request, an open registration form with a request |
| Rooms and places | Rooms, Room operations | a campus, a building, a hall, bookings, a fingerprint device |
| Attendance | Absence, Attendance | present / late / absent records, leaves, excuses, absence alerts, a rejected attempt, trainer check-in |
| Group trainers, seats | Groups | an approved and a proposed trainer, a seat allocation, a hold, a seating plan |
| Passing rules, exceptions, attempts | Passing policy, Assessments | a weighted policy, an exception, graded attempts, e-kit final assessments |
| Evaluation | Evaluations, Satisfaction | assignments, responses, an interview, a satisfaction alert, a report, classroom observations |
| Career and PD | Career paths, PD centre | a promotion path with levels, progress, licences (one near expiry), PD types, activities, a recognition request, knowledge transfer |
| Library, materials | Library | items, a collection, reviews, a shelf; two program materials |
| Announcements, news, events | Communication | published news, an event with RSVPs, a circular draft, a FAQ block |
| Notifications | Communication → rules, delivery | rules, a scheduled digest, deliveries (one failed), device tokens, preferences |
| Reports and indicators | Reports, KPI | a weekly schedule, a favourite, indicator history, an executive dashboard preset |
| Support, privacy, contact | Support tickets, Data-subject requests | tickets (queued / open / closed), two requests, a contact message, a profile change request |
| Integrations | Integrations | call logs (one failed), a webhook, a migration batch, a linked identity |
| E-kits | Interactive e-kits | both kits published; one trainee finished the first (certificate), one is half way, one just started |
| Help centre | Help | 31 articles for every role, reader feedback |

Not sampled because they need real files or an outside system: SCORM/H5P packages and LTI tools (upload one in Content standards), Teams meetings (connect Microsoft 365), SSO sign-in, push delivery to real devices.
