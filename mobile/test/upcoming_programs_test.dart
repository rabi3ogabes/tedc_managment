import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/features/home/upcoming_programs.dart';

Widget _wrap(Widget child) => MaterialApp(
      locale: const Locale('en'),
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
      home: Scaffold(body: SingleChildScrollView(padding: const EdgeInsets.all(20), child: child)),
    );

Map<String, dynamic> _row(String title, String mode) => {
      'registration_id': 'r-$title',
      'registration_status': 'approved',
      'mode': mode,
      'next_session_at': null,
      'program': {'title': title, 'start_date': '2030-01-10'},
    };

void main() {
  test('the strings of the home cards exist in both languages', () {
    for (final lang in ['ar', 'en']) {
      for (final key in ['home.myPrograms', 'home.openProgram', 'home.deckNext', 'home.deckPrev', 'profile.sessionsView', 'profile.viewList', 'profile.viewBoxes']) {
        expect(S(lang).t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });

  testWidgets('the deck shows the first program in front, with the next ones behind, and the arrows bring them forward', (tester) async {
    await tester.pumpWidget(_wrap(UpcomingProgramsSlider(items: [_row('One', 'online'), _row('Two', 'hybrid'), _row('Three', 'in_person')])));
    await tester.pumpAndSettle();
    expect(find.text('One'), findsOneWidget);
    // The two behind are drawn too (so they can peek out), only the front one takes touches.
    expect(find.text('Two'), findsOneWidget);
    expect(find.text('Three'), findsOneWidget);

    await tester.tap(find.byTooltip('Next program'));
    await tester.pumpAndSettle();
    // Two is in front now; One has gone to the back of the deck.
    final order = tester.widgetList<Text>(find.byType(Text)).map((t) => t.data).where((t) => t == 'One' || t == 'Two' || t == 'Three').toList();
    expect(order.last, 'Two');
  });

  testWidgets('swiping the front card away brings the next program forward', (tester) async {
    await tester.pumpWidget(_wrap(UpcomingProgramsSlider(items: [_row('One', 'online'), _row('Two', 'hybrid')])));
    await tester.pumpAndSettle();
    await tester.fling(find.text('One'), const Offset(-400, 0), 1500);
    await tester.pumpAndSettle();
    final order = tester.widgetList<Text>(find.byType(Text)).map((t) => t.data).where((t) => t == 'One' || t == 'Two').toList();
    expect(order.last, 'Two');
  });

  testWidgets('one program shows no arrows or dots, and nothing registered takes no space', (tester) async {
    await tester.pumpWidget(_wrap(UpcomingProgramsSlider(items: [_row('Only', 'online')])));
    await tester.pumpAndSettle();
    expect(find.text('Only'), findsOneWidget);
    expect(find.byTooltip('Next program'), findsNothing);

    await tester.pumpWidget(_wrap(const UpcomingProgramsSlider(items: [])));
    expect(find.text('My upcoming programs'), findsNothing);
  });
}
