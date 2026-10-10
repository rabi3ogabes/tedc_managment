import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/features/home/upcoming_programs.dart';

Widget _wrap(Widget child) => MaterialApp(
      locale: const Locale('en'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
      home: Scaffold(body: SingleChildScrollView(child: child)),
    );

Map<String, dynamic> _row(String title, String mode) => {
      'registration_id': 'r-$title',
      'registration_status': 'approved',
      'mode': mode,
      'next_session_at': null,
      'program': {'title': title, 'start_date': '2030-01-10'},
    };

void main() {
  test('the new strings exist in both languages', () {
    for (final lang in ['ar', 'en']) {
      for (final key in ['home.myPrograms', 'home.noModePrograms', 'forme.mine', 'forme.general', 'forme.recommended', 'forme.registered', 'forme.onlyYours']) {
        expect(S(lang).t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });

  testWidgets('the slider opens on the first tab that has programs and switches by tab', (tester) async {
    await tester.pumpWidget(_wrap(UpcomingProgramsSlider(items: [_row('Online one', 'online'), _row('Hybrid one', 'hybrid')])));
    await tester.pumpAndSettle();
    // In person has none, so the slider opens on online.
    expect(find.text('Online one'), findsOneWidget);
    expect(find.text('Hybrid one'), findsNothing);

    await tester.tap(find.textContaining('Hybrid'));
    await tester.pumpAndSettle();
    expect(find.text('Hybrid one'), findsOneWidget);

    await tester.tap(find.textContaining('In person'));
    await tester.pumpAndSettle();
    expect(find.text('No upcoming programs of this kind.'), findsOneWidget);
  });

  testWidgets('with nothing registered the slider takes no space', (tester) async {
    await tester.pumpWidget(_wrap(const UpcomingProgramsSlider(items: [])));
    expect(find.byType(PageView), findsNothing);
  });
}
