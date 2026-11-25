# متابعة التدريب المباشرة — Live training board

2026-11-15. A page of the dashboard (`/admin/live-training`, menu «متابعة التدريب المباشرة», under the dashboard) that shows the
centre's classrooms of one day, as **a board of boxes** (the view it opens with) or **in 3D**. A room with a running session is lit; a room with no training that day has its lights
off. The trainer stands at the board, the supervisor (المشرف) stands by the door, and every trainee who checks in takes a seat
with their name above them. Pressing a room opens its **training card (البطاقة التدريبية)**.

## What the administrator sees

| | |
| --- | --- |
| The day | Today by default. Previous / next day, a seven-day strip (a mark under each day that has sessions), and any day from the calendar. |
| Figures | Rooms with training now, sessions of the day, trainees inside the rooms now, attendance rate. On another day: rooms used and trainees who attended. |
| A room in the drawing | Lit = training now. Dim = training later that day or already ended. Dark = no training. Its tag shows the name and present / expected; under the pointer it also shows the programme and the state. |
| Under the pointer | The small details: room, programme, time, present of expected, the trainer and how many programmes they have delivered at the centre, the supervisor, the training kit. |
| A press on a room | The camera flies to the room, the names of the seated trainees appear, and the training card opens beside the drawing. |
| A new check-in | Within five seconds the trainee drops into their seat with a ring of light and a highlighted name, and appears in «آخر من حضر». A chime can be switched on (off by default). |
| The training card | State and time (with the time left), attendance (expected, attended, in the room, late, left, absent), trainers (photo, source, rating, programmes delivered at the centre, whether they checked in), the supervisor, the training kit and its version, the programme (type, field, level, hours, delivery, group, dates, objectives, units), the room and its equipment, the days of the programme, and the roster with each trainee's state and check-in time. Links to the programme page and the attendance code. |
| A room with several sessions that day | The card lists them by start time; choosing one shows that session in the room. |
| Outside the rooms | Online sessions and sessions without a room are listed beside the drawing with their counts. |

The page works with the keyboard through the room list (every room is a button), respects "reduce motion", and falls back to the
room list alone on a device that cannot draw 3D.

### The rooms board (لوحة القاعات)

The same day as a board of boxes, one box per room, floor by floor. It is the view the page opens with; «المجسّم» beside it switches
to the 3D drawing, and the choice is remembered on that browser.

| | |
| --- | --- |
| A lit box | The brand's maroon across its head. Training in the room now: the programme and its group, the hours with a clock counting down to the end and a bar of the time already behind, attended of expected with the rate, late and left, the trainer (with a green dot once they have checked in) and how many programmes they have delivered at the centre, the supervisor, the training kit. |
| The seats | One small square per expected trainee, filled for each trainee in the room (amber for a late arrival). A trainee who checks in fills a square that pops in with a green ring; the box is ringed for a moment and says «حضر الآن» with the name. |
| A box with its light off | A room whose session comes later (with a clock counting down to the start) or has ended (with who attended), in white with a pale dune head. A room with no training that day is the brand's light dune all over and shows its code and seats. |
| What deserves a look | No trainer for the session; the trainer has not checked in five minutes after the start; nobody in the room ten minutes after the start; fewer than half of the expected trainees fifteen minutes after the start; more expected trainees than seats; a start within fifteen minutes or an end within ten. A room with something wrong carries a red dot, and «تحتاج نظرة» above the board shows only those rooms. |
| The filters | All rooms, lit now, need a look, off now, each with its count. On another day: rooms with training and rooms without. |
| A press on a box | The training card opens beside the board (in place of the board on a narrow screen). |
| Above the board | The last three people who checked in, and how fresh the picture is («مباشر: حُدّث قبل 3 ث»). When the server stops answering it says so in amber and keeps trying. |

«The trainer has not checked in» is raised only on a day on which at least one trainer has checked in somewhere: where trainer
attendance is not recorded it would be raised in every room.

## How it is built

* **API** — `GET /api/v1/admin/live-training?date=YYYY-MM-DD` (the board) and `GET /api/v1/admin/live-training/sessions/{session}`
  (the training card). `App\Services\LiveTrainingService`, `LiveTrainingController`, tests in `tests/Feature/LiveTrainingTest.php`.
  The board is read in a fixed number of queries whatever the number of rooms; it carries only what the drawing needs (the names of
  the trainees who are seated, not the whole roster). The card is read when a room is opened.
* **Permission** — `live_training.view` (new). Granted to the system administrator, the centre administrator, the training
  supervisor (مشرف التدريب), the head of training, the centre leadership and the executive role. The deploy that brings it adds it to existing
  databases, once (`RolePermissionSeeder::additive`). Trainers, school roles and trainees do not see the page.
* **Refresh** — while it shows today the page asks for the board every 5 seconds around a session (from fifteen minutes before a
  start until five minutes after an end), every 15 seconds while sessions are still ahead and every 30 once the day is over
  (`pollEvery`; the host is serverless, so there is no socket). Another day is read once.
* **The clock on the page** — `liveTraining/live.ts` reads the server's own rules with the browser's time, set to the server's
  clock from each answer: a room lights up, counts down and goes dark on the second, the figure «rooms with training now» follows,
  and the moment a start or an end passes the server is asked again at once. A screen left open past midnight moves to the new day.
  Plain arithmetic with unit tests in `live.test.ts`.
* **Seats** — a seating plan made for the session (or for its group) in Room operations is followed: a trainee with a designed seat
  sits in it. Without a plan the room gets one seat per expected trainee and people fill them from the front in the order they
  arrived. A trainee keeps the same seat from one refresh to the next.
* **Who counts as seated** — a trainee with a check-in (present or late) who has not left early. Checking out within five minutes of
  the end is the normal end of the day, so a finished day still shows who attended.
* **Programmes delivered by a trainer** — distinct programmes with a session of that trainer that has already started (including
  co-training a professional-learning workshop).
* **Supervisor** — the supervisor of the session's group, else the programme's coordinator.
* **Dates** — sessions are stored on the centre's own clock (`APP_TIMEZONE`), so a day is read with local bounds.
* **Web** — `web/src/pages/admin/LiveTraining.tsx` (page), `liveTraining/scene.ts` (the 3D scene, three.js, no React inside),
  `liveTraining/layout.ts` (where rooms and seats stand; plain arithmetic with unit tests in `layout.test.ts`),
  `liveTraining/TrainingCard.tsx`, `liveTraining/RoomBoard.tsx` (the rooms board), `liveTraining/live.ts` (the clock, what
  deserves a look, how often to refresh). three.js is loaded only when the 3D drawing is opened (its own chunk, about 150 kB
  compressed); the rooms board does not load it.
  Floors stand side by side on a wide stage and one behind the other on a tall one; in Arabic the first floor and the first room
  of a row stand on the right.

## Limits

* The figures are stylised and identical in shape; they do not represent a person's appearance. Only the name is shown.
* More than 48 trainees in one room are counted but not all drawn.
* The supervisor is shown as assigned; the system has no check-in for supervisors, so the figure does not mean "in the room".
* Rooms are drawn in a tidy grid per floor, not on the real floor plan of the centre (the indoor guide at `/map` has that plan).
