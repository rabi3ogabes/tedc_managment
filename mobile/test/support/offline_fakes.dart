import 'dart:async';
import 'dart:convert';

import 'package:crypto/crypto.dart';
import 'package:tedc_mobile/core/offline/offline_store.dart';

class FakeTransport implements OfflineTransport {
  FakeTransport(this.files, this.manifestJson);

  final Map<String, List<int>> files;
  Map<String, dynamic> manifestJson;
  final offsets = <String, int>{};
  Object? failWith;

  @override
  Future<Map<String, dynamic>> manifest(String registrationId, String deviceId, String platform) async => manifestJson;

  @override
  Future<RangeResponse> fetch(String url, int offset) async {
    offsets[url] = offset;
    final rest = files[url]!.sublist(offset);
    if (failWith != null) {
      // Half the file arrives, then the write fails.
      final c = StreamController<List<int>>()
        ..add(rest.sublist(0, rest.length ~/ 2))
        ..addError(failWith!)
        ..close();
      return RangeResponse(c.stream, partial: offset > 0, total: files[url]!.length);
    }
    return RangeResponse(Stream.value(rest), partial: offset > 0, total: files[url]!.length);
  }
}

class FakeNetwork implements NetworkInfo {
  bool wifi = true;
  @override
  Future<bool> onWifi() async => wifi;
}

/// A manifest like the server's (v2) with one video, one article and one presentation the author keeps online.
Map<String, dynamic> fakeManifest(List<int> video, {String? sha, String expires = '2099-01-01T00:00:00Z', bool delete = false, bool revoked = false, String? key}) => {
      'manifest_version': 2, 'delete': delete, 'revoked': revoked, 'withdrawn': delete && !revoked, 'content_key': delete ? null : (key ?? base64Encode(List<int>.generate(32, (i) => i))),
      'lessons': delete ? [] : [
        {'id': 'v1', 'type': 'video', 'title_ar': 'فيديو', 'title_en': 'Video', 'downloadable': true, 'url': 'https://files.test/v1', 'sha256': sha ?? sha256.convert(video).toString(), 'size': video.length, 'expires_at': expires, 'mime': 'video/mp4', 'settings': {'min_watch_percent': 90}},
        {'id': 'a1', 'type': 'article', 'title_ar': 'مقال', 'title_en': 'Article', 'downloadable': true, 'body_ar': 'نص المقال', 'body_en': 'The text', 'expires_at': expires},
        {'id': 'x1', 'type': 'presentation', 'title_ar': 'عرض', 'title_en': 'Slides', 'downloadable': false, 'reason': 'not_downloadable'},
      ],
    };
