import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/l10n/strings.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/auth/login_screen.dart';

Future<void> _pump(WidgetTester tester, bool sso, {bool mandatory = false, List<Map<String, String>> showcase = const []}) async {
  await tester.pumpWidget(ProviderScope(
    overrides: [
      getProvider('/public/mobile-config').overrideWith((ref) async => {'data': {'sso': sso, 'sso_mandatory': mandatory, 'demo_accounts': false}}),
      getProvider('/public/showcase-accounts').overrideWith((ref) async => {'data': showcase}),
    ],
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

  testWidgets('the showcase accounts the server offers are one tap each on the sign-in screen', (tester) async {
    await _pump(tester, false, showcase: const [
      {'key': 'supervisor-1', 'group': 'supervision', 'name': 'Demo supervisor 1'},
      {'key': 'trainee-1', 'group': 'learners', 'name': 'Demo trainee 1'},
    ]);
    // The sign-in screen is a list built as it scrolls: the panel sits below the form.
    await tester.scrollUntilVisible(find.byKey(const ValueKey('showcase-accounts')), 300, scrollable: find.byType(Scrollable).first);
    expect(find.byKey(const ValueKey('showcase-accounts')), findsOneWidget);
    expect(find.byKey(const ValueKey('showcase-trainee-1')), findsOneWidget);
    expect(find.text('Demo supervisor 1'), findsOneWidget);
    expect(find.text('Trainee'), findsOneWidget);
  });

  testWidgets('and nothing shows when the server offers none', (tester) async {
    await _pump(tester, false);
    await tester.scrollUntilVisible(find.byKey(const ValueKey('centre-guide')), 300, scrollable: find.byType(Scrollable).first);
    expect(find.byKey(const ValueKey('showcase-accounts')), findsNothing);
  });

  test('a Teams town hall has its own name and note, in both languages', () {
    for (final lang in ['ar', 'en']) {
      final s = S(lang);
      expect(s.t('session.platform.teams_townhall'), isNot('session.platform.teams_townhall'));
      expect(s.t('session.townhallHint'), isNot('session.townhallHint'));
      expect(s.t('auth.usePassword'), isNot('auth.usePassword'));
      for (final key in ['auth.showcase.title', 'auth.showcase.hint', 'auth.showcase.supervision', 'auth.showcase.training', 'auth.showcase.learners']) {
        expect(s.t(key), isNot(key));
      }
    }
    expect(S('en').t('session.platform.teams_townhall'), 'Teams town hall');
  });
}
