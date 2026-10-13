import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// A small store for secrets and offline state (the content key, the device id, the progress queue).
abstract class SecureKv {
  Future<String?> read(String key);

  Future<void> write(String key, String value);

  Future<void> delete(String key);
}

/// The platform keystore: the iOS Keychain (this device only, never synced or restored to another phone) and the
/// Android Keystore.
class KeystoreKv implements SecureKv {
  KeystoreKv([FlutterSecureStorage? storage])
      : _storage = storage ?? const FlutterSecureStorage(iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock_this_device));

  final FlutterSecureStorage _storage;

  @override
  Future<String?> read(String key) => _storage.read(key: key);

  @override
  Future<void> write(String key, String value) => _storage.write(key: key, value: value);

  @override
  Future<void> delete(String key) => _storage.delete(key: key);
}
