import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/core/notification_route.dart';

void main() {
  test('a trainer proposal notification opens the assignment form', () {
    expect(NotificationRoute.resolve('trainer.assignment_proposed', {'route': '/assignments'}), '/assignments');
  });

  test('group and assignment strings exist in both languages', () {
    for (final lang in ['ar', 'en']) {
      final s = S(lang);
      for (final key in ['groups.choose', 'assignments.title', 'assignments.submit', 'assignments.st.proposed']) {
        expect(s.t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });
}
