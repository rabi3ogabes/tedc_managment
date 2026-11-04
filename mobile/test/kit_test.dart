import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/course/kit_screen.dart';
import 'package:tedc_mobile/features/course/kit_types.dart';

/// The kit (حقيبة) of an online programme: every kind of lesson has its name and icon, the overview counts them, and the trainer opens
/// the whole kit, read-only.
const _kit = {
  'data': {
    'program': {'id': 'p1', 'code': 'AFL-150', 'title': 'Assessment for learning', 'delivery_mode': 'online'},
    'modules': [
      {
        'id': 'm1',
        'title': 'Unit 1',
        'description': '',
        'lessons': [
          {'id': 'l1', 'type': 'video', 'title': 'Welcome video', 'description': 'The programme in five minutes.', 'is_required': true, 'duration_seconds': 300, 'slide_count': null, 'questions': null, 'pass_percent': null, 'file_name': 'welcome.mp4', 'url': 'https://example.test/welcome.mp4'},
          {'id': 'l2', 'type': 'presentation', 'title': 'Unit slides', 'description': '', 'is_required': true, 'duration_seconds': 600, 'slide_count': 24, 'questions': null, 'pass_percent': null, 'file_name': 'slides.pptx', 'url': null},
        ],
      },
      {
        'id': 'm2',
        'title': 'Check',
        'description': '',
        'lessons': [
          {'id': 'l3', 'type': 'quiz', 'title': 'Final quiz', 'description': '', 'is_required': true, 'duration_seconds': 0, 'slide_count': null, 'questions': 8, 'pass_percent': 70, 'file_name': null, 'url': null},
          {'id': 'l4', 'type': 'interactive-future', 'title': 'A newer kind', 'description': '', 'is_required': false, 'duration_seconds': 0, 'slide_count': null, 'questions': null, 'pass_percent': null, 'file_name': null, 'url': null},
        ],
      },
    ],
    'totals': {'modules': 2, 'lessons': 4, 'minutes': 15, 'by_type': {'video': 1, 'presentation': 1, 'quiz': 1}},
  },
};

void main() {
  test('every kind of lesson has an icon and a name in both languages', () {
    for (final lang in ['ar', 'en']) {
      final s = S(lang);
      for (final t in kitTypes) {
        expect(s.t('kit.type.$t'), isNot('kit.type.$t'), reason: '$lang $t');
        // the article's icon is the fallback for a kind the app does not know; every other kind has its own
        if (t != 'article') expect(kitIcon(t), isNot(kitIcon('nothing')), reason: '$t has its own icon');
      }
      for (final key in ['kit.title', 'kit.contents', 'kit.readOnly', 'kit.minutes', 'kit.questions', 'kit.webLesson', 'kit.webLessonHint', 'kit.openWeb', 'kit.openLink']) {
        expect(s.t(key), isNot(key), reason: '$lang $key');
      }
    }
  });

  test('the overview counts each kind in kit order, and a kind the app does not know counts as an article', () {
    final counts = kitCounts([
      {'type': 'quiz'},
      {'type': 'video'},
      {'type': 'video'},
      {'type': 'something-new'},
      {'type': 'external'},
    ]);
    expect(counts.keys.toList(), ['video', 'quiz', 'article', 'external']);
    expect(counts['video'], 2);
    expect(counts['article'], 1);
  });

  test('interactive units, learning tools and external courses run in the browser; the rest play in the app', () {
    expect([for (final t in kitTypes) if (kitRunsOnWeb(t)) t], ['package', 'lti', 'external']);
  });

  testWidgets('the trainer opens the whole kit and a lesson shows what it holds', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [getProvider('/me/programs/p1/kit').overrideWith((ref) async => _kit)],
      child: const MaterialApp(home: KitScreen(programId: 'p1')),
    ));
    await tester.pump();
    await tester.pump();
    expect(find.text('Assessment for learning'), findsOneWidget);
    expect(find.text('Video · 1'), findsOneWidget);
    expect(find.text('Presentation · 1'), findsOneWidget);
    expect(find.text('Quiz · 1'), findsOneWidget);
    expect(find.text('Article · 1'), findsOneWidget);
    expect(find.text('15 min'), findsOneWidget);
    expect(find.text('Unit 1'), findsOneWidget);
    expect(find.text('Final quiz'), findsOneWidget);

    // The lesson is further down than the test screen is tall.
    await tester.ensureVisible(find.text('Final quiz'));
    await tester.pump();
    await tester.tap(find.text('Final quiz'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.text('Questions: 8 · pass at 70%'), findsOneWidget);
  });
}
