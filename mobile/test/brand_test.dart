import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/theme/app_theme.dart';
import 'package:tedc_mobile/core/theme/brand.dart';

void main() {
  test('brand colours and the occasion are read from the mobile config', () {
    final b = Brand.fromConfig({
      'data': {
        'brand': {'primary': '#0E4B6B', 'accent': '#D9B35F', 'background': 'nope'},
        'occasion': {'id': 'teachers_day', 'name_ar': 'يوم المعلم', 'name_en': "Teachers' Day"},
        'loading': {'message_ar': 'شكراً لمعلمينا', 'message_en': 'Thank you, teachers'},
      },
    }, arabic: false);
    expect(b.primary, const Color(0xFF0E4B6B));
    expect(b.background, isNull, reason: 'a bad value is ignored');
    expect(b.occasionName, "Teachers' Day");
    expect(b.message, 'Thank you, teachers');
  });

  test('without a config, or with a broken one, the default identity applies', () {
    expect(Brand.fromConfig(null, arabic: true).primary, isNull);
    expect(Brand.fromConfig({'data': 'x'}, arabic: true).occasionName, isNull);
    expect(AppTheme.light(const Locale('ar')).colorScheme.primary, AppColors.navy900);
  });

  test('the theme follows the chosen colours', () {
    final t = AppTheme.light(const Locale('en'), const Brand(primary: Color(0xFF0E4B6B)));
    expect(t.colorScheme.primary, const Color(0xFF0E4B6B));
  });
}
