import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/config.dart';
import 'package:tedc_mobile/core/push/push_service.dart';

void main() {
  group('PushService.routeFor', () {
    test('opens the screen related to the notification type', () {
      expect(PushService.routeFor({'type': 'certificate.issued'}), '/certificates');
      expect(PushService.routeFor({'type': 'registration.approved'}), '/training');
      expect(PushService.routeFor({'type': 'task.approved'}), '/training');
      expect(PushService.routeFor({'type': 'session.reminder'}), '/training');
      expect(PushService.routeFor({'type': 'announcement'}), '/notifications');
    });

    test('opens the exact page the notification is about', () {
      expect(PushService.routeFor({'type': 'session.attendance_open', 'route': '/scan', 'session_id': 's1'}), '/scan');
      expect(PushService.routeFor({'type': 'session.attendance_missed', 'route': '/sessions/s1'}), '/sessions/s1');
      expect(PushService.routeFor({'type': 'session.reminder', 'session_id': 's1'}), '/sessions/s1');
      expect(PushService.routeFor({'type': 'task.approved', 'task_id': 't1'}), '/tasks/t1');
      expect(PushService.routeFor({'type': 'impact.survey', 'survey_id': 'i1'}), '/surveys/i1');
      expect(PushService.routeFor({'type': 'registration.approved', 'registration_id': 'r1'}), '/registrations/r1');
      expect(PushService.routeFor({'type': 'survey.open', 'route': '/my-program/p1'}), '/my-program/p1');
      expect(PushService.routeFor({'type': 'certificate.available'}), '/certificates');
    });

    test('accepts only known routes from the server', () {
      expect(PushService.routeFor({'type': 'test', 'route': '/profile'}), '/profile');
      expect(PushService.routeFor({'type': 'test', 'route': 'https://evil.example'}), '/notifications');
    });
  });

  group('AppConfig.normalizeApiUrl', () {
    test('turns a site address into the API base', () {
      expect(AppConfig.normalizeApiUrl('tedc.vercel.app'), 'https://tedc.vercel.app/api/v1');
      expect(AppConfig.normalizeApiUrl('https://tedc.vercel.app/'), 'https://tedc.vercel.app/api/v1');
      expect(AppConfig.normalizeApiUrl('https://tedc.vercel.app/api'), 'https://tedc.vercel.app/api/v1');
      expect(AppConfig.normalizeApiUrl(' http://10.0.2.2:8000/api/v1 '), 'http://10.0.2.2:8000/api/v1');
    });
  });
}
