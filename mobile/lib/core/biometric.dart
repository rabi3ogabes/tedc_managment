import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:local_auth/local_auth.dart';

import 'providers.dart';

enum BiometricResult { success, cancelled, lockedOut, unavailable }

/// The phone's own security check: fingerprint, face, or — when the phone has none of those — its PIN, pattern or passcode.
/// Nothing is stored by the app; the phone only answers yes or no.
class BiometricService {
  BiometricService([LocalAuthentication? auth]) : _auth = auth ?? LocalAuthentication();

  final LocalAuthentication _auth;

  /// A prompt is on screen: the app is briefly "in the background" and must not lock itself because of it.
  static bool prompting = false;

  Future<bool> available() async {
    try {
      return await _auth.isDeviceSupported();
    } catch (_) {
      return false;
    }
  }

  Future<BiometricResult> authenticate(String reason) async {
    prompting = true;
    try {
      final ok = await _auth.authenticate(
        localizedReason: reason,
        options: const AuthenticationOptions(biometricOnly: false, stickyAuth: true, useErrorDialogs: true),
      );
      return ok ? BiometricResult.success : BiometricResult.cancelled;
    } on PlatformException catch (e) {
      return switch (e.code) {
        'LockedOut' || 'PermanentlyLockedOut' => BiometricResult.lockedOut,
        'NotAvailable' || 'NotEnrolled' || 'PasscodeNotSet' => BiometricResult.unavailable,
        _ => BiometricResult.cancelled,
      };
    } catch (_) {
      return BiometricResult.unavailable;
    } finally {
      // The app is resumed a moment after the prompt closes.
      Future<void>.delayed(const Duration(seconds: 1), () => prompting = false);
    }
  }
}

final biometricServiceProvider = Provider<BiometricService>((ref) => BiometricService());

/// "Sign in with fingerprint": when on, the app asks for the phone's security check when it opens and after it was away for a while.
class AppLockController extends Notifier<bool> {
  static const awayLimit = Duration(seconds: 90);

  bool enabled = false;
  DateTime? _leftAt;

  /// true = locked (the unlock screen is shown).
  @override
  bool build() => false;

  /// Called once at launch, after the saved session is known. Never throws: a storage problem must not lock the user out.
  Future<void> init() async {
    try {
      if (ref.read(authProvider).value == null) return;
      enabled = await ref.read(sessionStoreProvider).readBiometricLogin();
      if (enabled && await ref.read(biometricServiceProvider).available()) {
        state = true;
      } else if (enabled) {
        await setEnabled(false);   // the phone no longer has any lock: nothing to ask for
      }
    } catch (_) {
      state = false;
    }
  }

  Future<void> setEnabled(bool on) async {
    enabled = on;
    await ref.read(sessionStoreProvider).writeBiometricLogin(on);
    if (!on) state = false;
  }

  void unlock() => state = false;

  void left() => _leftAt = DateTime.now();

  /// Coming back after a while locks the app again.
  void returned() {
    final at = _leftAt;
    _leftAt = null;
    if (enabled && !BiometricService.prompting && at != null && DateTime.now().difference(at) >= awayLimit && ref.read(authProvider).value != null) state = true;
  }
}

final appLockProvider = NotifierProvider<AppLockController, bool>(AppLockController.new);
