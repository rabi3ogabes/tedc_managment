import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/features/home/top_cards.dart';

Widget _wrap(Widget child) => MaterialApp(
      locale: const Locale('en'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
      home: Scaffold(body: SingleChildScrollView(child: child)),
    );

void main() {
  test('the strings of the new cards exist in both languages', () {
    for (final lang in ['ar', 'en']) {
      for (final key in ['hours.title', 'hours.noTarget', 'hours.center', 'hours.internal', 'hours.external', 'hours.knowledge_transfer', 'todo.title', 'todo.tasks', 'todo.needs', 'todo.allDone', 'progress.title', 'cs.total', 'cs.thisYear', 'cs.hours']) {
        expect(S(lang).t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });

  testWidgets('the tasks card lists only what is waiting, and says so when nothing is', (tester) async {
    await tester.pumpWidget(_wrap(const TodoCard(todo: {'tasks': 2, 'changes': 0, 'evaluations': 1, 'surveys': 0}, pendingNeeds: 3)));
    expect(find.text('Tasks to do'), findsOneWidget);
    expect(find.text('Evaluations to do'), findsOneWidget);
    expect(find.text('Needs surveys'), findsOneWidget);
    expect(find.text('Tasks to fix'), findsNothing);
    expect(find.text('6'), findsOneWidget);

    await tester.pumpWidget(_wrap(const TodoCard(todo: {}, pendingNeeds: 0)));
    expect(find.text('Nothing is waiting. Well done!'), findsOneWidget);
  });

  testWidgets('the certificates summary counts issued certificates, this year and hours', (tester) async {
    final year = DateTime.now().year;
    await tester.pumpWidget(_wrap(CertificatesSummary(items: [
      {'issued_at': '$year-01-05T10:00:00Z', 'hours': 10},
      {'issued_at': '${year - 2}-03-01T10:00:00Z', 'hours': 6},
      {'issued_at': null, 'hours': 99},
    ])));
    expect(find.text('2'), findsWidgets);
    expect(find.text('16'), findsOneWidget);
    expect(find.text('1'), findsOneWidget);
  });
}
