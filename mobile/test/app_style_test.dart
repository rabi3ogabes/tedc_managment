import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/theme/app_style.dart';
import 'package:tedc_mobile/core/theme/app_theme.dart';
import 'package:tedc_mobile/core/theme/brand.dart';

void main() {
  tearDown(() {
    AppStyle.reset();
    AppColors.apply();
  });

  test('a look assigned to the person changes the shape, header, bar, card order and colours', () {
    AppStyle.apply({
      'id': 'care',
      'shape': {'card_radius': 18, 'button_radius': 14, 'elevation': 1},
      'header': 'flat',
      'nav': 'classic',
      'home': ['stats', 'programs', 'bogus'],
      'colors': {'primary': '#1F5FA6', 'accent': '#F2B33D', 'background': '#F3F7FC'},
    });
    Brand.none.applyColors();
    expect(AppStyle.active, isTrue);
    expect(AppStyle.cardRadius, 18);
    expect(AppStyle.header, 'flat');
    expect(AppStyle.nav, 'classic');
    expect(AppStyle.home, ['stats', 'programs']);
    expect(AppColors.navy900, const Color(0xFF1F5FA6));
    expect(AppColors.ivory, const Color(0xFFF3F7FC));
  });

  test('bad values fall back and reset brings the app\'s own look back', () {
    AppStyle.apply({'id': 'x', 'shape': {'card_radius': 999, 'elevation': 99}, 'header': 'weird', 'nav': 'weird', 'colors': {'primary': 'red'}});
    expect(AppStyle.cardRadius, 36);
    expect(AppStyle.elevation, 4);
    expect(AppStyle.header, 'curved');
    expect(AppStyle.nav, isNull);
    expect(AppStyle.primary, isNull);
    AppStyle.reset();
    expect(AppStyle.active, isFalse);
    expect(AppStyle.cardRadius, 20);
    expect(AppStyle.home, isNull);
  });
}
