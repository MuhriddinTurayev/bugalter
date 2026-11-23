import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

/// The audit log as a timeline: who (user, AI or system) did what and when.
class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  int _filter = 0;
  static const _actors = ['', 'user', 'ai', 'system'];

  Color _color(Glass g, String actor) => switch (actor) {
        'ai' => g.accent2,
        'system' => g.info,
        _ => g.accent,
      };

  IconData _icon(String actor) => switch (actor) {
        'ai' => Icons.auto_awesome_rounded,
        'system' => Icons.settings_suggest_rounded,
        _ => Icons.person_rounded,
      };

  /// Turns the raw subject the store logged into something readable in the current language.
  String _subject(BuildContext context, AuditEntry e) {
    final sub = e.subject;
    final a = e.action;
    final period = RegExp(r'^(\d{1,2})\.(\d{4})$');
    String monthOf(String p) {
      final m = period.firstMatch(p);
      return m == null ? p : '${context.t('month.${int.parse(m.group(1)!)}')} ${m.group(2)}';
    }

    if (a == 'txn.created') {
      final parts = sub.split(' ');
      final som = parts.length == 2 ? int.tryParse(parts[1]) : null;
      if (som != null && (parts[0] == 'income' || parts[0] == 'expense')) return '${context.t('txn.${parts[0]}')} ${formatMoney(som * kTiyin, context.t('currency'))}';
    }
    if (a.startsWith('payroll.')) return monthOf(sub);
    if (a.startsWith('report.')) {
      final parts = sub.split(' ');
      if (parts.length == 2 && TaxType.values.any((t) => t.name == parts[0])) return '${context.t('taxtype.${parts[0]}')} · ${monthOf(parts[1])}';
      if (parts.length == 1 && TaxType.values.any((t) => t.name == parts[0])) return context.t('taxtype.${parts[0]}');
    }
    return sub;
  }

  String _sentence(BuildContext context, AuditEntry e) {
    final key = 'audit.${e.action}';
    final subject = _subject(context, e);
    final s = context.t(key, {'subject': subject});
    if (s != key) return s.trim();
    return context.t('audit.generic', {'action': e.action, 'subject': subject}).trim();
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final actor = _actors[_filter];
    final list = s.audit.where((e) => actor.isEmpty || e.actor == actor).toList().reversed.toList();

    return PageScaffold(
      title: context.t('nav.history'),
      subtitle: context.t('history.subtitle'),
      body: Column(children: [
        const SizedBox(height: 8),
        ChipBar(
          labels: [context.t('common.all'), context.t('history.actor_user'), context.t('history.actor_ai'), context.t('history.actor_system')],
          index: _filter,
          onChanged: (i) => setState(() => _filter = i),
        ),
        const SizedBox(height: 10),
        Expanded(
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 280),
            child: list.isEmpty
                ? EmptyState(key: const ValueKey('empty'), icon: Icons.history_rounded, title: context.t('history.empty'))
                : ListView.builder(
                    key: ValueKey(_filter),
                    padding: EdgeInsets.only(bottom: 24 + MediaQuery.viewPaddingOf(context).bottom),
                    itemCount: list.length,
                    itemBuilder: (context, i) {
                      final e = list[i];
                      final c = _color(g, e.actor);
                      return Reveal(
                        index: i,
                        dy: 10,
                        child: Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Stack(clipBehavior: Clip.none, children: [
                            if (i != list.length - 1) Positioned(left: 15, top: 34, bottom: -10, width: 2, child: DecoratedBox(decoration: BoxDecoration(color: g.border, borderRadius: BorderRadius.circular(1)))),
                            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Container(
                                width: 32,
                                height: 32,
                                decoration: BoxDecoration(shape: BoxShape.circle, color: c.withValues(alpha: .2), border: Border.all(color: c.withValues(alpha: .5))),
                                child: Icon(_icon(e.actor), size: 17, color: c),
                              ),
                              const SizedBox(width: 12),
                              Expanded(
                                child: GlassCard(
                                  blur: false,
                                  radius: 20,
                                  padding: const EdgeInsets.all(12),
                                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                                    Text(_sentence(context, e), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14.5, height: 1.3)),
                                    const SizedBox(height: 8),
                                    Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                                      StatusPill(label: context.t('history.actor_${e.actor}'), color: c),
                                      Text(context.dateTime(e.at), style: TextStyle(color: g.textDim, fontSize: 12)),
                                    ]),
                                  ]),
                                ),
                              ),
                            ]),
                          ]),
                        ),
                      );
                    },
                  ),
          ),
        ),
      ]),
    );
  }
}
