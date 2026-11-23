import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/cabinet.dart';
import '../../widgets/glass.dart';
import 'cabinet_common.dart';
import 'payments_screen.dart';

/// One letter of the tax authority: its text (in the app language, with the original next to a translation), a deterministic explanation
/// built only from the letter's own fields, and the safe next steps. The cabinet's answer button is never pressed.
class LetterScreen extends StatefulWidget {
  const LetterScreen({super.key, required this.letterId});
  final int letterId;

  @override
  State<LetterScreen> createState() => _LetterScreenState();
}

class _LetterScreenState extends State<LetterScreen> {
  @override
  void initState() {
    super.initState();
    // opening is what "read" means: done after the first frame, never while building
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      final d = CabinetData.of(context.readStore);
      final l = d.letters.where((e) => e.id == widget.letterId).firstOrNull;
      if (l != null) d.markRead(l);
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final d = CabinetData.of(s);
    final l = d.letters.where((e) => e.id == widget.letterId).firstOrNull;
    if (l == null) return PageScaffold(title: context.t('nav.letters'), body: EmptyState(icon: Icons.mail_outline_rounded, title: context.t('let.empty')));
    final locale = s.locale;
    final live = d.live;
    final bodyText = l.bodyIn(locale);
    final summary = l.summaryIn(locale);
    final ins = d.insight(l);
    final obligation = obligationFrom(ins.reportType, ins.periodStart, ins.periodEnd, ins.deadline);
    final translated = l.isTranslation(locale);

    String what() => context.t('let.ins.what.${ins.ask}', {
          'tax': ins.tax == null ? '' : cabTaxName(context, ins.tax!),
          'amount': context.money(ins.amount ?? 0),
          'report': ins.reportType == null ? '' : context.t('taxtype.${ins.reportType}'),
          'period': ins.periodStart == null ? '' : context.monthYear(ins.periodStart!),
        });

    String deadline() {
      if (ins.deadline == null || ins.daysLeft == null) return context.t('let.ins.no_deadline');
      final n = ins.daysLeft!;
      return n >= 0 ? context.t('let.ins.left', {'n': n, 'date': context.date(ins.deadline)}) : context.t('let.ins.late', {'n': -n, 'date': context.date(ins.deadline)});
    }

    return PageScaffold(
      title: context.t(l.isDemand ? 'let.demand' : 'let.notice'),
      subtitle: l.number.isEmpty ? null : context.t('let.number', {'number': l.number}),
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 8),
        children: [
          const Reveal(child: DemoNote()),
          const SizedBox(height: 12),
          Reveal(
            index: 1,
            child: GlassCard(
              strong: true,
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  LetterKindPill(l),
                  letterDeadlinePill(context, l),
                  if (l.answered) const SizedBox.shrink() else StatusPill(label: context.t('let.unanswered'), color: g.warning),
                ]),
                const SizedBox(height: 12),
                Text(l.titleIn(locale), style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800, height: 1.25)),
                const SizedBox(height: 10),
                InfoLine(context.t('let.date'), context.date(l.date)),
                if (l.deadline != null) InfoLine(context.t('let.deadline'), context.date(l.deadline), color: l.isOverdue(DateTime.now()) ? g.danger : null),
              ]),
            ),
          ),
          const SizedBox(height: 14),
          Reveal(
            index: 2,
            child: GlassCard(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t('let.text_title'), style: TextStyle(color: g.text, fontSize: 15.5, fontWeight: FontWeight.w800)),
                if (translated && bodyText.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('let.translated_note'), style: TextStyle(color: g.info, fontSize: 12.5))),
                const SizedBox(height: 10),
                if (bodyText.isEmpty)
                  Text(context.t('let.no_text'), style: TextStyle(color: g.textDim, fontSize: 14, height: 1.4))
                else
                  SelectableText(bodyText, style: TextStyle(color: g.text, fontSize: 14.5, height: 1.45)),
                const SizedBox(height: 14),
                if (bodyText.isNotEmpty) AppButton(
                  label: context.t('let.copy'),
                  icon: Icons.copy_rounded,
                  kind: ButtonKind.glass,
                  compact: true,
                  expanded: false,
                  onPressed: () async {
                    await Clipboard.setData(ClipboardData(text: '${l.titleIn(locale)}\n\n${l.bodyIn(locale)}'));
                    if (context.mounted) context.toast(context.t('let.copied'));
                  },
                ),
              ]),
            ),
          ),
          if (translated && (live ? (l.body['orig'] ?? '').isNotEmpty && l.body['orig'] != bodyText : bodyText.isNotEmpty)) ...[
            const SizedBox(height: 14),
            Reveal(
              index: 3,
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t(live ? 'let.original_live' : 'let.original'), style: TextStyle(color: g.textDim, fontSize: 13, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 8),
                  SelectableText(live ? (l.body['orig'] ?? '') : l.bodyIn('uz'), style: TextStyle(color: g.textDim, fontSize: 13.5, height: 1.4)),
                ]),
              ),
            ),
          ],
          if (live && summary != null && summary.isNotEmpty) ...[
            const SizedBox(height: 14),
            Reveal(
              index: 4,
              child: GlassCard(
                tint: g.accent2.withValues(alpha: .10),
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Icon(Icons.auto_awesome_rounded, color: g.accent2, size: 20),
                    const SizedBox(width: 8),
                    Text(context.t('let.summary_title'), style: TextStyle(color: g.text, fontSize: 15.5, fontWeight: FontWeight.w800)),
                  ]),
                  const SizedBox(height: 10),
                  SelectableText(summary, style: TextStyle(color: g.text, fontSize: 14.5, height: 1.4)),
                  const SizedBox(height: 10),
                  Text(context.t('let.ai_note'), style: TextStyle(color: g.textDim, fontSize: 11.5, height: 1.3)),
                ]),
              ),
            ),
          ],
          if (!live) ...[
          const SizedBox(height: 14),
          Reveal(
            index: 4,
            child: GlassCard(
              tint: g.accent2.withValues(alpha: .10),
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Icon(Icons.auto_awesome_rounded, color: g.accent2, size: 20),
                  const SizedBox(width: 8),
                  Text(context.t('let.summary_title'), style: TextStyle(color: g.text, fontSize: 15.5, fontWeight: FontWeight.w800)),
                ]),
                const SizedBox(height: 10),
                Text(what(), style: TextStyle(color: g.text, fontSize: 14.5, height: 1.4)),
                if (ins.ask != 'info') ...[
                  const SizedBox(height: 6),
                  Text(deadline(), style: TextStyle(color: (ins.daysLeft ?? 0) < 0 && !l.answered ? g.danger : g.textDim, fontSize: 13.5, height: 1.35)),
                ],
                const SizedBox(height: 12),
                Text(context.t('let.ins.next'), style: TextStyle(color: g.text, fontSize: 13.5, fontWeight: FontWeight.w800)),
                const SizedBox(height: 6),
                for (final step in ins.steps)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Padding(padding: const EdgeInsets.only(top: 3, right: 8), child: Icon(Icons.chevron_right_rounded, size: 18, color: g.accent)),
                      Expanded(child: Text(context.t('let.ins.step.$step'), style: TextStyle(color: g.textDim, fontSize: 13.5, height: 1.35))),
                    ]),
                  ),
                const SizedBox(height: 8),
                Wrap(spacing: 8, runSpacing: 8, children: [
                  if (ins.ask == 'pay_debt') AppButton(label: context.t('let.go_pay'), icon: Icons.payments_rounded, compact: true, expanded: false, onPressed: () => pushPage(context, const PaymentsScreen())),
                  if (ins.ask == 'file_report' && obligation != null) AppButton(label: context.t('let.go_report'), icon: Icons.edit_document, compact: true, expanded: false, onPressed: () => openReport(context, obligation)),
                ]),
                const SizedBox(height: 10),
                Text(context.t('let.ins.note'), style: TextStyle(color: g.textDim, fontSize: 11.5, height: 1.3)),
              ]),
            ),
          ),
          ],
          const SizedBox(height: 14),
          Text(context.t(live ? 'let.live_hint' : 'let.never_press'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.35)),
        ],
      ),
    );
  }
}
