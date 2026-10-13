import 'dart:convert';
import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/offline/offline_store.dart';

import 'support/memory_kv.dart';
import 'support/offline_fakes.dart';

void main() {
  late Directory dir;
  late MemoryKv kv;
  final video = List<int>.generate(5000, (i) => i % 251);
  final key = base64Encode(List<int>.generate(32, (i) => i));
  Map<String, dynamic> manifest({String? sha, String expires = '2099-01-01T00:00:00Z', bool delete = false, bool revoked = false}) => {
        'manifest_version': 2, 'delete': delete, 'revoked': revoked, 'withdrawn': delete && !revoked, 'content_key': delete ? null : key,
        'lessons': delete ? [] : [
          {'id': 'v1', 'type': 'video', 'title_ar': 'فيديو', 'title_en': 'Video', 'downloadable': true, 'url': 'https://files.test/v1', 'sha256': sha ?? sha256.convert(video).toString(), 'size': video.length, 'expires_at': expires, 'mime': 'video/mp4', 'settings': {'min_watch_percent': 90}},
          {'id': 'a1', 'type': 'article', 'title_ar': 'مقال', 'title_en': 'Article', 'downloadable': true, 'body_ar': 'نص المقال', 'body_en': 'The text', 'expires_at': expires},
          {'id': 'x1', 'type': 'presentation', 'title_ar': 'عرض', 'title_en': 'Slides', 'downloadable': false, 'reason': 'not_downloadable'},
        ],
      };
  OfflineStore store(FakeTransport t, FakeNetwork n, {DateTime Function()? clock}) => OfflineStore(kv: kv, transport: t, network: n, root: () async => dir, chunkSize: 1024, clock: clock);

  setUp(() async {
    dir = await Directory.systemTemp.createTemp('tedc-offline');
    kv = MemoryKv();
  });
  tearDown(() => dir.delete(recursive: true));

  test('a course downloads, is kept encrypted, and reads back', () async {
    final s = store(FakeTransport({'https://files.test/v1': video}, manifest()), FakeNetwork());

    final r = await s.downloadCourse('reg-1', 'Programme');

    expect(r.downloaded, 2);
    expect(r.failed, isEmpty);
    final course = (await s.courses()).single;
    expect(course.items.map((i) => i.lessonId), containsAll(['v1', 'a1']));
    expect(course.items.map((i) => i.lessonId), isNot(contains('x1')));
    final sealed = File('${dir.path}/reg-1/v1.bin').readAsBytesSync();
    expect(String.fromCharCodes(sealed).contains(String.fromCharCodes(video.sublist(0, 64))), isFalse);
    final plain = await s.openFile('reg-1', 'v1');
    addTearDown(() => plain.existsSync() ? plain.deleteSync() : null);
    expect(plain.readAsBytesSync(), video);
    await s.close(plain);
    expect(plain.existsSync(), isFalse);
    expect((await s.readArticle('reg-1', 'a1'))['ar'], 'نص المقال');
    expect(await kv.read(OfflineStore.keyName), key);
    expect(await s.usedBytes(), greaterThan(video.length));
    expect(await s.held(), ['reg-1']);
  });

  test('a download whose checksum is wrong is refused and leaves nothing behind', () async {
    final s = store(FakeTransport({'https://files.test/v1': video}, manifest(sha: 'f' * 64)), FakeNetwork());

    final r = await s.downloadCourse('reg-1', 'Programme');

    expect(r.failed.single.lessonId, 'v1');
    expect(r.failed.single.reason, 'checksum');
    expect(File('${dir.path}/reg-1/v1.bin').existsSync(), isFalse);
    expect(File('${dir.path}/reg-1/v1.part').existsSync(), isFalse);
  });

  test('an interrupted download continues from where it stopped', () async {
    final t = FakeTransport({'https://files.test/v1': video}, manifest());
    Directory('${dir.path}/reg-1').createSync(recursive: true);
    File('${dir.path}/reg-1/v1.part').writeAsBytesSync(video.sublist(0, 2000));

    await store(t, FakeNetwork()).downloadCourse('reg-1', 'Programme');

    expect(t.offsets['https://files.test/v1'], 2000);
    final plain = await store(t, FakeNetwork()).openFile('reg-1', 'v1');
    addTearDown(() => plain.existsSync() ? plain.deleteSync() : null);
    expect(plain.readAsBytesSync(), video);
  });

  test('running out of space stops cleanly and leaves no partial file', () async {
    final t = FakeTransport({'https://files.test/v1': video}, manifest())..failWith = const FileSystemException('No space left on device', 'v1.part', OSError('No space left on device', 28));

    final r = await store(t, FakeNetwork()).downloadCourse('reg-1', 'Programme');

    expect(r.failed.single.reason, 'no_space');
    expect(File('${dir.path}/reg-1/v1.part').existsSync(), isFalse);
    expect(File('${dir.path}/reg-1/v1.bin').existsSync(), isFalse);
  });

  test('Wi-Fi only waits for Wi-Fi, and a withdrawn course is deleted', () async {
    final t = FakeTransport({'https://files.test/v1': video}, manifest());
    final n = FakeNetwork();
    final s = store(t, n);
    await s.downloadCourse('reg-1', 'Programme');

    await s.setWifiOnly(true);
    n.wifi = false;
    await expectLater(s.downloadCourse('reg-1', 'Programme'), throwsA(isA<OfflineError>().having((e) => e.code, 'code', 'wifi_required')));
    n.wifi = true;

    t.manifestJson = manifest(delete: true);
    await expectLater(s.downloadCourse('reg-1', 'Programme'), throwsA(isA<OfflineError>().having((e) => e.code, 'code', 'withdrawn')));
    expect(await s.courses(), isEmpty);
    expect(Directory('${dir.path}/reg-1').existsSync(), isFalse);
    expect(await kv.read(OfflineStore.keyName), key);
  });

  test('a revoked phone deletes everything, forgets its key and takes a new device id', () async {
    final t = FakeTransport({'https://files.test/v1': video}, manifest());
    final s = store(t, FakeNetwork());
    await s.downloadCourse('reg-1', 'Programme');
    final device = await s.deviceId();

    t.manifestJson = manifest(delete: true, revoked: true);
    await expectLater(s.downloadCourse('reg-1', 'Programme'), throwsA(isA<OfflineError>().having((e) => e.code, 'code', 'revoked')));

    expect(await s.courses(), isEmpty);
    expect(await kv.read(OfflineStore.keyName), isNull);
    expect(await s.deviceId(), isNot(device));
  });

  test('expired items are removed, and turning the clock back does not bring them back', () async {
    var now = DateTime.utc(2030, 1, 1);
    final t = FakeTransport({'https://files.test/v1': video}, manifest(expires: '2030-01-05T00:00:00Z'));
    final s = store(t, FakeNetwork(), clock: () => now);
    await s.downloadCourse('reg-1', 'Programme');
    expect(await s.pruneExpired(), 0);

    now = DateTime.utc(2030, 1, 10);
    expect((await s.courses()).single.expired(now), isTrue);
    now = DateTime.utc(2030, 1, 2);
    // A new start of the app, with the phone's clock set back.
    expect(await store(t, FakeNetwork(), clock: () => now).pruneExpired(), 2);
    expect(await s.courses(), isEmpty);
    await expectLater(s.openFile('reg-1', 'v1'), throwsA(isA<OfflineError>()));
  });

  test('content left without its key (a phone restored from a backup) is deleted, never shown or reused', () async {
    final t = FakeTransport({'https://files.test/v1': video}, manifest());
    final s = store(t, FakeNetwork());
    await s.downloadCourse('reg-1', 'Programme');
    // The backup brought the files back, but not the key (it never leaves this phone's keystore).
    await kv.delete(OfflineStore.keyName);

    expect(await s.courses(), isEmpty);
    expect(Directory('${dir.path}/reg-1').existsSync(), isFalse);

    final other = base64Encode(List<int>.generate(32, (i) => 255 - i));
    t.manifestJson = {...manifest(), 'content_key': other};
    await kv.delete(OfflineStore.keyName);
    await s.downloadCourse('reg-1', 'Programme');
    final plain = await s.openFile('reg-1', 'v1');
    addTearDown(() => plain.existsSync() ? plain.deleteSync() : null);
    expect(plain.readAsBytesSync(), video);
  });

  test('a new key arriving while old content has no key replaces that content', () async {
    final t = FakeTransport({'https://files.test/v1': video}, manifest());
    final s = store(t, FakeNetwork());
    await s.downloadCourse('reg-1', 'Programme');
    await kv.delete(OfflineStore.keyName);
    t.manifestJson = {...manifest(), 'content_key': base64Encode(List<int>.generate(32, (i) => 255 - i))};

    final r = await s.downloadCourse('reg-1', 'Programme');

    expect(r.failed, isEmpty);
    expect(t.offsets['https://files.test/v1'], 0, reason: 'the file is fetched again, not taken from the unreadable copy');
    final plain = await s.openFile('reg-1', 'v1');
    addTearDown(() => plain.existsSync() ? plain.deleteSync() : null);
    expect(plain.readAsBytesSync(), video);
  });

  test('signing out wipes the content and the key', () async {
    final s = store(FakeTransport({'https://files.test/v1': video}, manifest()), FakeNetwork());
    await s.downloadCourse('reg-1', 'Programme');

    await s.removeAll();

    expect(await s.courses(), isEmpty);
    expect(await kv.read(OfflineStore.keyName), isNull);
    expect(dir.listSync(), isEmpty);
  });
}
