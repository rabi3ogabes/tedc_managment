import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/models.dart';
import 'package:tedc_mobile/core/providers.dart';
import 'package:tedc_mobile/features/auth/splash_screen.dart';

/// A signed-out start: the saved session is known at once, so only the animation decides how long the intro lasts.
class _SignedOut extends AuthController {
  @override
  Future<Me?> build() async => null;
}

Widget _app(ProviderContainer container) => UncontrolledProviderScope(container: container, child: const MaterialApp(home: SplashScreen()));

void main() {
  ProviderContainer makeContainer() => ProviderContainer(overrides: [authProvider.overrideWith(_SignedOut.new)]);

  testWidgets('the intro plays the animation, shows the name of the center, then lets the app open', (tester) async {
    final container = makeContainer();
    addTearDown(container.dispose);

    await tester.pumpWidget(_app(container));
    expect(container.read(introDoneProvider), isFalse);

    // Early on the emblem is arriving; the name is not there yet.
    await tester.pump(const Duration(milliseconds: 300));
    expect(tester.widget<Opacity>(find.ancestor(of: find.text('مركز التدريب والتطوير'), matching: find.byType(Opacity)).first).opacity, 0);

    // Mid-way the name has appeared.
    await tester.pump(const Duration(milliseconds: 1900));
    expect(find.text('مركز التدريب والتطوير'), findsOneWidget);
    expect(container.read(introDoneProvider), isFalse);

    // At the end the intro hands over to the app.
    await tester.pump(const Duration(milliseconds: 2000));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pump();
    expect(container.read(introDoneProvider), isTrue);
  });

  testWidgets('tapping skips ahead', (tester) async {
    final container = makeContainer();
    addTearDown(container.dispose);

    await tester.pumpWidget(_app(container));
    await tester.pump(const Duration(milliseconds: 400));
    await tester.tap(find.byType(SplashScreen));
    await tester.pump(const Duration(milliseconds: 300));
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pump();

    expect(container.read(introDoneProvider), isTrue, reason: 'a tap fast-forwards to the end instead of waiting 3.6 s');
  });

  testWidgets('with animations removed in the system settings the intro is brief', (tester) async {
    final container = makeContainer();
    addTearDown(container.dispose);

    tester.platformDispatcher.accessibilityFeaturesTestValue = const FakeAccessibilityFeatures(disableAnimations: true);
    addTearDown(tester.platformDispatcher.clearAccessibilityFeaturesTestValue);

    await tester.pumpWidget(_app(container));
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.text('مركز التدريب والتطوير'), findsOneWidget, reason: 'the finished picture is shown straight away');
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pump();
    expect(container.read(introDoneProvider), isTrue);
  });
}
