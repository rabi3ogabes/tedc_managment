import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';

import 'brand.dart';

/// Fonts the administrator picks in the dashboard (a Google font by name, or an uploaded file by address). They are
/// downloaded once, kept on disk, and registered with Flutter; while one is not available the bundled Tajawal is used.
class RemoteFont {
  /// Families the app already carries (or that the website bundles but the app does not need to fetch).
  static const _bundled = {'Qatar Sans', 'Tajawal'};

  static String? _arabic;
  static String? _latin;

  /// The family names in force for each script (null → the bundled font).
  static String? get arabic => _arabic;
  static String? get latin => _latin;

  static String _name(String family) =>
      'Remote-${family.replaceAll(RegExp(r'[^A-Za-z0-9]'), '')}';

  static bool _safe(String family) =>
      RegExp(r'^[A-Za-z0-9 ]{2,40}$').hasMatch(family);

  static Future<Directory> _dir(String family) async {
    final base = await getApplicationSupportDirectory();
    return Directory('${base.path}/remote_fonts/${_name(family)}')
        .create(recursive: true);
  }

  static final _registered = <String>{};

  static Future<bool> _register(String family, List<Uint8List> files) async {
    final name = _name(family);
    if (files.isEmpty || !_registered.add(name)) return false;
    final loader = FontLoader(name);
    for (final f in files) {
      loader.addFont(Future.value(ByteData.sublistView(f)));
    }
    await loader.load();
    return true;
  }

  static Future<List<Uint8List>> _cached(String family) async {
    final dir = await _dir(family);
    final files =
        dir
            .listSync()
            .whereType<File>()
            .where((f) => f.path.endsWith('.ttf') || f.path.endsWith('.otf'))
            .toList()
          ..sort((a, b) => a.path.compareTo(b.path));
    return [for (final f in files) await f.readAsBytes()];
  }

  /// At start: registers what is already on disk (no network).
  static Future<void> restore(Brand brand) async {
    await _apply(brand, download: false);
  }

  /// With a fresh config: downloads what is missing. True when the theme should be built again.
  static Future<bool> ensure(Brand brand) async {
    final before = '$_arabic|$_latin';
    await _apply(brand, download: true);
    return before != '$_arabic|$_latin';
  }

  static Future<void> _apply(Brand brand, {required bool download}) async {
    _arabic = await _family(brand.arabicFont, brand.arabicFontUrl, download);
    _latin = await _family(brand.latinFont, brand.latinFontUrl, download);
  }

  static Future<String?> _family(
    String? family,
    String? url,
    bool download,
  ) async {
    if (family == null || _bundled.contains(family) || !_safe(family)) {
      return null;
    }
    try {
      var files = await _cached(family);
      if (files.isEmpty && download) {
        files = await _download(family, url);
        if (files.isNotEmpty) {
          final dir = await _dir(family);
          for (var i = 0; i < files.length; i++) {
            await File('${dir.path}/$i.ttf').writeAsBytes(files[i]);
          }
        }
      }
      if (files.isEmpty) return null;
      await _register(family, files);
      return _name(family);
    } catch (_) {
      return null; // offline or blocked: the bundled font stays
    }
  }

  static Future<List<Uint8List>> _download(String family, String? url) async {
    final dio = Dio(
      BaseOptions(
        connectTimeout: const Duration(seconds: 8),
        receiveTimeout: const Duration(seconds: 20),
        responseType: ResponseType.bytes,
      ),
    );
    Future<Uint8List> fetch(String u) async =>
        Uint8List.fromList((await dio.get<List<int>>(u)).data!);
    if (url != null && Uri.tryParse(url)?.scheme == 'https') {
      return [await fetch(url)];
    }
    // Google Fonts answers with plain TrueType files when the caller is not a browser.
    final css =
        (await Dio(
              BaseOptions(
                connectTimeout: const Duration(seconds: 8),
                responseType: ResponseType.plain,
              ),
            ).get<String>(
              'https://fonts.googleapis.com/css2?family=${family.replaceAll(' ', '+')}:wght@400;500;700',
            ))
            .data!;
    final urls = RegExp(r'url\((https://fonts\.gstatic\.com/[^)]+)\)')
        .allMatches(css)
        .map((m) => m.group(1)!)
        .toSet()
        .take(3);
    return [for (final u in urls) await fetch(u)];
  }
}
