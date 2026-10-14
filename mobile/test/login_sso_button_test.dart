import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/auth/login_screen.dart';

Future<void> _pump(WidgetTester tester, bool sso) async {
  await tester.pumpWidget(ProviderScope(
    overrides: [getProvider('/public/mobile-config').overrideWith((ref) async => {'data': {'sso': sso, 'demo_accounts': false}})],
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
  });

  testWidgets('and not otherwise', (tester) async {
    await _pump(tester, false);
    expect(find.byKey(const ValueKey('sso')), findsNothing);
  });
}
