import 'package:tedc_mobile/core/offline/secure_kv.dart';

/// The keystore, in memory, for tests.
class MemoryKv implements SecureKv {
  final values = <String, String>{};

  @override
  Future<String?> read(String key) async => values[key];

  @override
  Future<void> write(String key, String value) async => values[key] = value;

  @override
  Future<void> delete(String key) async => values.remove(key);
}
