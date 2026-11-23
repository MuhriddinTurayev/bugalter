import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

/// One month's payroll: totals, the line of every employee, the rules used and the approval actions.
class PayrollDetailScreen extends StatelessWidget {
  const PayrollDetailScreen({super.key, required this.month});
  final DateTime month;

  Future<void> _editLine(BuildContext context, PayrollRun run, PayrollLine line) async {
    final gross = await showGlassSheet<int>(context, builder: (_) => _EditLineSheet(line: line));
    if (gross != null && context.mounted && context.attempt(() => context.readStore.updatePayrollLine(run, line, gross))) {
      context.toast(context.t('payroll.line_updated'));
    }
  }

  Future<void> _approve(BuildContext context, PayrollRun run) async {
    final ok = await confirmDialog(context, title: context.t('payroll.approve'), message: context.t('payroll.confirm_approve'), confirmLabel: context.t('payroll.approve'));
    if (ok && context.mounted && context.attempt(() => context.readStore.approvePayroll(run))) context.toast(context.t('payroll.approved_msg'));
  }

  Future<void> _reopen(BuildContext context, PayrollRun run) async {
    final ok = await confirmDialog(context, title: context.t('payroll.reopen'), message: context.t('payroll.confirm_reopen'), confirmLabel: context.t('payroll.reopen'));
    if (ok && context.mounted && context.attempt(() => context.readStore.reopenPayroll(run))) context.toast(context.t('payroll.reopened_msg'));
  }

  Future<void> _pay(BuildContext context, PayrollRun run) async {
    final store = context.readStore;
    if (store.activeAccounts.isEmpty) {
      context.toast(context.t('err.no_account'));
      return;
    }
    final accountId = await showGlassSheet<int>(context, builder: (ctx) {
      final g = ctx.g;
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(ctx.t('payroll.pay_from'), style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 12),
        for (final a in store.activeAccounts)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: GlassCard(
              blur: false,
              onTap: () => Navigator.pop(ctx, a.id),
              padding: const EdgeInsets.all(12),
              child: Row(children: [
                IconBadge(icon: a.type == AccountType.cash ? Icons.payments_rounded : Icons.account_balance_rounded, size: 40, color: g.info),
                const SizedBox(width: 12),
                Expanded(child: Text(a.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700))),
                const SizedBox(width: 8),
                Flexible(child: FittedBox(fit: BoxFit.scaleDown, child: Text(ctx.money(store.balanceOf(a)), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w600)))),
              ]),
            ),
          ),
      ]);
    });
    if (accountId == null || !context.mounted) return;
    // only what is still to pay: the advances were already given and booked
    final ok = await confirmDialog(context, title: context.t('payroll.mark_paid'), message: context.t('payroll.confirm_pay', {'amount': context.money(run.payout)}), confirmLabel: context.t('payroll.mark_paid'));
    if (ok && context.mounted && context.attempt(() => store.payPayroll(run, accountId))) context.toast(context.t('payroll.paid_msg'));
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final run = s.payrollFor(month);
    final title = context.t('payroll.detail', {'month': context.monthYear(month)});
    if (run == null) {
      return PageScaffold(title: title, body: EmptyState(icon: Icons.calculate_outlined, title: context.t('payroll.empty_month')));
    }
    final draft = run.status == PayrollStatus.draft;
    final canApprove = s.role.canApprove;
    final (statusLabel, statusColor) = switch (run.status) {
      PayrollStatus.draft => (context.t('status.draft'), g.warning),
      PayrollStatus.approved => (context.t('payroll.st_approved'), g.info),
      PayrollStatus.paid => (context.t('status.paid'), g.success),
    };

    Widget? bottom;
    if (run.status != PayrollStatus.paid) {
      final buttons = <Widget>[];
      if (canApprove) {
        if (draft) {
          buttons
            ..add(Expanded(child: AppButton(label: context.t('payroll.recalculate'), kind: ButtonKind.glass, onPressed: () {
              if (context.attempt(() => s.preparePayroll(month))) context.toast(context.t('payroll.prepared_msg'));
            })))
            ..add(const SizedBox(width: 12))
            ..add(Expanded(child: AppButton(label: context.t('payroll.approve'), icon: Icons.verified_rounded, onPressed: () => _approve(context, run))));
        } else {
          buttons
            ..add(Expanded(child: AppButton(label: context.t('payroll.reopen'), kind: ButtonKind.glass, onPressed: () => _reopen(context, run))))
            ..add(const SizedBox(width: 12))
            ..add(Expanded(child: AppButton(label: context.t('payroll.mark_paid'), icon: Icons.payments_rounded, onPressed: () => _pay(context, run))));
        }
      }
      bottom = SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: buttons.isEmpty ? Text(context.t('payroll.no_permission_hint'), textAlign: TextAlign.center, style: TextStyle(color: g.textDim, fontSize: 13)) : Row(children: buttons),
        ),
      );
    }

    // Rules in force: names and rates come from the rules table, never from this screen.
    final rules = [
      s.book.rule('pit.standard', month),
      s.book.rule('inps.employee', month),
      s.book.rule(s.company.budgetOrg ? 'social.budget' : 'social.general', month),
    ];

    var idx = 0;
    return PageScaffold(
      title: title,
      subtitle: statusLabel,
      bottom: bottom,
      body: ListView(
        padding: const EdgeInsets.fromLTRB(0, 8, 0, 24),
        children: [
          Reveal(
            index: idx++,
            child: GlassCard(
              strong: true,
              padding: const EdgeInsets.all(18),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(context.t('payroll.net'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w700))),
                  StatusPill(label: statusLabel, color: statusColor),
                ]),
                const SizedBox(height: 4),
                AmountText(run.net, size: 32),
                const SizedBox(height: 10),
                const Hairline(),
                const SizedBox(height: 6),
                InfoLine(context.t('payroll.gross'), context.money(run.gross)),
                if (run.bonuses > 0) InfoLine(context.t('kpi.p_bonuses_total'), context.money(run.bonuses)),
                InfoLine(context.t('payroll.pit_to_budget'), context.money(run.pit)),
                InfoLine(context.t('payroll.inps_to_fund'), context.money(run.inps)),
                InfoLine(context.t('payroll.social'), context.money(run.social)),
                InfoLine(context.t('payroll.employer_cost'), context.money(run.gross + run.social), bold: true),
                if (run.advances > 0) ...[
                  InfoLine(context.t('adv.p_advances_total'), context.money(run.advances), color: g.warning),
                  InfoLine(context.t('adv.p_to_pay_total'), context.money(run.payout), bold: true),
                ],
                if (run.approvedBy.isNotEmpty) InfoLine(context.t('common.status'), context.t('payroll.approved_by', {'name': run.approvedBy})),
                if (run.paidAt != null) InfoLine(context.t('common.date'), context.t('payroll.paid_note', {'date': context.date(run.paidAt)})),
              ]),
            ),
          ),
          const SizedBox(height: 18),
          SectionTitle(context.t('payroll.lines'), subtitle: draft && canApprove ? context.t('payroll.draft_hint') : null),
          if (run.lines.isEmpty)
            EmptyState(icon: Icons.group_off_outlined, title: context.t('payroll.no_lines'))
          else
            for (final line in run.lines)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Reveal(index: idx++, dy: 12, child: _LineTile(line: line, onTap: draft ? () => _editLine(context, run, line) : null)),
              ),
          const SizedBox(height: 10),
          Reveal(
            index: idx++,
            child: GlassCard(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(context.t('payroll.rules_used'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16))),
                  StatusPill(label: context.t('payroll.unverified'), color: g.warning, icon: Icons.warning_amber_rounded),
                ]),
                const SizedBox(height: 8),
                for (final r in rules)
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 5),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Expanded(child: Text(r.name(context.locale), style: TextStyle(color: g.textDim, fontSize: 13.5))),
                      const SizedBox(width: 10),
                      Text(formatBp(r.value), style: TextStyle(color: g.text, fontWeight: FontWeight.w800)),
                    ]),
                  ),
                const SizedBox(height: 6),
                Text(context.t('payroll.unverified_note'), style: TextStyle(color: g.textDim, fontSize: 12.5)),
              ]),
            ),
          ),
        ],
      ),
    );
  }
}

class _LineTile extends StatelessWidget {
  const _LineTile({required this.line, this.onTap});
  final PayrollLine line;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    Widget cell(String label, int v) => Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 11.5)),
          FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(context.compact(v), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 13.5))),
        ]);
    return GlassCard(
      blur: false,
      onTap: onTap,
      padding: const EdgeInsets.all(14),
      child: Column(children: [
        Row(children: [
          Avatar(name: line.name, size: 42),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(line.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
              if (line.position.isNotEmpty) Text(line.position, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
            ]),
          ),
          const SizedBox(width: 8),
          Flexible(
            child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(context.t('payroll.net'), style: TextStyle(color: g.textDim, fontSize: 11.5)),
              FittedBox(fit: BoxFit.scaleDown, child: Text(context.money(line.net), style: TextStyle(color: g.accent, fontWeight: FontWeight.w800, fontSize: 15))),
            ]),
          ),
          if (onTap != null) Padding(padding: const EdgeInsets.only(left: 6), child: Icon(Icons.edit_outlined, size: 18, color: g.textDim)),
        ]),
        const SizedBox(height: 10),
        const Hairline(),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: cell(context.t('payroll.gross'), line.gross)),
          Expanded(child: cell(context.t('payroll.pit'), line.pit)),
          Expanded(child: cell(context.t('payroll.inps'), line.inps)),
          Expanded(child: cell(context.t('payroll.social'), line.social)),
        ]),
        if (line.bonus > 0 || line.advance > 0) ...[
          const SizedBox(height: 10),
          Row(children: [
            if (line.bonus > 0) Expanded(child: cell(context.t('kpi.p_bonus'), line.bonus)),
            if (line.advance > 0) Expanded(child: cell(context.t('adv.p_advance'), line.advance)),
            if (line.advance > 0) Expanded(child: cell(context.t('adv.p_to_pay'), line.payout)),
            if (line.bonus > 0 && line.advance == 0) const Spacer(flex: 2),
            if (line.bonus == 0) const Spacer(),
          ]),
        ],
      ]),
    );
  }
}

class _EditLineSheet extends StatefulWidget {
  const _EditLineSheet({required this.line});
  final PayrollLine line;

  @override
  State<_EditLineSheet> createState() => _EditLineSheetState();
}

class _EditLineSheetState extends State<_EditLineSheet> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _gross = TextEditingController(text: formatSom(widget.line.gross));

  @override
  void dispose() {
    _gross.dispose();
    super.dispose();
  }

  void _submit() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final v = parseMinor(_gross.text);
    if (v != null) Navigator.pop(context, v);
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Form(
      key: _form,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('payroll.edit_line'), style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text(widget.line.name, style: TextStyle(color: g.textDim)),
        const SizedBox(height: 14),
        MoneyField(controller: _gross, label: context.t('payroll.gross')),
        const SizedBox(height: 16),
        AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _submit),
      ]),
    );
  }
}
