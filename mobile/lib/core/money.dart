/// Money is always an integer number of tiyin (1 so'm = 100 tiyin). Never a double.
library;

const int kTiyin = 100;

String _group(String digits) {
  final b = StringBuffer();
  for (var i = 0; i < digits.length; i++) {
    if (i > 0 && (digits.length - i) % 3 == 0) b.write(' ');
    b.write(digits[i]);
  }
  return b.toString();
}

/// "1 234 567" (whole so'm, rounded half away from zero).
String formatSom(int minor, {bool sign = false}) {
  final neg = minor < 0;
  final abs = minor.abs();
  final som = (abs + 50) ~/ kTiyin;
  final s = _group(som.toString());
  if (neg) return '−$s';
  return sign && som > 0 ? '+$s' : s;
}

/// Full amount with the currency word, e.g. "1 234 567 soʻm".
String formatMoney(int minor, String currencyWord, {bool sign = false}) =>
    '${formatSom(minor, sign: sign)} $currencyWord';

/// Compact form for charts: 1,2 mln / 3,4 mlrd.
String formatCompact(int minor, {required String thousand, required String million, required String billion}) {
  final som = minor.abs() ~/ kTiyin;
  final neg = minor < 0 ? '−' : '';
  String one(int v, int div, String unit) {
    final whole = v ~/ div;
    final frac = (v % div) * 10 ~/ div;
    return frac == 0 ? '$neg$whole $unit' : '$neg$whole,$frac $unit';
  }

  if (som >= 1000000000) return one(som, 1000000000, billion);
  if (som >= 1000000) return one(som, 1000000, million);
  if (som >= 1000) return one(som, 1000, thousand);
  return '$neg$som';
}

/// Parses user input such as "1 250 000" or "1250000,50" into tiyin. Returns null when it is not a number.
int? parseMinor(String input) {
  var s = input.replaceAll(RegExp(r'[\s _]'), '').replaceAll(',', '.');
  if (s.isEmpty) return null;
  final neg = s.startsWith('-') || s.startsWith('−');
  s = s.replaceAll(RegExp(r'^[-−+]'), '');
  final parts = s.split('.');
  if (parts.length > 2) return null;
  final whole = int.tryParse(parts[0].isEmpty ? '0' : parts[0]);
  if (whole == null) return null;
  var frac = 0;
  if (parts.length == 2 && parts[1].isNotEmpty) {
    final f = parts[1].padRight(2, '0').substring(0, 2);
    frac = int.tryParse(f) ?? -1;
    if (frac < 0) return null;
  }
  final v = whole * kTiyin + frac;
  return neg ? -v : v;
}

/// Basis-point percentage of an amount (1200 bp = 12%), rounded half up.
int percentOf(int minor, int basisPoints) => (minor * basisPoints + (minor >= 0 ? 5000 : -5000)) ~/ 10000;

/// "12%" or "0,1%" from basis points.
String formatBp(int bp) {
  if (bp % 100 == 0) return '${bp ~/ 100}%';
  final s = (bp / 100).toStringAsFixed(2).replaceFirst(RegExp(r'0+$'), '').replaceFirst('.', ',');
  return '$s%';
}
