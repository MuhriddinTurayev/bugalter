import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../data/tax.dart';
import '../../widgets/glass.dart';
import '../cabinet/cabinet_screen.dart';
import '../cabinet/letters_screen.dart';
import '../cabinet/payments_screen.dart';
import '../cabinet/runs_screen.dart';
import '../didox/didox_screen.dart';
import '../legal/legal_screen.dart';
import 'tax_common.dart';
import 'tax_report_screen.dart';

/// The tax calendar, the rates in force and the reports already filed. A tab page: the shell supplies the header.
class TaxTab extends StatelessWidget {
  const TaxTab({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    if (!s.role.canSeeTax) {
      return EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('tax.no_access_text'));
    }
    final now = DateTime.now();
    List<Obligation> calendar = const [];
    var missing = false;
    try {
      calendar = s.calc.calendar(s.company, now);
    } on MissingTaxRule {
      missing = true;
    }

    final rateCodes = ['vat.standard', 'turnover.general', 'profit.general', 'pit.standard', 'inps.employee', s.company.budgetOrg ? 'social.budget' : 'social.general'];
    final rules = <TaxRule>[];
    for (final c in rateCodes) {
      try {
        rules.add(s.book.rule(c, now));
      } on MissingTaxRule {
        // a missing rate is simply not listed
      }
    }
    final filed = s.reports.where((r) => r.status == ReportStatus.submitted).toList()..sort((a, b) => b.updatedAt.compareTo(a.updatedAt));

    return ListView(
      padding: EdgeInsets.only(top: context.headerSpace + 4, bottom: context.navSpace),
      children: [
        const Reveal(child: RulesBanner()),
        const SizedBox(height: 14),
        // the tax cabinet side of the platform: what the cabinet shows, what to pay, letters, Didox, drafts, automatic preparation
        Reveal(
          index: 1,
          child: AdaptiveGrid(minColumnWidth: 150, columns: context.width >= 760 ? 3 : 2, spacing: 12, children: [
            for (final t in <(IconData, String, Color, Widget)>[
              (Icons.account_balance_rounded, 'nav.cabinet', g.info, const CabinetScreen()),
              (Icons.payments_rounded, 'nav.tax_payments', g.success, const PaymentsScreen()),
              (Icons.mail_rounded, 'nav.letters', g.warning, const LettersScreen()),
              (Icons.description_rounded, 'nav.didox', g.accent2, const DidoxScreen()),
              (Icons.gavel_rounded, 'nav.legal', g.accent, const LegalScreen()),
              (Icons.auto_mode_rounded, 'nav.runs', g.info, const RunsScreen()),
            ])
              SoftTile(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                onTap: () => pushPage(context, t.$4),
                child: Row(children: [
                  IconBadge(icon: t.$1, color: t.$3, size: 38),
                  const SizedBox(width: 10),
                  Expanded(child: Text(context.t(t.$2), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 13.5, fontWeight: FontWeight.w800, height: 1.15))),
                ]),
              ),
          ]),
        ),
        const SizedBox(height: 18),
        Reveal(index: 1, child: SectionTitle(context.t('tax.calendar'), subtitle: context.t('tax.calendar_hint'))),
        if (missing)
          GlassCard(blur: false, child: Text(context.t('tax.rules_missing'), style: TextStyle(color: g.danger)))
        else if (calendar.isEmpty)
          GlassCard(blur: false, child: Text(context.t('tax.calendar_empty'), style: TextStyle(color: g.textDim)))
        else
          AdaptiveGrid(minColumnWidth: 340, children: [
            for (var i = 0; i < calendar.length; i++) Reveal(index: i + 2, child: _ObligationCard(o: calendar[i])),
          ]),
        const SizedBox(height: 22),
        TileSection(
          title: context.t('tax.rates'),
          subtitle: context.t('tax.rates_hint'),
          children: [
            for (final r in rules)
              SoftTile(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                child: Row(children: [
                  Expanded(child: Text(r.name(s.locale), style: TextStyle(color: g.text, fontSize: 14, fontWeight: FontWeight.w600))),
                  const SizedBox(width: 12),
                  Text(formatBp(r.value), style: TextStyle(color: g.accent, fontSize: 16, fontWeight: FontWeight.w800)),
                ]),
              ),
          ],
        ),
        const SizedBox(height: 22),
        TileSection(
          title: context.t('tax.submitted'),
          empty: context.t('tax.submitted_empty'),
          children: [
            for (final r in filed.take(6))
              SoftTile(
                onTap: () {
                  try {
                    pushPage(context, TaxReportScreen(obligation: obligationOf(s, r)));
                  } on MissingTaxRule {
                    context.toast(context.t('tax.rules_missing'));
                  }
                },
                child: RowTile(
                  leading: IconBadge(icon: taxIcon(r.type), color: g.success, size: 40),
                  title: context.t('taxtype.${r.type.name}'),
                  subtitle: '${periodLabel(context, r.start, r.end)} · ${r.receipt}',
                  trailing: Text(context.money(r.taxMinor), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14)),
                ),
              ),
          ],
        ),
      ],
    );
  }
}

class _ObligationCard extends StatelessWidget {
  const _ObligationCard({required this.o});
  final Obligation o;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final r = s.reportFor(o);
    final left = o.daysLeft(DateTime.now());
    final done = r?.status == ReportStatus.submitted;
    return GlassCard(
      blur: false,
      onTap: () => pushPage(context, TaxReportScreen(obligation: o)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          IconBadge(icon: taxIcon(o.type), color: done ? g.success : g.accent2),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(context.t('taxtype.${o.type.name}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15.5)),
              const SizedBox(height: 2),
              Text(periodLabel(context, o.start, o.end), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
            ]),
          ),
          Icon(Icons.chevron_right_rounded, color: g.textDim),
        ]),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, crossAxisAlignment: WrapCrossAlignment.center, children: [
          ReportStatusPill(r?.status),
          if (!done) DaysChip(left),
          Text(context.t('tax.due', {'date': context.date(o.due)}), style: TextStyle(color: g.textDim, fontSize: 12.5)),
        ]),
        if (r != null) ...[
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: Text(context.t('tax.tax'), style: TextStyle(color: g.textDim, fontSize: 13))),
            Text(context.money(r.taxMinor), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16)),
          ]),
        ],
      ]),
    );
  }
}
