import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/core/widgets/widgets.dart';

Widget _wrap(Widget child, {Locale locale = const Locale('ar')}) => MaterialApp(
      locale: locale,
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: const [GlobalMaterialLocalizations.delegate, GlobalWidgetsLocalizations.delegate, GlobalCupertinoLocalizations.delegate],
      home: Scaffold(body: child),
    );

void main() {
  test('Arabic is the primary string table with English fallback', () {
    expect(S('ar').t('nav.home'), 'الرئيسية');
    expect(S('en').t('nav.home'), 'Home');
    expect(S('ar').status('approved'), 'معتمد');
    expect(S('en').t('unknown.key'), 'unknown.key');
  });

  testWidgets('App renders right-to-left in Arabic', (tester) async {
    late TextDirection direction;
    await tester.pumpWidget(_wrap(Builder(builder: (context) {
      direction = Directionality.of(context);
      return Text(context.tr('nav.programs'));
    })));
    expect(direction, TextDirection.rtl);
    expect(find.text('البرامج'), findsOneWidget);
  });

  testWidgets('EligibilityPanel shows the explanation for each rule', (tester) async {
    await tester.pumpWidget(_wrap(const EligibilityPanel({
      'eligible': false,
      'label': 'غير مؤهل',
      'summary': 'غير مؤهل للتسجيل',
      'checks': [
        {'key': 'a', 'passed': true, 'mandatory': true, 'message': 'المسمى الوظيفي: معلم'},
        {'key': 'b', 'passed': false, 'mandatory': true, 'message': 'سنوات الخبرة يجب أن تكون أكبر من 2'},
      ],
    })));
    expect(find.text('غير مؤهل'), findsOneWidget);
    expect(find.text('سنوات الخبرة يجب أن تكون أكبر من 2'), findsOneWidget);
    expect(find.byIcon(Icons.block), findsOneWidget);
  });

  testWidgets('StatusChip localizes statuses', (tester) async {
    await tester.pumpWidget(_wrap(const StatusChip('issued'), locale: const Locale('en')));
    expect(find.text('Issued'), findsOneWidget);
  });

  testWidgets('LoadingView skeleton fits full-screen, small and unbounded parents', (tester) async {
    await tester.pumpWidget(_wrap(const LoadingView()));
    expect(find.byType(SkeletonBox), findsWidgets);
    await tester.pumpWidget(_wrap(const SizedBox(height: 90, child: LoadingView())));
    expect(find.byType(SkeletonBox), findsOneWidget);
    await tester.pumpWidget(_wrap(const SingleChildScrollView(child: Column(children: [LoadingView(items: 3)]))));
    expect(find.byType(SkeletonBox), findsWidgets);
    await tester.pump(const Duration(milliseconds: 500));
    expect(tester.takeException(), isNull);
  });
}
