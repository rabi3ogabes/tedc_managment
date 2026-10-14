import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/auth/login_screen.dart';

Future<void> _pump(WidgetTester tester, bool sso, {bool mandatory = false}) async {
  await tester.pumpWidget(ProviderScope(
    overrides: [getProvider('/public/mobile-config').overrideWith((ref) async => {'data': {'sso': sso, 'sso_mandatory': mandatory, 'demo_accounts': false}})],
    child: const MaterialApp(home: LoginScreen()),
  ));
  await tester.pump();
  await tester.pump();
}

void main() {
  testWidgets('the Ministry sign-in button appears when the server offers single sign-on', (tester) async {
    await _pump(tester, true);
    expect(find.byKey(const ValueKey('sso')), findsOneWidget);
    expect(find.text('Sign in with your Ministry account'), findsOneWidget);
    expect(find.byType(TextFormField), findsNWidgets(2), reason: 'the password form is still there');
  });

  testWidgets('and not otherwise', (tester) async {
    await _pump(tester, false);
    expect(find.byKey(const ValueKey('sso')), findsNothing);
  });

  testWidgets('when single sign-on is mandatory the password form waits behind a link for administrators and outside accounts', (tester) async {
    await _pump(tester, true, mandatory: true);
    expect(find.byKey(const ValueKey('sso')), findsOneWidget);
    expect(find.byType(TextFormField), findsNothing);
    await tester.ensureVisible(find.byKey(const ValueKey('show-password')));
    await tester.tap(find.byKey(const ValueKey('show-password')));
    await tester.pump();
    expect(find.byType(TextFormField), findsNWidgets(2));
  });

  test('a Teams town hall has its own name and note, in both languages', () {
    for (final lang in ['ar', 'en']) {
      final s = S(lang);
      expect(s.t('session.platform.teams_townhall'), isNot('session.platform.teams_townhall'));
      expect(s.t('session.townhallHint'), isNot('session.townhallHint'));
      expect(s.t('auth.usePassword'), isNot('auth.usePassword'));
    }
    expect(S('en').t('session.platform.teams_townhall'), 'Teams town hall');
  });
}
