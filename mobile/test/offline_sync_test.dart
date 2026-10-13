import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/api/api_client.dart';
import 'package:tedc_mobile/core/offline/offline_store.dart';
import 'package:tedc_mobile/core/offline/offline_sync.dart';

import 'support/memory_kv.dart';
import 'support/offline_fakes.dart';

void main() {
  late Directory dir;
  late MemoryKv kv;
  late OfflineStore store;
  final video = List<int>.generate(3000, (i) => i % 199);
  final sent = <Map<String, dynamic>>[];
  late Object? Function(Map<String, dynamic> body) answer;

  OfflineSync sync() => OfflineSync(kv: kv, store: store, send: (body) async {
        sent.add(body);
        final a = answer(body);
        if (a is Exception) throw a;
        return a as Map<String, dynamic>;
      });

  setUp(() async {
    dir = await Directory.systemTemp.createTemp('tedc-sync');
    kv = MemoryKv();
    sent.clear();
    store = OfflineStore(kv: kv, transport: FakeTransport({'https://files.test/v1': video}, fakeManifest(video)), network: FakeNetwork(), root: () async => dir, chunkSize: 1024);
    answer = (_) => {'applied': 1, 'rejected': [], 'replayed': false, 'purge': [], 'device_revoked': false};
  });
  tearDown(() => dir.delete(recursive: true));

  test('progress made offline is kept across restarts and sent once', () async {
    await sync().record((q) => q.slideView('l1', 1, 4));
    // The app was closed and opened again.
    final again = sync();
    expect(await again.pending(), 1);

    final r = await again.flush();

    expect(r!.applied, 1);
    expect(sent.single['items'], hasLength(1));
    expect(sent.single['device_id'], await store.deviceId());
    expect(await sync().pending(), 0);
  });

  test('a dropped connection keeps the batch and its key for the retry', () async {
    final s = sync();
    await s.record((q) => q.lessonComplete('l1'));
    answer = (_) => ApiException('offline', code: 'network');

    expect(await s.flush(), isNull);
    expect(await s.pending(), 1);

    answer = (_) => {'applied': 1, 'rejected': [], 'replayed': true, 'purge': [], 'device_revoked': false};
    await sync().flush();
    expect(sent[1]['batch_id'], sent[0]['batch_id']);
    expect(await s.pending(), 0);
  });

  test('a batch the server refuses as invalid is dropped instead of being retried for ever', () async {
    final s = sync();
    await s.record((q) => q.lessonComplete('l1'));
    answer = (_) => ApiException('invalid', status: 422);

    await s.flush();

    expect(await s.pending(), 0);
  });

  test('the server says which courses to delete, and a revoked phone forgets everything', () async {
    await store.downloadCourse('reg-1', 'Programme');
    answer = (body) => {'applied': 0, 'rejected': [], 'replayed': false, 'purge': ['reg-1'], 'device_revoked': false};

    final r = await sync().flush();

    expect(sent.single['held'], ['reg-1']);
    expect(r!.purged, 1);
    expect(await store.courses(), isEmpty);

    await store.downloadCourse('reg-1', 'Programme');
    final device = await store.deviceId();
    answer = (body) => {'applied': 0, 'rejected': [], 'replayed': false, 'purge': ['reg-1'], 'device_revoked': true};
    expect((await sync().flush())!.revoked, isTrue);
    expect(await store.courses(), isEmpty);
    expect(await kv.read(OfflineStore.keyName), isNull);
    expect(await store.deviceId(), isNot(device));
  });

  test('nothing to send and nothing kept means no call at all', () async {
    expect(await sync().flush(), isNull);
    expect(sent, isEmpty);
  });
}
