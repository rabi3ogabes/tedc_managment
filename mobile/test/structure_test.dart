import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/core/notification_route.dart';

void main() {
  test('a trainer proposal notification opens the assignment form', () {
    expect(NotificationRoute.resolve('individual_need.decided', {'route': '/needs'}), '/my-needs');
    expect(NotificationRoute.resolve('registration.pending_manager', {}), '/approvals');
    expect(NotificationRoute.resolve('trainer.assignment_proposed', {'route': '/assignments'}), '/assignments');
  });

  test('group and assignment strings exist in both languages', () {
    for (final lang in ['ar', 'en']) {
      final s = S(lang);
      for (final key in ['groups.choose', 'assignments.title', 'assignments.submit', 'assignments.st.proposed', 'withdraw.button', 'approvals.title', 'myneeds.title', 'status.pending_manager', 'myqr.title', 'excuse.title', 'excuse.reason.sick_leave', 'trainerScan.in']) {
        expect(s.t(key), isNot(key), reason: '$lang is missing $key');
      }
    }
  });
}
