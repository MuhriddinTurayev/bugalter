import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/cabinet.dart';
import '../../../data/models.dart';
import '../../../l10n/strings.dart' show kAllStrings;
import '../../widgets/glass.dart';
import '../tax/tax_common.dart';
import 'cabinet_common.dart';
import 'letter_screen.dart';
import 'letters_screen.dart';
import 'payments_screen.dart';
import 'runs_screen.dart';

/// The tax cabinet overview: what the personal account shows (debt, overpayment, per-tax balances), the reports and payment orders in the
/// cabinet, the letters, and the deterministic comparison of the cabinet with the books ("check now"). Demo: sandbox data on this device.
/// Server mode: what the platform's robot read from the real cabinet, read-only (no "check now", no report preparation).
class CabinetScreen extends StatefulWidget {
  const CabinetScreen({super.key});

  @override
  State<CabinetScreen> createState() => _CabinetScreenState();
}

class _CabinetScreenState extends State<CabinetScreen> {
  bool _checking = false;

  Future<void> _check() async {
    if (_checking) return;
    setState(() => _checking = true);
    await Future<void>.delayed(const Duration(milliseconds: 700)); // a short, one-shot pause: the comparison itself is instant
    if (!mounted) return;
    final s = context.readStore;
    final ok = context.attempt(() {
      CabinetData.of(s).checkNow();
    });
    setState(() => _checking = false);
    if (ok) context.toast(context.t('cab.check_done', {'n': CabinetData.of(s).lastCheck?.findings.length ?? 0}));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    if (!s.role.canSeeTax) {
      return PageScaffold(title: context.t('nav.cabinet'), body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('tax.no_access_text')));
    }
    final d = CabinetData.of(s);
    final gate = cabGate(context, title: context.t('nav.cabinet'), synced: d.synced, allowed: d.canSeeCabinet);
    if (gate != null) return gate;
    final live = d.live;
    final read = live ? d.readAt : null;
    final last = d.lastCheck;
    final findings = last?.findings ?? const <CabFinding>[];
    final balances = d.balances;
    final reports = d.reports.toList()..sort((a, b) => b.end.compareTo(a.end));
    final noRead = live && read == null && last == null;
    final orders = d.orders;
    final letters = d.letters;
    final now = DateTime.now();
    var i = 0;

    return PageScaffold(
      title: context.t('nav.cabinet'),
      subtitle: context.t('cab.subtitle'),
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 8),
        children: [
          Reveal(index: i++, child: const DemoNote()),
          const SizedBox(height: 12),
          Reveal(
            index: i++,
            child: GlassCard(
              strong: true,
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(children: [
                  IconBadge(icon: Icons.account_balance_rounded, color: g.info, size: 44),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Text(
                      live
                          ? (read == null ? context.t('cab.never_read') : context.t('cab.read_at', {'time': context.dateTime(read)}))
                          : (last == null ? context.t('cab.never_checked') : context.t('cab.checked_at', {'time': context.dateTime(last.at), 'who': last.by == 'system' ? context.t('cab.by_system') : last.by})),
                      style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14.5, height: 1.25),
                    ),
                  ),
                ]),
                const SizedBox(height: 10),
                Text(context.t(live ? 'cab.live_note' : 'cab.sandbox_note'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
                if (live && last != null && last.error.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  Text(context.t('cab.read_error', {'error': last.error}), maxLines: 4, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.warning, fontSize: 12.5, height: 1.3)),
                ],
                if (!live) ...[
                  const SizedBox(height: 14),
                  AppButton(label: context.t(_checking ? 'cab.checking' : 'cab.check_now'), icon: Icons.manage_search_rounded, loading: _checking, onPressed: _check),
                ],
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
              CabKpi(label: context.t('cab.kpi.demands'), icon: Icons.gavel_rounded, color: d.unansweredDemands > 0 ? g.danger : g.success, child: CabCount(d.unansweredDemands, color: d.unansweredDemands > 0 ? g.danger : null)),
              CabKpi(label: context.t('cab.kpi.unread'), icon: Icons.mail_outline_rounded, color: g.warning, child: CabCount(d.unreadLetters)),
              CabKpi(label: context.t('cab.kpi.submitted'), icon: Icons.task_alt_rounded, color: g.success, child: CabCount(last?.submitted ?? 0)),
            ]),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('cab.findings_title'),
              empty: context.t(noRead ? 'cab.no_read_yet' : (live ? 'cab.f.none_live' : 'cab.findings_empty')),
              children: [for (final f in findings) _FindingTile(f: f)],
            ),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('cab.balances_title'),
              empty: context.t(noRead ? 'cab.no_read_yet' : 'cab.balances_empty'),
              children: [for (final b in balances) _BalanceTile(b: b)],
            ),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('cab.reports_title'),
              empty: context.t(noRead ? 'cab.no_read_yet' : 'cab.reports_empty'),
              children: [
                for (final r in reports.take(live ? 12 : 6))
                  SoftTile(
                    child: RowTile(
                      leading: IconBadge(icon: TaxType.values.where((e) => e.name == r.type).map(taxIcon).firstOrNull ?? Icons.description_outlined, color: r.status == 'rejected' ? g.danger : g.success, size: 40),
                      title: r.type.isEmpty && r.name.isEmpty ? context.t('cab.unknown_type') : taxTypeName(context, r.type, r.name),
                      subtitle: '${periodLabel(context, r.start, r.end)}${r.receipt.isEmpty ? '' : ' · ${r.receipt}'}',
                      trailing: StatusPill(label: kAllStrings.containsKey('cab.report_st.${r.status}') ? context.t('cab.report_st.${r.status}') : r.status, color: r.status == 'rejected' ? g.danger : (r.status == 'draft' ? g.textDim : g.success)),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('cab.orders_title'),
              empty: context.t(noRead ? 'cab.no_read_yet' : 'cab.orders_empty'),
              trailing: GestureDetector(onTap: () => pushPage(context, const PaymentsScreen()), child: Text(context.t('common.view_all'), style: TextStyle(color: g.accent, fontWeight: FontWeight.w700, fontSize: 13))),
              children: [
                for (final o in orders.take(4))
                  SoftTile(
                    onTap: () => pushPage(context, const PaymentsScreen()),
                    child: RowTile(
                      leading: IconBadge(icon: Icons.receipt_long_rounded, color: o.paid ? g.success : g.warning, size: 40),
                      title: context.t('pay.order', {'number': o.number}),
                      subtitle: '${context.date(o.date)} · ${cabTaxName(context, o.tax)}',
                      trailing: Text(context.money(o.amount), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 13.5)),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('cab.letters_title'),
              empty: context.t('let.empty'),
              trailing: GestureDetector(onTap: () => pushPage(context, const LettersScreen()), child: Text(context.t('common.view_all'), style: TextStyle(color: g.accent, fontWeight: FontWeight.w700, fontSize: 13))),
              children: [
                for (final l in letters.take(3))
                  SoftTile(
                    onTap: () => pushPage(context, LetterScreen(letterId: l.id)),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          LetterKindPill(l),
                          const SizedBox(width: 8),
                          letterDeadlinePill(context, l),
                          const Spacer(),
                          if (l.unread) Container(width: 9, height: 9, decoration: BoxDecoration(color: g.accent, shape: BoxShape.circle)),
                        ]),
                        const SizedBox(height: 8),
                        Text(l.titleIn(s.locale), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14.5, height: 1.25)),
                        const SizedBox(height: 2),
                        Text('${context.t('let.number', {'number': l.number})} · ${context.date(l.date)}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                      ]),
                    ),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: i++,
            child: Wrap(spacing: 10, runSpacing: 10, children: [
              AppButton(label: context.t('cab.go_payments'), icon: Icons.payments_rounded, kind: ButtonKind.glass, expanded: false, compact: true, onPressed: () => pushPage(context, const PaymentsScreen())),
              AppButton(label: context.t('cab.go_letters'), icon: Icons.mail_rounded, kind: ButtonKind.glass, expanded: false, compact: true, onPressed: () => pushPage(context, const LettersScreen())),
              if (!live) AppButton(label: context.t('cab.go_runs'), icon: Icons.auto_mode_rounded, kind: ButtonKind.glass, expanded: false, compact: true, onPressed: () => pushPage(context, const RunsScreen())),
            ]),
          ),
          const SizedBox(height: 14),
          Text(context.t('cab.disclaimer'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.35)),
        ],
      ),
    );
  }
}

class _FindingTile extends StatelessWidget {
  const _FindingTile({required this.f});
  final CabFinding f;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final color = severityColor(g, f.severity);
    // preparing a report is a change of the books: not offered while the app only reads the real company
    final obligation = (!cabReadOnly(context) && (f.key == 'overdue' || f.key == 'missing' || f.key == 'rejected')) ? obligationFrom(f.type, f.start, f.end, f.due) : null;
    final goPay = f.key == 'debt' || f.key == 'penalty';
    final goLetters = f.key == 'unread_messages';
    return SoftTile(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          IconBadge(icon: severityIcon(f.severity), color: color, size: 38),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              StatusPill(label: context.t('cab.sev.${f.severity.name}'), color: color),
              const SizedBox(height: 6),
              Text(findingText(context, f), style: TextStyle(color: g.text, fontSize: 14, height: 1.3)),
            ]),
          ),
        ]),
        if (obligation != null || goPay || goLetters) ...[
          const SizedBox(height: 10),
          Align(
            alignment: Alignment.centerRight,
            child: AppButton(
              label: context.t(obligation != null ? 'cab.act.prepare' : (goPay ? 'cab.act.pay' : 'cab.act.letters')),
              icon: obligation != null ? Icons.edit_document : (goPay ? Icons.payments_rounded : Icons.mail_rounded),
              kind: ButtonKind.glass,
              compact: true,
              expanded: false,
              onPressed: () {
                if (obligation != null) {
                  openReport(context, obligation);
                } else if (goPay) {
                  pushPage(context, const PaymentsScreen());
                } else {
                  pushPage(context, const LettersScreen());
                }
              },
            ),
          ),
        ],
      ]),
    );
  }
}

class _BalanceTile extends StatelessWidget {
  const _BalanceTile({required this.b});
  final TaxBalance b;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final debt = b.debt > 0;
    return SoftTile(
      padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          IconBadge(icon: Icons.account_balance_wallet_outlined, color: debt ? g.danger : g.success, size: 40),
          const SizedBox(width: 12),
          Expanded(child: Text(cabTaxName(context, b.tax, b.name), maxLines: 3, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
          if (debt)
            StatusPill(label: context.t('cab.debt'), color: g.danger)
          else if (b.overpaid > 0)
            StatusPill(label: context.t('cab.overpaid'), color: g.info)
          else
            StatusPill(label: context.t('cab.sev.ok'), color: g.success),
        ]),
        const SizedBox(height: 10),
        Wrap(spacing: 18, runSpacing: 6, children: [
          if (!b.live) _Mini(label: context.t('cab.assessed'), value: context.money(b.assessed)),
          if (!b.live) _Mini(label: context.t('cab.paid'), value: context.money(b.paid)),
          if (b.live) _Mini(label: context.t('cab.paid_year_short'), value: context.money(b.paidYear ?? 0)),
          if (debt) _Mini(label: context.t(b.live ? 'cab.due_now' : 'cab.debt'), value: context.money(b.debt), color: g.danger),
          if (b.overpaid > 0) _Mini(label: context.t('cab.overpaid'), value: context.money(b.overpaid), color: g.info),
          if (b.penalty > 0) _Mini(label: context.t('cab.penalty'), value: context.money(b.penalty), color: g.warning),
        ]),
        if (b.asOf != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(context.t('cab.as_of', {'date': context.date(b.asOf)}), style: TextStyle(color: g.textDim, fontSize: 11.5))),
      ]),
    );
  }
}

class _Mini extends StatelessWidget {
  const _Mini({required this.label, required this.value, this.color});
  final String label, value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
      Text(label, style: TextStyle(color: g.textDim, fontSize: 11.5)),
      Text(value, style: TextStyle(color: color ?? g.text, fontWeight: FontWeight.w800, fontSize: 13.5)),
    ]);
  }
}
