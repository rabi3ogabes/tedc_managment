import 'package:intl/intl.dart';

class Fmt {
  Fmt(this.locale);

  final String locale;

  String get _intl => locale == 'ar' ? 'ar' : 'en';

  String number(num? n, {int digits = 0}) => n == null ? '—' : NumberFormat.decimalPatternDigits(locale: _intl, decimalDigits: digits).format(n);

  String percent(num? n) => n == null ? '—' : '${number(n)}%';

  String date(DateTime? d) => d == null ? '—' : DateFormat.yMMMd(_intl).format(d);

  String dateTime(DateTime? d) => d == null ? '—' : DateFormat.MMMd(_intl).add_jm().format(d);

  String time(DateTime? d) => d == null ? '—' : DateFormat.jm(_intl).format(d);

  String weekdayDate(DateTime? d) => d == null ? '—' : DateFormat.MMMMEEEEd(_intl).format(d);
}
