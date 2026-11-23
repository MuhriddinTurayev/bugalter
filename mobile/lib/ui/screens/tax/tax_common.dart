import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../data/store.dart';
import '../../../data/tax.dart';
import '../../widgets/glass.dart';

IconData taxIcon(TaxType t) => switch (t) {
      TaxType.vat => Icons.percent_rounded,
      TaxType.turnover => Icons.sync_alt_rounded,
      TaxType.profit => Icons.trending_up_rounded,
      TaxType.payroll => Icons.groups_rounded,
    };

/// "November 2026" for a monthly period, "2026, quarter 4" for a quarterly one.
String periodLabel(BuildContext context, DateTime start, DateTime end) {
  if (end.month - start.month == 2 && end.year == start.year) {
    return context.t('tax.period_quarter', {'year': start.year, 'q': (start.month - 1) ~/ 3 + 1});
  }
  return context.monthYear(start);
}

Color reportColor(Glass g, ReportStatus? s) => switch (s) {
      null => g.textDim,
      ReportStatus.prepared => g.info,
      ReportStatus.awaiting => g.warning,
      ReportStatus.approved => g.accent2,
      ReportStatus.submitted => g.success,
    };

String reportLabel(BuildContext context, ReportStatus? s) => context.t('tax.st.${s?.name ?? 'none'}');

/// The obligation a stored report belongs to (its due date comes from the rules table).
Obligation obligationOf(AppStore s, TaxReport r) {
  final dl = s.book.deadline(r.type, r.end);
  final dm = DateTime(r.end.year, r.end.month + dl.monthsAfter, 1);
  return Obligation(r.type, r.start, r.end, DateTime(dm.year, dm.month, dl.day));
}

class ReportStatusPill extends StatelessWidget {
  const ReportStatusPill(this.status, {super.key});
  final ReportStatus? status;

  @override
  Widget build(BuildContext context) => StatusPill(label: reportLabel(context, status), color: reportColor(context.g, status));
}

class DaysChip extends StatelessWidget {
  const DaysChip(this.left, {super.key});
  final int left;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    if (left < 0) return StatusPill(label: context.t('dash.days_late', {'n': -left}), color: g.danger, icon: Icons.warning_amber_rounded);
    return StatusPill(label: context.t('dash.days_left', {'n': left}), color: left <= 7 ? g.warning : g.info, icon: Icons.schedule_rounded);
  }
}

/// A warning strip: rules are not verified, amounts come from deterministic rules only.
class RulesBanner extends StatelessWidget {
  const RulesBanner({super.key});

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return GlassCard(
      tint: g.warning.withValues(alpha: .12),
      padding: const EdgeInsets.all(14),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(Icons.rule_rounded, color: g.warning),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(context.t('tax.unverified_title'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
            const SizedBox(height: 4),
            Text(context.t('tax.unverified_text'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35)),
            const SizedBox(height: 6),
            Text(context.t('tax.deterministic'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35)),
          ]),
        ),
      ]),
    );
  }
}
