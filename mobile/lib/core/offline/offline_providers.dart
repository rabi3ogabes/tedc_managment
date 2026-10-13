import 'dart:async';
import 'dart:io';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path_provider/path_provider.dart';

import '../api/api_client.dart';
import '../providers.dart';
import 'offline_exams.dart';
import 'offline_store.dart';
import 'offline_sync.dart';
import 'secure_kv.dart';

/// The manifest through the API; files straight from storage with their signed address (no API token goes there).
class ApiOfflineTransport implements OfflineTransport {
  ApiOfflineTransport(this.api);

  final ApiClient api;
  final Dio _files = Dio(BaseOptions(connectTimeout: const Duration(seconds: 20), receiveTimeout: const Duration(minutes: 2)));

  @override
  Future<Map<String, dynamic>> manifest(String registrationId, String deviceId, String platform) async {
    final res = await api.get('/me/courses/$registrationId/offline-manifest', query: {'device_id': deviceId, 'platform': platform});
    return Map<String, dynamic>.from((res as Map)['data'] as Map);
  }

  @override
  Future<RangeResponse> fetch(String url, int offset) async {
    try {
      final res = await _files.get<ResponseBody>(
        url,
        options: Options(responseType: ResponseType.stream, headers: {if (offset > 0) 'Range': 'bytes=$offset-'}, validateStatus: (s) => s == 200 || s == 206),
      );
      final range = res.headers.value('content-range'); // bytes 2000-4999/5000
      final total = range != null ? int.tryParse(range.split('/').last) : int.tryParse(res.headers.value(Headers.contentLengthHeader) ?? '');
      return RangeResponse(res.data!.stream, partial: res.statusCode == 206, total: total);
    } catch (e) {
      throw ApiException.from(e);
    }
  }
}

class ConnectivityNetwork implements NetworkInfo {
  @override
  Future<bool> onWifi() async {
    final r = await Connectivity().checkConnectivity();
    return r.contains(ConnectivityResult.wifi) || r.contains(ConnectivityResult.ethernet);
  }
}

class ApiExamTransport implements ExamTransport {
  ApiExamTransport(this.api);

  final ApiClient api;

  @override
  Future<Map<String, dynamic>> issue(String assessmentId, String deviceId) async =>
      Map<String, dynamic>.from(((await api.post('/me/assessments/$assessmentId/offline-token', {'device_id': deviceId})) as Map)['data'] as Map);

  @override
  Future<Map<String, dynamic>> submit(Map<String, dynamic> body) async => Map<String, dynamic>.from(((await api.post('/me/attempts/offline', body)) as Map)['data'] as Map);
}

final secureKvProvider = Provider<SecureKv>((ref) => KeystoreKv());

/// Offline content lives in the app's private support folder.
final offlineRootProvider = Provider<Future<Directory> Function()>((ref) => () async => Directory('${(await getApplicationSupportDirectory()).path}/offline'));

final offlineStoreProvider = Provider<OfflineStore>((ref) => OfflineStore(
      kv: ref.watch(secureKvProvider),
      transport: ApiOfflineTransport(ref.watch(apiProvider)),
      network: ConnectivityNetwork(),
      root: ref.watch(offlineRootProvider),
      platform: Platform.isIOS ? 'ios' : (Platform.isAndroid ? 'android' : 'app'),
    ));

final offlineSyncProvider = Provider<OfflineSync>((ref) => OfflineSync(
      kv: ref.watch(secureKvProvider),
      store: ref.watch(offlineStoreProvider),
      send: (body) async => Map<String, dynamic>.from(((await ref.read(apiProvider).post('/me/sync', body)) as Map)['data'] as Map),
    ));

final offlineExamsProvider = Provider<OfflineExams>((ref) {
  final store = ref.watch(offlineStoreProvider);
  return OfflineExams(kv: ref.watch(secureKvProvider), root: ref.watch(offlineRootProvider), transport: ApiExamTransport(ref.watch(apiProvider)), deviceId: store.deviceId);
});

/// Whether the phone has a network (it does not promise that the server answers).
final connectivityProvider = StreamProvider<bool>((ref) async* {
  final c = Connectivity();
  bool online(List<ConnectivityResult> r) => r.any((x) => x != ConnectivityResult.none);
  try {
    yield online(await c.checkConnectivity());
  } catch (_) {
    yield true;
  }
  yield* c.onConnectivityChanged.map(online);
});

/// True while the phone has no network at all.
final offlineNowProvider = Provider<bool>((ref) => ref.watch(connectivityProvider).value == false);

final offlineCoursesProvider = FutureProvider.autoDispose<List<OfflineCourse>>((ref) => ref.watch(offlineStoreProvider).courses());

final offlineCourseProvider = FutureProvider.autoDispose.family<OfflineCourse?, String>((ref, registrationId) async {
  for (final c in await ref.watch(offlineCoursesProvider.future)) {
    if (c.registrationId == registrationId) return c;
  }
  return null;
});

final offlineUsageProvider = FutureProvider.autoDispose<int>((ref) => ref.watch(offlineStoreProvider).usedBytes());

final offlineWifiOnlyProvider = FutureProvider.autoDispose<bool>((ref) => ref.watch(offlineStoreProvider).wifiOnly());

final offlineExamListProvider = FutureProvider.autoDispose<List<OfflineExam>>((ref) => ref.watch(offlineExamsProvider).list());

/// What would be lost by signing out now: progress not yet sent and exams answered but not handed in.
final offlineUnsentProvider = FutureProvider.autoDispose<int>((ref) async {
  final exams = await ref.watch(offlineExamsProvider).list();
  return await ref.watch(offlineSyncProvider).pending() + exams.where((e) => e.waiting || e.status == 'started').length;
});

/// Keeps what is kept offline in step with the server: when the connection returns (and when the app opens) it sends
/// the progress made offline, hands in exams answered offline, deletes what the server withdrew and what expired.
class OfflineCoordinator {
  OfflineCoordinator(this.ref);

  final Ref ref;
  StreamSubscription<List<ConnectivityResult>>? _sub;
  bool _busy = false;

  void start() {
    _sub ??= Connectivity().onConnectivityChanged.listen((r) {
      if (r.any((x) => x != ConnectivityResult.none)) unawaited(tick());
    });
    unawaited(ref.read(offlineStoreProvider).sweepOpened());
    unawaited(tick());
  }

  Future<void> tick() async {
    if (_busy) return;
    _busy = true;
    try {
      final store = ref.read(offlineStoreProvider);
      final pruned = await store.pruneExpired();
      final sync = await ref.read(offlineSyncProvider).flush();
      final handed = await ref.read(offlineExamsProvider).submitWaiting();
      if (pruned > 0 || (sync != null && (sync.purged > 0 || sync.revoked))) ref.invalidate(offlineCoursesProvider);
      if (handed > 0) ref.invalidate(offlineExamListProvider);
    } catch (_) {
      // Offline, or the server is busy: the next connection or start tries again.
    } finally {
      _busy = false;
    }
  }

  void stop() {
    _sub?.cancel();
    _sub = null;
  }
}

final offlineCoordinatorProvider = Provider<OfflineCoordinator>((ref) {
  final c = OfflineCoordinator(ref);
  ref.onDispose(c.stop);
  return c;
});

/// Signing out: what can still be sent is sent, then every offline file, answer and key leaves the phone.
Future<void> wipeOffline(Ref ref) async {
  try {
    await ref.read(offlineSyncProvider).flush().timeout(const Duration(seconds: 8));
    await ref.read(offlineExamsProvider).submitWaiting().timeout(const Duration(seconds: 8));
  } catch (_) {}
  try {
    await ref.read(offlineStoreProvider).removeAll();
    await ref.read(offlineExamsProvider).removeAll();
    await ref.read(secureKvProvider).delete(OfflineSync.queueName);
  } catch (_) {}
}
