import 'dart:async';
import 'dart:io' show Platform;

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';

import 'auth/session_store.dart';
import 'config.dart';

/// Reports the app's own errors to the administrator's error log: uncaught Flutter and platform errors and failed
/// server calls (5xx). Reports are de-duplicated, rate-limited and sent in the background; the reporter never throws.
class ErrorReporter {
  ErrorReporter._();

  static final _store = SessionStore();
  static final _seen = <String, DateTime>{};
  static int _sent = 0;
  static DateTime _window = DateTime.now();
  static String _route = '';

  /// The screen the user is on (set by the router observer when available).
  static void route(String value) => _route = value;

  /// Call once at start, after bindings are initialised.
  static void install() {
    final previous = FlutterError.onError;
    FlutterError.onError = (details) {
      previous?.call(details);
      report(details.exception, details.stack, level: 'critical', context: {'library': details.library ?? ''});
    };
    PlatformDispatcher.instance.onError = (error, stack) {
      report(error, stack, level: 'critical');
      return true;
    };
  }

  static void report(Object error, StackTrace? stack, {String level = 'error', int? statusCode, Map<String, dynamic>? context}) {
    try {
      final message = error.toString();
      if (message.length < 3 || RegExp(r'SocketException|HandshakeException|Connection (closed|reset|refused)|Failed host lookup|TimeoutException', caseSensitive: false).hasMatch(message)) return;
      final now = DateTime.now();
      if (now.difference(_window).inMinutes >= 1) {
        _window = now;
        _sent = 0;
      }
      if (_sent >= 15) return;
      final key = '$message|$_route';
      if (_seen[key] != null && now.difference(_seen[key]!).inSeconds < 60) return;
      _seen[key] = now;
      _sent++;
      unawaited(_send({
        'source': 'app',
        'level': level,
        'message': message.length > 1500 ? message.substring(0, 1500) : message,
        'stack': stack?.toString(),
        'route': _route,
        'status_code': statusCode,
        'app_version': AppConfig.appVersion,
        'device': {'platform': kIsWeb ? 'web' : Platform.operatingSystem, 'os': kIsWeb ? '' : Platform.operatingSystemVersion},
        'context': context,
      }));
    } catch (_) {/* never throw from the reporter */}
  }

  static Future<void> _send(Map<String, dynamic> payload) async {
    try {
      final session = await _store.read();
      await Dio(BaseOptions(baseUrl: AppConfig.apiUrl, connectTimeout: const Duration(seconds: 8), sendTimeout: const Duration(seconds: 8), headers: {
        'Accept': 'application/json',
        if (session != null) 'Authorization': 'Bearer ${session.accessToken}',
      })).post('/client-errors', data: {'errors': [payload]});
    } catch (_) {/* offline: dropped */}
  }
}
