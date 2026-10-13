import 'dart:convert';
import 'dart:math';

/// Progress made without a connection, kept until it can be sent. Watched stretches of one lesson are merged (the
/// furthest position wins); every slide view is kept with the time it happened, because the server credits a slide
/// only after the lesson's minimum time per slide and refuses views out of order. A batch carries one idempotency key
/// and is frozen once sent: what is added meanwhile waits for the next batch, so a retry never counts twice and never
/// hides new progress behind a key the server already answered. The server never lowers progress.
class OfflineQueue {
  OfflineQueue({List<Map<String, dynamic>>? items, List<Map<String, dynamic>>? sending, String? batchId})
      : _items = items ?? [],
        _sending = sending,
        _batchId = sending == null ? null : batchId;

  /// The most the server takes in one batch.
  static const maxBatch = 500;

  final List<Map<String, dynamic>> _items;
  List<Map<String, dynamic>>? _sending;
  String? _batchId;

  /// Everything not yet confirmed: the batch on its way first, then what came after.
  List<Map<String, dynamic>> get items => List.unmodifiable([...?_sending, ..._items]);
  bool get isEmpty => _items.isEmpty && (_sending?.isEmpty ?? true);

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
    if (items.any((i) => i['type'] == 'lesson_complete' && i['lesson_id'] == lessonId)) return;
    _items.add({'type': 'lesson_complete', 'lesson_id': lessonId});
  }

  /// A slide shown for the lesson's minimum time, with when that was.
  void slideView(String lessonId, int slide, int total, {DateTime? at}) {
    _items.add({'type': 'slide_view', 'lesson_id': lessonId, 'slide': slide, 'total': total, 'at': (at ?? DateTime.now()).toUtc().toIso8601String()});
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

  /// The batch to send. The same key and the same items are sent again until the server confirms them.
  Map<String, dynamic> batch() {
    if (_sending == null) {
      final take = min(maxBatch, _items.length);
      _sending = _items.sublist(0, take);
      _items.removeRange(0, take);
      _batchId = 'b-${DateTime.now().microsecondsSinceEpoch}-${Random().nextInt(1 << 20)}';
    }
    return {'batch_id': _batchId, 'items': _sending};
  }

  /// The server accepted the batch (or already had it): it leaves the queue and the next batch gets a new key.
  void confirmed() {
    _sending = null;
    _batchId = null;
  }

  String toJson() => jsonEncode({'batch_id': _batchId, 'sending': _sending, 'items': _items});

  factory OfflineQueue.fromJson(String s) {
    final m = jsonDecode(s) as Map<String, dynamic>;
    List<Map<String, dynamic>>? list(Object? v) => v is List ? v.map((e) => Map<String, dynamic>.from(e as Map)).toList() : null;
    return OfflineQueue(items: list(m['items']), sending: list(m['sending']), batchId: m['batch_id'] as String?);
  }
}
