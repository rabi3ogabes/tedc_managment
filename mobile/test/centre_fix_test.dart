import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/features/wayfinding/centre_fix.dart';

/// The classroom popup reads the phone's position against the centre: near, far, at the door; and whether the person arrives in time.
void main() {
  test('the centre is told apart by distance: at the grounds, close by, far away', () {
    expect(proximityOf(40), CentreProximity.at);
    expect(proximityOf(149), CentreProximity.at);
    expect(proximityOf(400), CentreProximity.near);
    expect(proximityOf(799), CentreProximity.near);
    expect(proximityOf(3200), CentreProximity.far);
  });

  test('a poor reading widens the circle, so someone at the door is never "far"', () {
    expect(proximityOf(200), CentreProximity.near);
    expect(proximityOf(200, accuracy: 60), CentreProximity.at);
    expect(proximityOf(900, accuracy: 100), CentreProximity.near);
    // the widening is capped: a reading of ±5 km does not put the whole city at the centre
    expect(proximityOf(5000, accuracy: 5000), CentreProximity.far);
    expect(proximityOf(10, accuracy: -5), CentreProximity.at);
  });

  test('the drive is rounded up and never under a minute', () {
    expect(driveMinutes(50), 1);
    expect(driveMinutes(3000), 7);
    expect(driveMinutes(12000), 28);
  });

  test('the walk from the entrance gets a minute to find the door', () {
    expect(walkMinutes(0), 1);
    expect(walkMinutes(60), 2);
    expect(walkMinutes(61), 3);
  });

  test('the verdict says go now, comfortable, only just, or late', () {
    expect(verdictFor(minutesToStart: -5, minutesNeeded: 4), ArrivalVerdict.started);
    expect(verdictFor(minutesToStart: 0, minutesNeeded: 4), ArrivalVerdict.started);
    expect(verdictFor(minutesToStart: 30, minutesNeeded: 10), ArrivalVerdict.comfortable);
    expect(verdictFor(minutesToStart: 14, minutesNeeded: 10), ArrivalVerdict.soon);
    expect(verdictFor(minutesToStart: 10, minutesNeeded: 10), ArrivalVerdict.soon);
    expect(verdictFor(minutesToStart: 6, minutesNeeded: 10), ArrivalVerdict.late);
  });
}
