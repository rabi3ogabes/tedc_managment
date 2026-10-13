import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/api/api_client.dart';
import 'package:tedc_mobile/core/models.dart';
import 'package:tedc_mobile/core/offline/exam_clock.dart';
import 'package:tedc_mobile/core/offline/offline_exams.dart';

import 'support/memory_kv.dart';

class _Exams implements ExamTransport {
  final submitted = <Map<String, dynamic>>[];
  Object? Function(Map<String, dynamic> body)? answer;

  @override
  Future<Map<String, dynamic>> issue(String assessmentId, String deviceId) async => {
        'token_id': 'tok-1', 'nonce': 'n' * 64, 'signature': 's' * 64, 'device_id': deviceId, 'assessment_id': assessmentId,
        'title_ar': 'اختبار الوحدة', 'title_en': 'Unit test', 'time_limit_minutes': 20, 'issued_at': '2026-10-10T08:00:00Z', 'expires_at': '2026-10-13T08:00:00Z', 'seed': 7,
        'questions': [
          {'id': 'q1', 'type': 'single_choice', 'stem_ar': 'ما عاصمة قطر؟', 'stem_en': 'Capital of Qatar?', 'points': 1, 'payload': {'options': [{'id': 'o1', 'text_ar': 'الدوحة'}, {'id': 'o2', 'text_ar': 'الخور'}]}},
          {'id': 'q2', 'type': 'essay', 'stem_ar': 'اشرح', 'stem_en': 'Explain', 'points': 2, 'payload': {}},
        ],
      };

  @override
  Future<Map<String, dynamic>> submit(Map<String, dynamic> body) async {
    submitted.add(body);
    final a = answer?.call(body);
    if (a is Exception) throw a;
    return (a as Map<String, dynamic>?) ?? {'id': 'att-9', 'status': 'grading'};
  }
}

void main() {
  late Directory dir;
  late MemoryKv kv;
  late _Exams api;
  OfflineExams exams() => OfflineExams(kv: kv, root: () async => dir, transport: api, deviceId: () async => 'device-0123456789');

  setUp(() async {
    dir = await Directory.systemTemp.createTemp('tedc-exams');
    kv = MemoryKv();
    api = _Exams();
  });
  tearDown(() => dir.delete(recursive: true));

  String everything() => dir.listSync(recursive: true).whereType<File>().map((f) => String.fromCharCodes(f.readAsBytesSync())).join();

  test('the package and the answers are kept encrypted and survive a restart', () async {
    final e = await exams().download('as-1');
    expect(e.title(true), 'اختبار الوحدة');
    expect(e.questionCount, 2);

    await exams().save('tok-1', ExamProgress(answers: {'q1': 'o1', 'q2': 'my secret essay'}, elapsedSeconds: 42, startedAt: DateTime.utc(2026, 10, 10, 9)));

    expect(everything().contains('my secret essay'), isFalse);
    expect(everything().contains('Capital of Qatar'), isFalse);
    final p = await exams().progress('tok-1');
    expect(p.answers['q2'], 'my secret essay');
    expect(p.elapsedSeconds, 42);
    expect((await exams().package('tok-1')).list('questions'), hasLength(2));
    expect((await exams().list()).single.status, 'started');
  });

  test('an exam finished offline is handed in later with its token, its device and the measured time', () async {
    await exams().download('as-1');
    await exams().finish('tok-1', ExamProgress(answers: {'q1': 'o1'}, elapsedSeconds: 600, startedAt: DateTime.utc(2026, 10, 10, 9), finishedAt: DateTime.utc(2026, 10, 10, 9, 10)));
    expect((await exams().list()).single.waiting, isTrue);

    final r = await exams().submit('tok-1');

    final body = api.submitted.single;
    expect(body['token_id'], 'tok-1');
    expect(body['nonce'], 'n' * 64);
    expect(body['signature'], 's' * 64);
    expect(body['device_id'], 'device-0123456789');
    expect(body['answers'], {'q1': 'o1'});
    expect(body['device_elapsed_seconds'], 600);
    expect(body['client_started_at'], '2026-10-10T09:00:00.000Z');
    expect(body['client_submitted_at'], '2026-10-10T09:10:00.000Z');
    expect(r['id'], 'att-9');
    final e = (await exams().list()).single;
    expect(e.status, 'submitted');
    expect(e.attemptId, 'att-9');
    expect(dir.listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.bin')), isEmpty);
  });

  test('without a connection it keeps waiting; a refusal says why and the answers go', () async {
    await exams().download('as-1');
    await exams().finish('tok-1', ExamProgress(answers: {'q1': 'o2'}, elapsedSeconds: 300));
    api.answer = (_) => ApiException('offline', code: 'network');

    await expectLater(exams().submit('tok-1'), throwsA(isA<ApiException>()));
    expect((await exams().list()).single.waiting, isTrue);
    expect(await exams().submitWaiting(), 0);

    api.answer = (_) => ApiException('used', code: 'offline_token_used', status: 422);
    await expectLater(exams().submit('tok-1'), throwsA(isA<ApiException>()));
    final e = (await exams().list()).single;
    expect(e.status, 'refused');
    expect(e.reason, 'offline_token_used');
    await expectLater(exams().progress('tok-1'), throwsA(isA<StateError>()));
  });

  test('signing out deletes the packages and their key', () async {
    await exams().download('as-1');
    await exams().removeAll();
    expect(await exams().list(), isEmpty);
    expect(await kv.read(OfflineExams.keyName), isNull);
  });

  group('the exam clock', () {
    test('counts with the monotonic clock: changing the phone clock changes nothing', () {
      var mono = Duration.zero;
      var wall = DateTime.utc(2026, 10, 10, 9);
      final c = ExamClock(wall: () => wall, monotonic: () => mono);
      mono = const Duration(seconds: 120);
      wall = wall.subtract(const Duration(hours: 1));
      expect(c.elapsedSeconds, 120);
    });

    test('time away from the app counts, as an online exam timer keeps running', () {
      var mono = Duration.zero;
      var wall = DateTime.utc(2026, 10, 10, 9);
      final c = ExamClock(wall: () => wall, monotonic: () => mono);
      mono = const Duration(seconds: 60);
      c.pause();
      wall = wall.add(const Duration(minutes: 5)); // the phone slept: the monotonic clock stood still
      c.resume();
      expect(c.elapsedSeconds, 360);
    });

    test('a new sitting continues from the saved time, and the clock turned back never takes time off', () {
      final saved = DateTime.utc(2026, 10, 10, 9);
      expect(ExamClock(spent: 100, savedAt: saved, wall: () => saved.add(const Duration(seconds: 50)), monotonic: () => Duration.zero).elapsedSeconds, 150);
      expect(ExamClock(spent: 100, savedAt: saved, wall: () => saved.subtract(const Duration(hours: 1)), monotonic: () => Duration.zero).elapsedSeconds, 100);
    });
  });
}
