import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../data/store.dart';
import '../../shell.dart';
import '../../widgets/charts.dart';
import '../../widgets/glass.dart';
import '../ai/ai_screen.dart';
import '../bank/transaction_form.dart';
import '../documents/document_detail.dart';
import '../documents/document_form.dart';

/// The home tab: balances, month result, week and 6-month charts, expense split, debts, tax deadlines and the latest operations.
class DashboardTab extends StatelessWidget {
  const DashboardTab({super.key, required this.onTab});
  final ValueChanged<TabId> onTab;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final now = DateTime.now();
    final mStart = DateTime(now.year, now.month, 1);
    final mEnd = DateTime(now.year, now.month + 1, 1);
    final pStart = DateTime(now.year, now.month - 1, 1);
    final income = s.sumTxns(TxType.income, mStart, mEnd);
    final expense = s.sumTxns(TxType.expense, mStart, mEnd);
    final pIncome = s.sumTxns(TxType.income, pStart, mStart);
    final pExpense = s.sumTxns(TxType.expense, pStart, mStart);
    final week = s.week(now);
    final weekTotal = week.days.fold(0, (a, b) => a + b);
    final months = s.months(6, now);

    final cards = <Widget>[
      _WeekCard(days: week.days, total: weekTotal, previous: week.previous, now: now),
      _TrendCard(months: months),
      _ExpenseCard(from: now.subtract(const Duration(days: 30)), to: now.add(const Duration(days: 1))),
      _DebtCard(),
      if (s.role.canSeeTax) _TaxSoonCard(onOpen: () => onTab(TabId.tax)),
      _RecentCard(onAll: () => onTab(TabId.bank)),
    ];

    final list = ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: EdgeInsets.only(top: context.headerSpace + 6, bottom: context.navSpace),
      children: [
        Reveal(child: _HeroCard(income: income, expense: expense, pIncome: pIncome, pExpense: pExpense)),
        const SizedBox(height: 14),
        Reveal(index: 1, child: _QuickActions(onTab: onTab)),
        const SizedBox(height: 18),
        Reveal(index: 2, child: SectionTitle(context.t('dash.accounts'), action: context.t('nav.bank'), onAction: () => onTab(TabId.bank))),
        Reveal(index: 2, child: _AccountsStrip()),
        const SizedBox(height: 18),
        AdaptiveGrid(
          minColumnWidth: 400,
          children: [for (var i = 0; i < cards.length; i++) Reveal(index: i + 3, child: cards[i])],
        ),
        const SizedBox(height: 8),
        Center(child: Text(s.serverMode ? context.t('srv.real_data', {'company': s.company.name}) : context.t('dash.demo_banner'), style: TextStyle(color: g.textDim.withValues(alpha: .7), fontSize: 11.5))),
      ],
    );
    // the real company: pull down to read it from the server again
    return s.serverMode ? RefreshIndicator(onRefresh: s.syncNow, color: g.accent, backgroundColor: g.fillStrong, child: list) : list;
  }
}

String _pct(int now, int prev) {
  if (prev <= 0) return '';
  final p = ((now - prev) * 100 / prev).round();
  return '${p >= 0 ? '+' : ''}$p%';
}

class _HeroCard extends StatelessWidget {
  const _HeroCard({required this.income, required this.expense, required this.pIncome, required this.pExpense});
  final int income, expense, pIncome, pExpense;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final profit = income - expense;
    Widget stat(String label, int v, IconData icon, Color c, String delta, bool goodWhenUp) {
      final up = delta.startsWith('+');
      final good = delta.isEmpty ? null : (up == goodWhenUp);
      return Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Icon(icon, size: 15, color: c),
            const SizedBox(width: 4),
            Flexible(child: Text(label, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12, height: 1.1))),
          ]),
          const SizedBox(height: 4),
          FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(context.compact(v), style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800))),
          if (delta.isNotEmpty) Text(delta, style: TextStyle(color: good == true ? g.success : g.danger, fontSize: 11.5, fontWeight: FontWeight.w700)),
        ]),
      );
    }

    return GlassCard(
      strong: true,
      radius: 30,
      padding: const EdgeInsets.all(20),
      gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [g.accent.withValues(alpha: g.dark ? .30 : .26), g.accent2.withValues(alpha: g.dark ? .22 : .18)]),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text(context.t('dash.balance'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w600))),
          Icon(Icons.account_balance_wallet_rounded, color: g.textDim, size: 20),
        ]),
        const SizedBox(height: 6),
        AmountText(s.totalBalance, size: context.isCompact ? 30 : 36),
        const SizedBox(height: 2),
        Text(context.t('dash.balance_sub'), style: TextStyle(color: g.textDim, fontSize: 12.5)),
        const SizedBox(height: 18),
        Container(height: 1, color: g.border),
        const SizedBox(height: 14),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          stat(context.t('dash.month_income'), income, Icons.south_west_rounded, g.success, _pct(income, pIncome), true),
          const SizedBox(width: 10),
          stat(context.t('dash.month_expense'), expense, Icons.north_east_rounded, g.danger, _pct(expense, pExpense), false),
          const SizedBox(width: 10),
          stat(context.t('dash.profit'), profit, profit >= 0 ? Icons.trending_up_rounded : Icons.trending_down_rounded, profit >= 0 ? g.accent : g.warning, '', true),
        ]),
      ]),
    );
  }
}

class _QuickActions extends StatelessWidget {
  const _QuickActions({required this.onTab});
  final ValueChanged<TabId> onTab;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final items = [
      (Icons.note_add_rounded, 'dash.q_invoice', g.info, () => pushPage(context, const DocumentFormScreen())),
      (Icons.south_west_rounded, 'dash.q_income', g.success, () => pushPage(context, const TransactionFormScreen(type: TxType.income))),
      (Icons.north_east_rounded, 'dash.q_expense', g.danger, () => pushPage(context, const TransactionFormScreen(type: TxType.expense))),
      (Icons.auto_awesome_rounded, 'dash.q_ai', g.accent2, () => pushPage(context, const AiScreen())),
    ];
    return IntrinsicHeight(child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (var i = 0; i < items.length; i++) ...[
        if (i > 0) const SizedBox(width: 10),
        Expanded(
          child: GlassCard(
            blur: false,
            padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 6),
            radius: 22,
            onTap: items[i].$4,
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              IconBadge(icon: items[i].$1, color: items[i].$3, size: 42),
              const SizedBox(height: 8),
              Text(context.t(items[i].$2), maxLines: 2, textAlign: TextAlign.center, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 12, fontWeight: FontWeight.w700, height: 1.15)),
            ]),
          ),
        ),
      ],
    ]));
  }
}

class _AccountsStrip extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final accs = s.activeAccounts;
    if (accs.isEmpty) return GlassCard(blur: false, child: Text(context.t('dash.empty'), style: TextStyle(color: g.textDim)));
    return SizedBox(
      height: 92,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        clipBehavior: Clip.none,
        itemCount: accs.length,
        separatorBuilder: (_, __) => const SizedBox(width: 12),
        itemBuilder: (context, i) {
          final a = accs[i];
          return SizedBox(
            width: 210,
            child: GlassCard(
              blur: false,
              radius: 22,
              padding: const EdgeInsets.all(14),
              child: Row(children: [
                IconBadge(icon: a.type == AccountType.cash ? Icons.payments_rounded : Icons.account_balance_rounded, color: a.type == AccountType.cash ? g.warning : g.info, size: 42),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(mainAxisAlignment: MainAxisAlignment.center, crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(a.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
                    const SizedBox(height: 3),
                    FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(context.money(s.balanceOf(a)), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15.5))),
                  ]),
                ),
              ]),
            ),
          );
        },
      ),
    );
  }
}

class _CardShell extends StatelessWidget {
  const _CardShell({required this.title, this.subtitle, required this.child, this.trailing});
  final String title;
  final String? subtitle;
  final Widget child;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return GlassCard(
      padding: const EdgeInsets.all(18),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, style: TextStyle(color: g.text, fontSize: 16.5, fontWeight: FontWeight.w800)),
              if (subtitle != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(subtitle!, style: TextStyle(color: g.textDim, fontSize: 12))),
            ]),
          ),
          if (trailing != null) trailing!,
        ]),
        const SizedBox(height: 14),
        child,
      ]),
    );
  }
}

class _WeekCard extends StatelessWidget {
  const _WeekCard({required this.days, required this.total, required this.previous, required this.now});
  final List<int> days;
  final int total, previous;
  final DateTime now;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final delta = _pct(total, previous);
    final labels = [for (var i = 6; i >= 0; i--) context.t('wd.${now.subtract(Duration(days: i)).weekday}')];
    return _CardShell(
      title: context.t('dash.week'),
      subtitle: context.t('dash.week_hint'),
      trailing: delta.isEmpty ? null : StatusPill(label: delta, color: delta.startsWith('+') ? g.success : g.danger, icon: delta.startsWith('+') ? Icons.trending_up_rounded : Icons.trending_down_rounded),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        AmountText(total, size: 26),
        const SizedBox(height: 12),
        AreaChart(values: days, labels: labels),
      ]),
    );
  }
}

class _TrendCard extends StatelessWidget {
  const _TrendCard({required this.months});
  final List<MonthPoint> months;

  @override
  Widget build(BuildContext context) {
    return _CardShell(
      title: context.t('dash.trend'),
      subtitle: context.t('dash.trend_hint'),
      child: BarsChart(
        labelA: context.t('txn.income'),
        labelB: context.t('txn.expense'),
        groups: [for (final m in months) BarGroup(context.t('mon.${m.month.month}'), m.income, m.expense)],
      ),
    );
  }
}

class _ExpenseCard extends StatelessWidget {
  const _ExpenseCard({required this.from, required this.to});
  final DateTime from, to;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final by = s.expenseByCategory(from, to);
    final sorted = by.entries.toList()..sort((a, b) => b.value.compareTo(a.value));
    final palette = [g.accent, g.accent2, g.info, g.warning, g.danger, g.textDim];
    final slices = <DonutSlice>[];
    var other = 0;
    for (var i = 0; i < sorted.length; i++) {
      final name = context.categoryName(s.category(sorted[i].key == 0 ? null : sorted[i].key));
      if (i < 5) {
        slices.add(DonutSlice(name, sorted[i].value, palette[i]));
      } else {
        other += sorted[i].value;
      }
    }
    if (other > 0) slices.add(DonutSlice('…', other, palette[5]));
    final total = slices.fold(0, (a, b) => a + b.value);
    return _CardShell(
      title: context.t('dash.expense_split'),
      subtitle: context.t('dash.expense_hint'),
      child: slices.isEmpty
          ? Text(context.t('dash.empty'), style: TextStyle(color: g.textDim))
          : LayoutBuilder(builder: (context, c) {
              final narrow = c.maxWidth < 330;
              final donut = DonutChart(
                size: narrow ? 130 : 140,
                slices: slices,
                center: Column(mainAxisSize: MainAxisSize.min, children: [
                  Text(context.compact(total), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                  Text(context.t('txn.expense'), style: TextStyle(color: g.textDim, fontSize: 11)),
                ]),
              );
              final legend = Column(children: [
                for (final sl in slices)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(children: [
                      Container(width: 10, height: 10, decoration: BoxDecoration(color: sl.color, shape: BoxShape.circle)),
                      const SizedBox(width: 8),
                      Expanded(child: Text(sl.label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 13))),
                      Text('${(sl.value * 100 / total).round()}%', style: TextStyle(color: g.textDim, fontSize: 12.5, fontWeight: FontWeight.w700)),
                    ]),
                  ),
              ]);
              return narrow
                  ? Column(children: [donut, const SizedBox(height: 12), legend])
                  : Row(children: [donut, const SizedBox(width: 16), Expanded(child: legend)]);
            }),
    );
  }
}

class _DebtCard extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final rec = s.receivable, pay = s.payable;
    final over = s.overdueDocs()..sort((a, b) => a.dueDate!.compareTo(b.dueDate!));
    final totalDebt = rec + pay;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _CardShell(
        title: context.t('dash.receivables'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Expanded(child: _Debt(label: context.t('dash.receivable'), value: rec, color: g.success)),
            const SizedBox(width: 12),
            Expanded(child: _Debt(label: context.t('dash.payable'), value: pay, color: g.warning)),
          ]),
          const SizedBox(height: 12),
          MeterBar(fraction: totalDebt == 0 ? 0 : rec / totalDebt, color: g.success, height: 9),
        ]),
      ),
      const SizedBox(height: 14),
      TileSection(
        title: context.t('dash.overdue'),
        empty: context.t('dash.no_overdue'),
        children: [
          for (final d in over.take(3))
            SoftTile(
              onTap: () => pushPage(context, DocumentDetailScreen(docId: d.id)),
              child: RowTile(
                leading: IconBadge(icon: Icons.event_busy_rounded, size: 40, color: g.danger),
                title: d.number,
                subtitle: s.contractor(d.contractorId)?.name,
                trailing: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.end, children: [
                  Text(context.money(d.total), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 13.5)),
                  Text(context.t('dash.days_late', {'n': DateTime.now().difference(d.dueDate!).inDays}), style: TextStyle(color: g.danger, fontSize: 11.5, fontWeight: FontWeight.w700)),
                ]),
              ),
            ),
        ],
      ),
    ]);
  }
}

class _Debt extends StatelessWidget {
  const _Debt({required this.label, required this.value, required this.color});
  final String label;
  final int value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: color.withValues(alpha: .12), borderRadius: BorderRadius.circular(18), border: Border.all(color: color.withValues(alpha: .3))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12)),
        const SizedBox(height: 4),
        AmountText(value, size: 18, color: g.text),
      ]),
    );
  }
}

class _TaxSoonCard extends StatelessWidget {
  const _TaxSoonCard({required this.onOpen});
  final VoidCallback onOpen;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final now = DateTime.now();
    final open = s.calc.calendar(s.company, now).where((o) => s.reportFor(o)?.status != ReportStatus.submitted).take(3).toList();
    return TileSection(
      title: context.t('dash.tax_soon'),
      empty: context.t('dash.no_tax'),
      trailing: GestureDetector(onTap: onOpen, child: Text(context.t('common.view_all'), style: TextStyle(color: g.accent, fontWeight: FontWeight.w700, fontSize: 13))),
      children: [
        for (final o in open)
          SoftTile(
            onTap: onOpen,
            child: RowTile(
              leading: IconBadge(icon: Icons.receipt_long_rounded, size: 40, color: o.daysLeft(now) < 0 ? g.danger : (o.daysLeft(now) <= 7 ? g.warning : g.info)),
              title: context.t('taxtype.${o.type.name}'),
              subtitle: context.date(o.due),
              trailing: StatusPill(
                label: o.daysLeft(now) >= 0 ? context.t('dash.days_left', {'n': o.daysLeft(now)}) : context.t('dash.days_late', {'n': -o.daysLeft(now)}),
                color: o.daysLeft(now) < 0 ? g.danger : (o.daysLeft(now) <= 7 ? g.warning : g.info),
              ),
            ),
          ),
      ],
    );
  }
}

class _RecentCard extends StatelessWidget {
  const _RecentCard({required this.onAll});
  final VoidCallback onAll;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final list = s.txnsSorted().take(6).toList();
    return TileSection(
      title: context.t('dash.recent'),
      empty: context.t('dash.empty'),
      trailing: GestureDetector(onTap: onAll, child: Text(context.t('common.view_all'), style: TextStyle(color: g.accent, fontWeight: FontWeight.w700, fontSize: 13))),
      children: [
        for (final t in list)
          SoftTile(
            onTap: () => pushPage(context, TransactionFormScreen(txn: t)),
            child: RowTile(
              leading: IconBadge(icon: t.type == TxType.income ? Icons.south_west_rounded : Icons.north_east_rounded, size: 40, color: t.type == TxType.income ? g.success : g.danger),
              title: t.description.isEmpty ? context.categoryName(s.category(t.categoryId)) : t.description,
              subtitle: '${context.date(t.occurredAt)} · ${context.categoryName(s.category(t.categoryId))}',
              trailing: Text(context.money(t.signed, sign: true), style: TextStyle(color: t.type == TxType.income ? g.success : g.text, fontWeight: FontWeight.w800, fontSize: 13.5)),
            ),
          ),
      ],
    );
  }
}
