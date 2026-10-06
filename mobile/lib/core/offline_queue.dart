import 'dart:convert';
import 'dart:math';

/// Progress made without a connection, kept until it can be sent. Events for the same lesson are merged
/// (watched stretches are united, the furthest position wins) and the batch carries one idempotency key,
/// so a retry after a dropped connection never counts twice. The server never lowers progress.
class OfflineQueue {
  // A private field cannot be a named parameter, so the batch id is assigned in the initializer list.
  // ignore: prefer_initializing_formals
  OfflineQueue({List<Map<String, dynamic>>? items, String? batchId}) : _items = items ?? [], _batchId = batchId;

  final List<Map<String, dynamic>> _items;
  String? _batchId;

  List<Map<String, dynamic>> get items => List.unmodifiable(_items);
  bool get isEmpty => _items.isEmpty;

  /// A stretch of video watched offline, in seconds.
  void videoProgress(String lessonId, double from, double to, {double? position}) {
    if (to <= from) return;
    final existing = _items.where((i) => i['type'] == 'video_progress' && i['lesson_id'] == lessonId).toList();
    if (existing.isEmpty) {
      _items.add({'type': 'video_progress', 'lesson_id': lessonId, 'segments': [[from, to]], 'position': position ?? to});
      return;
    }
    final item = existing.first;
    item['segments'] = merge([...(item['segments'] as List).map((s) => [(s[0] as num).toDouble(), (s[1] as num).toDouble()]), [from, to]]);
    item['position'] = max((item['position'] as num).toDouble(), position ?? to);
  }

  void lessonComplete(String lessonId) {
    if (_items.any((i) => i['type'] == 'lesson_complete' && i['lesson_id'] == lessonId)) return;
    _items.add({'type': 'lesson_complete', 'lesson_id': lessonId});
  }

  void slideView(String lessonId, int slide, int total) {
    _items.removeWhere((i) => i['type'] == 'slide_view' && i['lesson_id'] == lessonId && (i['slide'] as int) <= slide);
    _items.add({'type': 'slide_view', 'lesson_id': lessonId, 'slide': slide, 'total': total});
  }

  /// Unites overlapping or touching stretches.
  static List<List<double>> merge(List<List<double>> segments) {
    final sorted = [...segments]..sort((a, b) => a[0].compareTo(b[0]));
    final out = <List<double>>[];
    for (final s in sorted) {
      if (out.isNotEmpty && s[0] <= out.last[1] + 0.5) {
        out.last[1] = max(out.last[1], s[1]);
      } else {
        out.add([s[0], s[1]]);
      }
    }
    return out;
  }

  /// The batch to send. The same key is reused until the server confirms it.
  Map<String, dynamic> batch() {
    _batchId ??= 'b-${DateTime.now().microsecondsSinceEpoch}-${Random().nextInt(1 << 20)}';
    return {'batch_id': _batchId, 'items': _items};
  }

  /// The server accepted the batch (or already had it): the queue is emptied and the next batch gets a new key.
  void confirmed() {
    _items.clear();
    _batchId = null;
  }

  String toJson() => jsonEncode({'batch_id': _batchId, 'items': _items});

  factory OfflineQueue.fromJson(String s) {
    final m = jsonDecode(s) as Map<String, dynamic>;
    return OfflineQueue(items: (m['items'] as List).map((e) => Map<String, dynamic>.from(e as Map)).toList(), batchId: m['batch_id'] as String?);
  }
}
