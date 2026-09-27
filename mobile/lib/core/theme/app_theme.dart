import 'package:flutter/material.dart';

/// Luxury government palette: deep navy, off-white and gold.
class AppColors {
  static const navy950 = Color(0xFF06122A);
  static const navy900 = Color(0xFF0B1F3A);
  static const navy800 = Color(0xFF12294A);
  static const navy700 = Color(0xFF1B365F);
  static const navy100 = Color(0xFFE3E9F2);
  static const gold700 = Color(0xFF8F6F22);
  static const gold500 = Color(0xFFC8A24A);
  static const gold300 = Color(0xFFE5CD8A);
  static const gold100 = Color(0xFFF6EDD6);
  static const ivory = Color(0xFFF8F6F1);
  static const ink = Color(0xFF0F172A);
  static const muted = Color(0xFF64748B);
  static const success = Color(0xFF0F8A5F);
  static const danger = Color(0xFFC0392B);
  static const warning = Color(0xFFB7791F);

  static const goldGradient = LinearGradient(colors: [Color(0xFFD6B566), gold500, Color(0xFFA8852F)]);
  static const navyGradient = LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [navy900, navy700]);
}

class AppTheme {
  static ThemeData light(Locale locale) {
    final base = ThemeData(
      useMaterial3: true,
      colorScheme: ColorScheme.fromSeed(
        seedColor: AppColors.navy900,
        primary: AppColors.navy900,
        secondary: AppColors.gold500,
        surface: Colors.white,
      ),
      scaffoldBackgroundColor: AppColors.ivory,
      fontFamily: 'Tajawal',
    );

    return base.copyWith(
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.ivory,
        foregroundColor: AppColors.navy900,
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        titleTextStyle: TextStyle(fontFamily: 'Tajawal', color: AppColors.navy900, fontSize: 20, fontWeight: FontWeight.w800),
      ),
      cardTheme: CardThemeData(
        color: Colors.white,
        elevation: 0,
        margin: EdgeInsets.zero,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20), side: const BorderSide(color: AppColors.navy100)),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.navy900,
          foregroundColor: Colors.white,
          minimumSize: const Size.fromHeight(50),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
          textStyle: const TextStyle(fontFamily: 'Tajawal', fontWeight: FontWeight.w700, fontSize: 15),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.navy900,
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
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: const BorderSide(color: AppColors.gold500, width: 1.5)),
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
