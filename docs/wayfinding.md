# دليل المركز — Centre Guide (indoor wayfinding)

2026-11-01. A visitor or trainee — signed in or not, on the website (`/map`) or in the mobile app — chooses where to go (their
training room by name, a lab, the prayer room, a restroom…), says where they are, and is walked there step by step across both
floors. The plan always frames where they stand and where they are going.

## For visitors

| | Website `/map` | Mobile app («دليل المركز» on the sign-in screen, in the profile menu; «الطريق إلى القاعة» on a session) |
| --- | --- | --- |
| Where to | search (Arabic or English, Arabic-Indic digits), filters: training rooms, labs, meetings, services | same |
| Where I am | an entrance (default), a «أنت هنا» sign's QR code, a tap on the plan, "I'm next to…" a place | same (scanner built in) |
| The walk | route drawn on the plan (flowing, glowing), steps as sentences («انعطف يميناً عند المكتبة وامشِ 12 م»), which hand the door is on, minutes and metres, floor change by the stairs or a lift | same, with haptics |
| Step-free | ♿ toggle: lifts only | same |
| Outside the building | directions in the maps app to the centre, or to a room that is not at the centre (its coordinates) | same, with the distance from the phone |

A room is reached by `?to=room:<uuid>` (or a place, `?to=f-room-14`); the start by `?from=<entrance | sign | place | stairs-west@first | ground:x,y>`.
Signs print a QR code of `…/map?from=<id>`: a phone camera opens the guide from that spot, the app's scanner reads the same code.

**Why no "blue dot" indoors:** GPS does not work inside the building and the centre has no indoor positioning beacons. The guide
uses what is exact instead — the entrance, the QR signs, or the visitor's tap — and then walks them turn by turn with landmarks. A
Bluetooth beacon layer can later move the dot automatically without changing the map or the steps.

## The classroom popup (mobile app, trainers and trainees)

A **map icon** sits next to every live session: on the trainee's home (next and upcoming sessions), on each session of a programme and on the
session page, and on the trainer's sessions of the day. It opens a popup that answers "how do I get to my room?" without leaving the screen:

- **When it shows.** Only for a session at the centre: not online, not cancelled, not over, and its room is on the centre's plans (a room
  elsewhere keeps the old outdoor-directions link on the session page, and gets no icon elsewhere).
- **Reading the location.** The popup reads the phone's GPS now and as it changes, against the centre's point: *far* (more than ~800 m: the
  distance and minutes by car, and a button to the maps app), *near* (park and walk to the entrance), *at the centre* (≤ ~150 m: pick the
  entrance you came in by or scan a «أنت هنا» sign). A poor reading widens the circle, so someone at the door is never told they are far
  away; arriving updates the popup by itself. GPS does not work inside the building, so the start inside is the entrance, a scanned sign,
  or a tap (see above), never a guessed position.
- **In time?** With the minutes to the start, the drive (when far or near) and the walk from the entrance, the popup says: *the session has
  begun, go now*, *you have time (N min to spare)*, *set off now (only N min)*, or *you may be N min late*.
- **The walk.** The plan of the floor with the route drawn on it (a switch between the floors the walk crosses), distance, minutes, the stairs
  or lift, the first two steps in words, a step-free option, and **Start step-by-step guidance**, which opens the full guide from the chosen start.
- **Data.** Sessions already carry `training_room_id` and `mode`; the trainer's day list (`/me/app-home` → `today`) now carries `room_id`.
  The thresholds and the verdict are in `mobile/lib/features/wayfinding/centre_fix.dart` (tested in `mobile/test/centre_fix_test.dart`); the
  popup is `classroom_sheet.dart` (tested in `mobile/test/classroom_sheet_test.dart`).

## For administrators — «خريطة المركز» (`/admin/rooms/map`, right `rooms.manage`, button on «القاعات التدريبية»)

- **Rooms on the map:** every room is matched by its name — «قاعة 14», «قاعة التدريب 14», «Training Room 14», «معمل الحاسب 2»,
  «المكتبة» — only when the number is the room's whole name (not «قاعة أ-١») and only when one room claims the place. Confirm a
  match, choose another place by clicking it, move a door, mark a room "not in the building", hide it from visitors, or go back to
  automatic. Printable door sign with QR.
- **Entrances and signs:** rename or move the entrances; add «أنت هنا» signs (in front of the lifts, at junctions) by clicking the plan,
  then print their A4 poster (QR + instructions).
- **Plans for print:** both floors as SVG or PNG (2×) in the new design, for signage.

Stored in site settings (`wayfinding`: rooms, entrances, signs, centre), so it needs no migration and is live as soon as it deploys.

## How it works

- **The map** — `scripts/wayfinding/build_map.py` reads the two plans in `web/public/wayfinding/` (the centre's printed floor plans,
  1290 × 942) and writes `web/src/features/wayfinding/centre-map.json` and `backend/resources/wayfinding/centre-map.json`:
  the corridors (the plans' yellow floor) as a 6-pixel walkable grid and a traced smooth outline; 53 places (rooms 1–20, labs,
  meeting rooms, library, cafeterias, administration, prayer rooms, restrooms) each with a door on the corridor it faces; 5 stairs
  and 3 lifts joining the floors; the two entrances; the building's cores and the atrium void. Rooms printed in one block («قاعات
  التدريب 3 و4») are split in the order the plan prints their numbers. Corridors hidden by the drawing's perspective are bridged. The
  script refuses to write a map where any place cannot be reached.
- **Routing** — on the device (instant, works with a weak signal): Dijkstra over the grid, the cost rising near walls so the walk
  keeps to the middle of the corridor, never cutting a corner; across floors it picks the stairs or lift with the least total walk
  (a lift counts more, for the wait); then Ramer–Douglas–Peucker simplification and turns (bends a few metres apart are one turn,
  gentle curves are no step). Same algorithm in `web/src/features/wayfinding/engine.ts` and `mobile/lib/features/wayfinding/engine.dart`.
- **The drawing** — a vector plan in the platform's colours and typeface (`FloorPlan.tsx`, `floor_plan.dart`): stone corridors, room
  cards by kind, dark cores, stairs and lift tiles, the route with flowing dashes, a pulsing "you" dot and a gold destination. Markers
  keep their size on screen; the camera glides to keep the visitor and the destination in the part of the screen no panel covers.
- **API** — `GET /public/wayfinding` (cached a minute): the map, entrances, signs, rooms (place, door, or coordinates), the centre's
  position. `GET|PUT /admin/rooms/map`, `PUT /admin/rooms/{room}/map`. Sessions in `GET /me` and the session screen carry `room_id`.

## Tests

- `web/src/features/wayfinding/engine.test.ts` (8): the two copies of the map are identical; rooms 1–20 and computer labs 1–3 exist;
  every place is reachable from every entrance by the stairs and, step-free, by a lift (212 routes); routes stay on the corridors;
  turns and door side; down as well as up; under 120 ms.
- `mobile/test/wayfinding_engine_test.dart` (3): the same reachability on the phone's engine, sentences, sign codes and tapped points.
- `backend/tests/Feature/WayfindingTest.php` (4): the public payload, name matching (and what it refuses), two rooms on one place,
  placing / door / hide / reset, entrances, signs and the centre, rights. SQLite and PostgreSQL.

## Changing the plans

Replace `web/public/wayfinding/ground.webp` / `first.webp`, adjust `PLACES`, `CONNECTORS`, `BRIDGES` in the script if rooms moved, run
`python -X utf8 scripts/wayfinding/build_map.py --debug <folder>` and look at the overlays it writes (corridors, doors, lifts,
entrances), then commit both JSON copies.
