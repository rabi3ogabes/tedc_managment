/// Build-time configuration, supplied with `--dart-define`:
///
///   flutter run --dart-define=API_URL=https://api.tedc.edu.qa/api/v1 \
///               --dart-define=SUPABASE_URL=... --dart-define=SUPABASE_PUBLISHABLE_KEY=...
class AppConfig {
  static const apiUrl = String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8000/api/v1');
  static const supabaseUrl = String.fromEnvironment('SUPABASE_URL');
  // Publishable key (sb_publishable_…) or legacy anon key — never the secret key.
  static const _publishableKey = String.fromEnvironment('SUPABASE_PUBLISHABLE_KEY');
  static const _legacyAnonKey = String.fromEnvironment('SUPABASE_ANON_KEY');
  static String get supabaseAnonKey => _publishableKey.isNotEmpty ? _publishableKey : _legacyAnonKey;

  static bool get realtimeEnabled => supabaseUrl.isNotEmpty && supabaseAnonKey.isNotEmpty;
}
