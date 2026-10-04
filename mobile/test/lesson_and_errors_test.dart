import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/api/api_client.dart';
import 'package:tedc_mobile/features/course/lesson_screen.dart';

Widget _host(Widget child) => MaterialApp(home: Scaffold(body: SingleChildScrollView(padding: const EdgeInsets.all(16), child: child)));

void main() {
  group('articles are shown formatted, not as raw Markdown', () {
    testWidgets('headings, bold, bullets and numbers lose their symbols', (tester) async {
      await tester.pumpWidget(_host(const Column(children: [
        ArticleBlock(text: '## لماذا التعلّم الرقمي؟'),
        ArticleBlock(text: '- مرونة في الوقت\n- محتوى **قابل للتكرار**'),
        ArticleBlock(text: '1. اختر هدفاً\n2. جرّب الأداة'),
      ])));

      expect(find.textContaining('##'), findsNothing);
      expect(find.textContaining('**'), findsNothing);
      expect(find.text('لماذا التعلّم الرقمي؟'), findsOneWidget);
      expect(find.textContaining('محتوى قابل للتكرار'), findsOneWidget);
      expect(find.text('•'), findsNWidgets(2));
      expect(find.text('1.'), findsOneWidget);
      expect(find.text('2.'), findsOneWidget);
    });

    testWidgets('tables and quotes are drawn as such', (tester) async {
      await tester.pumpWidget(_host(const Column(children: [
        ArticleBlock(text: '| العنصر | دوره |\n|---|---|\n| المرسل | يصوغ الفكرة |'),
        ArticleBlock(text: '> التعلّم بالتطبيق أقوى من القراءة وحدها.'),
      ])));

      expect(find.textContaining('|'), findsNothing);
      expect(find.textContaining('---'), findsNothing);
      expect(find.text('المرسل'), findsOneWidget);
      expect(find.textContaining('يصوغ الفكرة'), findsOneWidget);
      expect(find.textContaining('>'), findsNothing);
      expect(find.textContaining('التعلّم بالتطبيق'), findsOneWidget);
    });
  });

  group('API errors', () {
    DioException failure({Object? data, DioExceptionType type = DioExceptionType.badResponse, int? status}) => DioException(
          requestOptions: RequestOptions(path: '/x'),
          type: type,
          response: status == null ? null : Response(requestOptions: RequestOptions(path: '/x'), statusCode: status, data: data),
        );

    test('a validation error reads whatever shape the server used', () {
      expect(ApiException.from(failure(status: 422, data: {'errors': {'email': ['Invalid e-mail']}})).message, 'Invalid e-mail');
      expect(ApiException.from(failure(status: 422, data: {'errors': {'email': 'Plain text'}})).message, 'Plain text');
      expect(ApiException.from(failure(status: 422, data: {'errors': {'email': <String>[]}, 'message': 'Fallback'})).message, 'Fallback');
      expect(ApiException.from(failure(status: 403, data: {'message': 'Forbidden', 'code': 'x'})).code, 'x');
    });

    test('being offline is explained in plain words, not with library text', () {
      final e = ApiException.from(failure(type: DioExceptionType.connectionError));
      expect(e.code, 'network');
      expect(e.status, isNull);
      expect(e.message, contains('تعذّر الاتصال'));
      expect(e.message, contains('Could not reach the server'));
    });
  });
}
