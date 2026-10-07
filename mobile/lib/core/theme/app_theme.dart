import 'package:flutter/material.dart';

import 'brand.dart';
import 'remote_font.dart';

/// Qatar Government identity (gba.gco.gov.qa): Al Adaam maroon, Dune and off-white.
/// Token names are kept (navy = primary shades, gold = accent shades) so the
/// rest of the app follows the identity without changes.
class AppColors {
  static const _primary = Color(0xFF8A1538); // Al Adaam
  static const _accent = Color(0xFFA29475); // Dune
  static const _background = Color(0xFFF8F6F2);

  static Color _p = _primary, _a = _accent, _bg = _background;
  static Color _p950 = const Color(0xFF5A0E24), _p800 = const Color(0xFF932848), _p700 = const Color(0xFF9D3A58), _p100 = const Color(0xFFF1E3E7);
  static Color _a700 = const Color(0xFF756B54), _a300 = const Color(0xFFC3B9A5), _a100 = const Color(0xFFECEAE3);

  /// Takes the colours the administrator chose in the dashboard; the shades are derived from them. With no colours (or
  /// the Qatar identity itself) the exact original tokens apply. Call it before the app is built, and rebuild after.
  static void apply({Color? primary, Color? accent, Color? background}) {
    _p = primary ?? _primary;
    _a = accent ?? _accent;
    _bg = background ?? _background;
    if (primary == null || primary == _primary) {
      _p950 = const Color(0xFF5A0E24);
      _p800 = const Color(0xFF932848);
      _p700 = const Color(0xFF9D3A58);
      _p100 = const Color(0xFFF1E3E7);
    } else {
      _p950 = _shade(_p, l: -.2);
      _p800 = _shade(_p, l: .04);
      _p700 = _shade(_p, l: .09);
      _p100 = _tint(_p, .93);
    }
    if (accent == null || accent == _accent) {
      _a700 = const Color(0xFF756B54);
      _a300 = const Color(0xFFC3B9A5);
      _a100 = const Color(0xFFECEAE3);
    } else {
      _a700 = _shade(_a, l: -.14);
      _a300 = _shade(_a, l: .16);
      _a100 = _tint(_a, .92);
    }
  }

  static Color _shade(Color c, {required double l}) {
    final h = HSLColor.fromColor(c);
    return h.withLightness((h.lightness + l).clamp(.04, .96)).toColor();
  }

  static Color _tint(Color c, double lightness) {
    final h = HSLColor.fromColor(c);
    return h.withSaturation((h.saturation * .55).clamp(0, 1)).withLightness(lightness).toColor();
  }

  /// Changes whenever the palette does; a rebuilt app uses it as its key so every screen picks the new colours up.
  static String get signature => '${_p.toARGB32()}-${_a.toARGB32()}-${_bg.toARGB32()}';

  static Color get navy950 => _p950;
  static Color get navy900 => _p;
  static Color get navy800 => _p800;
  static Color get navy700 => _p700;
  static Color get navy100 => _p100;
  static Color get gold700 => _a700;
  static Color get gold500 => _a;
  static Color get gold300 => _a300;
  static Color get gold100 => _a100;
  static Color get ivory => _bg;
  static const ink = Color(0xFF1A1A1A);
  static const muted = Color(0xFF64748B);
  static const success = Color(0xFF0F8A5F);
  static const danger = Color(0xFFC0392B);
  static const warning = Color(0xFFB7791F);

  static LinearGradient get goldGradient => LinearGradient(colors: [_shade(_a, l: .03), _a, _shade(_a, l: -.06)]);
  static LinearGradient get navyGradient => LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [_p, _p700]);
}

class AppTheme {
  /// [brand] carries the colours the administrator chose; without them the Qatar identity above applies.
  static ThemeData light(Locale locale, [Brand brand = Brand.none]) {
    final primary = AppColors.navy900;
    final accent = AppColors.gold500;
    final ivory = AppColors.ivory;
    // The font the administrator chose for this language (downloaded once), else the bundled one.
    final font = (locale.languageCode == 'ar' ? RemoteFont.arabic : RemoteFont.latin) ?? 'Tajawal';
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(
        seedColor: primary,
        primary: primary,
        secondary: accent,
        surface: Colors.white,
      ),
      scaffoldBackgroundColor: ivory,
      fontFamily: font,
    );

    return base.copyWith(
      appBarTheme: AppBarTheme(
        backgroundColor: ivory,
        foregroundColor: primary,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(fontFamily: font, color: primary, fontSize: 20, fontWeight: FontWeight.w800),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: BorderSide(color: AppColors.navy100)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(50),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: TextStyle(fontFamily: font, fontWeight: FontWeight.w700, fontSize: 15),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: primary,
          minimumSize: const Size.fromHeight(48),
          side: BorderSide(color: AppColors.navy100),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: AppColors.navy100)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: AppColors.navy100)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: accent, width: 1.5)),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        indicatorColor: AppColors.gold100,
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontFamily: font,
            fontSize: 11,
            fontWeight: states.contains(WidgetState.selected) ? FontWeight.w800 : FontWeight.w500,
            color: states.contains(WidgetState.selected) ? AppColors.navy900 : AppColors.muted,
          ),
        ),
        iconTheme: WidgetStateProperty.resolveWith(
          (states) => IconThemeData(color: states.contains(WidgetState.selected) ? AppColors.gold700 : AppColors.muted),
        ),
      ),
      chipTheme: base.chipTheme.copyWith(side: BorderSide.none, backgroundColor: AppColors.navy100),
    );
  }
}
