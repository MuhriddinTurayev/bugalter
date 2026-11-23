import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import 'payroll_detail_screen.dart';

/// The last twelve months of payroll with their status; tap a month to open or calculate it.
class PayrollScreen extends StatelessWidget {
  const PayrollScreen({super.key});

  Future<void> _open(BuildContext context, DateTime month) async {
    final s = context.readStore;
    if (s.payrollFor(month) == null) {
      if (!context.attempt(() => s.preparePayroll(month))) return;
      context.toast(context.t('payroll.prepared_msg'));
    }
    if (context.mounted) pushPage(context, PayrollDetailScreen(month: month));
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final now = DateTime.now();
    final months = [for (var i = 0; i < 12; i++) DateTime(now.year, now.month - i, 1)];
    final count = s.employees.where((e) => e.status == EmployeeStatus.active).length;

    return PageScaffold(
      title: context.t('nav.payroll'),
      subtitle: context.t('payroll.subtitle'),
      body: ListView(
        padding: EdgeInsets.fromLTRB(0, 8, 0, 32 + MediaQuery.viewPaddingOf(context).bottom),
        children: [
          Reveal(
            child: GlassCard(
              gradient: LinearGradient(colors: [g.accent2.withValues(alpha: .22), g.accent.withValues(alpha: .14)]),
              child: Row(children: [
                IconBadge(icon: Icons.groups_rounded, color: g.accent2, size: 46),
                const SizedBox(width: 12),
                Expanded(child: Text(context.t('payroll.hint'), style: TextStyle(color: g.textDim, fontSize: 13.5, height: 1.35))),
              ]),
            ),
          ),
          const SizedBox(height: 14),
          for (var i = 0; i < months.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: Reveal(index: i, dy: 12, child: _MonthTile(month: months[i], employeesCount: count, onTap: () => _open(context, months[i]))),
            ),
        ],
      ),
    );
  }
}

class _MonthTile extends StatelessWidget {
  const _MonthTile({required this.month, required this.employeesCount, required this.onTap});
  final DateTime month;
  final int employeesCount;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final run = context.store.payrollFor(month);
    final (label, color) = switch (run?.status) {
      null => (context.t('payroll.empty_month'), g.textDim),
      PayrollStatus.draft => (context.t('status.draft'), g.warning),
      PayrollStatus.approved => (context.t('payroll.st_approved'), g.info),
      PayrollStatus.paid => (context.t('status.paid'), g.success),
    };
    return GlassCard(
      blur: false,
      onTap: onTap,
      padding: const EdgeInsets.all(14),
      child: Row(children: [
        IconBadge(icon: run == null ? Icons.calculate_outlined : Icons.event_available_rounded, color: color, size: 46),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(context.monthYear(month), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16)),
            const SizedBox(height: 5),
            Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
              StatusPill(label: label, color: color),
              Text(context.t('payroll.employees_n', {'n': run?.lines.length ?? employeesCount}), style: TextStyle(color: g.textDim, fontSize: 12.5)),
            ]),
          ]),
        ),
        const SizedBox(width: 8),
        if (run != null)
          ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 120),
            child: Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(context.t('payroll.net'), style: TextStyle(color: g.textDim, fontSize: 11.5)),
              FittedBox(fit: BoxFit.scaleDown, child: Text(context.money(run.net), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
            ]),
          )
        else
          AppButton(label: context.t('payroll.calculate'), expanded: false, compact: true, onPressed: onTap),
      ]),
    );
  }
}
