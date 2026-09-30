import 'dart:async';
import 'dart:io' show Platform;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../providers.dart';

enum PushStatus { unsupported, notConfigured, denied, enabled, error }

/// A notification received while the app is open (shown as an in-app banner).
class PushMessage {
  const PushMessage({required this.title, this.body, required this.route});

  final String title;
  final String? body;
  final String route;
}

/// Push notifications through Firebase Cloud Messaging.
///
/// Firebase is configured by administrators on the dashboard (Settings → Notifications) and delivered to the
/// app through `/public/mobile-config` — no google-services.json is bundled, so switching Firebase projects
/// needs no new APK. The options are also cached natively so a closed app can still receive notifications.
class PushService {
  PushService(this._ref);

  static const _native = MethodChannel('tedc/push');

  final Ref _ref;
  final _messages = StreamController<PushMessage>.broadcast();
  final _status = ValueNotifier<PushStatus>(PushStatus.notConfigured);
  final _detail = ValueNotifier<String?>(null);
  Timer? _retry;
  final List<StreamSubscription<dynamic>> _subscriptions = [];
  String? _token;
  bool _starting = false;

  Stream<PushMessage> get messages => _messages.stream;
  ValueListenable<PushStatus> get status => _status;

  /// Why the last attempt failed (which step, and the error), for the profile screen.
  ValueListenable<String?> get detail => _detail;

  /// Where a tap on a notification should lead, from its type (server-provided route as fallback).
  static String routeFor(Map<String, dynamic> data) {
    final type = (data['type'] ?? '').toString();
    final surveyId = (data['needs_survey_id'] ?? '').toString();
    if (type.startsWith('needs_survey') && surveyId.isNotEmpty) return '/needs-surveys/$surveyId';
    if (type.startsWith('certificate')) return '/certificates';
    if (type.startsWith('registration') || type.startsWith('task') || type.startsWith('impact') || type.startsWith('session')) return '/training';
    final route = (data['route'] ?? '').toString();
    const known = ['/home', '/programs', '/training', '/certificates', '/notifications', '/profile'];
    return known.contains(route) ? route : '/notifications';
  }

  bool get _supported => !kIsWeb && (Platform.isAndroid || Platform.isIOS);

  /// Keeps trying while the platform is not configured yet or a step failed, so a phone opened before the
  /// administrator switched sending on registers by itself a little later.
  PushStatus _finish(PushStatus status) {
    _status.value = status;
    final settled = status == PushStatus.enabled || status == PushStatus.unsupported || status == PushStatus.denied;
    if (settled) {
      _retry?.cancel();
      _retry = null;
    } else {
      _retry ??= Timer.periodic(const Duration(seconds: 30), (_) => start());
    }
    return status;
  }

  /// Configures Firebase from the platform and registers this device. Safe to call repeatedly.
  Future<PushStatus> start() async {
    if (!_supported) return _finish(PushStatus.unsupported);
    if (_starting) return _status.value;
    if (_status.value == PushStatus.enabled && _token != null) return _status.value;
    _starting = true;
    var step = 'config';
    try {
      final api = _ref.read(apiProvider);
      final config = Map<String, dynamic>.from(((await api.get('/public/mobile-config')) as Map)['data'] as Map);
      final push = Map<String, dynamic>.from(config['push'] as Map);
      if (push['enabled'] != true || push['options'] == null) {
        await _native.invokeMethod('clear');
        _detail.value = null;
        return _finish(PushStatus.notConfigured);
      }

      step = 'firebase';
      final o = Map<String, dynamic>.from(push['options'] as Map);
      final android = Map<String, dynamic>.from((push['android'] ?? {}) as Map);
      final isArabic = _ref.read(localeProvider).languageCode == 'ar';
      final options = FirebaseOptions(
        apiKey: o['api_key'] as String,
        appId: o['app_id'] as String,
        messagingSenderId: o['messaging_sender_id'] as String,
        projectId: (o['project_id'] ?? '') as String,
        storageBucket: o['storage_bucket'] as String?,
      );

      if (Platform.isAndroid) {
        await _native.invokeMethod('configure', {
          'apiKey': options.apiKey,
          'appId': options.appId,
          'messagingSenderId': options.messagingSenderId,
          'projectId': options.projectId,
          'storageBucket': options.storageBucket,
          'channelName': (isArabic ? android['channel_name_ar'] : android['channel_name_en']) ?? 'TEDC',
        });
      }

      if (Firebase.apps.isEmpty) {
        await Firebase.initializeApp(options: options);
      }

      step = 'permission';
      final messaging = FirebaseMessaging.instance;
      final permission = await messaging.requestPermission();
      if (permission.authorizationStatus == AuthorizationStatus.denied) {
        _detail.value = null;
        return _finish(PushStatus.denied);
      }

      step = 'token';
      await messaging.setForegroundNotificationPresentationOptions(alert: false, badge: true, sound: true);
      final token = await messaging.getToken();
      if (token == null || token.isEmpty) throw StateError('Firebase returned no device token');

      step = 'register';
      final failure = await _register(token);
      if (failure != null) throw StateError(failure);

      if (_subscriptions.isEmpty) {
        _subscriptions
          ..add(messaging.onTokenRefresh.listen(_register))
          ..add(FirebaseMessaging.onMessage.listen(_onForeground))
          ..add(FirebaseMessaging.onMessageOpenedApp.listen(_onOpened));
        final initial = await messaging.getInitialMessage();
        if (initial != null) _onOpened(initial);
      }
      _detail.value = null;
      return _finish(PushStatus.enabled);
    } catch (e) {
      final message = e.toString().split('\n').first;
      debugPrint('Push setup failed at $step: $e');
      _detail.value = '$step: ${message.length > 160 ? message.substring(0, 160) : message}';
      return _finish(PushStatus.error);
    } finally {
      _starting = false;
    }
  }

  /// Unregisters this device before signing out, so the next user does not receive the previous one's alerts.
  Future<void> stop() async {
    _retry?.cancel();
    _retry = null;
    final token = _token;
    _token = null;
    _status.value = PushStatus.notConfigured;
    if (token == null) return;
    try {
      await _ref.read(apiProvider).dio.delete('/me/devices', data: {'token': token});
    } catch (_) {/* offline: the server prunes stale tokens */}
    try {
      await FirebaseMessaging.instance.deleteToken();
    } catch (_) {}
  }

  /// Tells the server about this device. Returns null on success, otherwise the reason.
  Future<String?> _register(String token) async {
    _token = token;
    try {
      await _ref.read(apiProvider).post('/me/devices', {
        'token': token,
        'platform': Platform.isIOS ? 'ios' : 'android',
        'locale': _ref.read(localeProvider).languageCode,
        'app_version': AppConfig.appVersion,
        'device_name': Platform.operatingSystem,
      });
      return null;
    } on ApiException catch (e) {
      debugPrint('Device registration failed: ${e.message}');
      return 'server ${e.status ?? ''}: ${e.message}';
    }
  }

  /// Re-registers so notifications follow the app language.
  Future<void> updateLocale() async {
    if (_token != null) await _register(_token!);
  }

  void _onForeground(RemoteMessage message) {
    final n = message.notification;
    if (n?.title == null) return;
    _messages.add(PushMessage(title: n!.title!, body: n.body, route: routeFor(message.data)));
  }

  final _opened = StreamController<String>.broadcast();

  /// Routes to open after the user taps a system notification.
  Stream<String> get openedRoutes => _opened.stream;

  void _onOpened(RemoteMessage message) => _opened.add(routeFor(message.data));
}

final pushServiceProvider = Provider<PushService>((ref) => PushService(ref));
