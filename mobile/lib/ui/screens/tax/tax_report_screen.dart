import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../data/store.dart';
import '../../../data/tax.dart';
import '../../widgets/glass.dart';
import 'tax_common.dart';

/// One tax report: the numbers, the rules behind them and the approval workflow.
/// Nothing is ever submitted without a person's approval; there is no autopilot.
class TaxReportScreen extends StatelessWidget {
  const TaxReportScreen({super.key, required this.obligation});
  final Obligation obligation;

  Future<void> _prepare(BuildContext context, AppStore s, TaxReport? r) async {
    if (r != null && (r.status == ReportStatus.awaiting || r.status == ReportStatus.approved)) {
      final ok = await confirmDialog(context, title: context.t('tax.recalculate'), message: context.t('tax.recalc_confirm'));
      if (!ok || !context.mounted) return;
    }
    if (context.attempt(() => s.prepareReport(obligation))) context.toast(context.t('common.done'));
  }

  Future<void> _approve(BuildContext context, AppStore s, TaxReport r) async {
    final ok = await showGlassSheet<bool>(context, builder: (ctx) {
      var checked = false;
      return StatefulBuilder(builder: (ctx, setState) {
        final g = ctx.g;
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(ctx.t('tax.approve_title'), style: TextStyle(color: g.text, fontSize: 19, fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),
          InfoLine(ctx.t('taxtype.${r.type.name}'), periodLabel(ctx, r.start, r.end)),
          InfoLine(ctx.t('tax.base'), ctx.money(r.baseMinor)),
          InfoLine(ctx.t('tax.tax'), ctx.money(r.taxMinor), bold: true),
          if (!r.rulesVerified)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(Icons.rule_rounded, color: g.warning, size: 20),
                const SizedBox(width: 8),
                Expanded(child: Text(ctx.t('tax.approve_warn'), style: TextStyle(color: g.warning, fontWeight: FontWeight.w700, fontSize: 13.5))),
              ]),
            ),
          const SizedBox(height: 10),
          GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTap: () => setState(() => checked = !checked),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Checkbox(value: checked, onChanged: (v) => setState(() => checked = v ?? false)),
              Expanded(child: Padding(padding: const EdgeInsets.only(top: 12), child: Text(ctx.t('tax.declaration'), style: TextStyle(color: g.text, fontSize: 14, height: 1.35)))),
            ]),
          ),
          const SizedBox(height: 14),
          Row(children: [
            Expanded(child: AppButton(label: ctx.t('common.cancel'), kind: ButtonKind.glass, onPressed: () => Navigator.pop(ctx, false))),
            const SizedBox(width: 12),
            Expanded(child: AppButton(label: ctx.t('tax.approve'), icon: Icons.verified_rounded, onPressed: checked ? () => Navigator.pop(ctx, true) : null)),
          ]),
        ]);
      });
    });
    if (ok == true && context.mounted) context.attempt(() => s.approveReport(r));
  }

  Future<void> _submit(BuildContext context, AppStore s, TaxReport r) async {
    final ok = await confirmDialog(context, title: context.t('tax.submit_title'), message: context.t('tax.submit_text'), confirmLabel: context.t('tax.submit'));
    if (ok && context.mounted) context.attempt(() => s.submitReport(r));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final o = obligation;
    final r = s.reportFor(o);
    TaxComputation? comp;
    try {
      comp = s.compute(o.type, o.start, o.end);
    } on MissingTaxRule {
      comp = null;
    }
    final base = r?.baseMinor ?? comp?.baseMinor ?? 0;
    final tax = r?.taxMinor ?? comp?.taxMinor ?? 0;
    final canApprove = s.role.canApprove;
    final status = r?.status;
    final left = o.daysLeft(DateTime.now());

    final buttons = <Widget>[];
    if (comp == null) {
      // rules missing: nothing can be prepared
    } else if (r == null) {
      buttons.add(AppButton(label: context.t('tax.prepare'), icon: Icons.calculate_rounded, onPressed: () => _prepare(context, s, r)));
    } else {
      switch (r.status) {
        case ReportStatus.prepared:
          buttons.add(AppButton(label: context.t('tax.request'), icon: Icons.send_rounded, onPressed: () => context.attempt(() => s.requestApproval(r))));
          if (canApprove) buttons.add(AppButton(label: context.t('tax.approve'), icon: Icons.verified_rounded, kind: ButtonKind.glass, onPressed: () => _approve(context, s, r)));
          buttons.add(AppButton(label: context.t('tax.recalculate'), icon: Icons.refresh_rounded, kind: ButtonKind.glass, onPressed: () => _prepare(context, s, r)));
        case ReportStatus.awaiting:
          if (canApprove) buttons.add(AppButton(label: context.t('tax.approve'), icon: Icons.verified_rounded, onPressed: () => _approve(context, s, r)));
          buttons.add(AppButton(label: context.t('tax.withdraw'), icon: Icons.undo_rounded, kind: ButtonKind.glass, onPressed: () => context.attempt(() => s.withdrawApproval(r))));
        case ReportStatus.approved:
          if (canApprove) buttons.add(AppButton(label: context.t('tax.submit'), icon: Icons.cloud_upload_outlined, onPressed: () => _submit(context, s, r)));
          buttons.add(AppButton(label: context.t('tax.withdraw'), icon: Icons.undo_rounded, kind: ButtonKind.glass, onPressed: () => context.attempt(() => s.withdrawApproval(r))));
        case ReportStatus.submitted:
          break;
      }
    }

    final step = switch (status) { null => -1, ReportStatus.prepared => 0, ReportStatus.awaiting => 1, ReportStatus.approved => 2, ReportStatus.submitted => 3 };
    const stepKeys = ['prepared', 'awaiting', 'approved', 'submitted'];

    return PageScaffold(
      title: context.t('taxtype.${o.type.name}'),
      subtitle: periodLabel(context, o.start, o.end),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
        children: [
          Reveal(
            child: GlassCard(
              strong: true,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  IconBadge(icon: taxIcon(o.type), color: g.accent2),
                  const SizedBox(width: 12),
                  Expanded(child: Text(context.t('tax.due', {'date': context.date(o.due)}), style: TextStyle(color: g.textDim, fontSize: 13.5))),
                  if (status != ReportStatus.submitted) DaysChip(left),
                ]),
                const SizedBox(height: 16),
                Text(context.t('tax.tax'), style: TextStyle(color: g.textDim, fontSize: 13)),
                const SizedBox(height: 2),
                AmountText(tax, size: 32),
                const SizedBox(height: 10),
                const Hairline(),
                InfoLine(context.t('tax.base'), context.money(base)),
                InfoLine(context.t('common.status'), reportLabel(context, status), color: reportColor(g, status), bold: true),
                if (r == null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(context.t('tax.preview'), style: TextStyle(color: g.textDim, fontSize: 12.5))),
                if (r != null && r.approvedBy.isNotEmpty)
                  Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Text(context.t('tax.approved_by', {'name': r.approvedBy}), style: TextStyle(color: g.textDim, fontSize: 14))),
                if (r != null) InfoLine(context.t('tax.updated'), context.dateTime(r.updatedAt)),
                if (r != null && r.receipt.isNotEmpty) InfoLine(context.t('tax.receipt'), r.receipt, bold: true, color: g.success),
              ]),
            ),
          ),
          if (comp == null) ...[
            const SizedBox(height: 14),
            GlassCard(blur: false, tint: g.danger.withValues(alpha: .12), child: Text(context.t('tax.rules_missing'), style: TextStyle(color: g.danger, fontWeight: FontWeight.w700))),
          ],
          const SizedBox(height: 16),
          Reveal(index: 1, child: SectionTitle(context.t('tax.workflow'))),
          Reveal(
            index: 1,
            child: GlassCard(
              blur: false,
              child: Column(children: [
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  for (var i = 0; i < stepKeys.length; i++)
                    Expanded(
                      child: Column(children: [
                        AnimatedContainer(
                          duration: const Duration(milliseconds: 400),
                          curve: Curves.easeOutCubic,
                          width: 32,
                          height: 32,
                          decoration: BoxDecoration(
                            shape: BoxShape.circle,
                            color: i <= step ? g.accent : g.fill,
                            border: Border.all(color: i <= step ? g.accent : g.border),
                          ),
                          child: Icon(i <= step ? Icons.check_rounded : Icons.circle_outlined, size: 18, color: i <= step ? (g.dark ? const Color(0xFF04231A) : Colors.white) : g.textDim),
                        ),
                        const SizedBox(height: 6),
                        Text(context.t('tax.step.${stepKeys[i]}'), textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: i <= step ? g.text : g.textDim, fontSize: 11, fontWeight: i == step ? FontWeight.w800 : FontWeight.w500)),
                      ]),
                    ),
                ]),
                if (buttons.isNotEmpty) ...[
                  const SizedBox(height: 16),
                  for (var i = 0; i < buttons.length; i++) Padding(padding: EdgeInsets.only(top: i == 0 ? 0 : 10), child: buttons[i]),
                ],
                if (!canApprove && (status == ReportStatus.prepared || status == ReportStatus.awaiting || status == ReportStatus.approved))
                  Padding(padding: const EdgeInsets.only(top: 12), child: Text(context.t('tax.wait_approver'), style: TextStyle(color: g.textDim, fontSize: 13))),
              ]),
            ),
          ),
          if (comp != null) ...[
            const SizedBox(height: 16),
            TileSection(
              title: context.t('tax.rules_used'),
              children: [
                for (final r in comp.rulesUsed)
                  SoftTile(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Expanded(child: Text(r.name(s.locale), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14))),
                        const SizedBox(width: 8),
                        Text(r.kind == 'rate' ? formatBp(r.value) : context.money(r.value), style: TextStyle(color: g.accent, fontWeight: FontWeight.w800)),
                      ]),
                      const SizedBox(height: 4),
                      Text(r.source, style: TextStyle(color: g.textDim, fontSize: 12, height: 1.3)),
                      const SizedBox(height: 6),
                      StatusPill(label: context.t('tax.unverified_badge'), color: g.warning, icon: Icons.rule_rounded),
                    ]),
                  ),
              ],
            ),
          ],
          const SizedBox(height: 16),
          Reveal(
            index: 3,
            child: GlassCard(
              blur: false,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Icon(Icons.science_outlined, color: g.info, size: 20),
                  const SizedBox(width: 10),
                  Expanded(child: Text(context.t('tax.sandbox'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35))),
                ]),
                const SizedBox(height: 10),
                Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Icon(Icons.how_to_reg_outlined, color: g.accent, size: 20),
                  const SizedBox(width: 10),
                  Expanded(child: Text(context.t('tax.no_autopilot'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35))),
                ]),
              ]),
            ),
          ),
        ],
      ),
    );
  }
}
