import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bugalter_mobile/core/money.dart';
import 'package:bugalter_mobile/l10n/strings.dart';
import 'package:bugalter_mobile/data/assistant.dart';
import 'package:bugalter_mobile/data/cabinet.dart';
import 'package:bugalter_mobile/data/models.dart';
import 'package:bugalter_mobile/data/store.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late AppStore store;
  late CabinetData d;

  Future<void> signIn(String login) async {
    expect(await store.login(login, 'Demo#2026pass'), isTrue);
  }

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    store = await AppStore.open();
    await signIn('accountant@bugalter.test');
    d = CabinetData.of(store);
  });

  /// A known debt, so the payment tests do not depend on the demo books.
  void setDebt(int vatDebt) {
    d.m['balances'] = [TaxBalance(tax: 'vat', assessed: vatDebt).toJson()];
  }

  group('snapshot and persistence', () {
    test('is seeded from the books and survives a JSON round trip', () {
      expect(store.ext['cabinet'], isNotNull);
      final filed = store.reports.where((r) => r.status == ReportStatus.submitted).length;
      expect(d.reports.length, filed);
      expect(d.lastCheck, isNotNull);
      for (final b in d.balances) {
        expect(b.debt, b.assessed > b.paid ? b.assessed - b.paid : 0);
      }
      final again = jsonDecode(jsonEncode(store.ext)) as Map<String, dynamic>;
      expect((again['cabinet'] as Map)['letters'], isNotEmpty);
    });

    test('letters exist in uz, ru and tg with a title and a text', () {
      expect(d.letters.length, greaterThanOrEqualTo(5));
      for (final l in d.letters) {
        for (final loc in kLocales) {
          expect(l.titleIn(loc).trim(), isNotEmpty);
          expect(l.bodyIn(loc).trim(), isNotEmpty);
        }
        expect(l.bodyIn('tg').contains('{'), isFalse, reason: 'unfilled placeholder in ${l.number}');
      }
    });

    test('the insight of a debt letter states the amount of the letter, never an invented one', () {
      final l = d.letters.where((e) => e.ask == 'pay_debt').firstOrNull;
      if (l == null) return; // no debt in this demo run
      final ins = d.insight(l);
      expect(ins.amount, l.amount);
      expect(ins.steps, contains('pay'));
      expect(d.balances.firstWhere((b) => b.tax == l.tax).debt, l.amount);
    });

    test('opening a letter marks it read and writes an audit entry', () {
      final l = d.letters.firstWhere((e) => e.unread);
      d.markRead(l);
      expect(d.letters.firstWhere((e) => e.id == l.id).unread, isFalse);
      expect(store.audit.last.action, 'cab_letter_read');
      expect(store.audit.last.actor, 'user');
    });
  });

  group('analysis (port of PortalAnalyzer)', () {
    test('checking finds unfiled closed periods and sorts errors first', () {
      final c = d.checkNow();
      expect(c.findings, isNotEmpty);
      final order = c.findings.map((f) => f.severity.index).toList();
      expect(order, [...order]..sort());
      expect(store.audit.any((e) => e.action == 'cab_check'), isTrue);
    });

    test('a company that changes its VAT status gets a mismatch warning', () {
      store.company.vatPayer = !store.company.vatPayer;
      final c = d.checkNow();
      expect(c.findings.any((f) => f.key == 'vat_status_mismatch'), isTrue);
    });

    test('a cabinet of another taxpayer stops everything else', () {
      d.m['profile'] = {...d.profile, 'tin': '111111111'};
      final c = d.checkNow();
      expect(c.findings.single.key, 'tin_mismatch');
    });

    test('the cashier cannot run the cabinet check', () async {
      await signIn('cashier@bugalter.test');
      expect(() => d.checkNow(), throwsA(isA<AppError>()));
    });
  });

  group('payments need a person', () {
    test('a proposal moves no money', () {
      setDebt(500000000);
      final debt = d.totalDebt;
      final orders = d.orders.length;
      final p = d.propose(tax: 'vat', kind: '08102', amount: 500000000, actor: 'ai');
      expect(p.status, PayStatus.proposed);
      expect(p.proposedBy, 'ai');
      expect(d.totalDebt, debt);
      expect(d.orders.length, orders);
    });

    test('validation: amount range, known tax and type, no duplicate open proposal', () {
      expect(() => d.propose(tax: 'vat', kind: '08102', amount: 0), throwsA(isA<AppError>()));
      expect(() => d.propose(tax: 'vat', kind: '08102', amount: kMaxPayMinor + 1), throwsA(isA<AppError>()));
      expect(() => d.propose(tax: 'nope', kind: '08102', amount: 100000), throwsA(isA<AppError>()));
      expect(() => d.propose(tax: 'vat', kind: '99999', amount: 100000), throwsA(isA<AppError>()));
      d.propose(tax: 'profit', kind: '08102', amount: 123400);
      expect(() => d.propose(tax: 'profit', kind: '08102', amount: 123400), throwsA(isA<AppError>()));
    });

    test('paying must repeat the exact tax, type and amount', () {
      setDebt(500000000);
      final p = d.propose(tax: 'vat', kind: '08102', amount: 500000000);
      expect(() => d.pay(p, tax: 'vat', kind: '08102', amount: 400000000), throwsA(isA<AppError>()));
      expect(() => d.pay(p, tax: 'profit', kind: '08102', amount: 500000000), throwsA(isA<AppError>()));
      expect(d.payments.firstWhere((e) => e.id == p.id).status, PayStatus.proposed);

      final before = d.orders.length;
      d.pay(p, tax: 'vat', kind: '08102', amount: 500000000);
      final paid = d.payments.firstWhere((e) => e.id == p.id);
      expect(paid.status, PayStatus.paid);
      expect(paid.orderNumber, startsWith('SBX-'));
      expect(paid.approvedBy, store.user!.name);
      expect(d.totalDebt, 0);
      expect(d.orders.length, before + 1);
      expect(store.audit.last.action, 'cab_pay_paid');
      expect(store.audit.last.actor, 'user');
      // a paid payment cannot be paid again
      expect(() => d.pay(paid, tax: 'vat', kind: '08102', amount: 500000000), throwsA(isA<AppError>()));
    });

    test('a prepayment creates an overpayment; a penalty payment reduces the penalty', () {
      d.m['balances'] = [TaxBalance(tax: 'vat', assessed: 100000000, paid: 100000000, penalty: 3000000).toJson()];
      final pre = d.propose(tax: 'vat', kind: '08101', amount: 200000000);
      d.pay(pre, tax: 'vat', kind: '08101', amount: 200000000);
      expect(d.totalOverpaid, 200000000);
      final pen = d.propose(tax: 'vat', kind: '08108', amount: 3000000);
      d.pay(pen, tax: 'vat', kind: '08108', amount: 3000000);
      expect(d.totalPenalty, 0);
    });

    test('the cashier cannot pay, check or cancel', () async {
      setDebt(100000000);
      final p = d.propose(tax: 'vat', kind: '08102', amount: 100000000);
      await signIn('cashier@bugalter.test');
      expect(() => d.pay(p, tax: 'vat', kind: '08102', amount: 100000000), throwsA(isA<AppError>()));
      expect(() => d.checkPayment(p), throwsA(isA<AppError>()));
      expect(() => d.cancelPayment(p), throwsA(isA<AppError>()));
    });

    test('checking without paying and cancelling', () {
      setDebt(100000000);
      final p = d.propose(tax: 'vat', kind: '08102', amount: 100000000);
      d.checkPayment(p);
      expect(d.payments.firstWhere((e) => e.id == p.id).status, PayStatus.checked);
      expect(d.totalDebt, 100000000);
      d.cancelPayment(p);
      expect(d.payments.firstWhere((e) => e.id == p.id).status, PayStatus.cancelled);
      expect(() => d.cancelPayment(p), throwsA(isA<AppError>()));
    });

    test('pay everything: a changed list is not paid', () {
      setDebt(100000000);
      final items = d.plan();
      final hash = d.planHash(items);
      expect(items, isNotEmpty);
      d.m['balances'] = [TaxBalance(tax: 'vat', assessed: 100000000 + 500000).toJson()];
      expect(() => d.payAll(hash), throwsA(isA<AppError>()));
      expect(d.totalDebt, 100500000);
      expect(d.payAll(d.planHash(d.plan())), 1);
      expect(d.totalDebt, 0);
    });

    test('an unknown outcome is never paid again; the cabinet list settles it', () {
      setDebt(100000000);
      final p = d.propose(tax: 'vat', kind: '08102', amount: 100000000);
      final raw = (d.m['payments'] as List).cast<Map<String, dynamic>>();
      raw.firstWhere((e) => e['id'] == p.id)['status'] = 'unknown';
      expect(() => d.propose(tax: 'vat', kind: '08102', amount: 100000000), throwsA(isA<AppError>()));
      d.settleUnknown(d.payments.firstWhere((e) => e.id == p.id));
      expect(d.payments.firstWhere((e) => e.id == p.id).status, PayStatus.failed); // no order in the cabinet list
    });
  });

  group('assistant', () {
    test('only proposes a payment; the debt stays until a person pays', () {
      setDebt(700000000);
      final a = Assistant(store);
      final r = a.answer('QQS qarzini toʻlab ber', 'uz');
      final ai = d.payments.where((p) => p.proposedBy == 'ai' && p.amount == 700000000).toList();
      expect(ai, isNotEmpty);
      expect(ai.first.status, PayStatus.proposed);
      expect(d.totalDebt, 700000000);
      expect(r.text, contains(formatSom(700000000)));
    });

    test('answers about the cabinet debt and the letters in all languages', () {
      setDebt(700000000);
      final a = Assistant(store);
      final debtQ = {'uz': 'Soliq kabineti boʻyicha qarz', 'ru': 'Какой долг в налоговом кабинете?', 'tg': 'Қарз аз рӯи кабинети андоз'};
      final letterQ = {'uz': 'Soliq xatlari bormi?', 'ru': 'Есть письма от налоговой?', 'tg': 'Мактуби андоз ҳаст?'};
      for (final loc in kLocales) {
        expect(a.answer(debtQ[loc]!, loc).text, contains(translate(loc, 'cab.tax.vat')), reason: 'debt answer in $loc');
        final letters = a.answer(letterQ[loc]!, loc).text;
        expect(letters, isNot(translate(loc, 'ai.r.unknown')), reason: 'letters answer in $loc');
        expect(letters, contains(d.letters.first.titleIn(loc).substring(0, 8)), reason: 'letters answer in $loc');
      }
    });
  });

  group('automatic preparation', () {
    DateTime lastMonth() {
      final n = DateTime.now();
      return DateTime(n.year, n.month - 1, 1);
    }

    test('the analysis prepares reports but submits nothing', () {
      final before = store.reports.where((r) => r.status == ReportStatus.submitted).length;
      final run = d.startRun(lastMonth());
      expect(run.steps.map((s) => s.key), ['profile', 'portal', 'obligations', 'data', 'prepare', 'validate']);
      expect(run.rows, isNotEmpty);
      expect(store.reports.where((r) => r.status == ReportStatus.submitted).length, before);
      expect(store.audit.any((e) => e.action == 'cab_run_started'), isTrue);
    });

    test('approval needs the declaration and a person with the right; then each report is submitted separately', () async {
      final run = d.startRun(lastMonth());
      final ready = run.rows.where(d.selectable).toList();
      if (ready.isEmpty) return; // the demo books have nothing ready for this month
      expect(() => d.approveRun(run, ready, declaration: false), throwsA(isA<AppError>()));
      expect(() => d.approveRun(run, [], declaration: true), throwsA(isA<AppError>()));
      await signIn('cashier@bugalter.test');
      expect(() => d.approveRun(run, ready, declaration: true), throwsA(isA<AppError>()));
      await signIn('accountant@bugalter.test');
      final r = d.approveRun(run, ready, declaration: true);
      expect(r.approved, ready.length);
      for (final row in ready) {
        final rep = d.reportOf(row)!;
        expect(rep.status, ReportStatus.submitted);
        expect(rep.receipt, startsWith('SBX-'));
        expect(rep.approvedBy, store.user!.name);
        expect(d.stateOf(row), RowState.submitted);
      }
    });

    test('a report that changed after the review is refused', () {
      final run = d.startRun(lastMonth());
      final ready = run.rows.where(d.selectable).toList();
      if (ready.isEmpty) return;
      final rep = d.reportOf(ready.first)!;
      rep.taxMinor += 100; // the report changed after it was reviewed
      expect(d.selectable(ready.first), isFalse);
      final r = d.approveRun(run, [ready.first], declaration: true);
      expect(r.approved, 0);
      expect(r.refused, contains('cab_version_changed'));
      expect(rep.status, isNot(ReportStatus.submitted));
    });
  });
}
