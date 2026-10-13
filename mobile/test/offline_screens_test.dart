import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tedc_mobile/core/api/api_client.dart';
import 'package:tedc_mobile/core/offline/offline_exams.dart';
import 'package:tedc_mobile/core/offline/offline_providers.dart';
import 'package:tedc_mobile/core/offline/offline_store.dart';
import 'package:tedc_mobile/features/assessment/offline_exam_screen.dart';
import 'package:tedc_mobile/features/offline/downloads_screen.dart';

import 'support/memory_kv.dart';
import 'support/offline_fakes.dart';

/// The store as the Downloads screen sees it, in memory.
class _Store extends OfflineStore {
  _Store(List<OfflineCourse> courses)
      : _courses = [...courses],
        super(kv: MemoryKv(), transport: FakeTransport(const {}, const {}), network: FakeNetwork(), root: () async => Directory.systemTemp);

  final List<OfflineCourse> _courses;
  bool wifi = false;
  final removed = <String>[];

  @override
  Future<List<OfflineCourse>> courses() async => List.of(_courses);

  @override
  Future<int> usedBytes() async => 5 * 1024 * 1024;

  @override
  Future<bool> wifiOnly() async => wifi;

  @override
  Future<void> setWifiOnly(bool on) async => wifi = on;

  @override
  Future<void> removeCourse(String registrationId) async {
    removed.add(registrationId);
    _courses.removeWhere((c) => c.registrationId == registrationId);
  }
}

/// Offline exams in memory; handing in fails until [online] is set.
class _Exams extends OfflineExams {
  _Exams(this.entries) : super(kv: MemoryKv(), root: () async => Directory.systemTemp, transport: _NoTransport(), deviceId: () async => 'device-0123456789');

  final List<Map<String, dynamic>> entries;
  bool online = false;
  ExamProgress? saved;
  ExamProgress? finished;
  int submits = 0;

  @override
  Future<List<OfflineExam>> list() async => entries.map(OfflineExam.new).toList();

  @override
  Future<Map<String, dynamic>> package(String tokenId) async => {
        'token_id': tokenId, 'title_ar': 'اختبار', 'title_en': 'Unit test', 'time_limit_minutes': 20, 'expires_at': DateTime.now().add(const Duration(days: 2)).toUtc().toIso8601String(),
        'questions': [
          {'id': 'q1', 'type': 'single_choice', 'stem_ar': 'ما عاصمة قطر؟', 'stem_en': 'Capital of Qatar?', 'payload': {'options': [{'id': 'o1', 'text_en': 'Doha', 'text_ar': 'الدوحة'}, {'id': 'o2', 'text_en': 'Al Khor', 'text_ar': 'الخور'}]}},
          {'id': 'q2', 'type': 'true_false', 'stem_ar': 'صح أم خطأ', 'stem_en': 'True or false?', 'payload': {}},
        ],
      };

  @override
  Future<ExamProgress> progress(String tokenId) async => finished ?? saved ?? ExamProgress();

  @override
  Future<void> save(String tokenId, ExamProgress p) async => saved = p;

  @override
  Future<void> finish(String tokenId, ExamProgress p) async {
    finished = p;
    entries.first['status'] = 'finished';
  }

  @override
  Future<Map<String, dynamic>> submit(String tokenId) async {
    submits++;
    if (!online) throw ApiException('offline', code: 'network');
    entries.first['status'] = 'submitted';
    return {'id': 'att-1', 'status': 'graded'};
  }

  @override
  Future<void> remove(String tokenId) async => entries.removeWhere((e) => e['token_id'] == tokenId);
}

class _NoTransport implements ExamTransport {
  @override
  Future<Map<String, dynamic>> issue(String assessmentId, String deviceId) => throw UnimplementedError();

  @override
  Future<Map<String, dynamic>> submit(Map<String, dynamic> body) => throw UnimplementedError();
}

void main() {
  setUpAll(() => initializeDateFormatting());

  final course = OfflineCourse('reg-1', 'Classroom leadership', [
    OfflineItem({'id': 'v1', 'type': 'video', 'title_en': 'Video', 'bytes': 3 * 1024 * 1024, 'expires_at': DateTime.now().add(const Duration(days: 9, hours: -1)).toUtc().toIso8601String()}),
    OfflineItem({'id': 'a1', 'type': 'article', 'title_en': 'Article', 'bytes': 2 * 1024 * 1024, 'expires_at': DateTime.now().add(const Duration(days: 12)).toUtc().toIso8601String()}),
  ], DateTime.now());

  testWidgets('Downloads shows each course with its size and expiry, the space used, Wi-Fi only, and removes a course', (tester) async {
    final store = _Store([course]);
    final exams = _Exams([{'token_id': 'tok-1', 'assessment_id': 'as-1', 'title_en': 'Unit test', 'status': 'finished'}]);
    await tester.pumpWidget(ProviderScope(
      overrides: [offlineStoreProvider.overrideWithValue(store), offlineExamsProvider.overrideWithValue(exams), connectivityProvider.overrideWith((ref) => Stream.value(true))],
      child: const MaterialApp(home: DownloadsScreen()),
    ));
    await tester.pumpAndSettle();

    expect(find.text('5 MB'), findsOneWidget);
    expect(find.text('Classroom leadership'), findsOneWidget);
    expect(find.text('2 lessons · 5 MB'), findsOneWidget);
    expect(find.text('Removed in 9 days'), findsOneWidget);
    expect(find.text('Answered offline — waiting to be handed in'), findsOneWidget);
    expect(find.text('Hand in now'), findsOneWidget);

    await tester.tap(find.byType(Switch));
    await tester.pumpAndSettle();
    expect(store.wifi, isTrue);

    await tester.tap(find.text('Remove from phone'));
    await tester.pumpAndSettle();
    expect(find.textContaining('Remove “Classroom leadership” from this phone?'), findsOneWidget);
    await tester.tap(find.descendant(of: find.byType(AlertDialog), matching: find.widgetWithText(FilledButton, 'Remove from phone')));
    await tester.pumpAndSettle();
    expect(store.removed, ['reg-1']);
    expect(find.text('Classroom leadership'), findsNothing);
  });

  testWidgets('an exam taken offline is saved, waits while there is no connection, and is handed in later', (tester) async {
    final exams = _Exams([{'token_id': 'tok-1', 'assessment_id': 'as-1', 'title_en': 'Unit test', 'status': 'ready'}]);
    final router = GoRouter(initialLocation: '/offline-exams/tok-1', routes: [
      GoRoute(path: '/offline-exams/:token', builder: (_, s) => OfflineExamScreen(tokenId: s.pathParameters['token']!)),
      GoRoute(path: '/attempts/:id', builder: (_, s) => Scaffold(body: Text('result ${s.pathParameters['id']}'))),
    ]);
    await tester.pumpWidget(ProviderScope(
      overrides: [offlineExamsProvider.overrideWithValue(exams), offlineStoreProvider.overrideWithValue(_Store(const []))],
      child: MaterialApp.router(routerConfig: router),
    ));
    await tester.pumpAndSettle();

    expect(find.text('Start the exam'), findsOneWidget);
    await tester.tap(find.text('Start the exam'));
    await tester.pump();
    expect(find.text('Offline · your answers are kept encrypted on the phone'), findsOneWidget);
    expect(exams.saved?.startedAt, isNotNull);

    await tester.tap(find.text('Doha'));
    await tester.pump(const Duration(seconds: 6)); // the autosave runs
    expect(exams.saved?.answers, {'q1': 'o1'});

    await tester.tap(find.text('Submit'));
    await tester.pumpAndSettle();
    await tester.tap(find.descendant(of: find.byType(AlertDialog), matching: find.widgetWithText(FilledButton, 'Submit')));
    await tester.pump();
    await tester.pump();

    expect(exams.finished?.answers, {'q1': 'o1'});
    expect(exams.finished?.finishedAt, isNotNull);
    expect(exams.submits, 1, reason: 'it tries to hand in straight away');
    expect(find.text('Your answers are saved'), findsOneWidget);

    exams.online = true;
    await tester.tap(find.text('Hand in now'));
    await tester.pumpAndSettle();
    expect(find.text('result att-1'), findsOneWidget);
  });
}
