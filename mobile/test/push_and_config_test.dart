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
