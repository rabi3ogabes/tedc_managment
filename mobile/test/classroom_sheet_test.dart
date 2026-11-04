import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tedc_mobile/features/wayfinding/classroom_sheet.dart';
import 'package:tedc_mobile/features/wayfinding/wayfinding_data.dart';

/// The classroom popup (docs/wayfinding.md): the map icon appears only for a live session in a room at the centre, and opens the way to it.
WayData _data() {
  final raw = jsonDecode(File('../backend/resources/wayfinding/centre-map.json').readAsStringSync()) as Map<String, dynamic>;
  final bare = WayData({'map': raw, 'entrances': raw['entrances'], 'signs': const [], 'rooms': const [], 'centre': null});
  final place = bare.map.places.firstWhere((p) => p.id == 'f-room-14');
  return WayData({
    'map': raw,
    'entrances': raw['entrances'],
    'signs': const [],
    'centre': {'lat': 25.3, 'lng': 51.5},
    'rooms': [
      {
        'id': 'r14',
        'name_ar': 'قاعة التدريب 14',
        'name_en': 'Training Room 14',
        'place': place.id,
        'floor': place.floor,
        'door': [place.door.x, place.door.y],
      },
      // A room that is not in the building: only its coordinates.
      {'id': 'out', 'name_ar': 'قاعة خارجية', 'name_en': 'Outside room', 'latitude': 25.2, 'longitude': 51.4},
    ],
  });
}

Future<void> _pump(WidgetTester tester, ClassroomMapButton button) async {
  final data = _data();
  await tester.pumpWidget(ProviderScope(
    overrides: [wayDataProvider.overrideWith((ref) async => data)],
    child: MaterialApp(home: Scaffold(body: Center(child: button))),
  ));
  await tester.pump();
  await tester.pump();
}

ClassroomMapButton _button({String room = 'r14', String mode = 'in_person', bool cancelled = false, Duration from = const Duration(minutes: 40), Duration length = const Duration(hours: 3)}) {
  final start = DateTime.now().add(from);
  return ClassroomMapButton(
    mode: mode,
    cancelled: cancelled,
    target: ClassroomTarget(roomId: room, program: 'Assessment for learning', title: 'Day 1', startsAt: start, endsAt: start.add(length)),
  );
}

void main() {
  const icon = ValueKey('classroom-map');

  // The app starts with its date formats ready; the popup writes the session's time with them.
  setUpAll(() => initializeDateFormatting('en'));

  testWidgets('a live session in a room at the centre has the map icon', (tester) async {
    await _pump(tester, _button());
    expect(find.byKey(icon), findsOneWidget);
  });

  testWidgets('and nothing else does: not online, not cancelled, not over, no room, not a room outside the centre', (tester) async {
    for (final b in [
      _button(mode: 'online'),
      _button(cancelled: true),
      _button(from: const Duration(hours: -5), length: const Duration(hours: 2)),
      _button(room: ''),
      _button(room: 'out'),
      _button(room: 'unknown'),
    ]) {
      await _pump(tester, b);
      expect(find.byKey(icon), findsNothing);
    }
  });

  testWidgets('a session that names its room only in words is found through the place the server worked out', (tester) async {
    final start = DateTime.now().add(const Duration(minutes: 40));
    ClassroomMapButton at(String place) => ClassroomMapButton(
          mode: 'in_person',
          target: ClassroomTarget(roomId: '', placeId: place, startsAt: start, endsAt: start.add(const Duration(hours: 2))),
        );
    await _pump(tester, at('g-room-3'));
    expect(find.byKey(icon), findsOneWidget);
    await _pump(tester, at('x-nowhere'));
    expect(find.byKey(icon), findsNothing);
  });

  testWidgets('a room outside the centre keeps the old link when the screen gives one', (tester) async {
    final start = DateTime.now().add(const Duration(minutes: 40));
    await _pump(
      tester,
      ClassroomMapButton(
        mode: 'in_person',
        target: ClassroomTarget(roomId: 'out', startsAt: start, endsAt: start.add(const Duration(hours: 2))),
        fallback: const Text('outdoor directions'),
      ),
    );
    expect(find.text('outdoor directions'), findsOneWidget);
    expect(find.byKey(icon), findsNothing);
  });

  testWidgets('pressing the icon opens the popup with the room, its floor, the walk and the start button', (tester) async {
    await _pump(tester, _button());
    await tester.tap(find.byKey(icon));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(find.text('Training Room 14'), findsOneWidget);
    expect(find.text('The way to your room'), findsOneWidget);
    expect(find.byKey(const ValueKey('classroom-start')), findsOneWidget);
    // Without a location the popup still shows the walk from the entrance; the steps are below the plan, further down than the test screen is tall.
    await tester.scrollUntilVisible(find.text('Your first steps'), 200, scrollable: find.descendant(of: find.byType(ClassroomSheet), matching: find.byType(Scrollable)).first);
    expect(find.text('Your first steps'), findsOneWidget);
  });
}
