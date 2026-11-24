import 'package:material_ui/material_ui.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/features/duties/assigned_programmes.dart';
import 'package:tedc_mobile/features/duties/duties_model.dart';
import 'package:tedc_mobile/features/duties/my_programmes_screen.dart';

Widget _wrap(Widget child, {String lang = 'en'}) => MaterialApp(
      locale: Locale(lang),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: Scaffold(body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: child)),
    );

Map<String, dynamic> _session(String id, String when, {bool focus = false, int present = 0, int expected = 0}) => {
      'id': id, 'sequence': 1, 'title_ar': 'اليوم $id', 'title_en': 'Day $id', 'starts_at': '2030-01-10T05:00:00Z', 'ends_at': '2030-01-10T08:00:00Z',
      'status': 'scheduled', 'mode': 'in_person', 'when': when, 'focus': focus, 'place': {'ar': 'قاعة 3', 'en': 'Room 3'}, 'present': present, 'expected': expected,
    };

Map<String, dynamic> _group(String title, List<String> capacities, String phase, {List<Map<String, dynamic>> sessions = const [], bool only = true}) => {
      'id': 'g-$title', 'capacities': capacities, 'phase': phase,
      'program': {'id': 'p-$title', 'code': 'P-$title', 'title_ar': 'برنامج $title', 'title_en': title, 'status': 'in_progress', 'delivery_mode': 'in_person'},
      'group': {'id': 'g-$title', 'title_ar': 'المجموعة ب', 'title_en': 'Group B', 'status': 'running', 'only': only},
      'from': '2030-01-08T05:00:00Z', 'to': '2030-01-12T08:00:00Z',
      'supervisor': {'ar': 'سارة', 'en': 'Sara', 'me': capacities.contains('supervisor')},
      'trainers': [
        {'ar': 'خالد', 'en': 'Khalid', 'me': false},
        {'ar': 'نور', 'en': 'Noor', 'me': capacities.contains('trainer')},
      ],
      'rooms': [{'ar': 'قاعة 3', 'en': 'Room 3'}],
      'trainees': 18, 'sessions': sessions,
      'forms': {'trainee': {'url': 'https://example.test/f/t/P-$title', 'status': 'open', 'answered': 4, 'expected': 18}, 'reflection': {'url': 'https://example.test/f/r/P-$title', 'status': 'waiting', 'answered': 0, 'assigned': 0}},
    };

void main() {
  test('the strings of «برامجي» exist in both languages', () {
    const keys = [
      'duties.title', 'duties.details', 'duties.allEnded', 'duties.none', 'duties.noneHint', 'duties.capacity.supervisor', 'duties.capacity.trainer',
      'duties.phase.all', 'duties.phase.running', 'duties.phase.upcoming', 'duties.phase.ended', 'duties.session.now', 'duties.session.today', 'duties.session.next', 'duties.session.last',
      'duties.when', 'duties.when.past', 'duties.when.now', 'duties.when.today', 'duties.when.later', 'duties.place', 'duties.placeTbd', 'duties.trainees', 'duties.trainers', 'duties.supervisor',
      'duties.noTrainers', 'duties.noSupervisor', 'duties.you', 'duties.attendance', 'duties.sessions', 'duties.survey', 'duties.survey.open', 'duties.survey.scheduled', 'duties.survey.closed',
      'duties.survey.off', 'duties.survey.show', 'duties.survey.hint', 'duties.survey.copy', 'duties.survey.copied',
    ];
    for (final lang in ['ar', 'en']) {
      for (final key in keys) {
        expect(S(lang).t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });

  group('what the screens decide from a group', () {
    final running = _group('A', ['supervisor'], 'running', sessions: [_session('1', 'past'), _session('2', 'now', focus: true)]);
    final upcoming = _group('B', ['trainer'], 'upcoming', sessions: [_session('3', 'later', focus: true)]);
    final ended = _group('C', ['supervisor', 'trainer'], 'ended', sessions: [_session('4', 'past', focus: true)]);

    test('the home screen keeps what is running or still to start, in the order given', () {
      expect(Duties.ahead([running, ended, upcoming]).map((g) => g['id']), ['g-A', 'g-B']);
      expect(Duties.ahead([ended]), isEmpty);
    });

    test('the groups are read from the answer, and anything else is nothing', () {
      expect(Duties.groups({'data': {'groups': [running, upcoming]}}).length, 2);
      expect(Duties.groups({'data': {}}), isEmpty);
      expect(Duties.groups(null), isEmpty);
      expect(Duties.groups('not a map'), isEmpty);
    });

    test('the session to act on is the one the server marked', () {
      expect(Duties.focus(running)?['id'], '2');
      expect(Duties.focus(_group('D', ['trainer'], 'upcoming')), isNull);
    });

    test('a person who is both is named the supervisor of the group', () {
      expect(Duties.capacity(ended), 'supervisor');
      expect(Duties.capacity(upcoming), 'trainer');
      expect(Duties.supervises(ended) && Duties.teaches(ended), isTrue);
    });

    test('names follow the language of the app, the reader first', () {
      expect(Duties.name({'ar': 'قاعة 3', 'en': 'Room 3'}, 'ar'), 'قاعة 3');
      expect(Duties.name({'ar': 'قاعة 3', 'en': ''}, 'en'), 'قاعة 3');
      expect(Duties.name(null, 'en'), '');
      expect(Duties.people((upcoming['trainers'] as List).cast<Map<String, dynamic>>(), 'en'), [('Noor', true), ('Khalid', false)]);
    });

    test('the group is named only when the programme has more than one', () {
      expect(Duties.groupTitle(running, 'en'), isNull);
      expect(Duties.groupTitle(_group('E', ['trainer'], 'running', only: false), 'en'), 'Group B');
    });

    test('attendance is a whole percentage and is taken for a session of today', () {
      expect(Duties.percent(2, 3), 67);
      expect(Duties.percent(0, 0), 0);
      expect(Duties.percent(30, 25), 100);
      expect([for (final w in ['past', 'now', 'today', 'later']) Duties.takesAttendance({'when': w})], [false, true, true, false]);
      expect([for (final w in ['past', 'now', 'today', 'later']) Duties.sessionLabel({'when': w})], ['last', 'now', 'today', 'next']);
    });

    test('the phase filter offers only the phases that have a group', () {
      expect(Duties.phases([running, ended]), ['running', 'ended']);
      expect(Duties.inPhase([running, upcoming, ended], 'all').length, 3);
      expect(Duties.inPhase([running, upcoming, ended], 'upcoming').map((g) => g['id']), ['g-B']);
    });
  });

  testWidgets('the home deck shows what is ahead, in which capacity, and leaves out what has ended', (tester) async {
    await tester.pumpWidget(_wrap(AssignedDeck(groups: [
      _group('Assessment', ['supervisor'], 'running', sessions: [_session('2', 'now', focus: true)]),
      _group('Leadership', ['trainer'], 'upcoming', sessions: [_session('3', 'later', focus: true)]),
      _group('History', ['trainer'], 'ended'),
    ])));
    await tester.pumpAndSettle();
    expect(find.text('My programmes'), findsOneWidget);
    expect(find.text('View all'), findsOneWidget);
    expect(find.text('Assessment'), findsOneWidget);
    expect(find.text('Leadership'), findsOneWidget);
    expect(find.text('History'), findsNothing);
    expect(find.text('Group supervisor'), findsOneWidget);
    expect(find.textContaining('A session is on now'), findsOneWidget);
    // Two cards: the arrows of the deck are offered.
    expect(find.byTooltip('Next program'), findsOneWidget);
  });

  testWidgets('when everything given has ended the home screen says so in one line', (tester) async {
    await tester.pumpWidget(_wrap(AssignedDeck(groups: [_group('History', ['trainer'], 'ended')])));
    await tester.pumpAndSettle();
    expect(find.text('Every programme assigned to you has ended.'), findsOneWidget);
    expect(find.text('History'), findsNothing);
  });

  testWidgets('a group card shows its facts, the reader marked, the attendance of its session, and the way to take it', (tester) async {
    final group = _group('Assessment', ['supervisor'], 'running', sessions: [_session('1', 'past', present: 16, expected: 18), _session('2', 'now', focus: true, present: 12, expected: 18)]);
    await tester.pumpWidget(_wrap(DutyCard(group: group, mayAttend: true)));
    await tester.pumpAndSettle();
    expect(find.text('Assessment'), findsOneWidget);
    expect(find.text('P-Assessment'), findsOneWidget);
    expect(find.text('Group supervisor'), findsOneWidget);
    expect(find.text('Room 3'), findsOneWidget);
    expect(find.text('Sara'), findsOneWidget);
    expect(find.text('you'), findsOneWidget);
    expect(find.text('A session is on now'), findsOneWidget);
    expect(find.text('12 / 18'), findsOneWidget);
    expect(find.text('Take trainee attendance'), findsOneWidget);
    expect(find.text('Trainee survey'), findsOneWidget);
    expect(find.text('Open · 4 / 18'), findsOneWidget);

    // Somebody whose role does not work with attendance sees the count without the button.
    await tester.pumpWidget(_wrap(DutyCard(group: group, mayAttend: false)));
    await tester.pumpAndSettle();
    expect(find.text('12 / 18'), findsOneWidget);
    expect(find.text('Take trainee attendance'), findsNothing);
  });

  testWidgets('a session that is not today offers no attendance, and the survey code opens for the room', (tester) async {
    final group = _group('Leadership', ['trainer'], 'upcoming', sessions: [_session('3', 'later', focus: true)]);
    await tester.pumpWidget(_wrap(DutyCard(group: group, mayAttend: true)));
    await tester.pumpAndSettle();
    expect(find.text('Next session'), findsOneWidget);
    expect(find.text('Take trainee attendance'), findsNothing);
    expect(find.text('Trainer'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('survey-code')));
    await tester.pumpAndSettle();
    expect(find.textContaining('each one scans it with their phone'), findsOneWidget);
    expect(find.text('Copy the link'), findsOneWidget);
  });

  testWidgets('the card reads right to left in Arabic', (tester) async {
    await tester.pumpWidget(_wrap(DutyCard(group: _group('Assessment', ['trainer'], 'running', sessions: [_session('2', 'today', focus: true, expected: 18)]), mayAttend: true), lang: 'ar'));
    await tester.pumpAndSettle();
    expect(find.text('برنامج Assessment'), findsOneWidget);
    expect(find.text('مدرّب'), findsOneWidget);
    expect(find.text('جلسة اليوم'), findsOneWidget);
    expect(find.text('أنت'), findsOneWidget);
    expect(find.text('تسجيل حضور المتدربين'), findsOneWidget);
  });
}
