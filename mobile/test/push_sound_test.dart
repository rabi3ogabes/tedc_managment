import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/push/push_service.dart';

void main() {
  test('a notification in the foreground shows the banner and plays the chime only when the sound is on', () async {
    var played = 0;
    final container = ProviderContainer(overrides: [
      chimePlayerProvider.overrideWithValue(() async {
        played++;
        return true;
      }),
    ]);
    addTearDown(container.dispose);
    final push = container.read(pushServiceProvider);
    final banners = <PushMessage>[];
    final sub = push.messages.listen(banners.add);
    addTearDown(sub.cancel);
    const message = RemoteMessage(notification: RemoteNotification(title: 'New reply'), data: {'type': 'announcement'});

    push.soundOn = false;
    await push.foreground(message);
    expect(played, 0);

    push.soundOn = true;
    await push.foreground(message);
    expect(played, 1);

    await Future<void>.delayed(Duration.zero);
    expect(banners.map((b) => b.title), ['New reply', 'New reply']);
  });

  test('a data-only message neither shows a banner nor plays the chime', () async {
    var played = 0;
    final container = ProviderContainer(overrides: [
      chimePlayerProvider.overrideWithValue(() async {
        played++;
        return true;
      }),
    ]);
    addTearDown(container.dispose);
    final push = container.read(pushServiceProvider)..soundOn = true;

    await push.foreground(const RemoteMessage(data: {'type': 'sync'}));

    expect(played, 0);
  });
}
