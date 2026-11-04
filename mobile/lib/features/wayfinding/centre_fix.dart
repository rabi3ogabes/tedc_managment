import 'dart:math' as math;

/// Reading where the phone is against the centre (docs/wayfinding.md), for the classroom popup. GPS works outdoors and at the doors,
/// not inside the building, so it answers one question well: how far is the person from the centre? The popup turns that into
/// "on your way", "almost there" or "you are at the centre", and says whether they will be in the room before the session starts.

/// Where the person is, relative to the centre's grounds.
enum CentreProximity { far, near, at }

/// Inside this many metres of the centre's point the person is at the centre (the building and its car park).
const atCentreMetres = 150.0;

/// Inside this many metres they are close: park, walk to the entrance.
const nearCentreMetres = 800.0;

/// The proximity for a distance. A poor reading (a phone next to the building often says ±60 m) widens the circle a little, so a
/// person standing at the door is never told they are far away.
CentreProximity proximityOf(double metres, {double accuracy = 0}) {
  final slack = math.min(math.max(accuracy, 0), 120);
  if (metres <= atCentreMetres + slack) return CentreProximity.at;
  if (metres <= nearCentreMetres + slack) return CentreProximity.near;
  return CentreProximity.far;
}

/// Minutes to reach the centre by car from a straight-line distance: roads are about a third longer than the crow flies, and the city
/// average is about 35 km/h. A promise of a few minutes, never of seconds, so it is rounded up.
int driveMinutes(double metres) =>
    math.max(1, (metres * 1.35 / (35000 / 60)).ceil());

/// Minutes for the walk from the entrance to the room, from the route's seconds, with a minute to find the door.
int walkMinutes(int seconds) => (seconds / 60).ceil() + 1;

/// Whether the person will be in their seat when the session starts.
enum ArrivalVerdict {
  /// The session has begun: go now.
  started,

  /// Eight minutes or more to spare.
  comfortable,

  /// Arrives on time, but only just.
  soon,

  /// Will arrive after the start.
  late,
}

/// The verdict for the minutes left before the session and the minutes the journey needs.
ArrivalVerdict verdictFor({
  required int minutesToStart,
  required int minutesNeeded,
}) {
  if (minutesToStart <= 0) return ArrivalVerdict.started;
  final spare = minutesToStart - minutesNeeded;
  if (spare < 0) return ArrivalVerdict.late;
  return spare < 8 ? ArrivalVerdict.soon : ArrivalVerdict.comfortable;
}
