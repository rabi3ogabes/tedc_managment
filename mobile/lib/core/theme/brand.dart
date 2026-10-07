import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../providers.dart';

/// What the administrator chose in the dashboard (Settings → Appearance): brand colours, and the occasion in force
/// (Ramadan, Eid, National Day …) with its loading message. Read from `/public/mobile-config`; the last copy is kept on
/// disk, so the app opens with the right colours even before the network answers.
class Brand {
  const Brand({this.primary, this.accent, this.background, this.occasionName, this.occasionId, this.message});

  final Color? primary;
  final Color? accent;
  final Color? background;
  final String? occasionId;
  final String? occasionName;
  final String? message;

  static const none = Brand();

  /// `#RRGGBB` → colour; anything else is ignored so a bad value can never break the theme.
  static Color? hex(Object? v) {
    if (v is! String || !RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(v)) return null;
    return Color(0xFF000000 | int.parse(v.substring(1), radix: 16));
  }

  factory Brand.fromConfig(Object? raw, {required bool arabic}) {
    final data = raw is Map ? raw['data'] : null;
    if (data is! Map) return none;
    final brand = data['brand'] is Map ? data['brand'] as Map : const {};
    final occasion = data['occasion'] is Map ? data['occasion'] as Map : null;
    final loading = data['loading'] is Map ? data['loading'] as Map : const {};
    final lang = arabic ? 'ar' : 'en';
    String? text(Object? v) => v is String && v.trim().isNotEmpty ? v : null;
    return Brand(
      primary: hex(brand['primary']),
      accent: hex(brand['accent']),
      background: hex(brand['background']),
      occasionId: occasion == null ? null : text(occasion['id']),
      occasionName: occasion == null ? null : text(occasion['name_$lang']),
      message: occasion == null ? null : text(loading['message_$lang']),
    );
  }
}

final brandProvider = Provider<Brand>((ref) {
  final locale = ref.watch(localeProvider);
  final config = ref.watch(getProvider('/public/mobile-config')).value;
  return Brand.fromConfig(config, arabic: locale.languageCode == 'ar');
});
