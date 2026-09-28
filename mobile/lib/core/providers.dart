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
        ref.invalidate(authProvider);
      },
    ));

class AuthController extends AsyncNotifier<Me?> {
  @override
  Future<Me?> build() async {
    final session = await ref.read(sessionStoreProvider).read();
    if (session == null) return null;
    try {
      final data = await ref.read(apiProvider).get('/auth/me');
      return Me(Map<String, dynamic>.from(data['data'] as Map));
    } on ApiException catch (e) {
      if (e.status == 401) await ref.read(sessionStoreProvider).write(null);
      if (e.status == 401) return null;
      rethrow;
    }
  }

  Future<void> login(String email, String password) async {
    final data = await ref.read(apiProvider).post('/auth/login', {'email': email, 'password': password}) as Map<String, dynamic>;
    await ref.read(sessionStoreProvider).write(ApiClient.sessionFromResponse(data));
    final me = Me(Map<String, dynamic>.from(data['user'] as Map));
    if (me.locale == 'en' || me.locale == 'ar') await ref.read(localeProvider.notifier).set(me.locale);
    state = AsyncData(me);
  }

  Future<void> logout() async {
    await ref.read(pushServiceProvider).stop();
    await ref.read(sessionStoreProvider).write(null);
    await ResponseCache.instance.clear();
    state = const AsyncData(null);
  }
}

final authProvider = AsyncNotifierProvider<AuthController, Me?>(AuthController.new);

/// GET helper provider keyed by path. Re-fetches when the language changes because
/// the API returns localized content. Results stay in memory for a few minutes so
/// going back to a screen is instant, and the last copy on disk is shown when offline.
final getProvider = FutureProvider.autoDispose.family<dynamic, String>((ref, path) async {
  final locale = ref.watch(localeProvider).languageCode;
  final link = ref.keepAlive();
  final timer = Timer(const Duration(minutes: 5), link.close);
  ref.onDispose(timer.cancel);

  final key = '$locale|$path';
  try {
    final data = await ref.watch(apiProvider).get(path);
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
