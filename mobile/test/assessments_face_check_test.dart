import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/assessment/assessments_screen.dart';

void main() {
  testWidgets('an exam with the camera presence check is sent to a computer, not started in the app', (tester) async {
    await tester.pumpWidget(ProviderScope(
      overrides: [
        getProvider(AssessmentsScreen.path).overrideWith((ref) async => {
              'data': [
                {'id': 'a1', 'title_en': 'Camera exam', 'title_ar': 'اختبار', 'program': 'P', 'attempts_used': 0, 'max_attempts': 2, 'open': true, 'face_check': true, 'face_fallback': 'centre'},
                {'id': 'a2', 'title_en': 'Plain exam', 'title_ar': 'اختبار', 'program': 'P', 'attempts_used': 0, 'max_attempts': 2, 'open': true, 'face_check': false},
              ],
            }),
        getProvider('/features').overrideWith((ref) async => {'data': {'flags': <String, bool>{}}}),
      ],
      child: const MaterialApp(home: AssessmentsScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Start'), findsOneWidget, reason: 'only the exam without the camera check can start here');
    expect(find.textContaining('computer with a camera'), findsOneWidget);
  });
}
