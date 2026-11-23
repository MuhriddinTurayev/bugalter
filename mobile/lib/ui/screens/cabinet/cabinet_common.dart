import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/cabinet.dart';
import '../../../data/models.dart';
import '../../../data/tax.dart';
import '../../../l10n/strings.dart' show kAllStrings;
import '../../widgets/glass.dart';
import '../server_gate.dart';
import '../tax/tax_common.dart';
import '../tax/tax_report_screen.dart';

/// The name of a tax: the app's own word for it; a tax of the real cabinet that the app has no word for keeps the cabinet's own name.
String cabTaxName(BuildContext context, String tax, [String? cabinetName]) {
  final own = cabinetName?.trim() ?? '';
  if (tax == 'other' && own.isNotEmpty) return own;
  if (kAllStrings.containsKey('cab.tax.$tax')) return context.t('cab.tax.$tax');
  return own.isNotEmpty ? own : tax;
}

/// The name of a report type; an unknown one (from the real cabinet) shows the cabinet's own name.
String taxTypeName(BuildContext context, String? type, [String? cabinetName]) {
  if (type != null && kAllStrings.containsKey('taxtype.$type')) return context.t('taxtype.$type');
  final own = cabinetName?.trim() ?? '';
  return own.isNotEmpty ? own : (type ?? '');
}

String payKindName(BuildContext context, String kind) => kAllStrings.containsKey('pay.kind.$kind') ? context.t('pay.kind.$kind') : kind;

/// A mutating button is not shown in server mode (the app reads the real company, it does not change it yet).
bool cabReadOnly(BuildContext context) => context.store.serverMode;

/// The page to show instead of a module when the server was not read yet or the person has no right to it; null when the data is there.
Widget? cabGate(BuildContext context, {required String title, required bool synced, required bool allowed}) => serverGate(context, title: title, synced: synced, allowed: allowed);

Color severityColor(Glass g, Severity s) => switch (s) {
      Severity.error => g.danger,
      Severity.warning => g.warning,
      Severity.info => g.info,
      Severity.ok => g.success,
    };

IconData severityIcon(Severity s) => switch (s) {
      Severity.error => Icons.error_outline_rounded,
      Severity.warning => Icons.warning_amber_rounded,
      Severity.info => Icons.info_outline_rounded,
      Severity.ok => Icons.check_circle_outline_rounded,
    };

Color payStatusColor(Glass g, PayStatus s) => switch (s) {
      PayStatus.proposed => g.info,
      PayStatus.checked => g.textDim,
      PayStatus.paid => g.success,
      PayStatus.unknown => g.warning,
      PayStatus.failed => g.danger,
      PayStatus.cancelled => g.textDim,
      PayStatus.queued => g.info,
      PayStatus.submitted => g.warning,
    };

/// The words of a payment's status. A real payment that is paid is just "paid" (the demo's says "sandbox").
String payStatusLabel(BuildContext context, CabPayment p, bool live) {
  if (live && p.status == PayStatus.paid) return context.t('pay.st.live.paid');
  return context.t('pay.st.${p.status.name}');
}

/// The sentence of a finding in the app language, built from its structured fields.
String findingText(BuildContext context, CabFinding f) {
  final type = f.type == null ? '' : taxTypeName(context, f.type);
  final period = (f.start != null && f.end != null) ? periodLabel(context, f.start!, f.end!) : '';
  return context.t('cab.f.${f.key}', {
    'report': type,
    'period': period,
    'date': context.date(f.due),
    'receipt': f.receipt == null || f.receipt!.isEmpty ? '—' : f.receipt,
    'tax': f.tax == null ? '' : cabTaxName(context, f.tax!),
    'amount': context.money(f.amount ?? 0),
    'count': f.count ?? 0,
    'portal': f.portalValue ?? '',
    'company': f.tax ?? '',
  });
}

/// The obligation of a finding or letter (its due date comes from the rules table).
Obligation? obligationFrom(String? type, DateTime? start, DateTime? end, DateTime? due) {
  if (type == null || start == null || end == null) return null;
  final t = TaxType.values.where((e) => e.name == type).firstOrNull;
  return t == null ? null : Obligation(t, start, end, due ?? end);
}

void openReport(BuildContext context, Obligation o) => pushPage(context, TaxReportScreen(obligation: o));

/// A small figure tile of the overview pages.
class CabKpi extends StatelessWidget {
  const CabKpi({super.key, required this.label, required this.child, this.sub, this.color, this.icon});
  final String label;
  final Widget child;
  final String? sub;
  final Color? color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return GlassCard(
      padding: const EdgeInsets.all(14),
      radius: 20,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          if (icon != null) ...[Icon(icon, size: 16, color: color ?? g.textDim), const SizedBox(width: 6)],
          Expanded(child: Text(label, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.15))),
        ]),
        const SizedBox(height: 8),
        child,
        if (sub != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(sub!, style: TextStyle(color: g.textDim, fontSize: 11.5))),
      ]),
    );
  }
}

/// A count shown like an amount.
class CabCount extends StatelessWidget {
  const CabCount(this.n, {super.key, this.color});
  final int n;
  final Color? color;

  @override
  Widget build(BuildContext context) =>
      CountUp(value: n, builder: (_, v) => Text('$v', style: TextStyle(color: color ?? context.g.text, fontSize: 24, fontWeight: FontWeight.w800)));
}

/// The kind pill of a letter: demand or notice.
class LetterKindPill extends StatelessWidget {
  const LetterKindPill(this.letter, {super.key});
  final CabLetter letter;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return StatusPill(
      label: context.t(letter.isDemand ? 'let.demand' : 'let.notice'),
      color: letter.isDemand ? g.danger : g.info,
      icon: letter.isDemand ? Icons.gavel_rounded : Icons.campaign_rounded,
    );
  }
}

/// "6 days left" / "2 days late" for a letter's deadline, or the answered pill.
Widget letterDeadlinePill(BuildContext context, CabLetter l) {
  final g = context.g;
  if (l.answered) return StatusPill(label: context.t('let.answered'), color: g.success, icon: Icons.check_rounded);
  if (l.deadline == null) return const SizedBox.shrink();
  final left = DateTime(l.deadline!.year, l.deadline!.month, l.deadline!.day).difference(DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day)).inDays;
  return DaysChip(left);
}
