import 'package:intl/intl.dart';

/// Locale-aware formatting. Arabic keeps Arabic month and day names, but all numbers and
/// dates are rendered with Western (Latin) digits across the app.
class Fmt {
  Fmt(this.locale);

  final String locale;

  String get _intl => locale == 'ar' ? 'ar' : 'en';

  DateFormat _latin(DateFormat format) => format..useNativeDigits = false;

  String number(num? n, {int digits = 0}) => n == null ? '—' : NumberFormat.decimalPatternDigits(locale: 'en', decimalDigits: digits).format(n);

  String percent(num? n) => n == null ? '—' : '${number(n)}%';

  String date(DateTime? d) => d == null ? '—' : _latin(DateFormat.yMMMd(_intl)).format(d);

  String dateTime(DateTime? d) => d == null ? '—' : _latin(DateFormat.MMMd(_intl).add_jm()).format(d);

  String time(DateTime? d) => d == null ? '—' : _latin(DateFormat.jm(_intl)).format(d);

  String weekdayDate(DateTime? d) => d == null ? '—' : _latin(DateFormat.MMMMEEEEd(_intl)).format(d);
}
