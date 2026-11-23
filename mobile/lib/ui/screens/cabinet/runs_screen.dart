import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/cabinet.dart';
import '../../../data/models.dart';
import '../../../data/tax.dart';
import '../../widgets/glass.dart';
import 'run_screen.dart';

/// "Hisobotlarni avtomatik tayyorlash": pick a month, the AI accountant analyses and prepares the reports, a person reviews and approves.
/// Nothing is submitted by the analysis itself.
class RunsScreen extends StatefulWidget {
  const RunsScreen({super.key});

  @override
  State<RunsScreen> createState() => _RunsScreenState();
}

class _RunsScreenState extends State<RunsScreen> {
  late DateTime _month = () {
    final n = DateTime.now();
    return DateTime(n.year, n.month - 1, 1);
  }();

  void _start() {
    final d = CabinetData.of(context.readStore);
    CabRun? made;
    final ok = context.attempt(() {
      try {
        made = d.startRun(_month);
      } on MissingTaxRule {
        context.toast(context.t('tax.rules_missing'));
      }
    });
    final run = made;
    if (ok && run != null) pushPage(context, RunScreen(runId: run.id, animate: true));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    if (!s.role.canSeeTax) {
      return PageScaffold(title: context.t('nav.runs'), body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('tax.no_access_text')));
    }
    // the real company: the automatic preparation (and its approval) is done in the web platform, there is nothing of it to show here
    if (s.serverMode) {
      return PageScaffold(title: context.t('nav.runs'), body: EmptyState(icon: Icons.auto_mode_rounded, title: context.t('nav.runs'), text: context.t('srv.mod.runs_web')));
    }
    final d = CabinetData.of(s);
    final now = DateTime.now();
    final months = [for (var k = 0; k < 7; k++) DateTime(now.year, now.month - k, 1)];
    final runs = d.runs;

    return PageScaffold(
      title: context.t('nav.runs'),
      subtitle: context.t('run.subtitle'),
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 8),
        children: [
          const Reveal(child: DemoNote()),
          const SizedBox(height: 12),
          const Reveal(index: 1, child: RunStepper(current: 1)),
          const SizedBox(height: 14),
          Reveal(
            index: 2,
            child: GlassCard(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text(context.t('run.choose_period'), style: TextStyle(color: g.text, fontSize: 16.5, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4),
                Text(context.t('run.month_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
                const SizedBox(height: 14),
                PickerField<DateTime>(
                  label: context.t('common.date'),
                  icon: Icons.event_outlined,
                  value: _month,
                  options: [for (final m in months) (m, context.monthYear(m))],
                  onChanged: (v) => setState(() => _month = v ?? _month),
                ),
                const SizedBox(height: 14),
                Text(context.t('run.nothing_submitted'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
                const SizedBox(height: 14),
                AppButton(label: context.t('run.start'), icon: Icons.play_arrow_rounded, onPressed: _start),
              ]),
            ),
          ),
          const SizedBox(height: 14),
          Reveal(
            index: 3,
            child: TileSection(
              title: context.t('run.sources'),
              children: [
                _Source(icon: Icons.menu_book_rounded, color: g.success, text: context.t('run.src.books')),
                _Source(icon: Icons.account_balance_wallet_outlined, color: g.info, text: context.t('run.src.accounts', {'count': s.activeAccounts.length})),
                _Source(icon: Icons.account_balance_rounded, color: g.warning, text: context.t('run.src.portal')),
                _Source(icon: Icons.send_rounded, color: g.accent2, text: context.t('run.src.gateway')),
              ],
            ),
          ),
          const SizedBox(height: 20),
          Reveal(
            index: 4,
            child: TileSection(
              title: context.t('run.recent'),
              empty: context.t('run.no_runs'),
              children: [
                for (final r in runs)
                  SoftTile(
                    onTap: () => pushPage(context, RunScreen(runId: r.id)),
                    padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                    child: Row(children: [
                      IconBadge(icon: Icons.auto_mode_rounded, color: r.approvedAt != null ? g.success : g.info, size: 40),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(context.monthYear(r.month), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                          Text(context.t('run.run_subtitle', {'month': context.dateTime(r.startedAt), 'who': r.by}), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
                        ]),
                      ),
                      Icon(Icons.chevron_right_rounded, color: g.textDim),
                    ]),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 14),
          Text(context.t('cab.disclaimer'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.35)),
        ],
      ),
    );
  }
}

class _Source extends StatelessWidget {
  const _Source({required this.icon, required this.color, required this.text});
  final IconData icon;
  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) => SoftTile(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(children: [
          IconBadge(icon: icon, color: color, size: 36),
          const SizedBox(width: 12),
          Expanded(child: Text(text, style: TextStyle(color: context.g.text, fontSize: 14, fontWeight: FontWeight.w600, height: 1.25))),
        ]),
      );
}
