import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/services.dart';
import 'package:path_provider/path_provider.dart';

import 'brand.dart';

/// Fonts the administrator picks in the dashboard: a family from the font library (every weight, OpenType / TrueType), an
/// uploaded file by address, or a Google font by name. They are downloaded once, kept on disk, and registered with Flutter;
/// while one is not available the bundled Tajawal is used. The files are kept under a name made from their addresses, so a
/// family whose files the administrator replaced is downloaded again.
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

  static Future<Directory> _dir(String family, List<String> urls) async {
    final base = await getApplicationSupportDirectory();
    final key = urls.isEmpty ? '' : '-${_hash(urls.join('|'))}';
    return Directory('${base.path}/remote_fonts/${_name(family)}$key')
        .create(recursive: true);
  }

  /// A short, stable fingerprint (FNV-1a) of the file addresses.
  static String _hash(String s) {
    var h = 0x811c9dc5;
    for (final c in s.codeUnits) {
      h = ((h ^ c) * 0x01000193) & 0xffffffff;
    }
    return h.toRadixString(16);
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

  static Future<List<Uint8List>> _cached(String family, List<String> urls) async {
    final dir = await _dir(family, urls);
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
    _arabic = await _family(brand.arabicFont, brand.arabicFontFiles.isNotEmpty ? brand.arabicFontFiles : _one(brand.arabicFontUrl), download);
    _latin = await _family(brand.latinFont, brand.latinFontFiles.isNotEmpty ? brand.latinFontFiles : _one(brand.latinFontUrl), download);
  }

  static List<String> _one(String? url) =>
      url != null && Uri.tryParse(url)?.scheme == 'https' ? [url] : const [];

  static Future<String?> _family(
    String? family,
    List<String> urls,
    bool download,
  ) async {
    if (family == null || _bundled.contains(family) || !_safe(family)) {
      return null;
    }
    // The Ministry's Lusail is licensed: it comes only from the font library, never from Google Fonts.
    if (urls.isEmpty && family == 'Lusail') return null;
    try {
      var files = await _cached(family, urls);
      if (files.isEmpty && download) {
        files = await _download(family, urls);
        if (files.isNotEmpty) {
          final dir = await _dir(family, urls);
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

  static Future<List<Uint8List>> _download(String family, List<String> urls) async {
    final dio = Dio(
      BaseOptions(
        connectTimeout: const Duration(seconds: 8),
        receiveTimeout: const Duration(seconds: 20),
        responseType: ResponseType.bytes,
      ),
    );
    Future<Uint8List> fetch(String u) async =>
        Uint8List.fromList((await dio.get<List<int>>(u)).data!);
    if (urls.isNotEmpty) {
      return [for (final u in urls.take(12)) await fetch(u)];
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
    final found = RegExp(r'url\((https://fonts\.gstatic\.com/[^)]+)\)')
        .allMatches(css)
        .map((m) => m.group(1)!)
        .toSet()
        .take(3);
    return [for (final u in found) await fetch(u)];
  }
}
