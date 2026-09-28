import 'dart:convert';
import 'dart:io';

import 'package:path_provider/path_provider.dart';

/// Small on-disk copy of the last successful GET responses, used to show content
/// instantly when the network is slow or unavailable. Cleared on sign-out.
class ResponseCache {
  ResponseCache._();

  static final ResponseCache instance = ResponseCache._();
  static const maxAge = Duration(days: 7);

  Directory? _dir;

  Future<Directory> _root() async {
    if (_dir != null) return _dir!;
    final base = await getApplicationSupportDirectory();
    return _dir = await Directory('${base.path}/api_cache').create(recursive: true);
  }

  /// Stable FNV-1a hash so file names survive app restarts.
  static String _key(String key) {
    var hash = 0x811c9dc5;
    for (final unit in utf8.encode(key)) {
      hash = ((hash ^ unit) * 0x01000193) & 0xffffffff;
    }
    return hash.toRadixString(16).padLeft(8, '0');
  }

  Future<dynamic> read(String key) async {
    try {
      final file = File('${(await _root()).path}/${_key(key)}.json');
      if (!await file.exists()) return null;
      if (DateTime.now().difference(await file.lastModified()) > maxAge) return null;
      final entry = jsonDecode(await file.readAsString()) as Map<String, dynamic>;
      return entry['k'] == key ? entry['v'] : null;
    } catch (_) {
      return null;
    }
  }

  Future<void> write(String key, dynamic value) async {
    try {
      final file = File('${(await _root()).path}/${_key(key)}.json');
      await file.writeAsString(jsonEncode({'k': key, 'v': value}), flush: false);
    } catch (_) {
      // Caching is best effort.
    }
  }

  Future<void> clear() async {
    try {
      final dir = await _root();
      if (await dir.exists()) await dir.delete(recursive: true);
      _dir = null;
    } catch (_) {
      // Ignore.
    }
  }
}
