import 'models.dart';
import 'store.dart';

/// Totals of the books for a period. Whole tiyin only. It is a sum of the operations done, not a tax or accounting return:
/// tax figures come only from the rules tables through the calculators (the Taxes page).

class CategoryTotal {
  CategoryTotal(this.categoryId, this.amount);
  final int? categoryId;
  final int amount;
}

class AccountFlow {
  AccountFlow(this.account, this.income, this.expense);
  final Account account;
  final int income, expense;
  int get net => income - expense;
}

class ContractorTotal {
  ContractorTotal(this.contractor, this.income, this.expense);
  final Contractor contractor;
  final int income, expense;
  int get volume => income + expense;
}

/// One bar group of the chart: a week (for a period up to a month and a bit) or a calendar month.
class Bucket {
  Bucket(this.start, this.monthly, this.income, this.expense);
  final DateTime start;
  final bool monthly;
  final int income, expense;
}

class ReportData {
  ReportData({
    required this.from,
    required this.to,
    required this.income,
    required this.expense,
    required this.incomeRows,
    required this.expenseRows,
    required this.flows,
    required this.contractors,
    required this.buckets,
    required this.receivable,
    required this.payable,
    required this.overdue,
    required this.count,
  });

  final DateTime from, to; // inclusive dates
  final int income, expense;
  final List<CategoryTotal> incomeRows, expenseRows;
  final List<AccountFlow> flows;
  final List<ContractorTotal> contractors; // by volume, biggest first
  final List<Bucket> buckets;
  final int receivable, payable, overdue; // open documents as of today
  final int count; // operations in the period

  int get net => income - expense;
}

DateTime _day(DateTime d) => DateTime(d.year, d.month, d.day);

/// The periods of the selector.
(DateTime, DateTime) presetRange(String preset, DateTime now) {
  final today = _day(now);
  switch (preset) {
    case 'quarter':
      final q = (today.month - 1) ~/ 3;
      return (DateTime(today.year, q * 3 + 1, 1), today);
    case 'year':
      return (DateTime(today.year, 1, 1), today);
    default:
      return (DateTime(today.year, today.month, 1), today);
  }
}

ReportData buildReport(AppStore s, DateTime fromDate, DateTime toDate, {DateTime? now}) {
  final from = _day(fromDate), to = _day(toDate);
  final end = to.add(const Duration(days: 1));
  final inRange = s.txns.where((t) => !t.occurredAt.isBefore(from) && t.occurredAt.isBefore(end)).toList();

  Map<int?, int> group(TxType type) {
    final m = <int?, int>{};
    for (final t in inRange.where((t) => t.type == type)) {
      m[t.categoryId] = (m[t.categoryId] ?? 0) + t.amountMinor;
    }
    return m;
  }

  List<CategoryTotal> rows(Map<int?, int> m) => [for (final e in m.entries) CategoryTotal(e.key, e.value)]..sort((a, b) => b.amount.compareTo(a.amount));

  final income = inRange.where((t) => t.type == TxType.income).fold(0, (a, t) => a + t.amountMinor);
  final expense = inRange.where((t) => t.type == TxType.expense).fold(0, (a, t) => a + t.amountMinor);

  final flows = <AccountFlow>[];
  for (final a in s.accounts) {
    final mine = inRange.where((t) => t.accountId == a.id);
    final i = mine.where((t) => t.type == TxType.income).fold(0, (x, t) => x + t.amountMinor);
    final e = mine.where((t) => t.type == TxType.expense).fold(0, (x, t) => x + t.amountMinor);
    if (i != 0 || e != 0) flows.add(AccountFlow(a, i, e));
  }

  final byContractor = <int, List<int>>{};
  for (final t in inRange.where((t) => t.contractorId != null)) {
    final v = byContractor.putIfAbsent(t.contractorId!, () => [0, 0]);
    v[t.type == TxType.income ? 0 : 1] += t.amountMinor;
  }
  final contractors = <ContractorTotal>[];
  for (final e in byContractor.entries) {
    final c = s.contractor(e.key);
    if (c != null) contractors.add(ContractorTotal(c, e.value[0], e.value[1]));
  }
  contractors.sort((a, b) => b.volume.compareTo(a.volume));

  // chart buckets: weeks for a period of up to 35 days, calendar months otherwise
  final days = to.difference(from).inDays + 1;
  final buckets = <Bucket>[];
  if (days <= 35) {
    for (var start = from; !start.isAfter(to); start = start.add(const Duration(days: 7))) {
      final stop = start.add(const Duration(days: 7));
      final part = inRange.where((t) => !t.occurredAt.isBefore(start) && t.occurredAt.isBefore(stop));
      buckets.add(Bucket(start, false, part.where((t) => t.type == TxType.income).fold(0, (a, t) => a + t.amountMinor), part.where((t) => t.type == TxType.expense).fold(0, (a, t) => a + t.amountMinor)));
    }
  } else {
    for (var m = DateTime(from.year, from.month, 1); !m.isAfter(to); m = DateTime(m.year, m.month + 1, 1)) {
      final stop = DateTime(m.year, m.month + 1, 1);
      final part = inRange.where((t) => !t.occurredAt.isBefore(m) && t.occurredAt.isBefore(stop));
      buckets.add(Bucket(m, true, part.where((t) => t.type == TxType.income).fold(0, (a, t) => a + t.amountMinor), part.where((t) => t.type == TxType.expense).fold(0, (a, t) => a + t.amountMinor)));
    }
  }

  final today = now ?? DateTime.now();
  return ReportData(
    from: from,
    to: to,
    income: income,
    expense: expense,
    incomeRows: rows(group(TxType.income)),
    expenseRows: rows(group(TxType.expense)),
    flows: flows,
    contractors: contractors.take(5).toList(),
    buckets: buckets,
    receivable: s.receivable,
    payable: s.payable,
    overdue: s.overdueDocs(today).fold(0, (a, d) => a + d.total),
    count: inRange.length,
  );
}
