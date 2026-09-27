import 'package:flutter_test/flutter_test.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:tedc_mobile/core/format.dart';

void main() {
  setUpAll(() async => initializeDateFormatting('ar'));

  test('Arabic formatting keeps Western (Latin) digits', () {
    final fmt = Fmt('ar');
    final date = DateTime(2026, 9, 27, 8, 30);
    final all = [fmt.number(12345), fmt.percent(85), fmt.date(date), fmt.dateTime(date), fmt.time(date), fmt.weekdayDate(date)].join(' ');

    expect(RegExp('[٠-٩]').hasMatch(all), isFalse, reason: all);
    expect(fmt.date(date), contains('2026'));
    expect(fmt.date(date), contains('سبتمبر'));
    expect(fmt.number(12345), '12,345');
  });
}
