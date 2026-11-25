import 'dart:convert';
import 'dart:io';

import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/api/api_client.dart';
import 'package:tedc_mobile/core/auth/session_store.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/core/models.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/attendance/roll_call_screen.dart';
import 'package:tedc_mobile/features/duties/duties_model.dart';
import 'package:tedc_mobile/features/shell/app_nav_bar.dart';
import 'package:tedc_mobile/features/shell/home_shell.dart';
import 'package:tedc_mobile/features/wayfinding/wayfinding_data.dart';
import 'package:tedc_mobile/features/workspace/kits.dart';
import 'package:tedc_mobile/features/workspace/parts.dart';
import 'package:tedc_mobile/features/workspace/workspace.dart';
import 'package:tedc_mobile/features/workspace/workspace_home.dart';
import 'package:tedc_mobile/features/workspace/workspace_programmes.dart';
import 'package:tedc_mobile/features/workspace/workspace_tabs.dart';

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

Me _trainer({bool alsoTrainee = false}) => Me({
      'id': 'u2', 'name': 'Khalid', 'permissions': ['attendance.manage'], 'trainer_id': 't1',
      'roles': [
        {'id': 'grant-trainer', 'slug': 'trainer', 'name': 'Trainer', 'active': true},
        if (alsoTrainee) {'id': 'grant-trainee', 'slug': 'employee', 'name': 'Trainee', 'active': false},
      ],
      'active_role': {'slug': 'trainer', 'name': 'Trainer'},
      if (alsoTrainee) 'employee': {'id': 'e2'},
    });

/// Remembers every change it was asked to make, and answers like the server.
class _Api extends ApiClient {
  _Api() : super(SessionStore(), locale: () => 'en', onSessionExpired: () {});

  final sent = <(String method, String path, Object? body)>[];

  @override
  Future<dynamic> post(String path, [Object? body]) async {
    sent.add(('POST', path, body));
    return {'data': {}};
  }

  @override
  Future<dynamic> put(String path, [Object? body]) async {
    sent.add(('PUT', path, body));
    return {'data': {}};
  }

  @override
  Future<dynamic> delete(String path) async {
    sent.add(('DELETE', path, null));
    return {'data': {}};
  }
}

/// A group as a trainer gets it: the hours of the programme, its kits, and the way to reach its supervisor.
Map<String, dynamic> _taught(String title, String phase, {List<Map<String, dynamic>> sessions = const [], bool course = false, int kits = 1, String phone = '+97455500011', String email = 'sara@moe.test'}) {
  final g = _group(title, phase, sessions: sessions, capacities: const ['trainer']);
  return {
    ...g,
    'program': {...g['program'] as Map<String, dynamic>, 'hours': 15, 'has_course': course},
    'supervisor': {'ar': 'سارة', 'en': 'Sara', 'me': false, 'email': email, 'phone': phone},
    'kits': [for (var i = 0; i < kits; i++) {'id': 'k$i', 'code': 'KIT-$i', 'title_ar': 'حقيبة $i', 'title_en': 'Kit $i', 'version': 2, 'hours': 15, 'files': 6}],
  };
}

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
      'work.nav.programs', 'work.nav.attendance', 'work.nav.surveys', 'work.now', 'work.next', 'work.freeDay', 'work.noNext', 'work.noUpcoming', 'work.stat.running', 'work.stat.upcoming', 'work.stat.today', 'work.stat.trainees',
      'work.today', 'work.todayRest', 'work.running', 'work.upcoming', 'work.act.sheet', 'work.act.code', 'work.act.scan', 'work.att.today', 'work.att.held', 'work.att.ahead', 'work.att.none.today', 'work.att.none.held',
      'work.att.none.ahead', 'work.att.noRight', 'work.count.trainees', 'work.count.trainers', 'work.count.sessions', 'work.prog.title', 'work.prog.sessions', 'work.prog.trainees',
      'work.prog.surveys', 'work.prog.missing', 'work.prog.noSessions', 'work.prog.noTrainees', 'work.prog.noMatch', 'work.prog.search', 'work.prog.attended', 'work.prog.notHeld', 'work.survey.trainee',
      'work.survey.reflection', 'work.survey.traineeHint', 'work.survey.reflectionHint', 'work.survey.answered', 'work.survey.st.open', 'work.survey.st.scheduled', 'work.survey.st.closed', 'work.survey.st.off',
      'work.survey.st.waiting', 'work.survey.st.done', 'work.survey.code', 'work.survey.copy', 'work.survey.open', 'work.survey.copied', 'work.survey.none', 'work.survey.intro', 'work.qr.hint', 'work.qr.renews',
      'work.qr.present', 'work.scan.hint', 'work.scan.in', 'work.scan.out', 'work.scan.notPersonal', 'work.scan.count',
      'work.nav.kits', 'work.hoursUnit', 'work.count.hours', 'work.sessionsCount.other', 'work.stat.hours', 'work.roomWay', 'work.call', 'work.email', 'work.copied', 'work.noContact', 'work.prog.team',
      'work.prog.kits', 'work.alsoTrainee', 'work.alsoTraineeHint', 'work.alsoSupervisor', 'work.alsoTrainer', 'work.backHint', 'work.myCertificates', 'work.kit.short', 'work.kit.count.other', 'work.kit.files.other', 'work.kit.version', 'work.kit.title',
      'work.kit.course', 'work.kit.courseHint', 'work.kit.none', 'work.kit.noneHint', 'work.kit.ownership', 'work.kit.open', 'work.kit.openHint', 'work.kit.about', 'work.kit.objectives',
      'work.kit.contents', 'work.kit.empty', 'work.kit.presentable', 'work.kit.acknowledged', 'roll.early', 'roll.early.short', 'roll.early.left', 'roll.early.undo', 'roll.early.at',
      'roll.early.reason', 'roll.early.save', 'roll.early.done', 'roll.early.badTime', 'roll.note.add', 'roll.note.edit', 'roll.note.title', 'roll.note.hint', 'roll.remark.heading',
      'roll.remark.none', 'roll.remark.add', 'roll.remark.edit', 'roll.remark.mine', 'roll.remark.title', 'roll.remark.hint',
    ];
    for (final lang in ['ar', 'en']) {
      for (final key in keys) {
        expect(S(lang).t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });

  group('who gets which app', () {
    test('the role the person works in decides, not the roles they hold', () {
      expect(_supervisor().workspace, 'supervisor');
      expect(_trainer().workspace, 'trainer');
      expect(_trainer().worksAsTrainer, isTrue);
      // The same person working as a trainee keeps the trainee's app.
      final asTrainee = Me({'roles': [{'slug': 'supervisor'}, {'slug': 'employee'}], 'active_role': {'slug': 'employee'}, 'employee': {'id': 'e1'}});
      expect(asTrainee.workspace, isNull);
      // No role chosen yet: an employee profile means a trainee; without one, the first role held.
      expect(Me({'roles': [{'slug': 'supervisor'}], 'employee': {'id': 'e1'}}).workSlug, 'employee');
      expect(Me({'roles': [{'slug': 'supervisor'}]}).worksAsSupervisor, isTrue);
      expect(Me({'roles': [{'slug': 'trainer'}]}).workspace, 'trainer');
      expect(Me({'roles': <Map<String, dynamic>>[]}).workSlug, 'employee');
      // A coordinator, an administrator: the home of their role, as before.
      expect(Me({'roles': [{'slug': 'coordinator'}]}).workspace, isNull);
    });

    test('a trainer who is also a trainee has the way to their own training', () {
      expect(_trainer(alsoTrainee: true).traineeGrant?['id'], 'grant-trainee');
      expect(_trainer().traineeGrant, isNull);
      // Somebody already working as a trainee is not offered what they are in.
      expect(Me({'roles': [{'id': 'g1', 'slug': 'employee', 'active': true}]}).traineeGrant, isNull);
    });

    test('the first four tabs are the person\'s own, at their root only; the fourth is the surveys or the kits', () {
      expect(workspaceTab('supervisor', 0, '/home'), isA<WorkspaceHome>());
      expect(workspaceTab('supervisor', 1, '/programs'), isA<WorkspaceProgrammes>());
      expect(workspaceTab('supervisor', 2, '/training'), isA<WorkspaceAttendance>());
      expect(workspaceTab('supervisor', 3, '/certificates'), isA<WorkspaceSurveys>());
      expect(workspaceTab('trainer', 0, '/home'), isA<WorkspaceHome>());
      expect(workspaceTab('trainer', 3, '/certificates'), isA<WorkspaceKits>());
      expect(workspaceTab('trainer', 4, '/profile'), isNull, reason: 'the account is the same for everybody');
      expect(workspaceTab('supervisor', 1, '/programs/P-1'), isNull, reason: 'a page opened inside a tab is not replaced');
      expect(workspaceTab(null, 0, '/home'), isNull);
    });

    test('a notice about the person\'s own training opens the page it names, not their tab', () {
      expect(tabAddress('/certificates', workspace: 'supervisor'), '/certificates?own=1');
      expect(workspaceTab('trainer', 3, tabAddress('/certificates', workspace: 'trainer')), isNull, reason: 'a trainer\'s own certificates');
      expect(tabAddress('/home', workspace: 'supervisor'), '/home');
      expect(tabAddress('/profile', workspace: 'trainer'), '/profile');
      expect(tabAddress('/certificates', workspace: null), '/certificates');
    });

    test('a group\'s page sits under «برامجي», a session\'s attendance under «الحضور», a trainer\'s kit under «الحقائب»', () {
      expect(tabIndexFor('/my-programs/g1', workspace: 'supervisor'), 1);
      expect(tabIndexFor('/roll-call/s1', workspace: 'trainer'), 2);
      expect(tabIndexFor('/session-qr/s1', workspace: 'supervisor'), 2);
      expect(tabIndexFor('/staff-scan/s1', workspace: 'supervisor'), 2);
      expect(tabIndexFor('/teaching-kit/k1', workspace: 'trainer'), 3);
      expect(tabIndexFor('/kit/p1', workspace: 'trainer'), 3);
      // Everybody else keeps the tabs they had.
      expect(tabIndexFor('/roll-call/s1'), 2);
      expect(tabIndexFor('/my-programs'), 0);
      expect(workspaceTabs(S('en'), 'supervisor').map((t) => t.label), ['Home', 'Programmes', 'Attendance', 'Surveys', 'Profile']);
      expect(workspaceTabs(S('en'), 'trainer').map((t) => t.label), ['Home', 'Programmes', 'Attendance', 'Kits', 'Profile']);
    });
  });

  group('what a trainer needs from a group', () {
    final start = DateTime(2030, 1, 10, 8);
    final taught = _taught('T', 'running', sessions: [
      _session('t1', 'past', start.subtract(const Duration(days: 1))),
      _session('t2', 'now', start),
      {..._session('t3', 'later', start.add(const Duration(days: 1))), 'ends_at': _at(start.add(const Duration(days: 1, hours: 2, minutes: 30)))},
    ], course: true);

    test('the hours: of a session, of the group, and the ones still to give', () {
      expect(Workspace.sessionHours(taught['sessions'][0] as Map<String, dynamic>), 3);
      expect(Workspace.sessionHours(const {}), 0);
      expect(Workspace.groupHours(taught), 8.5, reason: 'what its sessions add up to');
      expect(Workspace.groupHours(_taught('E', 'upcoming')), 15, reason: 'no session yet: the hours written for the programme');
      expect(Workspace.hoursAhead([taught]), 5.5, reason: 'the session held is behind');
      expect(Workspace.hoursText(3), '3');
      expect(Workspace.hoursText(5.5), '5.5');
      expect(Workspace.firstStart(taught)?.day, 9);
      expect(hoursLabel(S('en'), 3), '3 hours');
      expect(hoursLabel(S('ar'), 2), 'ساعتين');
      expect(hoursLabel(S('en'), 2.5), '2.5 hours');
    });

    test('the kits are a part of the page for somebody who teaches the group, not for its supervisor', () {
      expect(Workspace.kitCount(taught), 2, reason: 'one training kit and the online content');
      expect(Workspace.parts(taught), ['sessions', 'trainees', 'team', 'kits', 'surveys']);
      final supervised = {...taught, 'capacities': ['supervisor']};
      expect(Workspace.kitCount(supervised), 0);
      expect(Workspace.parts(supervised), ['sessions', 'trainees', 'team', 'surveys']);
      expect(Workspace.kitCount(_taught('N', 'running', kits: 0)), 0);
    });

    test('the supervisor is reached by what the account has; nobody is offered their own number', () {
      expect(Workspace.contact(taught['supervisor'] as Map<String, dynamic>), (phone: '+97455500011', email: 'sara@moe.test'));
      expect(Workspace.contact({'ar': 'سارة', 'me': false, 'phone': '', 'email': ' '}), isNull);
      expect(Workspace.contact({'ar': 'أنا', 'me': true, 'phone': '+974555'}), isNull);
      expect(Workspace.contact(null), isNull);
    });

    test('an early leave: the time offered, the time allowed, and who can leave', () {
      final ends = start.add(const Duration(hours: 3));
      // While the session runs it is now; for one already held, half an hour before its end.
      expect(Workspace.leaveDefault(start, ends, start.add(const Duration(minutes: 70))), start.add(const Duration(minutes: 70)));
      expect(Workspace.leaveDefault(start, ends, ends.add(const Duration(days: 1))), ends.subtract(const Duration(minutes: 30)));
      expect(Workspace.leaveDefault(start, start.add(const Duration(minutes: 40)), ends), start.add(const Duration(minutes: 20)), reason: 'a short session: its middle');
      expect(Workspace.leaveValid(start.add(const Duration(minutes: 1)), start, ends), isTrue);
      expect(Workspace.leaveValid(start, start, ends), isFalse);
      expect(Workspace.leaveValid(ends, start, ends), isFalse);
      expect(Workspace.onDayOf(start, 10, 45), DateTime(2030, 1, 10, 10, 45));

      final rows = [
        {'name': 'A', 'status': 'present', 'left_early_at': '2030-01-10T07:00:00Z'},
        {'name': 'B', 'status': 'late'},
        {'name': 'C', 'status': 'absent'},
      ];
      expect(rows.map(Workspace.came), [true, true, false]);
      expect(Workspace.marked(rows, 'early').single['name'], 'A');
      expect(Workspace.leftEarly(rows[1]), isFalse);
    });

    test('a kit page reads the objectives however the kit keeps them, and sizes its files', () {
      expect(KitInfoScreen.objectives(['One', ' ', 'Two'], 'en'), ['One', 'Two']);
      expect(KitInfoScreen.objectives([{'ar': 'أول', 'en': 'First'}], 'en'), ['First']);
      expect(KitInfoScreen.objectives('a\nb', 'en'), ['a', 'b']);
      expect(KitInfoScreen.objectives(null, 'en'), isEmpty);
      expect(KitInfoScreen.size(0), '');
      expect(KitInfoScreen.size(2048), '2 KB');
      expect(KitInfoScreen.size(3 * 1024 * 1024 + 512 * 1024), '3.5 MB');
      expect(KitInfoScreen.readerUri('k1').path, endsWith('/admin/teaching/kits/k1'));
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
      expect(Workspace.today(all, now).map((x) => x.session['id']), ['a3', 'b1', 'a2']);
      expect(Workspace.focus(all, now)?.session['id'], 'a3');
      expect(Workspace.focus([laterToday], now)?.session['id'], 'b1');
      expect(Workspace.focus([upcoming, ended], now), isNull, reason: 'nothing left to run today');
    });

    test('the sessions held before today come newest first, the days ahead soonest first', () {
      expect(Workspace.held(all, now).map((x) => x.session['id']), ['a1', 'd1']);
      expect(Workspace.ahead(all, now).map((x) => x.session['id']), ['a4', 'c1']);
      expect(Workspace.slot(all, 'c1')?.group['id'], 'g-C');
      expect(Workspace.slot(all, 'nope'), isNull);
      expect(Workspace.group(all, 'g-B')?['id'], 'g-B');
    });

    test('the numbers of the home count what is running and still to start', () {
      expect(Workspace.running(all).length, 2);
      expect(Workspace.upcoming(all).length, 1);
      expect(Workspace.trainees(all), 54, reason: 'a finished group is not counted');
    });

    test('the surveys belong to the programme: two groups of one programme show them once', () {
      final one = _group('G1', 'running', programme: 'X');
      final two = _group('G2', 'upcoming', programme: 'X');
      expect(Workspace.programmes([one, two, upcoming]).map((g) => g['id']), ['g-G1', 'g-C']);
      expect(Workspace.surveys(one).map((s) => s.$1), ['trainee', 'reflection']);
      // A survey without a link is not offered.
      final bare = _group('E', 'running')..['forms'] = {'trainee': {'url': '', 'status': 'off'}};
      expect(Workspace.surveys(bare), isEmpty);
      expect(Workspace.answers('trainee', {'answered': 4, 'expected': 18}), (4, 18));
      expect(Workspace.answers('reflection', {'answered': 0, 'assigned': 0, 'trainers': 2}), (0, 2), reason: 'before it is sent, the trainers of the programme');
      expect(Workspace.answers('reflection', {'answered': 1, 'assigned': 3, 'trainers': 2}), (1, 3));
    });

    test('a list of people is searched by name, number and school, and a sheet narrowed to one mark', () {
      final rows = [
        {'name': 'Ahmad Ali', 'employee_no': '1001', 'school': 'North School', 'status': 'present'},
        {'name': 'Basil Omar', 'employee_no': '2002', 'school': 'South School', 'status': 'absent'},
      ];
      expect(Workspace.search(rows, '').length, 2);
      expect(Workspace.search(rows, ' ali north ').single['employee_no'], '1001');
      expect(Workspace.search(rows, '2002').single['name'], 'Basil Omar');
      expect(Workspace.search(rows, 'ali south'), isEmpty);
      expect(Workspace.marked(rows, 'all').length, 2);
      expect(Workspace.marked(rows, 'absent').single['name'], 'Basil Omar');
    });

    test('a trainee\'s own code is told from a session\'s, and a code counts down to its end', () {
      expect(Workspace.personalCode('TEDC1P.e.123.2030-01-10.abc'), isTrue);
      expect(Workspace.personalCode('TEDC1.session.5.abc'), isFalse);
      expect(Workspace.personalCode(null), isFalse);
      expect(Workspace.secondsLeft(now.add(const Duration(seconds: 12)), now), 12);
      expect(Workspace.secondsLeft(now.subtract(const Duration(seconds: 3)), now), 0);
      expect(Workspace.secondsLeft(null, now), 0);
      expect(Workspace.initial('  sara'), 'S');
      expect(Workspace.initial(''), '؟');
    });
  });

  group('the home', () {
    final real = DateTime.now();
    final live = _group('A', 'running', sessions: [_session('a3', 'now', real.subtract(const Duration(minutes: 30)), present: 9, expected: 18)]);
    final upcoming = _group('C', 'upcoming', sessions: [_session('c1', 'later', real.add(const Duration(days: 5)))]);

    Widget home(List<Map<String, dynamic>> groups, {bool mayAttend = true}) => _app(Scaffold(
          body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: Builder(builder: (context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: workspaceHomeBody(context, groups, mayAttend: mayAttend)))),
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
    await tester.pumpWidget(_app(const WorkspaceSurveys(), overrides: [getProvider(Duties.path).overrideWith((ref) async => _duties(groups))]));
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
    await tester.pumpWidget(_app(const WorkspaceAttendance(), overrides: [getProvider(Duties.path).overrideWith((ref) async => _duties([group]))]));
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
      getProvider(Workspace.traineesPath('g-A')).overrideWith((ref) async => trainees),
    ]));
    await tester.pumpAndSettle();
    expect(find.byType(ProgrammeHead), findsOneWidget);
    // The parts of the page, each with how many it holds; a supervisor has no kits part.
    for (final part in ['sessions', 'trainees', 'team', 'surveys']) {
      expect(find.byKey(ValueKey('part-$part')), findsOneWidget);
    }
    expect(find.byKey(const ValueKey('part-kits')), findsNothing);
    expect(find.descendant(of: find.byKey(const ValueKey('part-trainees')), matching: find.text('8')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('part-team')), matching: find.text('3')), findsOneWidget, reason: 'two trainers and the supervisor');
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

    await tester.tap(find.byKey(const ValueKey('part-team')));
    await tester.pumpAndSettle();
    expect(find.text('Khalid'), findsOneWidget);
    expect(find.text('Noor'), findsOneWidget);
    expect(find.byType(ContactCard), findsOneWidget);
    expect(find.byKey(const ValueKey('contact-phone')), findsNothing, reason: 'nobody is offered their own number');
  });

  group('the trainer\'s app', () {
    final real = DateTime.now();
    final live = _taught('A', 'running', sessions: [_session('a3', 'now', real.subtract(const Duration(minutes: 30)), present: 9, expected: 18)], course: true);
    final upcoming = _taught('C', 'upcoming', sessions: [_session('c1', 'later', real.add(const Duration(days: 5)))]);

    testWidgets('the home leads with the session, its kit and the trainer\'s own check-in, and counts the hours ahead', (tester) async {
      await tester.pumpWidget(_app(
        Scaffold(body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: Builder(builder: (context) => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: workspaceHomeBody(context, [live, upcoming], mayAttend: true, trainer: true))))),
        me: _trainer(),
      ));
      expect(find.byType(NowCard), findsOneWidget);
      expect(find.byKey(const ValueKey('now-kit')), findsOneWidget);
      expect(find.byKey(const ValueKey('now-checkin')), findsOneWidget);
      expect(find.byKey(const ValueKey('sheet-a3')), findsOneWidget);
      expect(find.descendant(of: find.byKey(const ValueKey('stat-hours')), matching: find.text('6')), findsOneWidget, reason: 'three hours now and three in five days');
      // The programme still to start says when it starts, where, how long, and that it has a kit.
      expect(find.text('Programme C'), findsOneWidget);
      expect(find.text('1 training kit'), findsOneWidget);
    });

    testWidgets('a supervisor\'s session has no kit and no check-in of their own beside it', (tester) async {
      await tester.pumpWidget(_app(Scaffold(body: SingleChildScrollView(child: Builder(builder: (context) => Column(children: workspaceHomeBody(context, [live], mayAttend: true)))))));
      expect(find.byKey(const ValueKey('now-kit')), findsNothing);
      expect(find.byKey(const ValueKey('now-checkin')), findsNothing);
      expect(find.byKey(const ValueKey('stat-hours')), findsNothing);
    });

    testWidgets('somebody who is also a trainee is offered the way to their own training', (tester) async {
      await tester.pumpWidget(_app(const Scaffold(body: AlsoTrainee()), me: _trainer(alsoTrainee: true)));
      await tester.pumpAndSettle();
      expect(find.text('You are a trainee too'), findsOneWidget);
      await tester.pumpWidget(const SizedBox());
      await tester.pumpWidget(_app(const Scaffold(body: AlsoTrainee()), me: _trainer()));
      await tester.pumpAndSettle();
      expect(find.text('You are a trainee too'), findsNothing);
    });

    testWidgets('and from their own training, the way back to their work; somebody who is only a trainee sees nothing', (tester) async {
      // The same trainer, now working as a trainee.
      final asTrainee = Me({
        'id': 'u2', 'name': 'Khalid', 'trainer_id': 't1', 'employee': {'id': 'e2'}, 'active_role': {'slug': 'employee'},
        'roles': [{'id': 'grant-trainer', 'slug': 'trainer', 'active': false}, {'id': 'grant-trainee', 'slug': 'employee', 'active': true}],
      });
      expect(asTrainee.workspace, isNull);
      expect(asTrainee.workGrant?['id'], 'grant-trainer');
      await tester.pumpWidget(_app(const Scaffold(body: BackToWork()), me: asTrainee));
      await tester.pumpAndSettle();
      expect(find.text('You are a trainer too'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
      final supervisorAsTrainee = Me({'employee': {'id': 'e1'}, 'active_role': {'slug': 'employee'}, 'roles': [{'id': 'gs', 'slug': 'supervisor', 'active': false}, {'id': 'ge', 'slug': 'employee', 'active': true}]});
      await tester.pumpWidget(_app(const Scaffold(body: BackToWork()), me: supervisorAsTrainee));
      await tester.pumpAndSettle();
      expect(find.text('You are a supervisor too'), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
      final onlyTrainee = Me({'employee': {'id': 'e3'}, 'active_role': {'slug': 'employee'}, 'roles': [{'id': 'g3', 'slug': 'employee', 'active': true}]});
      expect(onlyTrainee.workGrant, isNull);
      await tester.pumpWidget(_app(const Scaffold(body: BackToWork()), me: onlyTrainee));
      await tester.pumpAndSettle();
      expect(find.byType(Surface), findsNothing);
    });

    testWidgets('a group\'s page gives the hours, the way to reach the supervisor, and the kits', (tester) async {
      await tester.pumpWidget(_app(const ProgrammeScreen(groupId: 'g-A'), me: _trainer(), overrides: [getProvider(Duties.path).overrideWith((ref) async => _duties([live]))]));
      await tester.pumpAndSettle();
      expect(find.textContaining('3 hours'), findsWidgets);
      expect(find.byKey(const ValueKey('part-kits')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('part-team')));
      await tester.pumpAndSettle();
      expect(find.text('Sara'), findsOneWidget);
      expect(find.text('+97455500011'), findsOneWidget);
      expect(find.text('sara@moe.test'), findsOneWidget);
      expect(find.text('Call'), findsOneWidget);
      expect(find.text('E-mail'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('part-kits')));
      await tester.pumpAndSettle();
      expect(find.byType(KitTile), findsOneWidget);
      expect(find.text('Kit 0'), findsOneWidget);
      expect(find.text('15 hours · 6 files'), findsOneWidget);
      expect(find.byType(CourseKitTile), findsOneWidget);
    });

    testWidgets('a supervisor whose account has no number is said to be reached through the centre', (tester) async {
      final bare = _taught('A', 'running', phone: '', email: '');
      await tester.pumpWidget(_app(const ProgrammeScreen(groupId: 'g-A', part: 'team'), me: _trainer(), overrides: [getProvider(Duties.path).overrideWith((ref) async => _duties([bare]))]));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('contact-phone')), findsNothing);
      expect(find.textContaining('Reach them through the centre'), findsOneWidget);
    });

    testWidgets('«الحقائب» lists the kits of each programme once, and says so when there are none', (tester) async {
      final second = {..._taught('B', 'upcoming'), 'program': {...live['program'] as Map<String, dynamic>}};
      await tester.pumpWidget(_app(const WorkspaceKits(), me: _trainer(), overrides: [getProvider(Duties.path).overrideWith((ref) async => _duties([live, second, upcoming]))]));
      await tester.pumpAndSettle();
      expect(find.byType(KitTile), findsNWidgets(2), reason: 'programme A once, programme C once');
      expect(find.byType(CourseKitTile), findsOneWidget);

      await tester.pumpWidget(const SizedBox());
      await tester.pumpWidget(_app(const WorkspaceKits(), me: _trainer(), overrides: [getProvider(Duties.path).overrideWith((ref) async => _duties([_taught('N', 'running', kits: 0)]))]));
      await tester.pumpAndSettle();
      expect(find.text('No training kits for your programmes yet'), findsOneWidget);
    });

    testWidgets('a kit\'s page says what it is for and what it holds, and leads to the protected reader', (tester) async {
      final kit = {
        'data': {
          'id': 'k0', 'code': 'KIT-0', 'title_ar': 'حقيبة', 'title_en': 'Active learning kit', 'description_en': 'Strategies for the classroom.', 'description_ar': 'وصف', 'version': 2, 'hours': 15,
          'objectives': ['Plan an active lesson', 'Run a think-pair-share'], 'acknowledged': true,
          'files': [
            {'id': 'f1', 'name': 'Day 1 slides.pptx', 'kind': 'pptx', 'size': 2 * 1024 * 1024, 'presentable': true},
            {'id': 'f2', 'name': 'Trainer guide.pdf', 'kind': 'pdf', 'size': 512 * 1024, 'presentable': true},
          ],
          'ownership': {'ar': 'ملك للمركز', 'en': 'The kit belongs to the centre.'},
        },
      };
      tester.view.physicalSize = const Size(800, 3200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(_app(const KitInfoScreen(kitId: 'k0', programId: 'p-A'), me: _trainer(), overrides: [getProvider(Workspace.kitPath('k0', 'p-A')).overrideWith((ref) async => kit)]));
      await tester.pumpAndSettle();
      expect(find.text('Active learning kit'), findsOneWidget);
      expect(find.text('15 hours · 2 files'), findsOneWidget);
      expect(find.byKey(const ValueKey('kit-open')), findsOneWidget);
      expect(find.text('Strategies for the classroom.'), findsOneWidget);
      expect(find.text('Run a think-pair-share'), findsOneWidget);
      expect(find.text('Trainer guide.pdf'), findsOneWidget);
      expect(find.text('PDF · 512 KB'), findsOneWidget);
      expect(find.text('The kit belongs to the centre.'), findsOneWidget);
    });
  });

  testWidgets('«موقع القاعة» is offered for a session still to be held in a room on the centre\'s plans, and for no other', (tester) async {
    final raw = jsonDecode(File('../backend/resources/wayfinding/centre-map.json').readAsStringSync()) as Map<String, dynamic>;
    final bare = WayData({'map': raw, 'entrances': raw['entrances'], 'signs': const [], 'rooms': const [], 'centre': null});
    final place = bare.map.places.firstWhere((p) => p.id == 'f-room-14');
    final way = WayData({
      'map': raw, 'entrances': raw['entrances'], 'signs': const [], 'centre': null,
      'rooms': [{'id': 'r14', 'name_ar': 'قاعة التدريب 14', 'name_en': 'Training Room 14', 'place': place.id, 'floor': place.floor, 'door': [place.door.x, place.door.y]}],
    });
    final real = DateTime.now();
    Map<String, dynamic> at(String id, String when, DateTime starts, {String room = 'r14', String mode = 'in_person'}) => {..._session(id, when, starts), 'room_id': room, 'map_place': '', 'mode': mode};
    final group = _taught('A', 'running');
    Widget ways(List<Map<String, dynamic>> sessions) => _app(
          Scaffold(body: Column(children: [for (final x in sessions) RoomWay(slot: (group: group, session: x))])),
          overrides: [wayDataProvider.overrideWith((ref) async => way)],
        );

    await tester.pumpWidget(ways([
      at('live', 'now', real.subtract(const Duration(minutes: 30))),
      at('next', 'later', real.add(const Duration(days: 1))),
      at('held', 'past', real.subtract(const Duration(days: 1))),
      at('online', 'later', real.add(const Duration(days: 1)), mode: 'online'),
      at('elsewhere', 'later', real.add(const Duration(days: 1)), room: 'not-on-the-plans'),
      at('nowhere', 'later', real.add(const Duration(days: 1)), room: ''),
    ]));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('room-way-live')), findsOneWidget);
    expect(find.byKey(const ValueKey('room-way-next')), findsOneWidget);
    expect(find.text('Room location'), findsNWidgets(2));
    for (final id in ['held', 'online', 'elsewhere', 'nowhere']) {
      expect(find.byKey(ValueKey('room-way-$id')), findsNothing, reason: id);
    }
  });

  group('the attendance sheet in a trainer\'s hands', () {
    final real = DateTime.now();
    final group = _taught('A', 'running', sessions: [_session('s1', 'now', real.subtract(const Duration(minutes: 20)), present: 2, expected: 3)]);
    Map<String, dynamic> sheet({List<Map<String, dynamic>> remarks = const []}) => {
          'data': {
            'expected': 3, 'present': 2, 'late': 0, 'absent': 1,
            'rows': [
              {'registration_id': 'r1', 'name': 'Ahmad Ali', 'school': 'North School', 'status': 'present', 'check_in_at': _at(real)},
              {'registration_id': 'r2', 'name': 'Basil Omar', 'school': 'South School', 'status': 'present', 'left_early_at': _at(real), 'note': 'Medical appointment'},
              {'registration_id': 'r3', 'name': 'Dana Saif', 'school': 'East School', 'status': 'absent'},
            ],
            'remarks': remarks,
          },
        };

    Future<_Api> open(WidgetTester tester, Map<String, dynamic> data) async {
      final api = _Api();
      tester.view.physicalSize = const Size(800, 3200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(_app(const RollCallScreen(sessionId: 's1'), me: _trainer(), overrides: [
        apiProvider.overrideWithValue(api),
        getProvider(Duties.path).overrideWith((ref) async => _duties([group])),
        getProvider(Workspace.attendancePath('s1')).overrideWith((ref) async => data),
      ]));
      await tester.pumpAndSettle();
      return api;
    }

    testWidgets('somebody present is recorded as having left early, with the reason; somebody absent cannot be', (tester) async {
      final api = await open(tester, sheet());
      expect(find.byKey(const ValueKey('early-r1')), findsOneWidget);
      expect(find.byKey(const ValueKey('early-r3')), findsNothing, reason: 'he did not come');
      expect(find.byKey(const ValueKey('early-r2')), findsNothing, reason: 'he already left');
      expect(find.text('Left early (1)'), findsOneWidget);

      await tester.ensureVisible(find.byKey(const ValueKey('early-r1')));
      await tester.tap(find.byKey(const ValueKey('early-r1')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('early-reason')), 'Family matter');
      await tester.tap(find.byKey(const ValueKey('early-save')));
      await tester.pumpAndSettle();

      final (method, path, body) = api.sent.single;
      expect((method, path), ('POST', '/admin/sessions/s1/early-leave'));
      expect((body as Map)['registration_id'], 'r1');
      expect(body['reason'], 'Family matter');
      expect(DateTime.parse(body['at'] as String).difference(real).inMinutes.abs(), lessThan(2), reason: 'while the session runs, he left now');
    });

    testWidgets('an early leave is taken back, and a comment is written about a trainee', (tester) async {
      final api = await open(tester, sheet());
      expect(find.byKey(const ValueKey('left-r2')), findsOneWidget);
      expect(find.text('Medical appointment'), findsOneWidget);
      await tester.ensureVisible(find.byKey(const ValueKey('stayed-r2')));
      await tester.tap(find.byKey(const ValueKey('stayed-r2')));
      await tester.pumpAndSettle();
      expect(api.sent.last.$1, 'DELETE');
      expect(api.sent.last.$2, '/admin/sessions/s1/early-leave/r2');

      await tester.ensureVisible(find.byKey(const ValueKey('comment-r3')));
      await tester.tap(find.byKey(const ValueKey('comment-r3')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('text-sheet-field')), 'Apologised by phone');
      await tester.tap(find.byKey(const ValueKey('text-sheet-save')));
      await tester.pumpAndSettle();
      expect((api.sent.last.$1, api.sent.last.$2), ('PUT', '/admin/sessions/s1/attendance/r3/note'));
      expect(api.sent.last.$3, {'note': 'Apologised by phone'});
    });

    testWidgets('each person who runs the session writes a remark about it and reads the others', (tester) async {
      final api = await open(tester, sheet(remarks: [{'id': 'm1', 'body': 'The projector was replaced.', 'by': 'Sara', 'mine': false, 'at': _at(real)}]));
      expect(find.text('The projector was replaced.'), findsOneWidget);
      expect(find.text('Sara'), findsOneWidget);
      expect(find.text('Add your remark'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('remark-write')));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('text-sheet-field')), 'Two trainees need the slides again.');
      await tester.tap(find.byKey(const ValueKey('text-sheet-save')));
      await tester.pumpAndSettle();
      expect((api.sent.single.$1, api.sent.single.$2), ('PUT', '/admin/sessions/s1/remark'));
      expect(api.sent.single.$3, {'body': 'Two trainees need the slides again.'});
    });
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
      getProvider(Workspace.attendancePath('s1')).overrideWith((ref) async => sheet),
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
