import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Build-time configuration, supplied with `--dart-define`:
///
///   flutter build apk --dart-define=API_URL=https://your-project.vercel.app/api/v1 \
///                     --dart-define=SUPABASE_URL=... --dart-define=SUPABASE_PUBLISHABLE_KEY=...
///
/// The server address can also be changed inside the app (long-press the emblem on the sign-in screen),
/// which is handy for testing an APK against another environment.
class AppConfig {
  static const defaultApiUrl = String.fromEnvironment('API_URL', defaultValue: 'https://tedc-managment.vercel.app/api/v1');
  static const supabaseUrl = String.fromEnvironment('SUPABASE_URL');
  // Publishable key (sb_publishable_…) or legacy anon key — never the secret key.
  static const _publishableKey = String.fromEnvironment('SUPABASE_PUBLISHABLE_KEY');
  static const _legacyAnonKey = String.fromEnvironment('SUPABASE_ANON_KEY');
  static String get supabaseAnonKey => _publishableKey.isNotEmpty ? _publishableKey : _legacyAnonKey;
  static bool get realtimeEnabled => supabaseUrl.isNotEmpty && supabaseAnonKey.isNotEmpty;

  /// Demo accounts on the sign-in screen (for test builds): `--dart-define=SHOW_DEMO_ACCOUNTS=false` hides them.
  static const showDemoAccounts = bool.fromEnvironment('SHOW_DEMO_ACCOUNTS', defaultValue: true);
  static const appVersion = String.fromEnvironment('APP_VERSION', defaultValue: '1.0.0');

  static const _serverKey = 'tedc.server';
  static const _storage = FlutterSecureStorage();
  static String? _override;

  static String get apiUrl => _override ?? defaultApiUrl;
  static bool get isCustomServer => _override != null;

  static Future<void> load() async {
    try {
      _override = await _storage.read(key: _serverKey);
    } catch (_) {
      _override = null;
    }
  }

  /// Saves a custom API address, or restores the built-in one with `null`.
  static Future<void> setServer(String? url) async {
    final normalized = url == null || url.trim().isEmpty ? null : normalizeApiUrl(url);
    _override = normalized == defaultApiUrl ? null : normalized;
    if (_override == null) {
      await _storage.delete(key: _serverKey);
    } else {
      await _storage.write(key: _serverKey, value: _override);
    }
  }

  /// Accepts "https://site.app", "https://site.app/api" or ".../api/v1" and returns the ".../api/v1" base.
  static String normalizeApiUrl(String input) {
    var url = input.trim();
    if (!url.startsWith('http://') && !url.startsWith('https://')) url = 'https://$url';
    url = url.replaceAll(RegExp(r'/+$'), '');
    if (url.endsWith('/api/v1')) return url;
    if (url.endsWith('/api')) return '$url/v1';
    return '$url/api/v1';
  }
}
