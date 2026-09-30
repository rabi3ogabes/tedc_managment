import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'dart:async';

import 'api/api_client.dart';
import 'api/response_cache.dart';
import 'auth/session_store.dart';
import 'models.dart';
import 'push/push_service.dart';

final sessionStoreProvider = Provider<SessionStore>((ref) => SessionStore());

/// Arabic is the default locale; the user's choice is persisted in secure storage.
class LocaleController extends Notifier<Locale> {
  @override
  Locale build() {
    ref.read(sessionStoreProvider).readLocale().then((code) {
      if (code == 'en' || code == 'ar') state = Locale(code!);
    });
    return const Locale('ar');
  }

  Future<void> set(String code) async {
    state = Locale(code);
    await ref.read(sessionStoreProvider).writeLocale(code);
    ref.read(pushServiceProvider).updateLocale();
  }

  Future<void> toggle() => set(state.languageCode == 'ar' ? 'en' : 'ar');
}

final localeProvider = NotifierProvider<LocaleController, Locale>(LocaleController.new);

final apiProvider = Provider<ApiClient>((ref) => ApiClient(
      ref.read(sessionStoreProvider),
      locale: () => ref.read(localeProvider).languageCode,
      onSessionExpired: () {
        ResponseCache.instance.clear();
        _resetGetState();
        ref.invalidate(authProvider);
      },
    ));

class AuthController extends AsyncNotifier<Me?> {
  static const _meKey = 'auth:me';

  @override
  Future<Me?> build() async {
    final session = await ref.read(sessionStoreProvider).read();
    if (session == null) return null;

    // Open instantly with the last known profile; the server confirms it in the background. Waiting for the
    // network here is what made the app sit on the splash screen on every launch.
    final cached = await ResponseCache.instance.read(_meKey);
    if (cached is Map) {
      Future<void>.delayed(Duration.zero, _confirm);
      return Me(Map<String, dynamic>.from(cached));
    }
    return _fetch();
  }

  Future<Me?> _fetch() async {
    try {
      final data = await ref.read(apiProvider).get('/auth/me');
      final profile = Map<String, dynamic>.from(data['data'] as Map);
      unawaited(ResponseCache.instance.write(_meKey, profile));
      return Me(profile);
    } on ApiException catch (e) {
      if (e.status == 401) await ref.read(sessionStoreProvider).write(null);
      if (e.status == 401) return null;
      rethrow;
    }
  }

  /// Refreshes the profile shown at launch; a rejected session signs the user out.
  Future<void> _confirm() async {
    try {
      final me = await _fetch();
      if (ref.mounted) state = AsyncData(me);
    } catch (_) {
      // Offline or server hiccup: keep the cached profile.
    }
  }

  Future<void> login(String email, String password) async {
    final data = await ref.read(apiProvider).post('/auth/login', {'email': email, 'password': password}) as Map<String, dynamic>;
    await ref.read(sessionStoreProvider).write(ApiClient.sessionFromResponse(data));
    final profile = Map<String, dynamic>.from(data['user'] as Map);
    unawaited(ResponseCache.instance.write(_meKey, profile));
    final me = Me(profile);
    if (me.locale == 'en' || me.locale == 'ar') await ref.read(localeProvider.notifier).set(me.locale);
    state = AsyncData(me);
  }

  Future<void> logout() async {
    await ref.read(pushServiceProvider).stop();
    await ref.read(sessionStoreProvider).write(null);
    await ResponseCache.instance.clear();
    _resetGetState();
    state = const AsyncData(null);
  }
}

final authProvider = AsyncNotifierProvider<AuthController, Me?>(AuthController.new);

/// Paths already loaded from the network during this app run, and background-refreshed copies waiting to be shown.
final _served = <String>{};
final _fresh = <String, dynamic>{};
final _generation = <String, int>{};

/// GET helper provider keyed by path. Re-fetches when the language changes because the API returns localized
/// content. Results stay in memory for a few minutes so going back to a screen is instant.
///
/// Stale-while-revalidate: the first time a screen is opened after launch, its last copy from disk is shown at
/// once while the server is asked for a fresh one in the background (the screen then updates by itself).
/// Refreshing, or invalidating after a change, always goes to the server.
final getProvider = FutureProvider.autoDispose.family<dynamic, String>((ref, path) async {
  final locale = ref.watch(localeProvider).languageCode;
  final link = ref.keepAlive();
  final timer = Timer(const Duration(minutes: 5), link.close);
  ref.onDispose(timer.cancel);

  final key = '$locale|$path';
  final generation = _generation[key] = (_generation[key] ?? 0) + 1;

  final ready = _fresh.remove(key);
  if (ready != null) {
    _served.add(key);
    return ready;
  }
  if (!_served.contains(key)) {
    final cached = await ResponseCache.instance.read(key);
    if (cached != null) {
      _served.add(key);
      unawaited(_revalidate(ref, key, path, generation));
      return cached;
    }
  }

  try {
    final data = await ref.watch(apiProvider).get(path);
    _served.add(key);
    unawaited(ResponseCache.instance.write(key, data));
    return data;
  } on ApiException catch (e) {
    // Only fall back for connectivity problems, never for real API answers such as 403 or 404.
    if (e.status != null) rethrow;
    final cached = await ResponseCache.instance.read(key);
    if (cached == null) rethrow;
    return cached;
  }
});

/// Forgets what the previous user saw (sign-out or expired session).
void _resetGetState() {
  _served.clear();
  _fresh.clear();
  _generation.clear();
}

Future<void> _revalidate(Ref ref, String key, String path, int generation) async {
  try {
    final data = await ref.read(apiProvider).get(path);
    // A newer load (refresh, or a reload after an edit) supersedes this background copy.
    if (_generation[key] != generation) return;
    unawaited(ResponseCache.instance.write(key, data));
    _fresh[key] = data;
    ref.invalidateSelf();
  } catch (_) {
    // Offline or refused: the copy already on screen stays.
  }
}
