import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:bugalter_mobile/core/money.dart';
import 'package:bugalter_mobile/data/models.dart';
import 'package:bugalter_mobile/data/store.dart';
import 'package:bugalter_mobile/data/tax.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  group('money', () {
    test('formats whole so\'m with thin groups and parses input back to tiyin', () {
      expect(formatSom(123456700), '1 234 567');
      expect(formatSom(-50000), '−500');
      expect(parseMinor('1 250 000'), 125000000);
      expect(parseMinor('12,5'), 1250);
      expect(parseMinor('abc'), isNull);
      expect(percentOf(1000000, 1200), 120000);
      expect(formatBp(1200), '12%');
      expect(formatBp(10), '0,1%');
    });
  });

  group('tax rules and payroll', () {
    test('rates come from rules.json and payroll follows the backend calculator', () async {
      final book = await TaxRuleBook.load();
      final day = DateTime(2026, 5, 1);
      expect(book.rule('pit.standard', day).value, 1200);
      expect(book.rule('inps.employee', day).value, 10);
      expect(() => book.rule('nope', day), throwsA(isA<MissingTaxRule>()));
      final line = TaxCalculator(book).payrollLine(e: Employee(id: 1, fullName: 'X', salaryMinor: 1000000000), on: day, budgetOrg: false);
      expect(line.pit, 120000000); // 12% of 10 000 000 so'm
      expect(line.inps, 1000000); // 0.1% (10 bp), part of the PIT
      expect(line.social, 120000000);
      expect(line.net, 880000000);
    });

    test('calendar for a general-regime VAT payer has VAT, profit and payroll', () async {
      final book = await TaxRuleBook.load();
      final cal = TaxCalculator(book).calendar(Company(), DateTime(2026, 11, 23));
      expect(cal.map((o) => o.type).toSet(), {TaxType.vat, TaxType.profit, TaxType.payroll});
      for (final o in cal) {
        expect(o.due.isAfter(o.end), isTrue);
      }
    });
  });

  group('store', () {
    late AppStore store;
    setUp(() async {
      SharedPreferences.setMockInitialValues({});
      store = await AppStore.open();
    });

    test('demo data is consistent', () {
      expect(store.users, isNotEmpty);
      expect(store.docs, isNotEmpty);
      for (final d in store.docs) {
        expect(d.total, d.subtotal + d.vat);
      }
      final sumAccounts = store.accounts.fold<int>(0, (a, x) => a + store.balanceOf(x));
      expect(store.totalBalance, sumAccounts);
      expect(store.months(6), hasLength(6));
    });

    test('login accepts the demo logins and rejects a wrong password', () async {
      expect(await store.login('owner@bugalter.test', 'bad'), isFalse);
      expect(await store.login('owner@bugalter.test', 'Demo#2026pass'), isTrue);
      expect(store.role, Role.owner);
    });

    test('paying a document records the movement; cashier cannot approve payroll', () async {
      await store.login('owner@bugalter.test', 'Demo#2026pass');
      final doc = store.docs.firstWhere((d) => d.status == DocStatus.sent && d.outgoing);
      final before = store.totalBalance;
      store.setDocStatus(doc, DocStatus.paid, accountId: store.accounts[1].id);
      expect(store.totalBalance, before + doc.total);
      expect(() => store.setDocStatus(doc, DocStatus.sent), throwsA(isA<AppError>()));

      final run = store.preparePayroll(DateTime(2026, 11, 1));
      await store.login('cashier@bugalter.test', 'Demo#2026pass');
      expect(() => store.approvePayroll(run), throwsA(isA<AppError>()));
    });

    test('a report cannot be submitted without a person\'s approval', () async {
      await store.login('accountant@bugalter.test', 'Demo#2026pass');
      final o = store.calc.calendar(store.company, DateTime.now()).firstWhere((o) => store.reportFor(o) == null);
      final r = store.prepareReport(o);
      expect(r.rulesVerified, isFalse);
      expect(() => store.submitReport(r), throwsA(isA<AppError>()));
      store.requestApproval(r);
      store.approveReport(r);
      store.submitReport(r);
      expect(r.status, ReportStatus.submitted);
      expect(r.receipt, startsWith('SBX-'));
    });
  });
}
