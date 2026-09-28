import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/needs/needs_survey_screen.dart';

void main() {
  final questions = <Map<String, dynamic>>[
    {'id': 's1', 'type': 'section', 'title': 'A'},
    {'id': 'ai', 'type': 'yes_no', 'title': 'AI?', 'required': true},
    {'id': 'why', 'type': 'long_text', 'title': 'Why not?', 'required': true, 'show_if': {'question': 'ai', 'op': 'equals', 'value': 'no'}},
    {'id': 's2', 'type': 'section', 'title': 'B'},
    {'id': 'm', 'type': 'matrix', 'title': 'Level', 'required': true, 'rows': [{'id': 'r1', 'label': 'x'}, {'id': 'r2', 'label': 'y'}]},
  ];

  test('conditional questions follow the answers', () {
    expect(isVisible(questions[2], {'ai': 'no'}), isTrue);
    expect(isVisible(questions[2], {'ai': 'yes'}), isFalse);
    expect(isVisible(questions[2], {}), isFalse);
  });

  test('required answers include every matrix statement', () {
    expect(missingRequired(questions, {'ai': 'no'}), ['why', 'm']);
    expect(missingRequired(questions, {'ai': 'yes', 'm': {'r1': 2}}), ['m']);
    expect(missingRequired(questions, {'ai': 'yes', 'm': {'r1': 2, 'r2': 4}}), isEmpty);
  });

  testWidgets('full-screen form renders every question type and validates', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 3;
    addTearDown(tester.view.reset);
    await _pumpForm(tester);
    await tester.tap(find.text('ابدأ الإجابة'));
    await tester.pumpAndSettle();
    expect(find.text('M *'), findsOneWidget);
    await tester.tap(find.text('إرسال الإجابات'));
    await tester.pump();
    expect(find.text('يرجى الإجابة عن الأسئلة المطلوبة'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  test('pages split at sections or one question per page', () {
    expect(paginate(questions, false).length, 2);
    final single = paginate(questions, true);
    expect(single.length, 3);
    expect(single.first.first['id'], 's1');
  });
}

// Renders every question type in the full-screen form.
Future<void> _pumpForm(WidgetTester tester) async {
  final survey = {
    'data': {
      'id': 'x', 'title': 'استبانة', 'open': true, 'anonymous': false, 'questions_count': 9, 'estimated_minutes': 3, 'answers': null,
      'settings': {'show_progress': true},
      'questions': [
        {'id': 'm', 'type': 'matrix', 'title': 'M', 'required': true, 'scale': {'min': 1, 'max': 5, 'min_label': 'low', 'max_label': 'high'}, 'rows': [{'id': 'r1', 'label': 'row'}]},
        {'id': 'r', 'type': 'ranking', 'title': 'R', 'options': [{'id': 'a', 'label': 'A'}, {'id': 'b', 'label': 'B'}]},
        {'id': 'n', 'type': 'nps', 'title': 'N'},
        {'id': 'st', 'type': 'rating', 'title': 'S', 'scale': {'min': 1, 'max': 5}},
        {'id': 'sc', 'type': 'scale', 'title': 'Sc', 'scale': {'min': 1, 'max': 5}},
        {'id': 'mu', 'type': 'multiple', 'title': 'Mu', 'max_select': 1, 'options': [{'id': 'a', 'label': 'A'}, {'id': 'b', 'label': 'B'}]},
        {'id': 'dd', 'type': 'dropdown', 'title': 'D', 'options': [{'id': 'a', 'label': 'A'}, {'id': 'b', 'label': 'B'}]},
        {'id': 'yn', 'type': 'yes_no', 'title': 'Y'},
        {'id': 'tx', 'type': 'long_text', 'title': 'T'},
      ],
    },
  };
  await tester.pumpWidget(ProviderScope(
    overrides: [getProvider('/me/needs-surveys/x').overrideWith((ref) async => survey)],
    child: const MaterialApp(
      locale: Locale('ar'),
      supportedLocales: [Locale('ar'), Locale('en')],
      localizationsDelegates: [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
      home: NeedsSurveyScreen(id: 'x'),
    ),
  ));
  await tester.pumpAndSettle();
}
