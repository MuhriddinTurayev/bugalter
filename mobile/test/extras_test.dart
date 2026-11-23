import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bugalter_mobile/core/money.dart';
import 'package:bugalter_mobile/data/csv_import.dart';
import 'package:bugalter_mobile/data/extras.dart';
import 'package:bugalter_mobile/data/models.dart';
import 'package:bugalter_mobile/data/reports.dart';
import 'package:bugalter_mobile/data/store.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('CSV parser', () {
    test('finds the delimiter, understands quotes and drops blank rows', () {
      final t = parseCsv('﻿Sana;Summa;Izoh\r\n01.11.2026;"1 000,50";"Toʻlov; ""aprel"""\r\n\r\n02.11.2026;-500;x\n');
      expect(t.delimiter, ';');
      expect(t.headers, ['Sana', 'Summa', 'Izoh']);
      expect(t.body, hasLength(2));
      expect(t.body.first, ['01.11.2026', '1 000,50', 'Toʻlov; "aprel"']);
      expect(parseCsv('a,b,c\n1,2,3').delimiter, ',');
      expect(parseCsv('a\tb\n1\t2').delimiter, '\t');
    });

    test('dates in the four formats; impossible dates are refused', () {
      expect(parseDate('23.11.2026', 'd.m.Y'), DateTime(2026, 11, 23));
      expect(parseDate('2026-11-23 10:15', 'Y-m-d'), DateTime(2026, 11, 23));
      expect(parseDate('23/11/2026', 'd/m/Y'), DateTime(2026, 11, 23));
      expect(parseDate('11/23/2026', 'm/d/Y'), DateTime(2026, 11, 23));
      expect(parseDate('31.02.2026', 'd.m.Y'), isNull);
      expect(parseDate('hello', 'd.m.Y'), isNull);
      expect(parseDate('23.11.2026', 'Y-m-d'), isNull);
    });

    test('amounts become whole tiyin, signed, with any thousands separator', () {
      expect(parseSignedAmount('1 234,56'), 123456);
      expect(parseSignedAmount('1 234,56'), 123456);
      expect(parseSignedAmount('1,234.56'), 123456);
      expect(parseSignedAmount('1.234,56'), 123456);
      expect(parseSignedAmount('-500 000'), -50000000);
      expect(parseSignedAmount('−12,5'), -1250);
      expect(parseSignedAmount('(12 500,00)'), -1250000);
      expect(parseSignedAmount('1.234.567'), 123456700);
      expect(parseSignedAmount('12,5'), 1250);
      expect(parseSignedAmount('abc'), isNull);
      expect(parseSignedAmount(''), isNull);
    });

    test('guesses the columns from the header words (uz, ru, en)', () {
      final m = guessColumns(['Sana', 'Summa', 'Izoh', 'Hujjat raqami']);
      expect([m.date, m.amount, m.description, m.reference], [0, 1, 2, 3]);
      final r = guessColumns(['Дата', 'Дебет', 'Кредит', 'Назначение']);
      expect(r.date, 0);
      expect(r.amount, isNull);
      expect([r.debit, r.credit, r.description], [1, 2, 3]);
      expect(r.useDebitCredit, isTrue);
    });

    test('rows: a minus is an expense, debit/credit columns, bad rows marked, duplicates flagged against the books', () {
      final t = parseCsv('Sana;Summa;Izoh\n23.11.2026;1 000;Kirim\n24.11.2026;-250,50;Chiqim\nxx;5;Bad date\n25.11.2026;abc;Bad amount');
      final existing = [Txn(id: 1, accountId: 1, type: TxType.income, amountMinor: 100000, occurredAt: DateTime(2026, 11, 23, 9), description: 'kirim')];
      final rows = buildImportRows(t, guessColumns(t.headers), existing: existing);
      expect(rows.map((r) => r.ok), [true, true, false, false]);
      expect(rows[0].type, TxType.income);
      expect(rows[0].minor, 100000);
      expect(rows[0].duplicate, isTrue); // same date, amount and description as a booked operation
      expect(rows[1].type, TxType.expense);
      expect(rows[1].minor, 25050);
      expect(rows[1].duplicate, isFalse);
      expect(rows[2].problem, ImportProblem.badDate);
      expect(rows[3].problem, ImportProblem.badAmount);
      expect(rows[3].line, 5);

      final dc = parseCsv('Дата;Дебет;Кредит\n01.11.2026;500;\n02.11.2026;;1 200,5');
      final dcRows = buildImportRows(dc, guessColumns(dc.headers));
      expect(dcRows[0].type, TxType.expense);
      expect(dcRows[0].minor, 50000);
      expect(dcRows[1].type, TxType.income);
      expect(dcRows[1].minor, 120050);
    });
  });

  group('KPI maths', () {
    test('bonus = max x achievement, whole tiyin, limited to 200 %', () {
      expect(kpiAmount(300000000, 8500), 255000000);
      expect(kpiAmount(100001, 3333), 33330); // 33 330,33 truncated, never rounded up
      expect(kpiAmount(100009, 5000), 50004); // 50 004,5 truncated
      expect(kpiAmount(100000, 50000), 200000); // above 200 % counts as 200 %
      expect(kpiAmount(100000, -5), 0);
      expect(percentToBp('85'), 8500);
      expect(percentToBp('85,5 %'), 8550);
      expect(percentToBp('0.25'), 25);
      expect(percentToBp('200'), 20000);
      expect(percentToBp('200,01'), isNull);
      expect(percentToBp('abc'), isNull);
      expect(percentLabel(8550), '85,5%');
      expect(percentLabel(10000), '100%');
    });
  });

  group('ERI keys', () {
    test('status follows the days left (30 / 14 / 3), expired and revoked', () {
      final now = DateTime(2026, 11, 23);
      EriKey key(int days, {bool revoked = false}) => EriKey(id: 1, label: 'k', ownerName: 'o', ownerTin: '', serial: 's', validFrom: now.subtract(const Duration(days: 300)), validTo: now.add(Duration(days: days)), revoked: revoked);
      expect(key(200).state(now), EriState.valid);
      expect(key(30).state(now), EriState.expiring30);
      expect(key(14).state(now), EriState.expiring14);
      expect(key(3).state(now), EriState.expiring3);
      expect(key(0).state(now), EriState.expiring3);
      expect(key(-1).state(now), EriState.expired);
      expect(key(200, revoked: true).state(now), EriState.revoked);
    });
  });

  group('books: advances, KPI, payroll, reports, import', () {
    late AppStore store;
    final month = monthStart(DateTime.now());

    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      store = await AppStore.open();
      await store.login('owner@bugalter.test', 'Demo#2026pass');
    });

    Employee sales(int k) => store.employees.where((e) => e.position == 'Sotuv menejeri').toList()[k];

    test('the demo has advances (each with its expense) and KPI bonuses for this month', () {
      expect(store.advancesOf(month), hasLength(2));
      expect(store.kpiOf(month), hasLength(2));
      for (final a in store.advancesOf(month)) {
        final t = store.txns.firstWhere((t) => t.id == a.txnId);
        expect(t.amountMinor, a.amountMinor);
        expect(t.type, TxType.expense);
      }
      expect(store.eriKeys, hasLength(2));
    });

    test('an advance is booked as an expense and limited by the net pay', () {
      final e = sales(0);
      final before = store.totalBalance;
      final left = store.advanceAllowance(e, month);
      expect(left, greaterThan(0));
      final a = store.giveAdvance(employee: e, month: month, givenOn: DateTime.now(), amountMinor: 100000000, accountId: store.accounts[1].id);
      expect(store.totalBalance, before - 100000000);
      expect(store.advanceAllowance(e, month), left - 100000000);
      // more than what is left is refused with the amount still allowed
      expect(
        () => store.giveAdvance(employee: e, month: month, givenOn: DateTime.now(), amountMinor: left, accountId: store.accounts[1].id),
        throwsA(isA<AppError>().having((x) => x.key, 'key', 'adv_too_much')),
      );
      expect(() => store.giveAdvance(employee: e, month: month, givenOn: DateTime.now(), amountMinor: 0, accountId: store.accounts[1].id), throwsA(isA<AppError>()));
      // change and take back: the expense follows
      store.updateAdvance(a, givenOn: DateTime.now(), amountMinor: 50000000, accountId: store.accounts[1].id);
      expect(store.totalBalance, before - 50000000);
      store.deleteAdvance(a);
      expect(store.totalBalance, before);
      expect(store.txns.any((t) => t.id == a.txnId), isFalse);
    });

    test('the cashier cannot give advances or bonuses', () async {
      await store.login('cashier@bugalter.test', 'Demo#2026pass');
      final e = sales(0);
      expect(() => store.giveAdvance(employee: e, month: month, givenOn: DateTime.now(), amountMinor: 1000, accountId: store.accounts[1].id), throwsA(isA<AppError>().having((x) => x.key, 'key', 'no_permission')));
      expect(() => store.addKpi(employee: e, month: month, title: 'x', maxBonusMinor: 1000, achievementBp: 5000), throwsA(isA<AppError>()));
    });

    test('payroll: KPI bonus raises the gross (taxes by the rules table), advances are deducted, only the rest is paid', () {
      final e = sales(0);
      final bonus = store.kpiTotals(month)[e.id]!;
      final given = store.advanceTotals(month)[e.id]!;
      final run = store.preparePayroll(month);
      final line = run.lines.firstWhere((l) => l.employeeId == e.id);
      expect(line.bonus, bonus);
      expect(line.advance, given);
      expect(line.gross, e.salaryMinor + bonus);
      expect(line.pit, percentOf(line.gross, store.book.rule('pit.standard', month).value));
      expect(line.payout, line.net - given);
      expect(run.payout, run.net - run.advances);

      // an advance given after the draft was prepared is picked up by the draft
      store.giveAdvance(employee: e, month: month, givenOn: DateTime.now(), amountMinor: 10000000, accountId: store.accounts[1].id);
      expect(store.payrollFor(month)!.lines.firstWhere((l) => l.employeeId == e.id).advance, given + 10000000);
      // so does a bonus added later (and its tax)
      final k = store.addKpi(employee: e, month: month, title: 'Plan', maxBonusMinor: 100000000, achievementBp: 5000);
      final l2 = store.payrollFor(month)!.lines.firstWhere((l) => l.employeeId == e.id);
      expect(l2.bonus, bonus + 50000000);
      expect(l2.gross, e.salaryMinor + bonus + 50000000);
      expect(l2.pit, percentOf(l2.gross, store.book.rule('pit.standard', month).value));
      store.deleteKpi(k);

      // approve and pay: the expense is the payout, not the whole net pay
      final payoutBefore = store.payrollFor(month)!.payout;
      store.approvePayroll(store.payrollFor(month)!);
      final balance = store.totalBalance;
      store.payPayroll(store.payrollFor(month)!, store.accounts[1].id);
      expect(store.totalBalance, balance - payoutBefore);
      expect(payoutBefore, lessThan(store.payrollFor(month)!.net));

      // a closed month is not changed any more
      expect(() => store.giveAdvance(employee: e, month: month, givenOn: DateTime.now(), amountMinor: 1000, accountId: store.accounts[1].id), throwsA(isA<AppError>().having((x) => x.key, 'key', 'adv_closed')));
      expect(() => store.addKpi(employee: e, month: month, title: 'x', maxBonusMinor: 1000, achievementBp: 5000), throwsA(isA<AppError>().having((x) => x.key, 'key', 'kpi_closed')));
    });

    test('the books survive a reload (advances and bonuses are saved with them)', () async {
      await store.flush();
      final again = await AppStore.open();
      expect(again.advancesOf(month), hasLength(2));
      expect(again.kpiOf(month), hasLength(2));
      expect(again.eriKeys, hasLength(2));
    });

    test('the report adds up: categories = totals, accounts = totals, net = income - expense', () {
      final r = buildReport(store, DateTime(DateTime.now().year, 1, 1), DateTime.now());
      expect(r.incomeRows.fold(0, (a, c) => a + c.amount), r.income);
      expect(r.expenseRows.fold(0, (a, c) => a + c.amount), r.expense);
      expect(r.flows.fold(0, (a, f) => a + f.income), r.income);
      expect(r.flows.fold(0, (a, f) => a + f.expense), r.expense);
      expect(r.net, r.income - r.expense);
      expect(r.buckets.fold(0, (a, b) => a + b.income), r.income);
      expect(r.income, greaterThan(0));
      final week = buildReport(store, DateTime.now().subtract(const Duration(days: 20)), DateTime.now());
      expect(week.buckets.every((b) => !b.monthly), isTrue);
      expect(buildReport(store, DateTime(2000), DateTime(2000, 1, 5)).income, 0);
    });

    test('import adds the rows to the chosen account, skipping duplicates and bad rows', () {
      final now = DateTime.now();
      final d = '${now.day.toString().padLeft(2, '0')}.${now.month.toString().padLeft(2, '0')}.${now.year}';
      final t = parseCsv('Sana;Summa;Izoh\n$d;1 500 000;Kirim A\n$d;-300 000;Chiqim B\nbad;1;x\n$d;1 500 000;Kirim A');
      final map = guessColumns(t.headers);
      final account = store.accounts[1];
      final before = store.balanceOf(account);
      final rows = buildImportRows(t, map, existing: store.txns);
      final created = store.importRows(rows, account.id);
      expect(created, 3); // the bad row is left out
      expect(store.balanceOf(account), before + 150000000 - 30000000 + 150000000);
      // importing the same text again: all three good rows are now duplicates
      final again = buildImportRows(t, map, existing: store.txns);
      expect(again.where((r) => r.ok && r.duplicate), hasLength(3));
      expect(store.importRows(again, account.id), 0);
      expect(store.importRows(again, account.id, includeDuplicates: true), 3);
    });
  });
}
