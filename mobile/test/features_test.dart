import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/providers.dart';

void main() {
  test('a feature is on only when the server says so; anything else is off', () async {
    final container = ProviderContainer(overrides: [
      getProvider('/features').overrideWith((ref) async => {
            'data': {'flags': {'ai': true, 'payments': false}, 'unsafe_active': [], 'environment': 'production'},
          }),
    ]);
    addTearDown(container.dispose);

    // Auto-dispose providers live only while somebody listens.
    container.listen(getProvider('/features'), (_, _) {});
    container.listen(featuresProvider, (_, _) {});
    await container.read(getProvider('/features').future);
    final flags = container.read(featuresProvider);

    expect(flags['ai'], isTrue);
    expect(flags['payments'], isFalse);
    expect(flags['not_a_flag'], isNull, reason: 'featureOn() treats a missing flag as off');
  });
}
