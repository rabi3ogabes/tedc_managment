/// Build-time configuration, supplied with `--dart-define`:
///
///   flutter run --dart-define=API_URL=https://api.tedc.edu.qa/api/v1 \
///               --dart-define=SUPABASE_URL=... --dart-define=SUPABASE_ANON_KEY=...
class AppConfig {
  static const apiUrl = String.fromEnvironment('API_URL', defaultValue: 'http://10.0.2.2:8000/api/v1');
  static const supabaseUrl = String.fromEnvironment('SUPABASE_URL');
  static const supabaseAnonKey = String.fromEnvironment('SUPABASE_ANON_KEY');

  static bool get realtimeEnabled => supabaseUrl.isNotEmpty && supabaseAnonKey.isNotEmpty;
}
