import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/api/api_client.dart';
import 'package:tedc_mobile/core/auth/session_store.dart';
import 'package:tedc_mobile/core/offline/offline_providers.dart';
import 'package:tedc_mobile/core/offline/offline_sync.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/course/slides_lesson.dart';

import 'support/memory_kv.dart';

/// The API, answering slide reports.
class _Api extends ApiClient {
  _Api() : super(SessionStore(), locale: () => 'ar', onSessionExpired: () {});

  final posts = <String, Object?>{};
  bool offline = false;

  @override
  Future<dynamic> post(String path, [Object? body]) async {
    if (offline) throw ApiException('offline', code: 'network');
    posts[path] = body;
    if (path.endsWith('/complete')) throw ApiException('view the slides', code: 'view_slides', status: 422);
    return {'data': {'percent': 50.0, 'status': 'in_progress', 'completed': false}};
  }
}

/// Stands in for the PDF renderer: three pages, and buttons to turn them.
class _FakePager implements SlidePager {
  _FakePager(this.onPage);

  final void Function(int) onPage;
  int page = 1;

  @override
  Future<void> next() async => onPage(++page);

  @override
  Future<void> previous() async => onPage(--page);
}

Widget _fakeViewer({required SlideSource source, required void Function(int, SlidePager) onLoaded, required void Function(int) onPage, required void Function(Object) onError}) =>
    _FakeViewer(onLoaded: onLoaded, onPage: onPage);

class _FakeViewer extends StatefulWidget {
  const _FakeViewer({required this.onLoaded, required this.onPage});

  final void Function(int, SlidePager) onLoaded;
  final void Function(int) onPage;

  @override
  State<_FakeViewer> createState() => _FakeViewerState();
}

class _FakeViewerState extends State<_FakeViewer> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => widget.onLoaded(3, _FakePager(widget.onPage)));
  }

  @override
  Widget build(BuildContext context) => const SizedBox(height: 200, child: Text('pdf'));
}

Map<String, dynamic> _lesson({String mime = 'application/pdf', bool offline = false}) => {
      'id': 'l1', 'type': 'presentation', 'title': 'Slides', 'offline': offline, 'slide_count': 3,
      'media': {'kind': offline ? 'offline' : 'file', 'url': 'https://files.test/s.pdf', 'mime': mime},
      'rules': {'min_seconds_per_slide': 5, 'min_view_percent': 80},
      'progress': {'status': 'not_started', 'percent': 0, 'segments': []},
    };

void main() {
  testWidgets('a slide is reported only after the minimum time on screen, and only once', (tester) async {
    final reported = <String>[];
    final r = SlideReporter(minSeconds: 8, report: (s, t) => reported.add('$s/$t'));
    r.show(1, 5);
    await tester.pump(const Duration(seconds: 7));
    expect(reported, isEmpty);
    await tester.pump(const Duration(seconds: 1));
    expect(reported, ['1/5']);

    r.show(2, 5);
    await tester.pump(const Duration(seconds: 3));
    r.show(3, 5); // turned the page before slide 2 counted
    await tester.pump(const Duration(seconds: 8));
    expect(reported, ['1/5', '3/5']);

    r.show(1, 5); // already counted
    await tester.pump(const Duration(seconds: 9));
    expect(reported, ['1/5', '3/5']);
    r.dispose();
  });

  Future<(_Api, MemoryKv)> pump(WidgetTester tester, Map<String, dynamic> lesson, {required void Function() onConfirm}) async {
    final api = _Api();
    final kv = MemoryKv();
    await tester.pumpWidget(ProviderScope(
      overrides: [apiProvider.overrideWithValue(api), secureKvProvider.overrideWithValue(kv), pdfViewerProvider.overrideWithValue(_fakeViewer)],
      child: MaterialApp(home: Scaffold(body: SingleChildScrollView(child: SlidesLesson(lesson: lesson, onConfirm: () async => onConfirm(), onProgress: (_) {})))),
    ));
    await tester.pump();
    return (api, kv);
  }

  testWidgets('a PDF is read in the app without "confirm done"; each slide is reported after its time', (tester) async {
    var confirmed = false;
    final (api, _) = await pump(tester, _lesson(), onConfirm: () => confirmed = true);

    expect(find.text('pdf'), findsOneWidget);
    expect(find.byKey(const ValueKey('slides-confirm')), findsNothing);
    await tester.pump(const Duration(seconds: 5));
    expect(api.posts['/me/lessons/l1/slide'], {'slide': 1, 'total': 3});

    await tester.tap(find.byKey(const ValueKey('slides-next')));
    await tester.pump(const Duration(seconds: 2));
    expect((api.posts['/me/lessons/l1/slide'] as Map)['slide'], 1);
    await tester.pump(const Duration(seconds: 3));
    expect((api.posts['/me/lessons/l1/slide'] as Map)['slide'], 2);
    expect(api.posts.keys.where((k) => k.endsWith('/complete')), isEmpty);
    expect(confirmed, isFalse);
  });

  testWidgets('offline, slide views wait in the queue with their time', (tester) async {
    final (_, kv) = await pump(tester, _lesson(offline: true), onConfirm: () {});
    await tester.pump(const Duration(seconds: 5));
    await tester.runAsync(() async {});
    final raw = await kv.read(OfflineSync.queueName);
    expect(raw, contains('"type":"slide_view"'));
    expect(raw, contains('"slide":1'));
    expect(raw, contains('"at":'));
  });

  testWidgets('a file that is not a PDF still opens outside the app and is confirmed', (tester) async {
    var confirmed = false;
    await pump(tester, _lesson(mime: 'application/vnd.openxmlformats-officedocument.presentationml.presentation'), onConfirm: () => confirmed = true);
    expect(find.text('pdf'), findsNothing);
    await tester.tap(find.byKey(const ValueKey('slides-confirm')));
    expect(confirmed, isTrue);
  });
}
