import 'dart:math';

import '../core/money.dart';
import 'models.dart';
import 'store.dart';

int _som(int v) => v * kTiyin;

/// Fills the store with a believable demo company. Dates are relative to today so the demo always looks current.
void seedDemo(AppStore s, {DateTime? today}) {
  final now = dateOnly(today ?? DateTime.now());
  final rnd = Random(2026);
  var id = 0;
  int next() => ++id;

  s.company = Company(
    name: 'Demo Savdo MChJ',
    stir: '305 123 456',
    address: 'Toshkent sh., Yunusobod t., Amir Temur koʻch., 108',
    phone: '+998 71 200 00 00',
    director: 'Akbarov Jasur',
    regime: 'general',
    vatPayer: true,
  );

  s.users
    ..clear()
    ..addAll([
      AppUser(id: next(), name: 'Akbarov Jasur', login: 'owner@bugalter.test', password: 'Demo#2026pass', role: Role.owner, position: 'Direktor'),
      AppUser(id: next(), name: 'Karimova Dilnoza', login: 'accountant@bugalter.test', password: 'Demo#2026pass', role: Role.chiefAccountant, position: 'Bosh buxgalter'),
      AppUser(id: next(), name: 'Rahimov Sherzod', login: 'cashier@bugalter.test', password: 'Demo#2026pass', role: Role.cashier, position: 'Kassir'),
    ]);

  s.accounts
    ..clear()
    ..addAll([
      Account(id: next(), name: 'Kassa', type: AccountType.cash, openingMinor: _som(90000000)),
      Account(id: next(), name: 'Asosiy hisob', type: AccountType.bank, bankName: 'Hamkorbank', accountNumber: '20208000905123456001', openingMinor: _som(85000000)),
      Account(id: next(), name: 'Zaxira hisob', type: AccountType.bank, bankName: 'Xalq banki', accountNumber: '20208000100987654002', openingMinor: _som(20000000)),
    ]);
  final cash = s.accounts[0], bank = s.accounts[1];

  Category cat(String key, TxType t) => Category(id: next(), key: key, type: t);
  s.categories
    ..clear()
    ..addAll([
      cat('sales', TxType.income),
      cat('services', TxType.income),
      cat('other_income', TxType.income),
      cat('salary', TxType.expense),
      cat('rent', TxType.expense),
      cat('utilities', TxType.expense),
      cat('supplies', TxType.expense),
      cat('transport', TxType.expense),
      cat('marketing', TxType.expense),
      cat('taxes', TxType.expense),
      cat('bank_fees', TxType.expense),
      cat('other_expense', TxType.expense),
    ]);
  int c(String key) => s.categoryByKey(key)!.id;

  Contractor con(String name, String stir, ContractorType t, String phone, String bankName) => Contractor(
        id: next(), name: name, stir: stir, type: t, phone: phone, bankName: bankName,
        bankAccount: '20208000${100000000 + rnd.nextInt(899999999)}', mfo: '00${400 + rnd.nextInt(99)}',
        address: 'Toshkent sh.', email: '',
      );
  s.contractors
    ..clear()
    ..addAll([
      con('Orient Trade MChJ', '301 556 781', ContractorType.customer, '+998 90 111 22 33', 'Hamkorbank'),
      con('Silk Road Logistics', '302 884 120', ContractorType.customer, '+998 91 222 33 44', 'Ipoteka bank'),
      con('Samarqand Non QK', '300 112 905', ContractorType.customer, '+998 93 333 44 55', 'Xalq banki'),
      con('Texno Mir MChJ', '305 778 342', ContractorType.both, '+998 94 444 55 66', 'Kapitalbank'),
      con('Bukhara Textile', '303 410 667', ContractorType.customer, '+998 97 555 66 77', 'Asakabank'),
      con('Afrosiyob Ofis Servis', '306 221 089', ContractorType.supplier, '+998 98 666 77 88', 'Hamkorbank'),
      con('UzEnergo Servis', '207 330 415', ContractorType.supplier, '+998 71 777 88 99', 'Milliy bank'),
      con('Navoiy Print', '304 998 201', ContractorType.supplier, '+998 99 888 99 00', 'Ipoteka bank'),
    ]);
  final customers = s.contractors.take(5).toList();
  final suppliers = s.contractors.skip(5).toList();

  // Documents: invoices and acts over the last ten months.
  s.docs.clear();
  s.txns.clear();
  final items = <List<Object>>[
    ['Dasturiy taʼminot litsenziyasi', 'dona', 1500000],
    ['Buxgalteriya xizmati', 'oy', 4200000],
    ['Konsalting xizmati', 'soat', 350000],
    ['Tovar yetkazib berish', 'dona', 850000],
    ['Texnik qoʻllab-quvvatlash', 'oy', 2800000],
    ['Reklama xizmati', 'dona', 1900000],
  ];

  Doc makeDoc(int monthsBack, int day, {required bool outgoing, required DocStatus status, int? due}) {
    final date = dateOnly(DateTime(now.year, now.month - monthsBack, day.clamp(1, 28)));
    final type = rnd.nextBool() ? DocType.invoice : DocType.act;
    final party = outgoing ? customers[rnd.nextInt(customers.length)] : suppliers[rnd.nextInt(suppliers.length)];
    final lines = <DocLine>[
      for (var i = 0; i < 1 + rnd.nextInt(2); i++)
        () {
          final it = items[rnd.nextInt(items.length)];
          return DocLine(name: it[0] as String, unit: it[1] as String, qtyMilli: (1 + rnd.nextInt(6)) * 1000, unitPriceMinor: _som(it[2] as int), vatBp: 1200);
        }(),
    ];
    final d = Doc(
      id: next(), type: type, number: s.nextNumber(type, date), contractorId: party.id, issueDate: date,
      dueDate: date.add(Duration(days: due ?? 14)), status: status, outgoing: outgoing, lines: lines,
    );
    s.docs.add(d);
    if (status == DocStatus.paid) {
      final paidOn = date.add(const Duration(days: 9));
      d.paidAt = paidOn.isAfter(now) ? now : paidOn;
      s.txns.add(Txn(
        id: next(), accountId: bank.id, type: outgoing ? TxType.income : TxType.expense, amountMinor: d.total,
        occurredAt: d.paidAt!.add(const Duration(hours: 11)), categoryId: c(outgoing ? (type == DocType.invoice ? 'sales' : 'services') : 'supplies'),
        contractorId: party.id, documentId: d.id, description: d.number, reference: d.number,
      ));
    }
    return d;
  }

  for (var m = 9; m >= 2; m--) {
    makeDoc(m, 3 + rnd.nextInt(5), outgoing: true, status: DocStatus.paid);
    makeDoc(m, 10 + rnd.nextInt(8), outgoing: true, status: DocStatus.paid);
    if (m.isEven) makeDoc(m, 20, outgoing: false, status: DocStatus.paid);
  }
  makeDoc(1, 6, outgoing: true, status: DocStatus.paid);
  makeDoc(1, 12, outgoing: true, status: DocStatus.sent, due: 10); // overdue
  makeDoc(1, 18, outgoing: false, status: DocStatus.sent, due: 12); // overdue payable
  makeDoc(0, 2, outgoing: true, status: DocStatus.paid);
  makeDoc(0, 4, outgoing: true, status: DocStatus.sent, due: 30);
  makeDoc(0, 5, outgoing: true, status: DocStatus.sent, due: 30);
  makeDoc(0, 5, outgoing: false, status: DocStatus.sent, due: 30);
  makeDoc(0, 5, outgoing: true, status: DocStatus.draft);

  // Everyday movements (not tied to documents).
  for (var m = 11; m >= 0; m--) {
    final base = DateTime(now.year, now.month - m, 1);
    DateTime on(int day) {
      final d = DateTime(base.year, base.month, day.clamp(1, 28), 9 + rnd.nextInt(8), rnd.nextInt(60));
      return d.isAfter(now) ? now : d;
    }

    void add(Account a, TxType t, int amount, String key, int day, {String desc = '', int? party}) => s.txns.add(Txn(
          id: next(), accountId: a.id, type: t, amountMinor: _som(amount), occurredAt: on(day), categoryId: c(key), contractorId: party, description: desc,
        ));

    add(bank, TxType.income, 18000000 + rnd.nextInt(35000000), 'sales', 5 + rnd.nextInt(6), party: customers[rnd.nextInt(customers.length)].id);
    add(bank, TxType.income, 6000000 + rnd.nextInt(14000000), 'services', 12 + rnd.nextInt(8), party: customers[rnd.nextInt(customers.length)].id);
    if (rnd.nextInt(3) == 0) add(cash, TxType.income, 1500000 + rnd.nextInt(4000000), 'other_income', 15);
    add(bank, TxType.expense, 8000000, 'rent', 2, party: suppliers[0].id, desc: 'Ofis ijarasi');
    add(bank, TxType.expense, 1200000 + rnd.nextInt(900000), 'utilities', 8, party: suppliers[1].id);
    add(cash, TxType.expense, 900000 + rnd.nextInt(1800000), 'supplies', 11 + rnd.nextInt(5), party: suppliers[2].id);
    add(cash, TxType.expense, 300000 + rnd.nextInt(700000), 'transport', 14 + rnd.nextInt(8));
    if (m.isEven) add(bank, TxType.expense, 1500000 + rnd.nextInt(2500000), 'marketing', 16);
    add(bank, TxType.expense, 80000 + rnd.nextInt(120000), 'bank_fees', 28);
    add(bank, TxType.expense, 2500000 + rnd.nextInt(2500000), 'taxes', 15);
  }
  // Unclassified lines from the bank statement: the AI accountant proposes categories for them.
  s.txns.addAll([
    Txn(id: next(), accountId: bank.id, type: TxType.expense, amountMinor: _som(540000), occurredAt: now.subtract(const Duration(days: 2, hours: -10)), description: 'Yandex Go taksi'),
    Txn(id: next(), accountId: bank.id, type: TxType.expense, amountMinor: _som(2300000), occurredAt: now.subtract(const Duration(days: 3, hours: -9)), description: 'Elektr energiya uchun toʻlov'),
    Txn(id: next(), accountId: bank.id, type: TxType.expense, amountMinor: _som(1750000), occurredAt: now.subtract(const Duration(days: 4, hours: -11)), description: 'Google Ads reklama'),
    Txn(id: next(), accountId: bank.id, type: TxType.income, amountMinor: _som(9600000), occurredAt: now.subtract(const Duration(days: 5, hours: -12)), description: 'Toʻlov: realizatsiya uchun'),
    Txn(id: next(), accountId: cash.id, type: TxType.expense, amountMinor: _som(310000), occurredAt: now.subtract(const Duration(days: 1, hours: -14)), description: 'Kanselyariya tovarlari'),
  ]);

  // Staff and three months of payroll.
  Employee emp(String name, String pos, String dept, int salary, int monthsAgo) => Employee(
        id: next(), fullName: name, pinfl: '${30000000000000 + rnd.nextInt(99999999)}', position: pos, department: dept,
        phone: '+998 9${rnd.nextInt(10)} ${100 + rnd.nextInt(899)} ${10 + rnd.nextInt(89)} ${10 + rnd.nextInt(89)}',
        hiredOn: DateTime(now.year, now.month - monthsAgo, 1), salaryMinor: _som(salary),
      );
  s.employees
    ..clear()
    ..addAll([
      emp('Akbarov Jasur', 'Direktor', 'Boshqaruv', 12000000, 40),
      emp('Karimova Dilnoza', 'Bosh buxgalter', 'Buxgalteriya', 9000000, 34),
      emp('Rahimov Sherzod', 'Kassir', 'Buxgalteriya', 5500000, 20),
      emp('Toshpoʻlatov Bobur', 'Sotuv menejeri', 'Sotuv', 6500000, 14),
      emp('Yusupova Malika', 'Sotuv menejeri', 'Sotuv', 6500000, 9),
      emp('Ergashev Doniyor', 'Haydovchi', 'Logistika', 4200000, 6),
    ]);

  s.payrolls.clear();
  for (var m = 3; m >= 1; m--) {
    final period = DateTime(now.year, now.month - m, 1);
    final run = PayrollRun(id: next(), period: period, lines: [
      for (final e in s.employees.where((e) => e.hiredOn!.isBefore(period.add(const Duration(days: 28)))))
        s.calc.payrollLine(e: e, on: period, budgetOrg: false),
    ]);
    run.status = m == 1 ? PayrollStatus.approved : PayrollStatus.paid;
    run.approvedBy = 'Karimova Dilnoza';
    if (m > 1) {
      run.paidAt = DateTime(period.year, period.month + 1, 5);
      run.paidAccountId = bank.id;
    }
    s.payrolls.add(run);
  }

  // Tax reports already filed through the sandbox for periods that are over.
  s.reports.clear();
  for (final o in s.calc.calendar(s.company, now)) {
    if (!o.due.isBefore(now) || !o.start.isBefore(DateTime(now.year, now.month - 1, 1))) continue;
    final comp = s.compute(o.type, o.start, o.end);
    s.reports.add(TaxReport(
      id: next(), type: o.type, start: o.start, end: o.end, baseMinor: comp.baseMinor, taxMinor: comp.taxMinor,
      status: ReportStatus.submitted, approvedBy: 'Akbarov Jasur', receipt: 'SBX-${o.end.year}${o.end.month.toString().padLeft(2, '0')}-$id',
      updatedAt: o.due.subtract(const Duration(days: 3)),
    ));
  }

  s.audit
    ..clear()
    ..add(AuditEntry(at: now, actor: 'system', action: 'seed', subject: 'demo'));
  // keep the id counter ahead of everything created above
  while (s.nextId() <= id) {}
}
