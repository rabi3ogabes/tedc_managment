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
}
