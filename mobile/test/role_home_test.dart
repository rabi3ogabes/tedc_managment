import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/features/home/role_home.dart';

Widget _wrap(Widget child) => MaterialApp(
      locale: const Locale('en'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
      home: Scaffold(body: SingleChildScrollView(padding: const EdgeInsets.all(20), child: child)),
    );

void main() {
  test('the strings of the role home exist in both languages', () {
    for (final lang in ['ar', 'en']) {
      for (final key in ['role.waiting', 'role.allClear', 'role.today', 'role.noToday', 'role.rollCall', 'role.openDashboard']) {
        expect(S(lang).t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });

  testWidgets('action tiles show each waiting item with its number, and a calm message when nothing waits', (tester) async {
    await tester.pumpWidget(_wrap(const ActionTiles(tiles: [
      {'key': 'approvals_center', 'count': 7, 'icon': 'how_to_reg', 'tone': 'primary', 'route': null, 'web': '/admin/approvals', 'label': {'ar': 'تسجيلات بانتظار المركز', 'en': 'Registrations waiting for the centre'}},
      {'key': 'errors', 'count': 2, 'icon': 'bug_report', 'tone': 'danger', 'route': null, 'web': '/admin/error-log', 'label': {'ar': 'أخطاء', 'en': 'Open system errors'}},
    ])));
    expect(find.text('Waiting for you'), findsOneWidget);
    expect(find.text('7'), findsOneWidget);
    expect(find.text('Open system errors'), findsOneWidget);

    await tester.pumpWidget(_wrap(const ActionTiles(tiles: [])));
    expect(find.text('Nothing is waiting for you right now'), findsOneWidget);
  });

  testWidgets('today\'s sessions list the program and place; only a trainer gets the attendance button', (tester) async {
    final rows = [
      {'id': 's1', 'program': 'Leadership', 'title': 'Day 2', 'location': 'Room 4', 'starts_at': '2030-01-10T07:00:00Z', 'ends_at': '2030-01-10T09:00:00Z'},
    ];
    await tester.pumpWidget(_wrap(TodaySessions(sessions: rows, trainer: true)));
    expect(find.text('Leadership'), findsOneWidget);
    expect(find.text('Room 4'), findsOneWidget);
    expect(find.text('Take trainee attendance'), findsOneWidget);

    await tester.pumpWidget(_wrap(TodaySessions(sessions: rows, trainer: false)));
    expect(find.text('Take trainee attendance'), findsNothing);

    await tester.pumpWidget(_wrap(const TodaySessions(sessions: [], trainer: true)));
    expect(find.text('No sessions today'), findsOneWidget);
    expect(find.text('Take trainee attendance'), findsNothing);
  });
}
