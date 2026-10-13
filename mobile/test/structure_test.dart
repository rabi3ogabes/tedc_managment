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

  test('counts take the right Arabic and English forms', () {
    final ar = S('ar');
    final en = S('en');
    expect(ar.count('count.lessons', 1), 'درس واحد');
    expect(ar.count('count.lessons', 2), 'درسان');
    expect(ar.count('count.lessons', 3), '3 دروس');
    expect(ar.count('count.lessons', 10), '10 دروس');
    expect(ar.count('count.lessons', 11), '11 درساً');
    expect(ar.count('count.lessons', 100), '100 درس');
    expect(ar.count('count.lessons', 103), '103 دروس');
    expect(ar.count('count.days', 9), '9 أيام');
    expect(ar.count('count.questions', 12), '12 سؤالاً');
    expect(ar.count('count.minutes', 30), '30 دقيقة');
    expect(en.count('count.lessons', 1), '1 lesson');
    expect(en.count('count.lessons', 2), '2 lessons');
    expect(en.count('count.days', 9), '9 days');
  });
}
