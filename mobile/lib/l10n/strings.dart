import 'strings_bank.dart';
import 'strings_cabinet.dart';
import 'strings_extra.dart';
import 'strings_legal.dart';
import 'strings_common.dart';
import 'strings_docs.dart';
import 'strings_tax.dart';

/// The three languages of the platform, in the order of the lists in the string tables.
const List<String> kLocales = ['uz', 'ru', 'tg'];

const Map<String, String> kLocaleNames = {'uz': 'Oʻzbekcha', 'ru': 'Русский', 'tg': 'Тоҷикӣ'};

/// Every string of the app: key -> [uz (Latin), ru, tg (Cyrillic)]. Placeholders look like {name}.
final Map<String, List<String>> kAllStrings = {
  ...kStringsCommon,
  ...kStringsDocs,
  ...kStringsBank,
  ...kStringsTax,
  ...kStringsCabinet,
  ...kStringsLegal,
  ...kStringsExtra,
};

/// Looks a string up. A missing key shows the key itself (and is caught by the string-table test).
String translate(String locale, String key, [Map<String, Object?>? args]) {
  final row = kAllStrings[key];
  if (row == null) return key;
  final i = kLocales.indexOf(locale);
  var s = row[i < 0 ? 0 : i];
  if (args != null) {
    args.forEach((k, v) => s = s.replaceAll('{$k}', '$v'));
  }
  return s;
}
