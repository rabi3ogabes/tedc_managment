# «برامجي» — what a supervisor and a trainer were given

2026-11-24. One page, `/admin/my-programs`, for the two people who run a training group: its **supervisor** and its **trainers**.
It answers "what was I given, when is it, who came, and how do I put the attendance code and the survey on the screen?".

## Whose groups

A group is listed for a person when one of these is true (`App\Services\Duties\MyDuties`):

| Capacity | The record | Written by |
| --- | --- | --- |
| Supervisor | `training_groups.supervisor_id` is the person | «البرامج» (the group's supervisor), «سابر+» (إسناد المشرف, on publishing) |
| Trainer | an approved `group_trainers` row for the group | «سابر+» (إسناد المدربين), trainer nominations |
| Trainer | `program_sessions.trainer_id` is the person's trainer record | «البرامج» → the session's trainer, «سابر+» days |
| Trainer | the person is a trainer of the programme (`program_trainer`) | «البرامج» → trainers; covers every group of the programme |

Both screens write the same records, so nothing has to know where an assignment was made. A programme that is a draft, cancelled or
archived is not announced; a cancelled group is left out. Somebody can hold both capacities for the same group and is shown both.

`GET /me/duties` asks for no right: it only ever returns the signed-in person's own groups. Each group carries its programme, dates,
rooms, trainers and supervisor (with `me`), the trainees, every session with `when` (`past`, `now`, `today`, `later`), `present` and
`expected`, the session to act on (`focus`: running now, else the next, else the last held), and the programme's two forms
(`FormLinks::many`, the same reading as «سابر+ ← استمارات التقييم»).

`GET /me/duties/{group}` lists the trainees of one of the person's own groups: `held` (the group's sessions held so far) and
`trainees`, each with `registration_id`, `name`, `employee_no`, `school` and `attended` (how many of those sessions they were
present or late at), in the order of the names. A trainee placed in no group belongs to the programme's only group, as in the
count of the list. The names are what the attendance sheet of the group's sessions already shows, so it asks for what the sheet
asks for: the group is theirs to run (another group is **404**) and they hold `attendance.manage`, or were given
`attendance.mark` on that programme (**403** otherwise). `MyDuties::trainees`, tested in `MyDutiesTest`.

## What the page offers

- **The session to act on**, with who attended out of how many.
- **«رمز الحضور على الشاشة»** for a session of today held in a room: the existing rotating attendance code (`/admin/sessions/{id}/qr`)
  in its own window, for the projector.
- **«كشف الحضور»**: the existing attendance sheet (marking by hand, scanning, leaves, export).
- **The trainee survey**: status, answered / trainees, and «اعرض الرمز»: a large code of `/f/t/{programme code}` with the link to copy.
  Scanned, it opens each trainee's own survey (docs/saber/07-programme-forms.md).
- **The trainer's self-reflection**: status, answered / assigned, and «افتح استمارتي» for a trainer.
- **Every session** of the group, and the way to the teaching desk (kits and presenting) and to the programme page for whoever may open them.

Where it is found: first under «الإشراف» for a supervisor, with his pages for a trainer (`trainers.respond`), as a card on the home
page of the portal in any role but «موظف», and from a line on the trainer's teaching desk.

## Who may open a session's attendance and code

`SessionController::authorizeTrainer`, for `GET …/sessions/{id}/qr`, `GET` and `POST …/sessions/{id}/attendance`:

1. centre staff;
2. a trainer of the session: it is theirs, or they are approved for its group, or they are a trainer of its programme;
3. **the supervisor of its group** (new on 2026-11-24: before, a supervisor was refused the attendance of the group he was given);
4. somebody the head of training gave the attendance right on this programme (`ProgramGrant`).

The route still asks for `attendance.manage` (or a programme grant) first, so the role decides whether a person works with attendance
at all, and the assignment decides which sessions. Holding `attendance.manage` does not open a session of a group the person was not
given. A session that belongs to no group is a session of every group of its programme.

If an administrator removes `attendance.manage` from a role, «برامجي» still lists the person's groups and the counts, and says that
the sheet and the code are not available in that role.

## A supervisor or a trainer is a trainee too

A person holds several roles and works in one at a time. A supervisor or a trainer who also holds «موظف» switches to it and finds
their own training (hours, programmes, certificates, the survey to answer); «برامجي» and the supervision pages belong to the other
role. The showcase accounts show it: the first supervisor completed a programme he does not supervise, and the second trainer attends
a programme she does not teach.

## Showcase data

`ShowcaseAccounts::assignSupervisors()` gives the showcase supervisors their groups (this week's and next week's programmes to the
first, last month's to the second). It runs when the showcase is built and, for accounts built earlier, once by the migration
`2026_01_01_009500_showcase_supervisors`, which touches only the `TEST-SHOWCASE` programmes. The story's dates move only when the
showcase is rebuilt (Settings → الحسابات التجريبية); today's session of the running programme lasts until the end of the day so
the attendance code can be shown whenever the showcase is.

## In the mobile app

- **Home screen of a supervisor or a trainer** (`RoleHome`): `AssignedProgrammes` draws the groups of `/me/duties` that are running
  or still to start as a `ProgramDeck` of `ProgramBanner`s, the same deck a trainee has for «برامجي القادمة» (`/me/home` →
  `upcoming_programs`, unchanged). It takes no space while loading, for somebody given nothing, and on a server without the route.
- **«برامجي»** (`/my-programs`, `MyProgrammesScreen`): a filter by phase and a `DutyCard` per group: facts, the session to act on
  with attendance, «تسجيل حضور المتدربين» (the existing roll-call screen) for a session of today when the role holds
  `attendance.manage`, the trainee survey with «اعرض الرمز» (a code and the link to copy), and every session.
- The decisions are in `Duties` (`lib/features/duties/duties_model.dart`), tested in `test/duties_test.dart`.

### The supervisor's app (since 2026-11-25)

Somebody **working as «مشرف»** (`Me.worksAsSupervisor`: the active role, not the roles held, so the same person working as a
trainee keeps the trainee's app) gets an app laid out around the programmes given to them. The five tabs keep their addresses
(`/home`, `/programs`, `/training`, `/certificates`, `/profile`), so links still work; `supervisorTab` in `home_shell.dart` puts
the supervisor's own screen at the root of the first four, and `supervisorTabs` names them:

| Tab | Screen | What it holds |
|---|---|---|
| «الرئيسية» | `SupervisorHome` | The session to act on now (`NowCard`: who came, «كشف الحضور», «رمز الحضور», «مسح رمز متدرب»), or a free day with the next session; four numbers (running, upcoming, sessions today, trainees); the rest of today; the running programmes; the upcoming ones; what waits for the person (`/me/app-home` → `actions`). |
| «برامجي» | `SupervisorProgrammes` | Every group by phase (`GroupTile`). A group opens `ProgrammeScreen` (`/my-programs/{group}`): its sessions, its **trainees** with what each attended (`/me/duties/{group}`, searched by name, number or school), its **trainers** and supervisor, and the two surveys. |
| «الحضور» | `SupervisorAttendance` | The sessions by day: today's with the three ways to take attendance, the ones already held (a tap opens the sheet), the days ahead. |
| «الاستبانات» | `SupervisorSurveys` | For every programme, once: the link of the **trainees' survey** (`/f/t/{code}`) and of the **trainer's survey** (`/f/r/{code}`, the self-reflection), each with where it stands and how many answered, the link itself (a press copies it), «اعرض الرمز» and «فتح الرابط». |
| «حسابي» | `ProfileScreen` | Unchanged. |

- **«كشف الحضور»** (`RollCallScreen`, also the trainer's): headed by its session, searched by name, narrowed to one mark, and
  leading to the two codes for a session of today.
- **«رمز الحضور»** (`SessionQrScreen`, `/session-qr/{session}`): the rotating code of the session (`/admin/sessions/{id}/qr`) on
  the supervisor's phone for the trainees to scan; it asks for the next code as each runs out and shows who has come.
- **«مسح رمز متدرب»** (`StaffScanScreen`, `/staff-scan/{session}`): the camera reads the code a trainee shows from «رمز حضوري»
  (`/admin/sessions/{id}/staff-scan`): the first scan records the entry, the next one the leaving; it stays open for the next person.
- Without `attendance.manage` the sessions and their numbers are still shown; nothing to press is offered, and «الحضور» says why.
- A notice that names a tab about the supervisor's own training (a certificate, a registration) opens that page as it is
  (`tabAddress` adds `own=1`).
- The decisions are in `Supervision` (`lib/features/supervisor/supervision.dart`), tested with the screens in
  `test/supervisor_test.dart`.

## «برامج اليوم» — the day, for whoever runs the centre

`App\Services\TodayBoard` reads the sessions of today (not cancelled, of programmes that are not cancelled or archived) in the
order they start. Each row keeps what the app's home card always read and adds `program_code`, `room`, `trainers`, `supervisors`,
`when` (`past`, `now`, `later`), `present`, `expected` and `attention` (a session still to be held with no trainer or no room).

- **Trainers**: the trainer written on the session; else the approved trainers of its group and the trainers of the programme.
- **Supervisors**: the supervisor of the session's group (of every group of the programme when the session belongs to none);
  else the programme's coordinator.
- **Who sees what** (unchanged): a trainer who does not manage programmes sees their own sessions; anybody else needs
  `programs.view`; nobody else gets a row.

`GET /me/app-home` → `today` carries the first eight rows; `GET /me/today-board` carries them all with `summary` (sessions, now,
later, past, attention, programmes, rooms, trainers, supervisors, and present / expected of the sessions on now) and
`whole_centre`. In the app the home card (`TodaySessions`) and the screen «برامج اليوم» (`/today`, `TodayBoardScreen`) draw the
same `TodaySessionCard`; the screen refreshes every minute. A tap opens the roll-call of the session for a trainer or a holder of
`attendance.manage` (`SessionController::authorizeTrainer` still decides). Tests: `TodayBoardTest` (4), `test/today_board_test.dart`.

## Tests

`MyDutiesTest` (7): a supervisor's groups and only his; a trainer's groups by each kind of assignment, not proposed ones or drafts;
both capacities at once and nothing for somebody with no assignment; the attendance count of a session; the supervisor of a group
opens its attendance and code and is refused another group's; a trainer approved for one group is refused another group of the same
programme; the showcase story. Web: `duties/model.test.ts`.
