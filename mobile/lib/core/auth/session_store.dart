import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class Session {
  const Session({required this.accessToken, required this.refreshToken, required this.expiresAt});

  final String accessToken;
  final String refreshToken;
  final DateTime expiresAt;

  Map<String, dynamic> toJson() => {'access_token': accessToken, 'refresh_token': refreshToken, 'expires_at': expiresAt.toIso8601String()};

  factory Session.fromJson(Map<String, dynamic> json) => Session(
        accessToken: json['access_token'] as String,
        refreshToken: json['refresh_token'] as String,
        expiresAt: DateTime.parse(json['expires_at'] as String),
      );
}

/// Tokens are kept in the platform keystore (Keychain / Android Keystore), never in plain prefs.
class SessionStore {
  SessionStore([FlutterSecureStorage? storage]) : _storage = storage ?? const FlutterSecureStorage();

  static const _key = 'tedc.session';
  static const _localeKey = 'tedc.locale';
  final FlutterSecureStorage _storage;
  Session? _cached;

  Future<Session?> read() async {
    if (_cached != null) return _cached;
    final raw = await _storage.read(key: _key);
    if (raw == null) return null;
    try {
      return _cached = Session.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  Future<void> write(Session? session) async {
    _cached = session;
    if (session == null) {
      await _storage.delete(key: _key);
    } else {
      await _storage.write(key: _key, value: jsonEncode(session.toJson()));
    }
  }

  static const _roleKey = 'tedc.active_role';
  static const _bioKey = 'tedc.biometric_login';
  static const _bioOfferedKey = 'tedc.biometric_offered';

  /// The role grant the person works in on this phone (sent as X-Active-Role).
  Future<String?> readActiveRole() => _storage.read(key: _roleKey);

  Future<void> writeActiveRole(String? id) => id == null || id.isEmpty ? _storage.delete(key: _roleKey) : _storage.write(key: _roleKey, value: id);

  Future<bool> readBiometricLogin() async => (await _storage.read(key: _bioKey)) == '1';

  Future<void> writeBiometricLogin(bool on) => _storage.write(key: _bioKey, value: on ? '1' : '0');

  /// The offer to switch it on is made once.
  Future<bool> biometricOffered() async => (await _storage.read(key: _bioOfferedKey)) == '1';

  Future<void> markBiometricOffered() => _storage.write(key: _bioOfferedKey, value: '1');

  Future<String?> readLocale() => _storage.read(key: _localeKey);

  Future<void> writeLocale(String code) => _storage.write(key: _localeKey, value: code);
}
