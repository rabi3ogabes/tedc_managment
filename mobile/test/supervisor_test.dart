import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/core/models.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/attendance/roll_call_screen.dart';
import 'package:tedc_mobile/features/duties/duties_model.dart';
import 'package:tedc_mobile/features/shell/app_nav_bar.dart';
import 'package:tedc_mobile/features/shell/home_shell.dart';
import 'package:tedc_mobile/features/supervisor/parts.dart';
import 'package:tedc_mobile/features/supervisor/supervision.dart';
import 'package:tedc_mobile/features/supervisor/supervisor_home.dart';
import 'package:tedc_mobile/features/supervisor/supervisor_programmes.dart';
import 'package:tedc_mobile/features/supervisor/supervisor_tabs.dart';

/// Somebody signed in, without the network.
class _As extends AuthController {
  _As(this.me);

  final Me? me;

  @override
  Future<Me?> build() async => me;
}

Me _supervisor({List<String> permissions = const ['attendance.manage']}) => Me({
      'id': 'u1', 'name': 'Sara', 'permissions': permissions,
      'roles': [
        {'slug': 'supervisor', 'name': 'Supervisor'},
        {'slug': 'employee', 'name': 'Trainee'},
      ],
      'active_role': {'slug': 'supervisor', 'name': 'Supervisor'},
      'employee': {'id': 'e1'},
    });

Widget _app(Widget home, {List overrides = const [], String lang = 'en', Me? me}) => ProviderScope(
      overrides: [authProvider.overrideWith(() => _As(me ?? _supervisor())), ...overrides],
      child: MaterialApp(
        locale: Locale(lang),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        home: home,
      ),
    );

String _at(DateTime d) => d.toUtc().toIso8601String();

Map<String, dynamic> _session(String id, String when, DateTime starts, {int present = 0, int expected = 0, bool focus = false}) => {
      'id': id, 'sequence': 1, 'title_ar': 'اليوم $id', 'title_en': 'Day $id', 'starts_at': _at(starts), 'ends_at': _at(starts.add(const Duration(hours: 3))),
      'status': 'scheduled', 'mode': 'in_person', 'when': when, 'focus': focus, 'place': {'ar': 'قاعة 3', 'en': 'Room 3'}, 'present': present, 'expected': expected,
    };

Map<String, dynamic> _group(String title, String phase, {List<Map<String, dynamic>> sessions = const [], String? programme, List<String> capacities = const ['supervisor'], int trainees = 18}) => {
      'id': 'g-$title', 'capacities': capacities, 'phase': phase,
      'program': {'id': 'p-${programme ?? title}', 'code': 'P-${programme ?? title}', 'title_ar': 'برنامج ${programme ?? title}', 'title_en': 'Programme ${programme ?? title}', 'status': 'in_progress', 'delivery_mode': 'in_person'},
      'group': {'id': 'g-$title', 'title_ar': 'المجموعة $title', 'title_en': 'Group $title', 'status': 'running', 'only': programme == null},
      'from': '2030-01-08T05:00:00Z', 'to': '2030-01-12T08:00:00Z',
      'supervisor': {'ar': 'سارة', 'en': 'Sara', 'me': capacities.contains('supervisor')},
      'trainers': [
        {'ar': 'خالد', 'en': 'Khalid', 'me': false},
        {'ar': 'نور', 'en': 'Noor', 'me': capacities.contains('trainer')},
      ],
      'rooms': [{'ar': 'قاعة 3', 'en': 'Room 3'}],
      'trainees': trainees, 'sessions': sessions,
      'forms': {
        'trainee': {'url': 'https://example.test/f/t/P-${programme ?? title}', 'status': 'open', 'answered': 4, 'expected': 18},
        'reflection': {'url': 'https://example.test/f/r/P-${programme ?? title}', 'status': 'waiting', 'answered': 0, 'assigned': 0, 'trainers': 2},
      },
    };

Map<String, dynamic> _duties(List<Map<String, dynamic>> groups) => {'data': {'groups': groups}};

void main() {
  final now = DateTime(2030, 1, 10, 9);

  test('the strings of the supervisor\'s app exist in both languages', () {
    const keys = [
      'sup.nav.programs', 'sup.nav.attendance', 'sup.nav.surveys', 'sup.now', 'sup.next', 'sup.freeDay', 'sup.noNext', 'sup.noUpcoming', 'sup.stat.running', 'sup.stat.upcoming', 'sup.stat.today', 'sup.stat.trainees',
      'sup.today', 'sup.todayRest', 'sup.running', 'sup.upcoming', 'sup.act.sheet', 'sup.act.code', 'sup.act.scan', 'sup.att.today', 'sup.att.held', 'sup.att.ahead', 'sup.att.none.today', 'sup.att.none.held',
      'sup.att.none.ahead', 'sup.att.noRight', 'sup.count.trainees', 'sup.count.trainers', 'sup.count.sessions', 'sup.prog.title', 'sup.prog.sessions', 'sup.prog.trainees', 'sup.prog.trainers',
      'sup.prog.surveys', 'sup.prog.missing', 'sup.prog.noSessions', 'sup.prog.noTrainees', 'sup.prog.noMatch', 'sup.prog.search', 'sup.prog.attended', 'sup.prog.notHeld', 'sup.survey.trainee',
      'sup.survey.reflection', 'sup.survey.traineeHint', 'sup.survey.reflectionHint', 'sup.survey.answered', 'sup.survey.st.open', 'sup.survey.st.scheduled', 'sup.survey.st.closed', 'sup.survey.st.off',
      'sup.survey.st.waiting', 'sup.survey.st.done', 'sup.survey.code', 'sup.survey.copy', 'sup.survey.open', 'sup.survey.copied', 'sup.survey.none', 'sup.survey.intro', 'sup.qr.hint', 'sup.qr.renews',
      'sup.qr.present', 'sup.scan.hint', 'sup.scan.in', 'sup.scan.out', 'sup.scan.notPersonal', 'sup.scan.count',
    ];
    for (final lang in ['ar', 'en']) {
      for (final key in keys) {
        expect(S(lang).t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });

  group('who gets the supervisor\'s app', () {
    test('the role the person works in decides, not the roles they hold', () {
      expect(_supervisor().worksAsSupervisor, isTrue);
      // The same person working as a trainee keeps the trainee's app.
      final asTrainee = Me({'roles': [{'slug': 'supervisor'}, {'slug': 'employee'}], 'active_role': {'slug': 'employee'}, 'employee': {'id': 'e1'}});
      expect(asTrainee.worksAsSupervisor, isFalse);
      // No role chosen yet: an employee profile means a trainee; without one, the first role held.
      expect(Me({'roles': [{'slug': 'supervisor'}], 'employee': {'id': 'e1'}}).workSlug, 'employee');
      expect(Me({'roles': [{'slug': 'supervisor'}]}).worksAsSupervisor, isTrue);
      expect(Me({'roles': <Map<String, dynamic>>[]}).workSlug, 'employee');
    });

    test('the first four tabs are the supervisor\'s own, at their root only', () {
      expect(supervisorTab(true, 0, '/home'), isA<SupervisorHome>());
      expect(supervisorTab(true, 1, '/programs'), isA<SupervisorProgrammes>());
      expect(supervisorTab(true, 2, '/training'), isA<SupervisorAttendance>());
      expect(supervisorTab(true, 3, '/certificates'), isA<SupervisorSurveys>());
      expect(supervisorTab(true, 4, '/profile'), isNull, reason: 'the account is the same for everybody');
      expect(supervisorTab(true, 1, '/programs/P-1'), isNull, reason: 'a page opened inside a tab is not replaced');
      expect(supervisorTab(false, 0, '/home'), isNull);
    });

    test('a notice about the supervisor\'s own training opens the page it names, not the supervisor\'s tab', () {
      expect(tabAddress('/certificates', supervisor: true), '/certificates?own=1');
      expect(supervisorTab(true, 3, tabAddress('/certificates', supervisor: true)), isNull);
      expect(tabAddress('/home', supervisor: true), '/home');
      expect(tabAddress('/profile', supervisor: true), '/profile');
      expect(tabAddress('/certificates', supervisor: false), '/certificates');
    });

    test('a group\'s page sits under «برامجي» and a session\'s attendance under «الحضور»', () {
      expect(tabIndexFor('/my-programs/g1', supervisor: true), 1);
      expect(tabIndexFor('/roll-call/s1', supervisor: true), 2);
      expect(tabIndexFor('/session-qr/s1', supervisor: true), 2);
      expect(tabIndexFor('/staff-scan/s1', supervisor: true), 2);
      // Everybody else keeps the tabs they had.
      expect(tabIndexFor('/roll-call/s1'), 2);
      expect(tabIndexFor('/my-programs'), 0);
      expect(supervisorTabs(S('en')).map((t) => t.label), ['Home', 'Programmes', 'Attendance', 'Surveys', 'Profile']);
    });
  });

  group('what the screens decide from the groups', () {
    final live = _group('A', 'running', sessions: [
      _session('a1', 'past', now.subtract(const Duration(days: 1)), present: 15, expected: 18),
      _session('a2', 'past', now.subtract(const Duration(hours: 4)), present: 16, expected: 18),
      _session('a3', 'now', now.subtract(const Duration(hours: 1)), present: 9, expected: 18),
      _session('a4', 'later', now.add(const Duration(days: 1))),
    ]);
    final laterToday = _group('B', 'running', sessions: [_session('b1', 'today', now.add(const Duration(hours: 3)))]);
    final upcoming = _group('C', 'upcoming', sessions: [_session('c1', 'later', now.add(const Duration(days: 5)))]);
    final ended = _group('D', 'ended', sessions: [_session('d1', 'past', now.subtract(const Duration(days: 30)))]);
    final all = [live, laterToday, upcoming, ended];

    test('today: the session running first, then the ones still to start, then the ones held today', () {
      expect(Supervision.today(all, now).map((x) => x.session['id']), ['a3', 'b1', 'a2']);
      expect(Supervision.focus(all, now)?.session['id'], 'a3');
      expect(Supervision.focus([laterToday], now)?.session['id'], 'b1');
      expect(Supervision.focus([upcoming, ended], now), isNull, reason: 'nothing left to run today');
    });

    test('the sessions held before today come newest first, the days ahead soonest first', () {
      expect(Supervision.held(all, now).map((x) => x.session['id']), ['a1', 'd1']);
      expect(Supervision.ahead(all, now).map((x) => x.session['id']), ['a4', 'c1']);
      expect(Supervision.slot(all, 'c1')?.group['id'], 'g-C');
      expect(Supervision.slot(all, 'nope'), isNull);
      expect(Supervision.group(all, 'g-B')?['id'], 'g-B');
    });

    test('the numbers of the home count what is running and still to start', () {
      expect(Supervision.running(all).length, 2);
      expect(Supervision.upcoming(all).length, 1);
      expect(Supervision.trainees(all), 54, reason: 'a finished group is not counted');
    });

    test('the surveys belong to the programme: two groups of one programme show them once', () {
      final one = _group('G1', 'running', programme: 'X');
      final two = _group('G2', 'upcoming', programme: 'X');
      expect(Supervision.programmes([one, two, upcoming]).map((g) => g['id']), ['g-G1', 'g-C']);
      expect(Supervision.surveys(one).map((s) => s.$1), ['trainee', 'reflection']);
      // A survey without a link is not offered.
      final bare = _group('E', 'running')..['forms'] = {'trainee': {'url': '', 'status': 'off'}};
      expect(Supervision.surveys(bare), isEmpty);
      expect(Supervision.answers('trainee', {'answered': 4, 'expected': 18}), (4, 18));
      expect(Supervision.answers('reflection', {'answered': 0, 'assigned': 0, 'trainers': 2}), (0, 2), reason: 'before it is sent, the trainers of the programme');
      expect(Supervision.answers('reflection', {'answered': 1, 'assigned': 3, 'trainers': 2}), (1, 3));
    });

    test('a list of people is searched by name, number and school, and a sheet narrowed to one mark', () {
      final rows = [
        {'name': 'Ahmad Ali', 'employee_no': '1001', 'school': 'North School', 'status': 'present'},
        {'name': 'Basil Omar', 'employee_no': '2002', 'school': 'South School', 'status': 'absent'},
      ];
      expect(Supervision.search(rows, '').length, 2);
      expect(Supervision.search(rows, ' ali north ').single['employee_no'], '1001');
      expect(Supervision.search(rows, '2002').single['name'], 'Basil Omar');
      expect(Supervision.search(rows, 'ali south'), isEmpty);
      expect(Supervision.marked(rows, 'all').length, 2);
      expect(Supervision.marked(rows, 'absent').single['name'], 'Basil Omar');
    });

    test('a trainee\'s own code is told from a session\'s, and a code counts down to its end', () {
      expect(Supervision.personalCode('TEDC1P.e.123.2030-01-10.abc'), isTrue);
      expect(Supervision.personalCode('TEDC1.session.5.abc'), isFalse);
      expect(Supervision.personalCode(null), isFalse);
      expect(Supervision.secondsLeft(now.add(const Duration(seconds: 12)), now), 12);
      expect(Supervision.secondsLeft(now.subtract(const Duration(seconds: 3)), now), 0);
      expect(Supervision.secondsLeft(null, now), 0);
      expect(Supervision.initial('  sara'), 'S');
      expect(Supervision.initial(''), '؟');
    });
  });

  group('the home', () {
    final real = DateTime.now();
    final live = _group('A', 'running', sessions: [_session('a3', 'now', real.subtract(const Duration(minutes: 30)), present: 9, expected: 18)]);
    final upcoming = _group('C', 'upcoming', sessions: [_session('c1', 'later', real.add(const Duration(days: 5)))]);

    Widget home(List<Map<String, dynamic>> groups, {bool mayAttend = true}) => _app(Scaffold(
          body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: Builder(builder: (context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: supervisorHomeBody(context, groups, mayAttend: mayAttend)))),
        ));

    testWidgets('a session on now leads the home, with who came and the three ways to take attendance', (tester) async {
      await tester.pumpWidget(home([live, upcoming]));
      expect(find.byType(NowCard), findsOneWidget);
      expect(find.text('On now'), findsOneWidget);
      expect(find.text('9 / 18'), findsOneWidget);
      expect(find.byKey(const ValueKey('sheet-a3')), findsOneWidget);
      expect(find.byKey(const ValueKey('code-a3')), findsOneWidget);
      expect(find.byKey(const ValueKey('scan-a3')), findsOneWidget);
      // The programme still to start is listed under it.
      expect(find.text('My upcoming programmes'), findsOneWidget);
      expect(find.text('Programme C'), findsOneWidget);
    });

    testWidgets('without the attendance right the session is shown and nothing is offered to press', (tester) async {
      await tester.pumpWidget(home([live], mayAttend: false));
      expect(find.byType(NowCard), findsOneWidget);
      expect(find.byType(SessionActions), findsNothing);
    });

    testWidgets('a day with nothing to run says so and names the next session', (tester) async {
      await tester.pumpWidget(home([upcoming]));
      expect(find.byType(NowCard), findsNothing);
      expect(find.byType(FreeDayCard), findsOneWidget);
      expect(find.text('No sessions for you today'), findsOneWidget);
      expect(find.textContaining('Programme C'), findsWidgets);
    });

    testWidgets('somebody given nothing is told how programmes reach them', (tester) async {
      await tester.pumpWidget(home([]));
      expect(find.text('No programme has been assigned to you yet'), findsOneWidget);
    });
  });

  testWidgets('«الاستبانات» lists each programme once with the link of the trainees\' survey and the trainer\'s', (tester) async {
    final groups = [_group('G1', 'running', programme: 'X'), _group('G2', 'upcoming', programme: 'X'), _group('C', 'upcoming')];
    await tester.pumpWidget(_app(const SupervisorSurveys(), overrides: [getProvider(Duties.path).overrideWith((ref) async => _duties(groups))]));
    await tester.pumpAndSettle();
    expect(find.byType(ProgrammeSurveys), findsNWidgets(2));
    expect(find.text('Trainee survey'), findsNWidgets(2));
    expect(find.text('Trainer survey'), findsNWidgets(2));
    expect(find.text('4 of 18 answered'), findsNWidgets(2));
    expect(find.text('0 of 2 answered'), findsNWidgets(2));
    expect(find.text('Opens when the group ends'), findsNWidgets(2));
    // The link itself is on the card.
    expect(find.text('example.test/f/t/P-X'), findsOneWidget);
    expect(find.text('example.test/f/r/P-X'), findsOneWidget);

    // The code of a survey opens with its link under it.
    await tester.tap(find.byKey(const ValueKey('survey-code-reflection')).first);
    await tester.pumpAndSettle();
    expect(find.text('https://example.test/f/r/P-X'), findsOneWidget);
  });

  testWidgets('«الحضور» sorts the sessions by day and offers the codes for today\'s only', (tester) async {
    final real = DateTime.now();
    final group = _group('A', 'running', sessions: [
      _session('old', 'past', real.subtract(const Duration(days: 2)), present: 15, expected: 18),
      _session('live', 'now', real.subtract(const Duration(minutes: 20)), present: 9, expected: 18),
      _session('next', 'later', real.add(const Duration(days: 2))),
    ]);
    await tester.pumpWidget(_app(const SupervisorAttendance(), overrides: [getProvider(Duties.path).overrideWith((ref) async => _duties([group]))]));
    await tester.pumpAndSettle();
    expect(find.text('Today (1)'), findsOneWidget);
    expect(find.text('Held (1)'), findsOneWidget);
    expect(find.text('Ahead (1)'), findsOneWidget);
    expect(find.byKey(const ValueKey('code-live')), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('view-held')));
    await tester.pumpAndSettle();
    expect(find.text('15 / 18'), findsOneWidget);
    expect(find.byType(SessionActions), findsNothing, reason: 'a session already held opens its sheet by a tap, with no codes');
  });

  testWidgets('a group\'s page lists its trainees with what each attended, and finds one by name', (tester) async {
    final group = _group('A', 'running', sessions: [_session('a1', 'past', DateTime.now().subtract(const Duration(days: 1)), present: 2, expected: 3)], trainees: 8);
    final trainees = {
      'data': {
        'id': 'g-A', 'held': 4,
        'trainees': [
          for (final (i, name) in ['Ahmad Ali', 'Basil Omar', 'Dana Saif', 'Fahad Nasser', 'Hind Jaber', 'Jude Karim', 'Lina Saad', 'Mona Zaid'].indexed) {'registration_id': 'r$i', 'name': name, 'employee_no': '10$i', 'school': 'School $i', 'attended': i % 5},
        ],
      },
    };
    await tester.pumpWidget(_app(const ProgrammeScreen(groupId: 'g-A'), overrides: [
      getProvider(Duties.path).overrideWith((ref) async => _duties([group])),
      getProvider(Supervision.traineesPath('g-A')).overrideWith((ref) async => trainees),
    ]));
    await tester.pumpAndSettle();
    expect(find.byType(ProgrammeHead), findsOneWidget);
    // The four parts of the page, each with how many it holds.
    for (final part in programmeParts) {
      expect(find.byKey(ValueKey('part-$part')), findsOneWidget);
    }
    expect(find.descendant(of: find.byKey(const ValueKey('part-trainees')), matching: find.text('8')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('part-trainers')), matching: find.text('2')), findsOneWidget);
    // Inside its own group a session is named by itself.
    expect(find.text('Day a1'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('part-trainees')));
    await tester.pumpAndSettle();
    expect(find.byType(TraineeRow), findsNWidgets(8));
    expect(find.text('3 / 4'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('trainee-search')), 'basil');
    await tester.pumpAndSettle();
    expect(find.byType(TraineeRow), findsOneWidget);
    expect(find.text('Basil Omar'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('part-trainers')));
    await tester.pumpAndSettle();
    expect(find.text('Khalid'), findsOneWidget);
    expect(find.text('Noor'), findsOneWidget);
  });

  testWidgets('the attendance sheet is headed by its session, narrowed to one mark, and leads to the two codes', (tester) async {
    final group = _group('A', 'running', sessions: [_session('s1', 'now', DateTime.now().subtract(const Duration(minutes: 20)), present: 1, expected: 2)]);
    final sheet = {
      'data': {
        'expected': 2, 'present': 1, 'late': 0, 'absent': 1,
        'rows': [
          {'registration_id': 'r1', 'name': 'Ahmad Ali', 'school': 'North School', 'status': 'present', 'check_in_at': _at(DateTime.now())},
          {'registration_id': 'r2', 'name': 'Basil Omar', 'school': 'South School', 'status': 'absent'},
        ],
      },
    };
    await tester.pumpWidget(_app(const RollCallScreen(sessionId: 's1'), overrides: [
      getProvider(Duties.path).overrideWith((ref) async => _duties([group])),
      getProvider(Supervision.attendancePath('s1')).overrideWith((ref) async => sheet),
    ]));
    await tester.pumpAndSettle();
    expect(find.text('Programme A'), findsOneWidget);
    expect(find.byKey(const ValueKey('roll-code')), findsOneWidget);
    expect(find.byKey(const ValueKey('roll-scan')), findsOneWidget);
    expect(find.text('Ahmad Ali'), findsOneWidget);
    expect(find.text('Basil Omar'), findsOneWidget);

    await tester.ensureVisible(find.byKey(const ValueKey('roll-filter-absent')));
    await tester.tap(find.byKey(const ValueKey('roll-filter-absent')));
    await tester.pumpAndSettle();
    expect(find.text('Ahmad Ali'), findsNothing);
    expect(find.text('Basil Omar'), findsOneWidget);
  });
}
