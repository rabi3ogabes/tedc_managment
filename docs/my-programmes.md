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

## Tests

`MyDutiesTest` (7): a supervisor's groups and only his; a trainer's groups by each kind of assignment, not proposed ones or drafts;
both capacities at once and nothing for somebody with no assignment; the attendance count of a session; the supervisor of a group
opens its attendance and code and is refused another group's; a trainer approved for one group is refused another group of the same
programme; the showcase story. Web: `duties/model.test.ts`.
