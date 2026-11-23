import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import 'account_form.dart';
import 'categories_screen.dart';
import 'category_icons.dart';
import 'transaction_form.dart';

/// The money tab: balances, accounts, the month's summary and the list of operations.
class BankTab extends StatefulWidget {
  const BankTab({super.key});

  @override
  State<BankTab> createState() => _BankTabState();
}

class _BankTabState extends State<BankTab> {
  late DateTime _month = DateTime(DateTime.now().year, DateTime.now().month, 1);
  int _filter = 0; // 0 all, 1 income, 2 expense
  String _query = '';

  bool get _isCurrent {
    final n = DateTime.now();
    return _month.year == n.year && _month.month == n.month;
  }

  void _step(int d) => setState(() => _month = DateTime(_month.year, _month.month + d, 1));

  Future<void> _delete(Txn t) async {
    final ok = await confirmDialog(context, title: context.t('bank.delete_txn_title'), message: context.t('bank.delete_txn_text'), confirmLabel: context.t('common.delete'), danger: true);
    if (ok && mounted) {
      context.readStore.deleteTxn(t);
      context.toast(context.t('common.deleted'));
    }
  }

  String _dayLabel(DateTime d) {
    final now = DateTime.now();
    final today = dateOnly(now);
    final day = dateOnly(d);
    if (day == today) return context.t('common.today');
    if (day == today.subtract(const Duration(days: 1))) return context.t('common.yesterday');
    return context.date(d);
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final from = _month;
    final to = DateTime(_month.year, _month.month + 1, 1);
    final income = s.sumTxns(TxType.income, from, to);
    final expense = s.sumTxns(TxType.expense, from, to);

    final q = _query.trim().toLowerCase();
    final list = s.txnsSorted().where((t) {
      if (t.occurredAt.isBefore(from) || !t.occurredAt.isBefore(to)) return false;
      if (_filter == 1 && t.type != TxType.income) return false;
      if (_filter == 2 && t.type != TxType.expense) return false;
      if (q.isEmpty) return true;
      final hay = '${t.description} ${t.reference} ${context.categoryName(s.category(t.categoryId))} ${s.contractor(t.contractorId)?.name ?? ''} ${s.account(t.accountId)?.name ?? ''} ${formatSom(t.amountMinor)}'.toLowerCase();
      return hay.contains(q);
    }).toList();

    // Flat list of rows: day headers and operations.
    final rows = <Widget>[];
    DateTime? lastDay;
    var n = 0;
    for (final t in list) {
      final d = dateOnly(t.occurredAt);
      if (lastDay != d) {
        lastDay = d;
        rows.add(Padding(
          key: ValueKey('h${d.toIso8601String()}'),
          padding: const EdgeInsets.fromLTRB(6, 14, 6, 6),
          child: Text(_dayLabel(d), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w800, fontSize: 13)),
        ));
      }
      rows.add(Reveal(key: ValueKey('t${t.id}'), index: n++, dy: 12, child: _TxnTile(txn: t, onDelete: () => _delete(t))));
    }

    return Stack(children: [
      ListView(
        padding: EdgeInsets.only(top: context.headerSpace + 4, bottom: context.navSpace + 64),
        children: [
          Reveal(child: _BalanceCard(total: s.totalBalance)),
          const SizedBox(height: 14),
          SectionTitle(context.t('bank.accounts')),
          SizedBox(
            height: 128,
            child: ListView(
              scrollDirection: Axis.horizontal,
              clipBehavior: Clip.none,
              children: [
                for (var i = 0; i < s.accounts.length; i++)
                  Reveal(index: i, child: _AccountCard(account: s.accounts[i], onTap: () => pushPage(context, AccountFormScreen(account: s.accounts[i])))),
                Reveal(index: s.accounts.length, child: _AddAccountCard(onTap: () => pushPage(context, const AccountFormScreen()))),
              ],
            ),
          ),
          const SizedBox(height: 18),
          SectionTitle(context.t('bank.transactions'), action: context.t('bank.categories'), onAction: () => pushPage(context, const CategoriesScreen())),
          _MonthSummary(month: _month, income: income, expense: expense, canNext: !_isCurrent, onPrev: () => _step(-1), onNext: () => _step(1)),
          const SizedBox(height: 12),
          SegmentedGlass(labels: [context.t('common.all'), context.t('txn.income'), context.t('txn.expense')], index: _filter, onChanged: (i) => setState(() => _filter = i)),
          const SizedBox(height: 10),
          GlassField(label: context.t('common.search'), icon: Icons.search_rounded, onChanged: (v) => setState(() => _query = v)),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 280),
            child: list.isEmpty
                ? Padding(
                    key: const ValueKey('empty'),
                    padding: const EdgeInsets.only(top: 24),
                    child: EmptyState(icon: Icons.receipt_long_outlined, title: context.t('bank.empty_title'), text: context.t('bank.empty_text')),
                  )
                : Column(key: ValueKey('list$_filter$q${_month.month}'), crossAxisAlignment: CrossAxisAlignment.stretch, children: rows),
          ),
        ],
      ),
      Positioned(
        right: 0,
        bottom: context.navSpace - 12,
        child: AppButton(
          label: context.t('bank.add_txn'),
          icon: Icons.add_rounded,
          expanded: false,
          onPressed: () => pushPage(context, TransactionFormScreen(type: _filter == 2 ? TxType.expense : TxType.income)),
        ),
      ),
    ]);
  }
}

class _BalanceCard extends StatelessWidget {
  const _BalanceCard({required this.total});
  final int total;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return GlassCard(
      strong: true,
      padding: const EdgeInsets.all(20),
      gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [g.accent.withValues(alpha: .28), g.accent2.withValues(alpha: .2)]),
      child: Row(children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(context.t('bank.total_balance'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            AmountText(total, size: 34),
          ]),
        ),
        const SizedBox(width: 10),
        IconBadge(icon: Icons.account_balance_wallet_rounded, size: 52, color: g.accent),
      ]),
    );
  }
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({required this.account, required this.onTap});
  final Account account;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final cash = account.type == AccountType.cash;
    return Padding(
      padding: const EdgeInsets.only(right: 12),
      child: SizedBox(
        width: 210,
        child: Opacity(
          opacity: account.active ? 1 : .55,
          child: GlassCard(
            onTap: onTap,
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
              Row(children: [
                IconBadge(icon: cash ? Icons.payments_rounded : Icons.account_balance_rounded, size: 38, color: cash ? g.warning : g.info),
                const SizedBox(width: 10),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(account.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                    Text(cash ? context.t('bank.type_cash') : (account.bankName.isEmpty ? context.t('bank.type_bank') : account.bankName),
                        maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12)),
                  ]),
                ),
              ]),
              AmountText(s.balanceOf(account), size: 22),
            ]),
          ),
        ),
      ),
    );
  }
}

class _AddAccountCard extends StatelessWidget {
  const _AddAccountCard({required this.onTap});
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return SizedBox(
      width: 140,
      child: GlassCard(
        onTap: onTap,
        tint: Colors.transparent,
        child: Center(
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            IconBadge(icon: Icons.add_rounded, color: g.accent2),
            const SizedBox(height: 8),
            Text(context.t('bank.add_account'), textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 13)),
          ]),
        ),
      ),
    );
  }
}

class _MonthSummary extends StatelessWidget {
  const _MonthSummary({required this.month, required this.income, required this.expense, required this.canNext, required this.onPrev, required this.onNext});
  final DateTime month;
  final int income, expense;
  final bool canNext;
  final VoidCallback onPrev, onNext;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final net = income - expense;
    Widget stat(String label, int v, Color c, {bool sign = false}) => Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12, fontWeight: FontWeight.w600)),
            const SizedBox(height: 3),
            FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(context.compact(v), style: TextStyle(color: c, fontWeight: FontWeight.w800, fontSize: 17))),
          ]),
        );
    return GlassCard(
      padding: const EdgeInsets.all(14),
      child: Column(children: [
        Row(children: [
          GlassIconButton(icon: Icons.chevron_left_rounded, size: 38, onTap: onPrev),
          Expanded(
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 250),
              child: Text(context.monthYear(month), key: ValueKey(month), textAlign: TextAlign.center, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16)),
            ),
          ),
          Opacity(opacity: canNext ? 1 : .35, child: GlassIconButton(icon: Icons.chevron_right_rounded, size: 38, onTap: canNext ? onNext : () {})),
        ]),
        const SizedBox(height: 12),
        Row(children: [
          stat(context.t('txn.income'), income, g.success),
          const SizedBox(width: 8),
          stat(context.t('txn.expense'), expense, g.danger),
          const SizedBox(width: 8),
          stat(context.t('bank.net'), net, net >= 0 ? g.accent : g.danger),
        ]),
      ]),
    );
  }
}

class _TxnTile extends StatelessWidget {
  const _TxnTile({required this.txn, required this.onDelete});
  final Txn txn;
  final VoidCallback onDelete;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final cat = s.category(txn.categoryId);
    final income = txn.type == TxType.income;
    final color = income ? g.success : g.danger;
    final title = txn.description.isNotEmpty ? txn.description : context.categoryName(cat);
    final parts = [
      if (txn.description.isNotEmpty) context.categoryName(cat),
      s.contractor(txn.contractorId)?.name,
      s.account(txn.accountId)?.name,
    ].whereType<String>().where((e) => e.isNotEmpty).join(' · ');
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Dismissible(
        key: ValueKey('d${txn.id}'),
        direction: DismissDirection.endToStart,
        confirmDismiss: (_) async {
          onDelete();
          return false;
        },
        background: Container(
          alignment: Alignment.centerRight,
          padding: const EdgeInsets.only(right: 22),
          decoration: BoxDecoration(color: g.danger.withValues(alpha: .25), borderRadius: BorderRadius.circular(24)),
          child: Icon(Icons.delete_outline_rounded, color: g.danger),
        ),
        child: GlassCard(
          blur: false,
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 4),
          onTap: () => pushPage(context, TransactionFormScreen(txn: txn)),
          onLongPress: onDelete,
          child: RowTile(
            leading: IconBadge(icon: categoryIcon(cat, fallbackType: txn.type), size: 42, color: color),
            title: title,
            subtitle: parts,
            trailing: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 130),
              child: FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerRight, child: Text(context.money(txn.signed, sign: true), style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 15))),
            ),
          ),
        ),
      ),
    );
  }
}
