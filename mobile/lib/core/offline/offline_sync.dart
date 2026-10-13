import 'dart:async';

import '../api/api_client.dart';
import '../offline_queue.dart';
import 'offline_store.dart';
import 'secure_kv.dart';

class SyncResult {
  const SyncResult({required this.applied, required this.rejected, required this.purged, required this.revoked});

  final int applied;
  final int rejected;

  /// Courses deleted from the phone because the server said so (withdrawn).
  final int purged;

  /// The phone was removed from the person's offline devices: everything kept on it was deleted.
  final bool revoked;
}

/// Sends what was learned without a connection once the phone is back online (`POST me/sync`), and deletes what the
/// server says must go. The queue is kept in the keystore, so closing the app loses nothing, and a batch keeps its
/// idempotency key until the server answers, so a retry never counts twice.
class OfflineSync {
  OfflineSync({required this.kv, required this.store, required this.send});

  static const queueName = 'tedc.offline.queue';

  final SecureKv kv;
  final OfflineStore store;

  /// `POST me/sync`, answering the `data` of the response.
  final Future<Map<String, dynamic>> Function(Map<String, dynamic> body) send;

  static Future<void> _lock = Future.value();

  /// One change to the queue at a time, also across screens.
  Future<T> _locked<T>(Future<T> Function() fn) {
    final previous = _lock;
    final done = Completer<void>();
    _lock = done.future;
    return previous.then((_) => fn()).whenComplete(done.complete);
  }

  Future<OfflineQueue> _load() async {
    final raw = await kv.read(queueName);
    if (raw == null) return OfflineQueue();
    try {
      return OfflineQueue.fromJson(raw);
    } catch (_) {
      return OfflineQueue();
    }
  }

  Future<void> _save(OfflineQueue q) => q.isEmpty ? kv.delete(queueName) : kv.write(queueName, q.toJson());

  /// Adds progress made without a connection.
  Future<void> record(void Function(OfflineQueue q) change) => _locked(() async {
        final q = await _load();
        change(q);
        await _save(q);
      });

  /// How many events wait to be sent.
  Future<int> pending() async => (await _load()).items.length;

  /// Sends the waiting progress (in batches the server accepts) and applies the server's purge. Null when there was
  /// nothing to do or the server could not be reached; what was not sent stays for the next time.
  Future<SyncResult?> flush() => _locked(() async {
        var applied = 0;
        var rejected = 0;
        var purged = 0;
        var revoked = false;
        var answered = false;
        for (var round = 0; round < 20; round++) {
          final q = await _load();
          final held = await store.held();
          if (q.isEmpty && (held.isEmpty || answered)) break;
          final body = {...q.batch(), 'device_id': await store.deviceId(), 'held': held};
          await _save(q); // the batch and its key survive a crash while it is on its way
          final Map<String, dynamic> res;
          try {
            res = await send(body);
          } on ApiException catch (e) {
            // A batch the server refuses as invalid would be refused for ever; anything else is tried again later.
            if (e.status == 422) {
              q.confirmed();
              await _save(q);
              continue;
            }
            return answered ? SyncResult(applied: applied, rejected: rejected, purged: purged, revoked: revoked) : null;
          } catch (_) {
            return answered ? SyncResult(applied: applied, rejected: rejected, purged: purged, revoked: revoked) : null;
          }
          answered = true;
          q.confirmed();
          await _save(q);
          applied += (res['applied'] as num?)?.toInt() ?? 0;
          rejected += ((res['rejected'] as List?) ?? const []).length;
          if (res['device_revoked'] == true) {
            await store.forgetDevice();
            revoked = true;
            break;
          }
          for (final reg in ((res['purge'] as List?) ?? const []).whereType<String>()) {
            await store.removeCourse(reg);
            purged++;
          }
        }
        return answered ? SyncResult(applied: applied, rejected: rejected, purged: purged, revoked: revoked) : null;
      });
}
