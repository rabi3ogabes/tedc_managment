import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/response_cache.dart';
import '../providers.dart';
import 'app_theme.dart';
import 'remote_font.dart';

/// What the administrator chose in the dashboard (Settings → Appearance): brand colours, fonts, the loading page and the
/// occasion in force (Ramadan, Eid, National Day …). Read from `/public/mobile-config`; the last copy is kept on disk and
/// read before the app starts, so it opens with the right look even before the network answers.
class Brand {
  const Brand({
    this.primary,
    this.accent,
    this.background,
    this.occasionName,
    this.occasionId,
    this.message,
    this.loadingStyle = 'emblem',
    this.loadingBackground,
    this.loadingAccent,
    this.arabicFont,
    this.latinFont,
    this.arabicFontUrl,
    this.latinFontUrl,
  });

  final Color? primary;
  final Color? accent;
  final Color? background;
  final String? occasionId;
  final String? occasionName;

  /// The greeting set for the loading page (always shown with an occasion; otherwise only if the administrator wrote one).
  final String? message;
  final String loadingStyle;
  final Color? loadingBackground;
  final Color? loadingAccent;
  final String? arabicFont;
  final String? latinFont;
  final String? arabicFontUrl;
  final String? latinFontUrl;

  static const none = Brand();
  static const styles = ['emblem', 'bar', 'dots', 'pulse', 'crescent'];

  /// `#RRGGBB` → colour; anything else is ignored so a bad value can never break the theme.
  static Color? hex(Object? v) {
    if (v is! String || !RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(v)) return null;
    return Color(0xFF000000 | int.parse(v.substring(1), radix: 16));
  }

  factory Brand.fromConfig(Object? raw, {required bool arabic}) {
    final data = raw is Map ? raw['data'] : null;
    if (data is! Map) return none;
    Map map(Object? v) => v is Map ? v : const {};
    final brand = map(data['brand']);
    final occasion = data['occasion'] is Map ? data['occasion'] as Map : null;
    final loading = map(data['loading']);
    final type = map(data['typography']);
    final lang = arabic ? 'ar' : 'en';
    String? text(Object? v) => v is String && v.trim().isNotEmpty ? v : null;
    final style = loading['style'];
    return Brand(
      primary: hex(brand['primary']),
      accent: hex(brand['accent']),
      background: hex(brand['background']),
      occasionId: occasion == null ? null : text(occasion['id']),
      occasionName: occasion == null ? null : text(occasion['name_$lang']),
      message: text(loading['message_$lang']),
      loadingStyle: style is String && styles.contains(style)
          ? style
          : 'emblem',
      loadingBackground: hex(loading['background']),
      loadingAccent: hex(loading['accent']),
      arabicFont: text(type['arabic_family']),
      latinFont: text(type['latin_family']),
      arabicFontUrl: text(type['arabic_font_url']),
      latinFontUrl: text(type['latin_font_url']),
    );
  }

  /// The colours, as the app applies them.
  void applyColors() =>
      AppColors.apply(primary: primary, accent: accent, background: background);
}

/// The look read from disk before the first frame (no network), and kept up to date when the config arrives.
class BrandStore {
  static const _key = 'brand:config';

  /// What the launch intro and the first frames use.
  static Brand current = Brand.none;
  static bool _arabic = true;

  /// Called from `main()`: reads the last copy, applies the colours and registers the fonts that are already on disk.
  static Future<void> load() async {
    try {
      final raw = await ResponseCache.instance.read(_key);
      if (raw is Map) {
        _adopt(raw, arabic: true);
        await RemoteFont.restore(current);
      }
    } catch (_) {
      // A broken copy is simply ignored: the default identity applies.
    }
  }

  static void _adopt(Object? raw, {required bool arabic}) {
    _arabic = arabic;
    current = Brand.fromConfig(raw, arabic: arabic);
    current.applyColors();
  }

  /// A fresh config from the server: applied now and kept for the next launch.
  static Brand remember(Object? raw, {required bool arabic}) {
    _adopt(raw, arabic: arabic);
    if (raw is Map) unawaited(ResponseCache.instance.write(_key, raw));
    return current;
  }

  static bool get arabic => _arabic;
}

/// Bumps when a font finishes downloading so the theme is built again with it.
final fontEpochProvider = NotifierProvider<_Epoch, int>(_Epoch.new);

class _Epoch extends Notifier<int> {
  @override
  int build() => 0;
  void bump() => state++;
}

final brandProvider = Provider<Brand>((ref) {
  final locale = ref.watch(localeProvider);
  final config = ref.watch(getProvider('/public/mobile-config')).value;
  final arabic = locale.languageCode == 'ar';
  if (config is! Map) return BrandStore.current;
  final brand = BrandStore.remember(config, arabic: arabic);
  unawaited(
    RemoteFont.ensure(brand).then((changed) {
      if (changed) ref.read(fontEpochProvider.notifier).bump();
    }),
  );
  return brand;
});
