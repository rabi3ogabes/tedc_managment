import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:isolate';
import 'dart:math';

import 'package:crypto/crypto.dart';

import 'content_cipher.dart';
import 'secure_kv.dart';

/// How the store reaches the server: the course's offline manifest, and a file from a byte offset (to resume).
abstract class OfflineTransport {
  Future<Map<String, dynamic>> manifest(String registrationId, String deviceId, String platform);

  Future<RangeResponse> fetch(String url, int offset);
}

class RangeResponse {
  RangeResponse(this.stream, {required this.partial, this.total});

  final Stream<List<int>> stream;

  /// The server honoured the offset (206 Partial Content); otherwise the body is the whole file.
  final bool partial;
  final int? total;
}

abstract class NetworkInfo {
  Future<bool> onWifi();
}

/// Why something kept offline cannot be downloaded or opened.
/// wifi_required · withdrawn · revoked · no_key · missing · expired · tampered · checksum · no_space
class OfflineError implements Exception {
  const OfflineError(this.code);

  final String code;

  @override
  String toString() => 'OfflineError($code)';
}

class DownloadFailure {
  const DownloadFailure(this.lessonId, this.reason);

  final String lessonId;

  /// checksum · no_space · network
  final String reason;
}

class DownloadResult {
  const DownloadResult(this.downloaded, this.failed);

  /// Items of the course now kept on the phone (new or already up to date).
  final int downloaded;
  final List<DownloadFailure> failed;
}

/// Progress of one item being downloaded (0–1).
class DownloadProgress {
  const DownloadProgress(this.registrationId, this.lessonId, this.fraction);

  final String registrationId;
  final String lessonId;
  final double fraction;
}

/// One lesson kept on the phone.
class OfflineItem {
  OfflineItem(this.json);

  final Map<String, dynamic> json;

  String get lessonId => json['id'] as String;
  String get type => json['type'] as String;
  String? get mime => json['mime'] as String?;
  int get bytes => (json['bytes'] as num?)?.toInt() ?? 0;
  DateTime? get expiresAt => DateTime.tryParse(json['expires_at'] as String? ?? '');
  Map<String, dynamic> get settings => Map<String, dynamic>.from((json['settings'] as Map?) ?? const {});

  String title(bool ar) {
    final a = json['title_ar'] as String? ?? '';
    final e = json['title_en'] as String? ?? '';
    return ar ? (a.isNotEmpty ? a : e) : (e.isNotEmpty ? e : a);
  }

  bool expired(DateTime now) => expiresAt != null && !expiresAt!.isAfter(now);
}

/// A course kept on the phone.
class OfflineCourse {
  OfflineCourse(this.registrationId, this.title, this.items, this.updatedAt);

  final String registrationId;
  final String title;
  final List<OfflineItem> items;
  final DateTime? updatedAt;

  int get bytes => items.fold(0, (sum, i) => sum + i.bytes);

  /// The first expiry among its items: after it, that item must be downloaded again.
  DateTime? get expiresAt {
    DateTime? first;
    for (final i in items) {
      final e = i.expiresAt;
      if (e != null && (first == null || e.isBefore(first))) first = e;
    }
    return first;
  }

  bool expired(DateTime now) => items.any((i) => i.expired(now));
}

/// Lessons kept for use without a connection (TYP-12).
///
/// Files are downloaded with resume (a `.part` file grows until it is complete), checked against the manifest's
/// SHA-256, then sealed with AES-256-GCM under the device's content key and only then kept; the plain download is
/// deleted. The content key lives only in the keystore. Playback opens a private plain copy that the player deletes
/// when it closes. Every item expires; the expiry is checked against the latest time this phone has seen (and the
/// server's time when it answers), so turning the clock back does not keep content longer.
class OfflineStore {
  OfflineStore({required this.kv, required this.transport, required this.network, required this.root, this.chunkSize = 1 << 20, DateTime Function()? clock, this.platform = 'app'})
      : _clock = clock ?? DateTime.now;

  static const keyName = 'tedc.offline.key';
  static const deviceName = 'tedc.offline.device';
  static const wifiOnlyName = 'tedc.offline.wifi_only';
  static const seenName = 'tedc.offline.seen';

  final SecureKv kv;
  final OfflineTransport transport;
  final NetworkInfo network;
  final Future<Directory> Function() root;
  final int chunkSize;
  final String platform;
  final DateTime Function() _clock;

  final _running = <String, Future<DownloadResult>>{};
  final _progress = StreamController<DownloadProgress>.broadcast();
  Future<void> _lock = Future.value();

  /// Download progress of every item, for the screens that show it.
  Stream<DownloadProgress> get progress => _progress.stream;

  /// Which course downloads are running now.
  Set<String> get downloading => _running.keys.toSet();

  /// This phone's id for offline content, made once.
  Future<String> deviceId() async {
    final id = await kv.read(deviceName);
    if (id != null && id.length >= 16) return id;
    final r = Random.secure();
    final fresh = List.generate(16, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
    await kv.write(deviceName, fresh);
    return fresh;
  }

  Future<bool> wifiOnly() async => (await kv.read(wifiOnlyName)) == '1';

  Future<void> setWifiOnly(bool on) => kv.write(wifiOnlyName, on ? '1' : '0');

  /// Downloads (or brings up to date) everything of the course the author allows offline. A second call for the same
  /// course while it runs joins the running download.
  Future<DownloadResult> downloadCourse(String registrationId, String title, {void Function(String lessonId, double fraction)? onProgress}) {
    final running = _running[registrationId];
    if (running != null) return running;
    final job = _download(registrationId, title, onProgress);
    _running[registrationId] = job;
    return job.whenComplete(() => _running.remove(registrationId));
  }

  Future<DownloadResult> _download(String reg, String title, void Function(String, double)? onProgress) async {
    if (await wifiOnly() && !await network.onWifi()) throw const OfflineError('wifi_required');
    final m = await transport.manifest(reg, await deviceId(), platform);
    await _learnServerTime(m);
    if (m['delete'] == true) {
      if (m['revoked'] == true) {
        await forgetDevice();
        throw const OfflineError('revoked');
      }
      await removeCourse(reg);
      throw const OfflineError('withdrawn');
    }
    final key = await _acceptKey(m['content_key']);
    final dir = Directory('${(await _root()).path}/$reg');
    await dir.create(recursive: true);

    final lessons = ((m['lessons'] as List?) ?? const []).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    final kept = <String, Map<String, dynamic>>{};
    final failed = <DownloadFailure>[];
    var noSpace = false;
    for (final l in lessons) {
      if (l['downloadable'] != true) continue;
      final id = l['id'] as String;
      void report(double f) {
        onProgress?.call(id, f);
        _progress.add(DownloadProgress(reg, id, f));
      }

      try {
        final Map<String, dynamic> entry;
        if (l['type'] == 'article') {
          entry = await _saveArticle(dir, l, key);
        } else if (l['url'] is String && l['sha256'] is String) {
          if (noSpace) {
            failed.add(DownloadFailure(id, 'no_space'));
            continue;
          }
          entry = await _current(reg, l) ?? await _saveFile(dir, l, key, report);
        } else {
          continue; // HTML5 and H5P packages still play online in the app.
        }
        kept[id] = entry;
        report(1);
      } on OfflineError catch (e) {
        if (e.code == 'no_space') noSpace = true;
        failed.add(DownloadFailure(id, e.code));
      } catch (e) {
        if (_isNoSpace(e)) noSpace = true;
        failed.add(DownloadFailure(id, _isNoSpace(e) ? 'no_space' : 'network'));
      }
    }

    await _locked(() async {
      final index = await _readIndex();
      final courses = index['courses'] as Map<String, dynamic>;
      final before = Map<String, dynamic>.from(((courses[reg] as Map?)?['items'] as Map?) ?? const {});
      // What the author no longer allows offline (or removed) leaves the phone; a failed update keeps the old copy.
      final allowed = lessons.where((l) => l['downloadable'] == true).map((l) => l['id'] as String).toSet();
      final items = <String, dynamic>{
        for (final e in before.entries)
          if (allowed.contains(e.key) && !kept.containsKey(e.key)) e.key: e.value,
        ...kept,
      };
      for (final id in before.keys.where((id) => !allowed.contains(id))) {
        await _deleteQuietly(File('${dir.path}/$id.bin'));
      }
      if (items.isEmpty) {
        courses.remove(reg);
        if (failed.isEmpty) await _deleteQuietly(dir);
      } else {
        courses[reg] = {'title': title, 'updated_at': _clock().toUtc().toIso8601String(), 'items': items};
      }
      await _writeIndex(index);
    });
    return DownloadResult(kept.length, failed);
  }

  /// The copy already on the phone when it is still the same file; its expiry moves to the new one.
  Future<Map<String, dynamic>?> _current(String reg, Map<String, dynamic> l) async {
    final index = await _readIndex();
    final old = ((index['courses'] as Map)[reg] as Map?)?['items'] as Map?;
    final item = old?[l['id']] as Map?;
    if (item == null || item['sha256'] != l['sha256']) return null;
    if (!await File('${(await _root()).path}/$reg/${l['id']}.bin').exists()) return null;
    return {...Map<String, dynamic>.from(item), 'expires_at': l['expires_at'], 'title_ar': l['title_ar'], 'title_en': l['title_en'], 'settings': l['settings'] ?? const {}};
  }

  Map<String, dynamic> _entry(Map<String, dynamic> l, int bytes) => {
        'id': l['id'], 'type': l['type'], 'title_ar': l['title_ar'], 'title_en': l['title_en'], 'version': l['version'], 'mime': l['mime'], 'size': l['size'],
        'sha256': l['sha256'], 'expires_at': l['expires_at'], 'settings': l['settings'] ?? const {}, 'bytes': bytes, 'saved_at': _clock().toUtc().toIso8601String(),
      };

  Future<Map<String, dynamic>> _saveArticle(Directory dir, Map<String, dynamic> l, List<int> key) async {
    final sealed = await ContentCipher(key, chunkSize: chunkSize).encryptBytes(utf8.encode(jsonEncode({'ar': l['body_ar'] ?? '', 'en': l['body_en'] ?? ''})));
    final out = File('${dir.path}/${l['id']}.bin');
    final tmp = File('${out.path}.tmp');
    try {
      await tmp.writeAsBytes(sealed, flush: true);
      await tmp.rename(out.path);
    } catch (e) {
      await _deleteQuietly(tmp);
      if (_isNoSpace(e)) throw const OfflineError('no_space');
      rethrow;
    }
    return _entry(l, sealed.length);
  }

  Future<Map<String, dynamic>> _saveFile(Directory dir, Map<String, dynamic> l, List<int> key, void Function(double) report) async {
    final id = l['id'] as String;
    final part = File('${dir.path}/$id.part');
    final tmp = File('${dir.path}/$id.bin.tmp');
    final out = File('${dir.path}/$id.bin');
    try {
      // A stale partial file (the lesson's file was replaced meanwhile) fails the checksum once; then start again.
      for (var round = 0;; round++) {
        final resumed = await part.exists() && await part.length() > 0;
        await _fetch(l['url'] as String, part, (l['size'] as num?)?.toInt(), report);
        final digest = await sha256.bind(part.openRead()).first;
        if (digest.toString() == (l['sha256'] as String).toLowerCase()) break;
        await part.delete();
        if (!resumed || round > 0) throw const OfflineError('checksum');
      }
      await _sealFile(key, chunkSize, part.path, tmp.path);
      await tmp.rename(out.path);
      await part.delete();
      return _entry(l, await out.length());
    } catch (e) {
      await _deleteQuietly(tmp);
      if (_isNoSpace(e)) {
        await _deleteQuietly(part);
        throw const OfflineError('no_space');
      }
      // A dropped connection keeps the partial file so the next try continues from there.
      rethrow;
    }
  }

  Future<void> _fetch(String url, File part, int? size, void Function(double) report) async {
    var offset = await part.exists() ? await part.length() : 0;
    if (size != null && offset > size) {
      await part.delete();
      offset = 0;
    }
    if (size != null && size > 0 && offset == size) return;
    final res = await transport.fetch(url, offset);
    final append = res.partial && offset > 0;
    final sink = part.openWrite(mode: append ? FileMode.append : FileMode.write);
    var got = append ? offset : 0;
    final total = size ?? res.total;
    try {
      await for (final chunk in res.stream) {
        sink.add(chunk);
        got += chunk.length;
        // The last tenth is sealing the file.
        if (total != null && total > 0) report(min(1.0, got / total) * 0.9);
      }
      await sink.flush();
    } finally {
      await sink.close();
    }
  }

  /// The courses kept on the phone.
  Future<List<OfflineCourse>> courses() async {
    await _now();
    final index = await _readIndex();
    final courses = index['courses'] as Map<String, dynamic>;
    // Content without its key (a phone restored from a backup: the key never leaves this phone's keystore) cannot be
    // read, so it is deleted rather than shown.
    if (courses.isNotEmpty && await kv.read(keyName) == null) {
      await _locked(_wipeSealed);
      return [];
    }
    return [
      for (final e in courses.entries)
        OfflineCourse(
          e.key,
          (e.value as Map)['title'] as String? ?? '',
          [for (final i in (((e.value as Map)['items'] as Map?) ?? const {}).values) OfflineItem(Map<String, dynamic>.from(i as Map))],
          DateTime.tryParse((e.value as Map)['updated_at'] as String? ?? ''),
        ),
    ];
  }

  /// One lesson kept on the phone, or null.
  Future<OfflineItem?> item(String registrationId, String lessonId) async {
    final index = await _readIndex();
    final raw = (((index['courses'] as Map)[registrationId] as Map?)?['items'] as Map?)?[lessonId];
    return raw is Map ? OfflineItem(Map<String, dynamic>.from(raw)) : null;
  }

  /// The registration whose course keeps this lesson on the phone, or null.
  Future<String?> registrationOf(String lessonId) async {
    final index = await _readIndex();
    for (final e in (index['courses'] as Map<String, dynamic>).entries) {
      if ((((e.value as Map)['items'] as Map?) ?? const {}).containsKey(lessonId)) return e.key;
    }
    return null;
  }

  /// The registrations whose content is kept on the phone (the server says which to delete).
  Future<List<String>> held() async => ((await _readIndex())['courses'] as Map<String, dynamic>).keys.toList();

  Future<File> _sealed(String reg, String lessonId) async {
    final item = await this.item(reg, lessonId);
    if (item == null) throw const OfflineError('missing');
    if (item.expired(await _now())) throw const OfflineError('expired');
    final f = File('${(await _root()).path}/$reg/$lessonId.bin');
    if (!await f.exists()) throw const OfflineError('missing');
    return f;
  }

  /// A private plain copy for the player (`video_player` needs a file). Delete it with [close] when the player closes.
  Future<File> openFile(String registrationId, String lessonId) async {
    final sealed = await _sealed(registrationId, lessonId);
    final key = await _key();
    final open = Directory('${(await _root()).path}/.open');
    await open.create(recursive: true);
    final item = await this.item(registrationId, lessonId);
    final out = File('${open.path}/${_randomName()}${_extension(item?.mime)}');
    try {
      await _openFile(key, sealed.path, out.path);
    } catch (e) {
      await _deleteQuietly(out);
      if (_isNoSpace(e)) throw const OfflineError('no_space');
      throw const OfflineError('tampered');
    }
    return out;
  }

  Future<void> close(File plain) => _deleteQuietly(plain);

  /// Plain copies left behind when the app was closed during playback.
  Future<void> sweepOpened() async => _deleteQuietly(Directory('${(await _root()).path}/.open'));

  Future<Map<String, dynamic>> readArticle(String registrationId, String lessonId) async {
    final sealed = await _sealed(registrationId, lessonId);
    try {
      final plain = await ContentCipher(await _key()).decryptBytes(await sealed.readAsBytes());
      return Map<String, dynamic>.from(jsonDecode(utf8.decode(plain)) as Map);
    } on ContentTampered {
      throw const OfflineError('tampered');
    }
  }

  /// The space the kept content takes on the phone.
  Future<int> usedBytes() async {
    final dir = await _root();
    var total = 0;
    await for (final f in dir.list(recursive: true, followLinks: false)) {
      if (f is File && !f.path.contains('${Platform.pathSeparator}.open${Platform.pathSeparator}')) total += await f.length();
    }
    return total;
  }

  /// Deletes what has expired; returns how many items went.
  Future<int> pruneExpired() async {
    final now = await _now();
    var removed = 0;
    await _locked(() async {
      final index = await _readIndex();
      final courses = index['courses'] as Map<String, dynamic>;
      final base = (await _root()).path;
      for (final reg in courses.keys.toList()) {
        final items = Map<String, dynamic>.from(((courses[reg] as Map)['items'] as Map?) ?? const {});
        for (final id in items.keys.toList()) {
          if (OfflineItem(Map<String, dynamic>.from(items[id] as Map)).expired(now)) {
            await _deleteQuietly(File('$base/$reg/$id.bin'));
            items.remove(id);
            removed++;
          }
        }
        if (items.isEmpty) {
          courses.remove(reg);
          await _deleteQuietly(Directory('$base/$reg'));
        } else {
          (courses[reg] as Map)['items'] = items;
        }
      }
      if (removed > 0) await _writeIndex(index);
    });
    return removed;
  }

  Future<void> removeCourse(String registrationId) => _locked(() async {
        final index = await _readIndex();
        (index['courses'] as Map).remove(registrationId);
        await _deleteQuietly(Directory('${(await _root()).path}/$registrationId'));
        await _writeIndex(index);
      });

  /// Signing out: every kept file and the content key go.
  Future<void> removeAll() => _locked(() async {
        final dir = await _root();
        if (await dir.exists()) {
          await for (final e in dir.list(followLinks: false)) {
            await _deleteQuietly(e);
          }
        }
        await kv.delete(keyName);
      });

  /// The phone was removed from the person's offline devices: everything goes, and a later download registers anew.
  Future<void> forgetDevice() async {
    await removeAll();
    await kv.delete(deviceName);
  }

  /// The content key from the manifest. Content sealed under an older key cannot be read any more, so it goes.
  Future<List<int>> _acceptKey(Object? value) async {
    final raw = value is String ? value : null;
    List<int>? bytes;
    try {
      bytes = raw == null ? null : base64Decode(raw);
    } catch (_) {}
    if (bytes == null || bytes.length != 32) throw const OfflineError('no_key');
    final stored = await kv.read(keyName);
    if (stored != raw) {
      // What was sealed under another key (or under a key this phone no longer has) cannot be read: it goes.
      await _locked(_wipeSealed);
      await kv.write(keyName, raw!);
    }
    return bytes;
  }

  /// Deletes every sealed file, plain copy and the index; partial downloads stay (they are checked when complete).
  Future<void> _wipeSealed() async {
    final dir = await _root();
    final all = await dir.list(recursive: true, followLinks: false).toList();
    for (final e in all.whereType<File>()) {
      if (!e.path.endsWith('.part')) await _deleteQuietly(e);
    }
    await _deleteQuietly(Directory('${dir.path}/.open'));
    for (final d in (await dir.list(followLinks: false).toList()).whereType<Directory>()) {
      if (await d.list().isEmpty) await _deleteQuietly(d);
    }
  }

  Future<List<int>> _key() async {
    final raw = await kv.read(keyName);
    if (raw == null) throw const OfflineError('no_key');
    return base64Decode(raw);
  }

  /// The latest time this phone has seen: its clock, but never earlier than a time seen before.
  Future<DateTime> _now() async {
    final device = _clock().toUtc();
    final seen = DateTime.tryParse(await kv.read(seenName) ?? '');
    if (seen == null || device.isAfter(seen.add(const Duration(minutes: 1)))) {
      await kv.write(seenName, device.toIso8601String());
      return device;
    }
    return seen.isAfter(device) ? seen : device;
  }

  /// The server's time, from the manifest, replaces what the phone's clock said.
  Future<void> _learnServerTime(Map<String, dynamic> m) async {
    final expires = DateTime.tryParse(m['expires_at'] as String? ?? '');
    final days = (m['expiry_days'] as num?)?.toInt();
    if (expires == null || days == null) return;
    await kv.write(seenName, expires.toUtc().subtract(Duration(days: days)).toIso8601String());
  }

  Future<Directory> _root() async {
    final dir = await root();
    if (!await dir.exists()) await dir.create(recursive: true);
    return dir;
  }

  Future<Map<String, dynamic>> _readIndex() async {
    final f = File('${(await _root()).path}/index.json');
    try {
      if (await f.exists()) {
        final m = Map<String, dynamic>.from(jsonDecode(await f.readAsString()) as Map);
        m['courses'] = Map<String, dynamic>.from((m['courses'] as Map?) ?? const {});
        return m;
      }
    } catch (_) {}
    return {'v': 1, 'courses': <String, dynamic>{}};
  }

  Future<void> _writeIndex(Map<String, dynamic> index) async {
    final dir = await _root();
    final tmp = File('${dir.path}/index.json.tmp');
    await tmp.writeAsString(jsonEncode(index), flush: true);
    await tmp.rename('${dir.path}/index.json');
  }

  /// Index changes run one at a time.
  Future<T> _locked<T>(Future<T> Function() fn) {
    final previous = _lock;
    final done = Completer<void>();
    _lock = done.future;
    return previous.then((_) => fn()).whenComplete(done.complete);
  }

  static Future<void> _deleteQuietly(FileSystemEntity e) async {
    try {
      if (await e.exists()) await e.delete(recursive: true);
    } catch (_) {}
  }

  static bool _isNoSpace(Object e) {
    if (e is! FileSystemException) return false;
    final code = e.osError?.errorCode;
    final text = '${e.osError?.message ?? ''} ${e.message}'.toLowerCase();
    return code == 28 || code == 112 || text.contains('no space') || text.contains('disk full');
  }

  static String _randomName() {
    final r = Random.secure();
    return List.generate(12, (_) => r.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
  }

  static String _extension(String? mime) => switch (mime) {
        'video/mp4' => '.mp4',
        'video/quicktime' => '.mov',
        'video/webm' => '.webm',
        'audio/mpeg' => '.mp3',
        'application/pdf' => '.pdf',
        _ => '',
      };
}

// Sealing and opening run on another isolate so a large video never stalls the screen. These are top-level so the
// isolate receives only the key bytes and the paths.
Future<void> _sealFile(List<int> key, int chunkSize, String from, String to) =>
    Isolate.run(() => ContentCipher(key, chunkSize: chunkSize).encryptFile(File(from), File(to)));

Future<void> _openFile(List<int> key, String from, String to) => Isolate.run(() => ContentCipher(key).decryptFile(File(from), File(to)));
