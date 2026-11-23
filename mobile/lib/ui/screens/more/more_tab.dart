import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../l10n/strings.dart';
import '../../widgets/glass.dart';
import '../agent/agent_screen.dart';
import '../ai/ai_screen.dart';
import '../ai/proposals_screen.dart';
import '../bank/import_screen.dart';
import '../cabinet/cabinet_screen.dart';
import '../cabinet/letters_screen.dart';
import '../cabinet/payments_screen.dart';
import '../cabinet/runs_screen.dart';
import '../contractors/contractors_screen.dart';
import '../didox/didox_screen.dart';
import '../legal/legal_screen.dart';
import '../payroll/advances_screen.dart';
import '../payroll/employees_screen.dart';
import '../payroll/history_screen.dart';
import '../payroll/kpi_screen.dart';
import '../payroll/payroll_screen.dart';
import '../reports/reports_screen.dart';
import '../settings/company_screen.dart';
import '../settings/keys_screen.dart';
import '../settings/profile_screen.dart';
import '../settings/settings_screen.dart';
import '../settings/users_screen.dart';

typedef _Tile = (IconData, String, Color, Widget);

/// All the sections that do not fit in the bottom bar, grouped, plus language and theme.
class MoreTab extends StatelessWidget {
  const MoreTab({super.key});

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final u = s.user;
    final tax = s.role.canSeeTax;

    final groups = <(String, List<_Tile>)>[
      if (tax)
        (
          'more.group_tax',
          [
            (Icons.account_balance_rounded, 'nav.cabinet', g.info, const CabinetScreen()),
            (Icons.payments_rounded, 'nav.tax_payments', g.success, const PaymentsScreen()),
            (Icons.mail_rounded, 'nav.letters', g.warning, const LettersScreen()),
            (Icons.description_rounded, 'nav.didox', g.accent2, const DidoxScreen()),
            (Icons.gavel_rounded, 'nav.legal', g.accent, const LegalScreen()),
            (Icons.auto_mode_rounded, 'nav.runs', g.info, const RunsScreen()),
            (Icons.smart_toy_rounded, 'nav.agent', g.success, const AgentScreen()),
          ],
        ),
      (
        'more.group_books',
        [
          (Icons.auto_awesome_rounded, 'nav.ai', g.accent2, const AiScreen()),
          (Icons.contacts_rounded, 'nav.contractors', g.info, const ContractorsScreen()),
          if (tax) (Icons.insights_rounded, 'nav.fin_reports', g.success, const ReportsScreen()),
          (Icons.upload_file_rounded, 'nav.import', g.warning, const ImportScreen()),
          (Icons.lightbulb_rounded, 'nav.proposals', g.accent, const ProposalsScreen()),
          (Icons.history_rounded, 'nav.history', g.textDim, const HistoryScreen()),
        ],
      ),
      if (tax)
        (
          'more.group_team',
          [
            (Icons.payments_rounded, 'nav.payroll', g.success, const PayrollScreen()),
            (Icons.groups_rounded, 'nav.employees', g.warning, const EmployeesScreen()),
            (Icons.request_quote_rounded, 'nav.advances', g.info, const AdvancesScreen()),
            (Icons.emoji_events_rounded, 'nav.kpi', g.accent, const KpiScreen()),
          ],
        ),
      (
        'more.group_system',
        [
          if (tax) (Icons.apartment_rounded, 'nav.company', g.info, const CompanyScreen()),
          if (s.role.canManagePeople) (Icons.admin_panel_settings_rounded, 'nav.users', g.danger, const UsersScreen()),
          if (tax) (Icons.vpn_key_rounded, 'nav.eri', g.warning, const SigningKeysScreen()),
          (Icons.settings_rounded, 'nav.settings', g.accent2, const SettingsScreen()),
        ],
      ),
    ];

    var idx = 3;
    return ListView(
      padding: EdgeInsets.only(top: context.headerSpace + 6, bottom: context.navSpace),
      children: [
        if (u != null)
          Reveal(
            child: GlassCard(
              strong: true,
              padding: const EdgeInsets.all(16),
              onTap: () => pushPage(context, const ProfileScreen()),
              child: Row(children: [
                Avatar(name: u.name, size: 56),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(u.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
                    const SizedBox(height: 2),
                    Text(s.company.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 13)),
                    const SizedBox(height: 6),
                    StatusPill(label: context.roleName(u.role), color: g.accent),
                  ]),
                ),
                Icon(Icons.chevron_right_rounded, color: g.textDim),
              ]),
            ),
          ),
        if (s.serverMode) ...[
          const SizedBox(height: 12),
          Reveal(index: 1, child: const _ServerCard()),
        ],
        const SizedBox(height: 16),
        Reveal(index: 1, child: SectionTitle(context.t('lang.title'))),
        Reveal(
          index: 1,
          child: SegmentedGlass(
            labels: [for (final l in kLocales) kLocaleNames[l]!],
            index: kLocales.indexOf(s.locale),
            onChanged: (i) => s.setLocale(kLocales[i]),
          ),
        ),
        const SizedBox(height: 14),
        Reveal(
          index: 2,
          child: GlassCard(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            child: Row(children: [
              AnimatedSwitcher(
                duration: const Duration(milliseconds: 350),
                transitionBuilder: (c, a) => RotationTransition(turns: Tween(begin: .75, end: 1.0).animate(a), child: FadeTransition(opacity: a, child: c)),
                child: Icon(s.dark ? Icons.dark_mode_rounded : Icons.light_mode_rounded, key: ValueKey(s.dark), color: g.warning),
              ),
              const SizedBox(width: 12),
              Expanded(child: Text(context.t(s.dark ? 'theme.dark' : 'theme.light'), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 15))),
              Switch(value: s.dark, onChanged: s.setDark),
            ]),
          ),
        ),
        for (final grp in groups) ...[
          const SizedBox(height: 18),
          Reveal(index: idx++, child: SectionTitle(context.t(grp.$1))),
          AdaptiveGrid(
            minColumnWidth: 150,
            columns: context.width >= 760 ? 3 : 2,
            spacing: 12,
            children: [
              for (final t in grp.$2)
                Reveal(
                  index: idx++,
                  child: GlassCard(
                    padding: const EdgeInsets.all(16),
                    radius: 24,
                    onTap: () => pushPage(context, t.$4),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      IconBadge(icon: t.$1, color: t.$3, size: 46),
                      const SizedBox(height: 14),
                      Text(context.t(t.$2), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 15, fontWeight: FontWeight.w800)),
                    ]),
                  ),
                ),
            ],
          ),
        ],
        const SizedBox(height: 18),
        AppButton(
          label: context.t('nav.logout'),
          icon: Icons.logout_rounded,
          kind: ButtonKind.glass,
          onPressed: () async {
            final ok = await confirmDialog(context, title: context.t('nav.logout'), message: context.t('common.confirm'), confirmLabel: context.t('nav.logout'));
            if (ok && context.mounted) context.readStore.logout();
          },
        ),
      ],
    );
  }
}


/// The real company: where the data comes from, when it was last read and the button to read it again.
class _ServerCard extends StatelessWidget {
  const _ServerCard();

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final t = s.lastSync;
    final when = t == null ? context.t('srv.never_synced') : context.t('srv.last_sync', {'time': context.dateTime(t)});
    final problem = s.syncError == null ? null : (s.offline ? context.t('srv.offline') : context.t('srv.err_server'));
    return GlassCard(
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          IconBadge(icon: s.offline ? Icons.cloud_off_rounded : Icons.cloud_done_rounded, color: s.offline ? g.warning : g.success, size: 42),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(context.t('srv.real_data', {'company': s.company.name}), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
              const SizedBox(height: 2),
              Text(when, style: TextStyle(color: g.textDim, fontSize: 12.5)),
            ]),
          ),
          const SizedBox(width: 8),
          AppButton(
            label: context.t(s.syncing ? 'srv.syncing' : 'srv.refresh'),
            icon: Icons.sync_rounded,
            compact: true,
            expanded: false,
            loading: s.syncing,
            onPressed: s.syncing ? null : () async {
              await s.syncNow();
              if (context.mounted) context.toast(context.t(s.syncError == null ? 'srv.synced_ok' : (s.offline ? 'srv.err_network' : 'srv.err_server')));
            },
          ),
        ]),
        if (s.pending > 0) Padding(padding: const EdgeInsets.only(top: 10), child: Text(context.t('srv.pending', {'count': s.pending}), style: TextStyle(color: g.warning, fontSize: 12.5, height: 1.3))),
        if (problem != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text(problem, style: TextStyle(color: g.warning, fontSize: 12.5, height: 1.3))),
      ]),
    );
  }
}
