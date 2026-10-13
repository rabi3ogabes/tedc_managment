import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/offline_queue.dart';

void main() {
  test('watched stretches of one lesson are united and the furthest position wins', () {
    final q = OfflineQueue();
    q.videoProgress('l1', 0, 30);
    q.videoProgress('l1', 30, 60, position: 60);
    q.videoProgress('l1', 10, 20, position: 20);
    expect(q.items, hasLength(1));
    expect(q.items.first['segments'], [[0.0, 60.0]]);
    expect(q.items.first['position'], 60.0);
  });

  test('the same batch key is used for retries and a new one after confirmation', () {
    final q = OfflineQueue()..lessonComplete('l1')..lessonComplete('l1');
    final first = q.batch()['batch_id'];
    expect(q.items, hasLength(1));
    expect(q.batch()['batch_id'], first);
    q.confirmed();
    expect(q.isEmpty, isTrue);
    q.lessonComplete('l2');
    expect(q.batch()['batch_id'], isNot(first));
  });

  test('the queue survives being saved and loaded', () {
    final q = OfflineQueue()..videoProgress('l1', 0, 10)..slideView('l2', 3, 10);
    q.batch();
    final copy = OfflineQueue.fromJson(q.toJson());
    expect(copy.items, hasLength(2));
    expect(copy.batch()['batch_id'], q.batch()['batch_id']);
  });

  test('every slide view is kept with the time it happened, in order', () {
    final t = DateTime.utc(2026, 10, 10, 10);
    final q = OfflineQueue()
      ..slideView('l2', 1, 10, at: t)
      ..slideView('l2', 2, 10, at: t.add(const Duration(seconds: 9)))
      ..slideView('l2', 1, 10, at: t.add(const Duration(seconds: 20)));
    final views = q.items.where((i) => i['type'] == 'slide_view').toList();
    expect(views.map((v) => v['slide']), [1, 2, 1]);
    expect(views.map((v) => DateTime.parse(v['at'] as String)), [t, t.add(const Duration(seconds: 9)), t.add(const Duration(seconds: 20))]);
  });

  test('what is added while a batch is on its way goes in the next batch, never under the sent key', () {
    final q = OfflineQueue()..lessonComplete('l1');
    final sent = q.batch();
    q.videoProgress('l3', 0, 10);
    expect(q.batch()['batch_id'], sent['batch_id']);
    expect(q.batch()['items'], hasLength(1));
    q.confirmed();
    final next = q.batch();
    expect(next['batch_id'], isNot(sent['batch_id']));
    expect((next['items'] as List).single['lesson_id'], 'l3');
    q.confirmed();
    expect(q.isEmpty, isTrue);
  });

  test('a batch carries at most 500 items', () {
    final q = OfflineQueue();
    for (var i = 0; i < 520; i++) {
      q.slideView('l1', i % 10 + 1, 10);
    }
    expect(q.batch()['items'], hasLength(500));
    q.confirmed();
    expect(q.batch()['items'], hasLength(20));
  });
}
