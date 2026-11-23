import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/cabinet.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import 'cabinet_common.dart';

/// Tax payments: what the cabinet says is owed, what was paid, and the payments started here.
///
/// A payment is only PROPOSED (by the form below or by the AI assistant). A person presses "Pay"; the approval repeats exactly the tax,
/// the type and the amount and is refused if any of them changed. In this sandbox only a test receipt is recorded.
class PaymentsScreen extends StatefulWidget {
  const PaymentsScreen({super.key});

  @override
  State<PaymentsScreen> createState() => _PaymentsScreenState();
}

class _PaymentsScreenState extends State<PaymentsScreen> {
  final _form = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _purpose = TextEditingController();
  String _tax = 'vat';
  String _kind = '08102';
  int _orders = 6;

  @override
  void dispose() {
    _amount.dispose();
    _purpose.dispose();
    super.dispose();
  }

  CabinetData get _d => CabinetData.of(context.readStore);

  /// The person's confirmation: the dialog repeats the tax, the type and the amount that will be bound to the approval.
  Future<void> _confirmAndPay(CabPayment p) async {
    final ok = await confirmDialog(
      context,
      title: context.t('pay.confirm_title'),
      message: context.t('pay.confirm', {'tax': cabTaxName(context, p.tax), 'kind': payKindName(context, p.kind), 'amount': context.money(p.amount)}),
      confirmLabel: context.t('pay.pay'),
    );
    if (!ok || !mounted) return;
    final done = context.attempt(() => _d.pay(p, tax: p.tax, kind: p.kind, amount: p.amount));
    if (done && mounted) {
      final number = _d.payments.where((e) => e.id == p.id).firstOrNull?.orderNumber ?? '';
      context.toast(context.t('pay.done', {'number': number}));
    }
  }

  Future<void> _check(CabPayment p) async {
    if (context.attempt(() => _d.checkPayment(p)) && mounted) context.toast(context.t('pay.checked'));
  }

  Future<void> _cancel(CabPayment p) async {
    final ok = await confirmDialog(context, title: context.t('pay.cancelled'), message: context.t('pay.confirm_cancel'), danger: true, confirmLabel: context.t('common.yes'));
    if (!ok || !mounted) return;
    if (context.attempt(() => _d.cancelPayment(p)) && mounted) context.toast(context.t('pay.cancelled'));
  }

  void _settle(CabPayment p) {
    if (context.attempt(() => _d.settleUnknown(p))) context.toast(context.t('pay.settled'));
  }

  /// The form: creates a proposal, then either confirms and pays it or only checks it.
  Future<void> _submit({required bool pay}) async {
    if (!(_form.currentState?.validate() ?? false)) return;
    final amount = parseMinor(_amount.text) ?? 0;
    CabPayment? made;
    final ok = context.attempt(() {
      made = _d.propose(tax: _tax, kind: _kind, amount: amount, purpose: _purpose.text.trim());
    });
    final p = made;
    if (!ok || p == null) return;
    _amount.clear();
    _purpose.clear();
    if (pay) {
      await _confirmAndPay(p);
    } else {
      await _check(p);
    }
  }

  /// "Close this debt": reuses an open proposal for the same debt, otherwise prepares one, then asks the person to confirm.
  Future<void> _closeDebt(TaxBalance b) async {
    final d = _d;
    CabPayment? p = d.payments.where((e) => e.open && e.tax == b.tax && e.kind == '08102' && e.amount == b.debt).firstOrNull;
    if (p == null) {
      final ok = context.attempt(() {
        p = d.propose(tax: b.tax, kind: '08102', amount: b.debt);
      });
      if (!ok) return;
    }
    final target = p;
    if (target != null && mounted) await _confirmAndPay(target);
  }

  Future<void> _payAll() async {
    final d = _d;
    final items = d.plan();
    final hash = d.planHash(items);
    final total = items.fold(0, (a, e) => a + e.amount);
    final ok = await confirmDialog(
      context,
      title: context.t('pay.all.title'),
      message: context.t('pay.all.confirm', {'count': items.length, 'total': context.money(total)}),
      confirmLabel: context.t('pay.all.pay'),
    );
    if (!ok || !mounted) return;
    var n = 0;
    if (context.attempt(() => n = d.payAll(hash)) && mounted) context.toast(context.t('pay.all.done', {'count': n}));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    if (!s.role.canSeeTax) {
      return PageScaffold(title: context.t('nav.tax_payments'), body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('tax.no_access_text')));
    }
    final d = CabinetData.of(s);
    final gate = cabGate(context, title: context.t('nav.tax_payments'), synced: d.synced, allowed: d.canSeeCabinet);
    if (gate != null) return gate;
    final live = d.live; // the real company: the payments are only shown, they are made on the web platform
    final canPay = !live && s.role.canApprove;
    final balances = d.balances;
    final debts = balances.where((b) => b.debt > 0).toList();
    final plan = live ? const <PlanItem>[] : d.plan();
    final mine = d.payments;
    final orders = d.orders;
    final now = DateTime.now();
    var i = 0;

    return PageScaffold(
      title: context.t('nav.tax_payments'),
      subtitle: context.t('pay.subtitle'),
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 8),
        children: [
          Reveal(index: i++, child: const DemoNote()),
          const SizedBox(height: 10),
          Reveal(
            index: i++,
            child: GlassCard(
              tint: g.accent2.withValues(alpha: .12),
              padding: const EdgeInsets.all(14),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(Icons.verified_user_outlined, color: g.accent2, size: 20),
                const SizedBox(width: 10),
                Expanded(child: Text(context.t(live ? 'pay.live_note' : (canPay ? 'pay.ai_note' : 'pay.needs_permission')), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3))),
              ]),
            ),
          ),
          const SizedBox(height: 14),
          Reveal(
            index: i++,
            child: AdaptiveGrid(minColumnWidth: 150, columns: context.width >= 760 ? 3 : 2, spacing: 12, children: [
              CabKpi(
                label: context.t('cab.kpi.debt'),
                icon: Icons.warning_amber_rounded,
                color: d.totalDebt > 0 ? g.danger : g.success,
                sub: d.totalPenalty > 0 ? context.t('cab.kpi.penalty', {'amount': context.money(d.totalPenalty)}) : null,
                child: AmountText(d.totalDebt, size: 19, color: d.totalDebt > 0 ? g.danger : g.text),
              ),
              CabKpi(label: context.t('cab.kpi.overpaid'), icon: Icons.savings_outlined, color: g.info, child: AmountText(d.totalOverpaid, size: 19)),
              CabKpi(label: context.t('cab.kpi.paid_year', {'year': now.year}), icon: Icons.payments_outlined, color: g.success, child: AmountText(d.paidThisYear(now), size: 19)),
            ]),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('pay.debts_title'),
              empty: context.t('pay.no_debt'),
              children: [
                for (final b in debts)
                  SoftTile(
                    padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        IconBadge(icon: Icons.account_balance_wallet_outlined, color: g.danger, size: 40),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(cabTaxName(context, b.tax, b.name), maxLines: 3, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                            Text(context.money(b.debt), style: TextStyle(color: g.danger, fontWeight: FontWeight.w800, fontSize: 14)),
                          ]),
                        ),
                      ]),
                      if (canPay) ...[
                        const SizedBox(height: 10),
                        Align(alignment: Alignment.centerRight, child: AppButton(label: context.t('pay.pay'), icon: Icons.payments_rounded, compact: true, expanded: false, onPressed: () => _closeDebt(b))),
                      ],
                    ]),
                  ),
              ],
            ),
          ),
          if (plan.isNotEmpty) ...[
            const SizedBox(height: 16),
            Reveal(
              index: i++,
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('pay.all.title'), style: TextStyle(color: g.text, fontSize: 16, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text(context.t('pay.all.hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
                  const SizedBox(height: 10),
                  Wrap(spacing: 8, runSpacing: 8, children: [
                    for (final it in plan) StatusPill(label: '${cabTaxName(context, it.tax)} · ${context.compact(it.amount)}', color: g.warning),
                  ]),
                  if (canPay) ...[
                    const SizedBox(height: 14),
                    AppButton(label: context.t('pay.all.pay'), icon: Icons.done_all_rounded, onPressed: _payAll),
                  ],
                ]),
              ),
            ),
          ],
          if (!live) ...[
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: GlassCard(
              padding: const EdgeInsets.all(16),
              child: Form(
                key: _form,
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(context.t('pay.new_title'), style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text(context.t('pay.new_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
                  const SizedBox(height: 14),
                  PickerField<String>(
                    label: context.t('pay.tax'),
                    icon: Icons.account_balance_outlined,
                    value: _tax,
                    options: [for (final t in kCabTaxes) (t, cabTaxName(context, t))],
                    onChanged: (v) => setState(() => _tax = v ?? _tax),
                  ),
                  const SizedBox(height: 12),
                  PickerField<String>(
                    label: context.t('pay.kind'),
                    icon: Icons.category_outlined,
                    value: _kind,
                    options: [for (final k in kPayKinds) (k, payKindName(context, k))],
                    onChanged: (v) => setState(() => _kind = v ?? _kind),
                  ),
                  const SizedBox(height: 12),
                  MoneyField(controller: _amount, label: context.t('pay.amount')),
                  const SizedBox(height: 12),
                  GlassField(controller: _purpose, label: context.t('pay.purpose'), icon: Icons.notes_rounded),
                  const SizedBox(height: 16),
                  if (canPay) ...[
                    AppButton(label: context.t('pay.pay'), icon: Icons.payments_rounded, onPressed: () => _submit(pay: true)),
                    const SizedBox(height: 10),
                    AppButton(label: context.t('pay.dry'), icon: Icons.fact_check_outlined, kind: ButtonKind.glass, onPressed: () => _submit(pay: false)),
                  ],
                ]),
              ),
            ),
          ),
          ],
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('pay.mine_title'),
              empty: context.t('pay.mine_empty'),
              children: [for (final p in mine) _PaymentTile(p: p, canPay: canPay, live: live, onPay: _confirmAndPay, onCheck: _check, onCancel: _cancel, onSettle: _settle)],
            ),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('pay.cabinet_title'),
              empty: context.t('pay.cabinet_empty'),
              children: [
                for (final o in orders.take(_orders))
                  SoftTile(
                    padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
                    child: Row(children: [
                      IconBadge(icon: Icons.receipt_long_rounded, color: o.paid ? g.success : g.warning, size: 40),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(context.t('pay.order', {'number': o.number}), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14.5)),
                          Text('${context.date(o.date)} · ${cabTaxName(context, o.tax)}', maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
                          if (o.kind.isNotEmpty || o.statusText.isNotEmpty) Text(o.kind.isNotEmpty ? payKindName(context, o.kind) : o.statusText, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 11.5)),
                        ]),
                      ),
                      const SizedBox(width: 8),
                      Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
                        Text(context.money(o.amount), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 13.5)),
                        const SizedBox(height: 4),
                        StatusPill(label: context.t(o.paid ? 'pay.paid' : 'pay.not_paid'), color: o.paid ? g.success : g.warning),
                      ]),
                    ]),
                  ),
              ],
            ),
          ),
          if (orders.length > _orders) ...[
            const SizedBox(height: 12),
            AppButton(label: context.t('pay.show_more'), kind: ButtonKind.glass, compact: true, onPressed: () => setState(() => _orders += 6)),
          ],
          const SizedBox(height: 14),
          Text(context.t('cab.disclaimer'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.35)),
        ],
      ),
    );
  }
}

class _PaymentTile extends StatelessWidget {
  const _PaymentTile({required this.p, required this.canPay, required this.live, required this.onPay, required this.onCheck, required this.onCancel, required this.onSettle});
  final CabPayment p;
  final bool canPay, live;
  final void Function(CabPayment) onPay, onCheck, onCancel, onSettle;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final color = payStatusColor(g, p.status);
    final story = p.history.map((e) => '${context.t('pay.st.${e.status}')} ${context.date(e.at)}').join(' → ');
    return SoftTile(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          IconBadge(icon: p.proposedBy == 'ai' ? Icons.auto_awesome_rounded : (live ? Icons.account_balance_wallet_outlined : Icons.person_outline_rounded), color: p.proposedBy == 'ai' ? g.accent2 : g.info, size: 40),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(cabTaxName(context, p.tax), maxLines: 3, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
              if (p.kind.isNotEmpty) Text(payKindName(context, p.kind), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
              const SizedBox(height: 4),
              Text(context.money(p.amount), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
            ]),
          ),
          const SizedBox(width: 8),
          Flexible(flex: 0, child: StatusPill(label: payStatusLabel(context, p, live), color: color)),
        ]),
        const SizedBox(height: 8),
        if (!live) Text(context.t('pay.by.${p.proposedBy}'), style: TextStyle(color: g.textDim, fontSize: 12)),
        if (p.dryRun) Text(context.t('pay.dry_note'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.3)),
        if (p.error.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(context.t('pay.error', {'error': p.error}), maxLines: 4, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.danger, fontSize: 12, height: 1.3))),
        if (p.approvedBy.isNotEmpty) Text('${context.t('pay.approved_by', {'name': p.approvedBy})}${p.orderNumber.isEmpty ? '' : ' · ${p.orderNumber}'}', style: TextStyle(color: g.textDim, fontSize: 12)),
        if (story.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text('${context.t('pay.timeline')}: $story', style: TextStyle(color: g.textDim, fontSize: 11.5, height: 1.3))),
        if (p.status == PayStatus.unknown) Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('pay.unknown_hint'), style: TextStyle(color: g.warning, fontSize: 12, height: 1.3))),
        if (canPay && (p.open || p.status == PayStatus.unknown)) ...[
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (p.open) AppButton(label: context.t('pay.pay'), icon: Icons.payments_rounded, compact: true, expanded: false, onPressed: () => onPay(p)),
            if (p.open) AppButton(label: context.t('pay.dry'), icon: Icons.fact_check_outlined, kind: ButtonKind.glass, compact: true, expanded: false, onPressed: () => onCheck(p)),
            if (p.open) AppButton(label: context.t('common.cancel'), icon: Icons.close_rounded, kind: ButtonKind.glass, compact: true, expanded: false, onPressed: () => onCancel(p)),
            if (p.status == PayStatus.unknown) AppButton(label: context.t('pay.settle'), icon: Icons.manage_search_rounded, compact: true, expanded: false, onPressed: () => onSettle(p)),
          ]),
        ],
      ]),
    );
  }
}
