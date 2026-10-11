import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/response_cache.dart';
import '../providers.dart';
import 'brand.dart';

/// The ready-made look the administrator assigned to the signed-in person's group (Settings → App themes): card
/// shape, header, bottom bar, home-card order and its colours. With no look assigned every value is the app's own.
/// The last look is kept on disk and read before the first frame, so the app opens in it.
class AppStyle {
  static const _key = 'apptheme:config';

  static String? id;
  static double cardRadius = 20;
  static double buttonRadius = 14;
  static int elevation = 0;
  static String header = 'curved';
  static String? nav;
  static List<String>? home;
  static Color? primary;
  static Color? accent;
  static Color? background;

  static bool get active => id != null;

  /// Changes whenever the look does; part of the key that rebuilds every screen.
  static String get signature => '$id-$cardRadius-$buttonRadius-$elevation-$header-$nav';

  /// A card's corner for a larger surface (the hero cards), kept in proportion to the look.
  static double radius([double extra = 0]) => cardRadius + extra;

  static void reset() {
    id = null;
    cardRadius = 20;
    buttonRadius = 14;
    elevation = 0;
    header = 'curved';
    nav = null;
    home = null;
    primary = accent = background = null;
  }

  /// [data] is `/me/app-theme` → `data`.
  static void apply(Map data) {
    reset();
    Map map(Object? v) => v is Map ? v : const {};
    final shape = map(data['shape']);
    final colors = map(data['colors']);
    id = data['id']?.toString();
    cardRadius = (shape['card_radius'] as num?)?.toDouble().clamp(8, 36).toDouble() ?? 20;
    buttonRadius = (shape['button_radius'] as num?)?.toDouble().clamp(8, 28).toDouble() ?? 14;
    elevation = ((shape['elevation'] as num?)?.toInt() ?? 0).clamp(0, 4).toInt();
    final h = data['header'];
    header = h is String && const ['curved', 'flat', 'glass'].contains(h) ? h : 'curved';
    final n = data['nav'];
    nav = n is String && Brand.navStyles.contains(n) ? n : null;
    final order = data['home'];
    home = order is List ? order.whereType<String>().where(Brand.defaultHomeSections.contains).toList() : null;
    primary = Brand.hex(colors['primary']);
    accent = Brand.hex(colors['accent']);
    background = Brand.hex(colors['background']);
  }

  /// Read from disk before the first frame.
  static Future<void> load() async {
    try {
      final raw = await ResponseCache.instance.read(_key);
      if (raw is Map && raw['data'] is Map) {
        apply(raw['data'] as Map);
        BrandStore.current.applyColors();
      }
    } catch (_) {
      // A broken copy is ignored: the app's own look applies.
    }
  }

  static void remember(Object? raw) {
    if (raw is Map) unawaited(ResponseCache.instance.write(_key, raw));
  }
}

/// Applies the person's look as soon as it is known; drops it when nobody is signed in or no look is assigned.
final appThemeProvider = Provider<void>((ref) {
  final me = ref.watch(authProvider).value;
  if (me == null) {
    if (AppStyle.active) {
      AppStyle.reset();
      BrandStore.current.applyColors();
    }
    return;
  }
  final raw = ref.watch(getProvider('/me/app-theme')).value;
  if (raw is! Map) return;   // still loading, or the server did not answer: keep what is on screen
  final data = raw['data'];
  if (data is Map) {
    AppStyle.apply(data);
  } else {
    AppStyle.reset();
  }
  BrandStore.current.applyColors();
  AppStyle.remember(raw);
});
