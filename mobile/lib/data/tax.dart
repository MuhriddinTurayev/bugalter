import 'dart:convert';

import 'package:flutter/services.dart' show rootBundle;

import '../core/money.dart';
import 'models.dart';

/// Thrown when a rate or deadline is not in the rules table. Rates are never invented or hardcoded in this app.
class MissingTaxRule implements Exception {
  MissingTaxRule(this.code);
  final String code;
  @override
  String toString() => 'Missing tax rule: $code';
}

class TaxRule {
  TaxRule({required this.code, required this.kind, required this.value, required this.from, this.to, required this.names, required this.source});

  final String code, kind, source;
  final int value; // basis points for rates, tiyin for amounts
  final DateTime from;
  final DateTime? to;
  final Map<String, String> names;

  factory TaxRule.fromJson(Map<String, dynamic> j) => TaxRule(
        code: j['code'] as String,
        kind: j['kind'] as String,
        value: j['value'] as int,
        from: DateTime.parse(j['effective_from'] as String),
        to: j['effective_to'] == null ? null : DateTime.parse(j['effective_to'] as String),
        names: Map<String, String>.from(j['name'] as Map),
        source: (j['source'] ?? '') as String,
      );

  String name(String locale) => names[locale] ?? names['ru'] ?? names['uz'] ?? code;
}

class Deadline {
  Deadline({required this.type, required this.quarterly, required this.monthsAfter, required this.day, required this.from, this.to});

  final String type;
  final bool quarterly;
  final int monthsAfter, day;
  final DateTime from;
  final DateTime? to;

  factory Deadline.fromJson(Map<String, dynamic> j) => Deadline(
        type: j['report_type'] as String,
        quarterly: j['period'] == 'quarter',
        monthsAfter: j['months_after'] as int,
        day: j['day'] as int,
        from: DateTime.parse(j['effective_from'] as String),
        to: j['effective_to'] == null ? null : DateTime.parse(j['effective_to'] as String),
      );
}

/// Versioned rates and deadlines, loaded from the same `docs/tax/rules.json` the Laravel backend is seeded from.
class TaxRuleBook {
  TaxRuleBook(this.rules, this.deadlines);

  final List<TaxRule> rules;
  final List<Deadline> deadlines;

  static Future<TaxRuleBook> load() async {
    final raw = jsonDecode(await rootBundle.loadString('assets/rules.json')) as Map<String, dynamic>;
    return TaxRuleBook(
      (raw['rates'] as List).map((e) => TaxRule.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
      (raw['deadlines'] as List).map((e) => Deadline.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
    );
  }

  TaxRule rule(String code, DateTime on) {
    final d = dateOnly(on);
    final hits = rules.where((r) => r.code == code && !r.from.isAfter(d) && (r.to == null || !r.to!.isBefore(d))).toList()
      ..sort((a, b) => b.from.compareTo(a.from));
    if (hits.isEmpty) throw MissingTaxRule(code);
    return hits.first;
  }

  Deadline deadline(TaxType type, DateTime on) {
    final d = dateOnly(on);
    final hits = deadlines.where((r) => r.type == type.name && !r.from.isAfter(d) && (r.to == null || !r.to!.isBefore(d))).toList()
      ..sort((a, b) => b.from.compareTo(a.from));
    if (hits.isEmpty) throw MissingTaxRule('deadline.${type.name}');
    return hits.first;
  }
}

/// One obligation in the tax calendar: a report type, its period and its due date.
class Obligation {
  Obligation(this.type, this.start, this.end, this.due);

  final TaxType type;
  final DateTime start, end, due;

  String get key => '${type.name}:${start.year}-${start.month}-${start.day}';
  int daysLeft(DateTime now) => dateOnly(due).difference(dateOnly(now)).inDays;
}

DateTime _lastDay(int year, int month) => DateTime(year, month + 1, 0);

class TaxCalculator {
  TaxCalculator(this.book);

  final TaxRuleBook book;

  /// Per-employee payroll taxes: PIT is withheld from gross pay; INPS is part of the PIT amount; social tax is the employer's cost.
  PayrollLine payrollLine({required Employee e, required DateTime on, required bool budgetOrg}) {
    final gross = e.salaryMinor;
    final pit = percentOf(gross, book.rule('pit.standard', on).value);
    final inpsRaw = percentOf(gross, book.rule('inps.employee', on).value);
    final social = percentOf(gross, book.rule(budgetOrg ? 'social.budget' : 'social.general', on).value);
    return PayrollLine(employeeId: e.id, name: e.fullName, position: e.position, gross: gross, pit: pit, inps: inpsRaw < pit ? inpsRaw : pit, social: social);
  }

  PayrollLine recalcLine(PayrollLine l, DateTime on, bool budgetOrg) {
    final pit = percentOf(l.gross, book.rule('pit.standard', on).value);
    final inpsRaw = percentOf(l.gross, book.rule('inps.employee', on).value);
    l
      ..pit = pit
      ..inps = inpsRaw < pit ? inpsRaw : pit
      ..social = percentOf(l.gross, book.rule(budgetOrg ? 'social.budget' : 'social.general', on).value);
    return l;
  }

  /// The tax calendar of the company's regime around [now]: the last periods that are still open and the next ones.
  List<Obligation> calendar(Company c, DateTime now) {
    final types = <TaxType>[
      if (c.regime == 'turnover') TaxType.turnover else ...[if (c.vatPayer) TaxType.vat, TaxType.profit],
      TaxType.payroll,
    ];
    final out = <Obligation>[];
    for (final t in types) {
      final dl = book.deadline(t, now);
      for (var back = -8; back <= 1; back++) {
        late DateTime start, end;
        if (dl.quarterly) {
          final q = (now.month - 1) ~/ 3 + back; // quarter index relative to the current quarter
          final y = now.year + (q >= 0 ? q ~/ 4 : -((-q + 3) ~/ 4));
          final qi = ((q % 4) + 4) % 4;
          start = DateTime(y, qi * 3 + 1, 1);
          end = _lastDay(y, qi * 3 + 3);
        } else {
          final m = now.month + back;
          start = DateTime(now.year, m, 1);
          end = _lastDay(start.year, start.month);
        }
        final dm = DateTime(end.year, end.month + dl.monthsAfter, 1);
        final due = DateTime(dm.year, dm.month, dl.day);
        if (due.isBefore(dateOnly(now).subtract(const Duration(days: 45))) || due.isAfter(dateOnly(now).add(const Duration(days: 75)))) continue;
        out.add(Obligation(t, start, end, due));
      }
    }
    out.sort((a, b) => a.due.compareTo(b.due));
    return out;
  }
}

/// A prepared computation (base and tax) before it becomes a [TaxReport].
class TaxComputation {
  TaxComputation(this.baseMinor, this.taxMinor, this.rulesUsed);
  final int baseMinor, taxMinor;
  final List<TaxRule> rulesUsed;
}
