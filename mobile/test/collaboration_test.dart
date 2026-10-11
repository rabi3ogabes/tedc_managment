import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/api/api_client.dart';
import 'package:tedc_mobile/core/auth/session_store.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/community/attachments.dart';
import 'package:tedc_mobile/features/community/subject_social.dart';

Widget _wrap(Widget child, {List overrides = const []}) => ProviderScope(
      overrides: [...overrides],
      child: MaterialApp(
        locale: const Locale('en'),
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: const [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
        home: Scaffold(body: child),
      ),
    );

/// Records what the screens send instead of calling the server.
class _FakeApi extends ApiClient {
  _FakeApi() : super(SessionStore(), locale: () => 'en', onSessionExpired: () {});

  final sent = <String, Object?>{};

  @override
  Future<dynamic> post(String path, [Object? body]) async {
    sent[path] = body;
    return {'data': {}};
  }
}

void main() {
  testWidgets('the attachment tray shows a chip for an accepted file and refuses one that is too large', (tester) async {
    final files = AttachmentController(
      spaceId: 's1',
      limits: const FileLimits(fileMaxMb: 10),
      max: 5,
      uploader: (draft, progress, cancel) async {
        progress(1);
        return 'upload-1';
      },
    );
    addTearDown(files.dispose);
    await tester.pumpWidget(_wrap(AttachmentTray(controller: files)));

    expect(files.addFile('/tmp/report.pdf', 'report.pdf', 2048), isNull);
    await tester.pumpAndSettle();
    expect(find.text('report.pdf'), findsOneWidget);
    expect(find.text('2 KB'), findsOneWidget);
    expect(files.payload, [
      {'type': 'file', 'upload_id': 'upload-1'},
    ]);

    expect(files.addFile('/tmp/huge.pdf', 'huge.pdf', 11 * 1024 * 1024), 'files.tooBig');
    expect(files.addFile('/tmp/tool.exe', 'tool.exe', 100), 'files.badType');
    await tester.pumpAndSettle();
    expect(find.text('huge.pdf'), findsNothing);
    expect(files.drafts, hasLength(1));
  });

  testWidgets('a failed upload can be retried and holds the post until it finishes', (tester) async {
    var attempts = 0;
    final files = AttachmentController(
      spaceId: 's1',
      limits: const FileLimits(),
      max: 5,
      uploader: (draft, progress, cancel) async {
        attempts++;
        if (attempts == 1) throw Exception('offline');
        return 'upload-2';
      },
    );
    addTearDown(files.dispose);
    await tester.pumpWidget(_wrap(AttachmentTray(controller: files)));

    files.addFile('/tmp/notes.docx', 'notes.docx', 4000);
    await tester.pumpAndSettle();
    expect(files.failed, isTrue);
    expect(files.payload, isEmpty);

    await tester.tap(find.byTooltip('Try again'));
    await tester.pumpAndSettle();
    expect(files.failed, isFalse);
    expect(files.payload.single['upload_id'], 'upload-2');
  });

  testWidgets('the rating sheet sends the stars and the review', (tester) async {
    final api = _FakeApi();
    await tester.pumpWidget(_wrap(const RatingSheet(type: 'material', id: 'm1'), overrides: [apiProvider.overrideWithValue(api)]));

    expect(tester.widget<FilledButton>(find.byKey(const ValueKey('rating-save'))).onPressed, isNull); // no stars yet
    await tester.tap(find.byKey(const ValueKey('star-4')));
    await tester.enterText(find.byKey(const ValueKey('review-field')), 'Clear and useful');
    await tester.pump();
    await tester.tap(find.byKey(const ValueKey('rating-save')));
    await tester.pumpAndSettle();

    expect(api.sent['/social/ratings'], {'subject_type': 'material', 'subject_id': 'm1', 'stars': 4, 'review': 'Clear and useful'});
  });
}
