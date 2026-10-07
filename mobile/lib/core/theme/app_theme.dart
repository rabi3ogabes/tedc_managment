import 'package:flutter/material.dart';

import 'brand.dart';

/// Qatar Government identity (gba.gco.gov.qa): Al Adaam maroon, Dune and off-white.
/// Token names are kept (navy = primary shades, gold = accent shades) so the
/// rest of the app follows the identity without changes.
class AppColors {
  static const navy950 = Color(0xFF5A0E24);
  static const navy900 = Color(0xFF8A1538); // Al Adaam
  static const navy800 = Color(0xFF932848);
  static const navy700 = Color(0xFF9D3A58);
  static const navy100 = Color(0xFFF1E3E7);
  static const gold700 = Color(0xFF756B54);
  static const gold500 = Color(0xFFA29475); // Dune
  static const gold300 = Color(0xFFC3B9A5);
  static const gold100 = Color(0xFFECEAE3);
  static const ivory = Color(0xFFF8F6F2);
  static const ink = Color(0xFF1A1A1A);
  static const muted = Color(0xFF64748B);
  static const success = Color(0xFF0F8A5F);
  static const danger = Color(0xFFC0392B);
  static const warning = Color(0xFFB7791F);

  static const goldGradient = LinearGradient(colors: [Color(0xFFB0A48A), gold500, Color(0xFF8A7E63)]);
  static const navyGradient = LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [navy900, navy700]);
}

class AppTheme {
  /// [brand] carries the colours the administrator chose; without them the Qatar identity above applies.
  static ThemeData light(Locale locale, [Brand brand = Brand.none]) {
    final primary = brand.primary ?? AppColors.navy900;
    final accent = brand.accent ?? AppColors.gold500;
    final ivory = brand.background ?? AppColors.ivory;
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(
        seedColor: primary,
        primary: primary,
        secondary: accent,
        surface: Colors.white,
      ),
      scaffoldBackgroundColor: ivory,
      fontFamily: 'Tajawal',
    );

    return base.copyWith(
      appBarTheme: AppBarTheme(
        backgroundColor: ivory,
        foregroundColor: primary,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(fontFamily: 'Tajawal', color: primary, fontSize: 20, fontWeight: FontWeight.w800),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: const BorderSide(color: AppColors.navy100)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: primary,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(50),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontFamily: 'Tajawal', fontWeight: FontWeight.w700, fontSize: 15),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: primary,
          minimumSize: const Size.fromHeight(48),
          side: const BorderSide(color: AppColors.navy100),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
        ),
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white,
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: AppColors.navy100)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: AppColors.navy100)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: accent, width: 1.5)),
      ),
      navigationBarTheme: NavigationBarThemeData(
        backgroundColor: Colors.white,
        indicatorColor: AppColors.gold100,
        elevation: 0,
        labelTextStyle: WidgetStateProperty.resolveWith(
          (states) => TextStyle(
            fontFamily: 'Tajawal',
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
