import 'dart:io';
import 'package:flutter_test/flutter_test.dart';

import 'package:bugalter_mobile/l10n/strings.dart';
import 'package:bugalter_mobile/l10n/strings_agent.dart';
import 'package:bugalter_mobile/l10n/strings_bank.dart';
import 'package:bugalter_mobile/l10n/strings_cabinet.dart';
import 'package:bugalter_mobile/l10n/strings_extra.dart';
import 'package:bugalter_mobile/l10n/strings_legal.dart';
import 'package:bugalter_mobile/l10n/strings_common.dart';
import 'package:bugalter_mobile/l10n/strings_docs.dart';
import 'package:bugalter_mobile/l10n/strings_tax.dart';

Set<String> _placeholders(String s) => RegExp(r'\{(\w+)\}').allMatches(s).map((m) => m.group(1)!).toSet();

void main() {
  usedKeysTest();
  test('every string has uz, ru and tg with the same placeholders', () {
    final problems = <String>[];
    kAllStrings.forEach((key, row) {
      if (row.length != 3) {
        problems.add('$key: needs 3 translations, has ${row.length}');
        return;
      }
      for (var i = 0; i < 3; i++) {
        if (row[i].trim().isEmpty) problems.add('$key: empty ${kLocales[i]}');
      }
      final p0 = _placeholders(row[0]);
      for (var i = 1; i < 3; i++) {
        if (!_placeholders(row[i]).containsAll(p0) || !p0.containsAll(_placeholders(row[i]))) problems.add('$key: placeholders differ in ${kLocales[i]}');
      }
    });
    expect(problems, isEmpty, reason: problems.join('\n'));
  });

  test('no key is defined in two string files', () {
    final total = kStringsCommon.length + kStringsDocs.length + kStringsBank.length + kStringsTax.length + kStringsCabinet.length + kStringsLegal.length + kStringsExtra.length + kStringsAgent.length;
    expect(kAllStrings.length, total, reason: 'a key is duplicated between string files');
  });

  test('uz, ru and tg really differ (no copy-pasted language)', () {
    var sameRuTg = 0, sameUzRu = 0, words = 0;
    kAllStrings.forEach((key, row) {
      if (row[0].length < 14) return;
      words++;
      if (row[1] == row[2]) sameRuTg++;
      if (row[0] == row[1]) sameUzRu++;
    });
    expect(sameUzRu, lessThan(words * .02));
    expect(sameRuTg, lessThan(words * .1));
  });
}

void usedKeysTest() {
  test('every literal key used in the code exists in the string tables', () {
    final re = RegExp(r"""\bt\(\s*'([a-z_]+(?:\.[a-z_0-9]+)+)'""");
    final missing = <String>{};
    for (final f in Directory('lib').listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.dart'))) {
      for (final m in re.allMatches(f.readAsStringSync())) {
        if (!kAllStrings.containsKey(m.group(1))) missing.add('${m.group(1)}  (${f.path})');
      }
    }
    expect(missing, isEmpty, reason: missing.join('\n'));
  });
}
