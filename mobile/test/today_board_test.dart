import 'package:material_ui/material_ui.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/features/home/role_home.dart';
import 'package:tedc_mobile/features/home/today_board.dart';

Widget _wrap(Widget child, {String lang = 'en'}) => MaterialApp(
      locale: Locale(lang),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Scaffold(body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: child)),
    );

Map<String, dynamic> _row(String program, String when, {List<String> trainers = const ['Khalid'], List<String> supervisors = const ['Sara'], String room = 'Room 7', int present = 0, int expected = 0, bool attention = false}) => {
      'id': 's-$program', 'title': 'Day 2', 'program': program, 'program_id': 'p-$program', 'program_code': 'P-1', 'has_course': false,
      'starts_at': '2030-01-10T05:00:00Z', 'ends_at': '2030-01-10T08:00:00Z', 'location': room, 'room': room, 'room_id': '', 'map_place': '', 'mode': 'in_person',
      'trainers': trainers, 'supervisors': supervisors, 'when': when, 'present': present, 'expected': expected, 'attention': attention,
    };

void main() {
  test('the strings of «برامج اليوم» exist in both languages', () {
    const keys = [
      'today.title', 'today.open', 'today.mine', 'today.room', 'today.trainer', 'today.supervisor', 'today.attendance', 'today.noTrainer', 'today.noRoom', 'today.inRooms',
      'today.state.now', 'today.state.later', 'today.state.past', 'today.filter.all', 'today.filter.now', 'today.filter.later', 'today.filter.past', 'today.filter.attention',
      'today.sum.sessions', 'today.sum.now', 'today.sum.rooms', 'today.sum.trainers', 'today.sum.supervisors', 'today.sum.attention',
    ];
    for (final lang in ['ar', 'en']) {
      for (final key in keys) {
        expect(S(lang).t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });

  group('what the board decides from a session', () {
    final rows = [_row('A', 'past'), _row('B', 'now'), _row('C', 'later', trainers: const [], attention: true)];

    test('the filters offered are the states that have a session, and what needs attention', () {
      expect(Today.filters(rows), ['all', 'now', 'later', 'past', 'attention']);
      expect(Today.filters([_row('B', 'now')]), ['all', 'now']);
      expect(Today.filter(rows, 'all').length, 3);
      expect(Today.filter(rows, 'now').map((r) => r['program']), ['B']);
      expect(Today.filter(rows, 'attention').map((r) => r['program']), ['C']);
    });

    test('a session from an older server is placed by its times', () {
      final at = DateTime.utc(2030, 1, 10, 6);
      Map<String, dynamic> timed(String start, String end) => {'starts_at': start, 'ends_at': end};
      expect(Today.when(timed('2030-01-10T05:00:00Z', '2030-01-10T08:00:00Z'), at), 'now');
      expect(Today.when(timed('2030-01-10T07:00:00Z', '2030-01-10T08:00:00Z'), at), 'later');
      expect(Today.when(timed('2030-01-10T03:00:00Z', '2030-01-10T05:00:00Z'), at), 'past');
    });

    test('the room falls back to the place an older answer carries, and the bar never divides by nothing', () {
      expect(Today.room({'room': '', 'location': 'Hall 2'}), 'Hall 2');
      expect(Today.room({'room': 'Room 7', 'location': 'Hall 2'}), 'Room 7');
      expect(Today.share({'present': 9, 'expected': 18}), .5);
      expect(Today.share({'present': 3, 'expected': 0}), 0);
      expect(Today.names({'trainers': ['Khalid', '', 'Noor']}, 'trainers'), ['Khalid', 'Noor']);
    });
  });

  testWidgets('a session card names the programme, the room, the trainer and the supervisor, and shows who came', (tester) async {
    await tester.pumpWidget(_wrap(TodaySessionCard(row: _row('Leadership', 'now', present: 12, expected: 18), onAttend: () {})));
    await tester.pumpAndSettle();
    expect(find.text('Leadership'), findsOneWidget);
    expect(find.text('Room 7'), findsOneWidget);
    expect(find.text('Khalid'), findsOneWidget);
    expect(find.text('Sara'), findsOneWidget);
    expect(find.text('On now'), findsOneWidget);
    expect(find.text('12 / 18'), findsOneWidget);
  });

  testWidgets('a session still to be held with no trainer is flagged, and one that has not started shows no attendance', (tester) async {
    await tester.pumpWidget(_wrap(TodaySessionCard(row: _row('Planning', 'later', trainers: const [], attention: true, expected: 18))));
    await tester.pumpAndSettle();
    expect(find.text('No trainer assigned'), findsOneWidget);
    expect(find.text('Later today'), findsOneWidget);
    expect(find.text('0 / 18'), findsNothing);
  });

  testWidgets('the summary shows the day in numbers and who is in the rooms now', (tester) async {
    await tester.pumpWidget(_wrap(const TodaySummary(summary: {'sessions': 6, 'now': 2, 'later': 3, 'past': 1, 'attention': 1, 'programs': 5, 'rooms': 4, 'trainers': 5, 'supervisors': 3, 'present': 31, 'expected': 40})));
    await tester.pumpAndSettle();
    expect(find.text('Sessions today'), findsOneWidget);
    expect(find.text('6'), findsOneWidget);
    expect(find.text('Rooms'), findsOneWidget);
    expect(find.text('Supervisors'), findsOneWidget);
    expect(find.text('Need attention'), findsOneWidget);
    expect(find.text('31 / 40'), findsOneWidget);
  });

  testWidgets('the home card offers the whole day to whoever runs the centre', (tester) async {
    await tester.pumpWidget(_wrap(TodaySessions(sessions: [_row('Leadership', 'now')], trainer: false, canAttend: true, board: true)));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('today-board')), findsOneWidget);
    expect(find.text('Khalid'), findsOneWidget);
    // An administrator reads the day; the trainer's own attendance button is not theirs.
    expect(find.text('Take trainee attendance'), findsNothing);
  });

  testWidgets('the card reads in Arabic', (tester) async {
    await tester.pumpWidget(_wrap(TodaySessionCard(row: _row('القيادة', 'now', trainers: const ['خالد'], supervisors: const ['سارة'], room: 'قاعة 7', present: 5, expected: 10)), lang: 'ar'));
    await tester.pumpAndSettle();
    expect(find.text('القيادة'), findsOneWidget);
    expect(find.text('المدرب'), findsOneWidget);
    expect(find.text('المشرف'), findsOneWidget);
    expect(find.text('تُعقد الآن'), findsOneWidget);
    expect(find.text('قاعة 7'), findsOneWidget);
  });
}
