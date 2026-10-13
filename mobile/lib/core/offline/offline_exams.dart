import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:math';

import '../api/api_client.dart';
import 'content_cipher.dart';
import 'secure_kv.dart';

/// How offline exams reach the server.
abstract class ExamTransport {
  /// `POST me/assessments/{id}/offline-token`: the signed, single-use package for this phone (questions without answers).
  Future<Map<String, dynamic>> issue(String assessmentId, String deviceId);

  /// `POST me/attempts/offline`: the answers, handed in; answers the attempt's result.
  Future<Map<String, dynamic>> submit(Map<String, dynamic> body);
}

/// The answers given so far and the time spent (measured by [ExamClock]).
class ExamProgress {
  ExamProgress({Map<String, dynamic>? answers, this.elapsedSeconds = 0, this.startedAt, this.finishedAt, this.savedAt}) : answers = answers ?? {};

  final Map<String, dynamic> answers;
  final int elapsedSeconds;
  final DateTime? startedAt;
  final DateTime? finishedAt;
  final DateTime? savedAt;

  Map<String, dynamic> toJson() => {
        'answers': answers, 'elapsed': elapsedSeconds, 'started_at': startedAt?.toUtc().toIso8601String(), 'finished_at': finishedAt?.toUtc().toIso8601String(),
        'saved_at': savedAt?.toUtc().toIso8601String(),
      };

  factory ExamProgress.fromJson(Map<String, dynamic> m) => ExamProgress(
        answers: Map<String, dynamic>.from((m['answers'] as Map?) ?? const {}),
        elapsedSeconds: (m['elapsed'] as num?)?.toInt() ?? 0,
        startedAt: DateTime.tryParse(m['started_at'] as String? ?? ''),
        finishedAt: DateTime.tryParse(m['finished_at'] as String? ?? ''),
        savedAt: DateTime.tryParse(m['saved_at'] as String? ?? ''),
      );
}

/// One exam package kept on the phone.
class OfflineExam {
  OfflineExam(this.json);

  final Map<String, dynamic> json;

  String get tokenId => json['token_id'] as String;
  String get assessmentId => json['assessment_id'] as String? ?? '';
  DateTime? get expiresAt => DateTime.tryParse(json['expires_at'] as String? ?? '');
  int? get timeLimitMinutes => (json['time_limit_minutes'] as num?)?.toInt();
  int get questionCount => (json['questions'] as num?)?.toInt() ?? 0;

  /// ready · started · finished (waiting to be handed in) · submitted · refused
  String get status => json['status'] as String? ?? 'ready';
  String? get attemptId => json['attempt_id'] as String?;

  /// Why the server refused it (the error code).
  String? get reason => json['reason'] as String?;
  bool get waiting => status == 'finished';
  bool get open => status == 'ready' || status == 'started';

  String title(bool ar) {
    final a = json['title_ar'] as String? ?? '';
    final e = json['title_en'] as String? ?? '';
    return ar ? (a.isNotEmpty ? a : e) : (e.isNotEmpty ? e : a);
  }
}

/// Assessments taken without a connection (TYP-12). The package and the answers are kept sealed with AES-256-GCM
/// under a key of this phone's keystore; the answers are handed in when the phone is back online, with the time
/// measured by [ExamClock]. A package refused by the server (used, expired, too long) keeps only its reason.
class OfflineExams {
  OfflineExams({required this.kv, required this.root, required this.transport, required this.deviceId, DateTime Function()? clock}) : _clock = clock ?? DateTime.now;

  static const keyName = 'tedc.offline.exam_key';

  /// Server answers that mean the package can never be handed in.
  static const finalRefusals = {'offline_token_invalid', 'offline_token_used', 'offline_token_expired', 'offline_time_exceeded', 'attempts_exhausted', 'offline_not_allowed'};

  final SecureKv kv;
  final Future<Directory> Function() root;
  final ExamTransport transport;
  final Future<String> Function() deviceId;
  final DateTime Function() _clock;

  static Future<void> _lock = Future.value();

  Future<T> _locked<T>(Future<T> Function() fn) {
    final previous = _lock;
    final done = Completer<void>();
    _lock = done.future;
    return previous.then((_) => fn()).whenComplete(done.complete);
  }

  Future<Directory> _dir() async {
    final d = Directory('${(await root()).path}/exams');
    if (!await d.exists()) await d.create(recursive: true);
    return d;
  }

  Future<ContentCipher> _cipher() async {
    var raw = await kv.read(keyName);
    if (raw == null) {
      final r = Random.secure();
      raw = base64Encode(List<int>.generate(32, (_) => r.nextInt(256)));
      await kv.write(keyName, raw);
    }
    return ContentCipher(base64Decode(raw), chunkSize: 1 << 16);
  }

  Future<void> _seal(File f, Object value) async {
    final sealed = await (await _cipher()).encryptBytes(utf8.encode(jsonEncode(value)));
    final tmp = File('${f.path}.tmp');
    await tmp.writeAsBytes(sealed, flush: true);
    await tmp.rename(f.path);
  }

  Future<Map<String, dynamic>> _open(File f) async {
    if (!await f.exists()) throw StateError('This exam is no longer on the phone.');
    try {
      return Map<String, dynamic>.from(jsonDecode(utf8.decode(await (await _cipher()).decryptBytes(await f.readAsBytes()))) as Map);
    } on ContentTampered {
      throw StateError('This exam could not be read on this phone.');
    }
  }

  Future<File> _packageFile(String tokenId) async => File('${(await _dir()).path}/${_safe(tokenId)}.bin');

  Future<File> _progressFile(String tokenId) async => File('${(await _dir()).path}/${_safe(tokenId)}.answers.bin');

  static String _safe(String id) => id.replaceAll(RegExp(r'[^A-Za-z0-9_-]'), '_');

  Future<List<Map<String, dynamic>>> _readIndex() async {
    final f = File('${(await _dir()).path}/index.json');
    try {
      if (await f.exists()) return (jsonDecode(await f.readAsString()) as List).map((e) => Map<String, dynamic>.from(e as Map)).toList();
    } catch (_) {}
    return [];
  }

  Future<void> _writeIndex(List<Map<String, dynamic>> index) async {
    final dir = await _dir();
    final tmp = File('${dir.path}/index.json.tmp');
    await tmp.writeAsString(jsonEncode(index), flush: true);
    await tmp.rename('${dir.path}/index.json');
  }

  Future<void> _update(String tokenId, Map<String, dynamic> change) async {
    final index = await _readIndex();
    for (final e in index) {
      if (e['token_id'] == tokenId) e.addAll(change);
    }
    await _writeIndex(index);
  }

  /// Takes the exam's package for this phone (one attempt; it expires within the assessment's offline window).
  Future<OfflineExam> download(String assessmentId) async {
    final pkg = await transport.issue(assessmentId, await deviceId());
    return _locked(() async {
      final tokenId = pkg['token_id'] as String;
      await _seal(await _packageFile(tokenId), pkg);
      final entry = {
        'token_id': tokenId, 'assessment_id': pkg['assessment_id'] ?? assessmentId, 'title_ar': pkg['title_ar'], 'title_en': pkg['title_en'], 'expires_at': pkg['expires_at'],
        'time_limit_minutes': pkg['time_limit_minutes'], 'questions': ((pkg['questions'] as List?) ?? const []).length, 'status': 'ready',
      };
      final index = (await _readIndex())..removeWhere((e) => e['token_id'] == tokenId);
      await _writeIndex(index..add(entry));
      return OfflineExam(entry);
    });
  }

  Future<List<OfflineExam>> list() async => (await _readIndex()).map(OfflineExam.new).toList();

  /// The package kept for this assessment that can still be taken or handed in.
  Future<OfflineExam?> forAssessment(String assessmentId) async {
    for (final e in (await list()).reversed) {
      if (e.assessmentId == assessmentId && (e.open || e.waiting)) return e;
    }
    return null;
  }

  Future<Map<String, dynamic>> package(String tokenId) async => _open(await _packageFile(tokenId));

  Future<ExamProgress> progress(String tokenId) async {
    final f = await _progressFile(tokenId);
    if (!await f.exists()) {
      if (!await (await _packageFile(tokenId)).exists()) throw StateError('This exam is no longer on the phone.');
      return ExamProgress();
    }
    return ExamProgress.fromJson(await _open(f));
  }

  /// Autosave: the answers so far, sealed.
  Future<void> save(String tokenId, ExamProgress p) => _locked(() async {
        await _seal(await _progressFile(tokenId), ExamProgress(answers: p.answers, elapsedSeconds: p.elapsedSeconds, startedAt: p.startedAt, finishedAt: p.finishedAt, savedAt: _clock()).toJson());
        await _update(tokenId, {'status': 'started'});
      });

  /// The learner finished (or the time ran out): the answers wait to be handed in.
  Future<void> finish(String tokenId, ExamProgress p) => _locked(() async {
        await _seal(await _progressFile(tokenId), ExamProgress(answers: p.answers, elapsedSeconds: p.elapsedSeconds, startedAt: p.startedAt, finishedAt: p.finishedAt ?? _clock(), savedAt: _clock()).toJson());
        await _update(tokenId, {'status': 'finished'});
      });

  /// Hands the answers in. A connection problem leaves the exam waiting (the error is thrown); a final refusal keeps
  /// only its reason.
  Future<Map<String, dynamic>> submit(String tokenId) async {
    final pkg = await package(tokenId);
    final p = await progress(tokenId);
    final body = {
      'token_id': pkg['token_id'], 'nonce': pkg['nonce'], 'signature': pkg['signature'], 'device_id': pkg['device_id'], 'answers': p.answers,
      'device_elapsed_seconds': p.elapsedSeconds, 'client_started_at': p.startedAt?.toUtc().toIso8601String(), 'client_submitted_at': (p.finishedAt ?? _clock()).toUtc().toIso8601String(),
    };
    try {
      final result = await transport.submit(body);
      await _locked(() async {
        await _forget(tokenId);
        await _update(tokenId, {'status': 'submitted', 'attempt_id': result['id']?.toString()});
      });
      return result;
    } on ApiException catch (e) {
      if (e.status == 422 && (e.code == null || finalRefusals.contains(e.code))) {
        await _locked(() async {
          await _forget(tokenId);
          await _update(tokenId, {'status': 'refused', 'reason': e.code ?? 'refused'});
        });
      }
      rethrow;
    }
  }

  /// Hands in everything that waits; answers how many went.
  Future<int> submitWaiting() async {
    var done = 0;
    for (final e in await list()) {
      if (!e.waiting) continue;
      try {
        await submit(e.tokenId);
        done++;
      } catch (_) {}
    }
    return done;
  }

  Future<void> _forget(String tokenId) async {
    for (final f in [await _packageFile(tokenId), await _progressFile(tokenId)]) {
      if (await f.exists()) await f.delete();
    }
  }

  /// Removes a package from the phone (and its record).
  Future<void> remove(String tokenId) => _locked(() async {
        await _forget(tokenId);
        await _writeIndex((await _readIndex())..removeWhere((e) => e['token_id'] == tokenId));
      });

  /// Signing out: every package, every answer and the key go.
  Future<void> removeAll() => _locked(() async {
        final dir = Directory('${(await root()).path}/exams');
        if (await dir.exists()) await dir.delete(recursive: true);
        await kv.delete(keyName);
      });
}
