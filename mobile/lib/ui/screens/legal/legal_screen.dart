import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/legal.dart';
import '../../../l10n/strings.dart';
import '../../widgets/glass.dart';
import '../server_gate.dart';
import 'legal_common.dart';
import 'legal_draft_screen.dart';

/// Yurist: contract, act, agreement, power of attorney and letter drafts. The text is made on the device from templates in the
/// language you choose; a person approves it, and only then can it be "sent" to Didox (sandbox on the phone).
class LegalScreen extends StatelessWidget {
  const LegalScreen({super.key});

  Future<void> _new(BuildContext context) async {
    final s = context.readStore;
    final picked = await showGlassSheet<String>(context, builder: (ctx) {
      final g = ctx.g;
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(ctx.t('legal.pick_title'), style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 12),
        for (final t in LegalTemplate.all)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: SoftTile(
              onTap: () => Navigator.pop(ctx, t.id),
              child: RowTile(
                leading: IconBadge(icon: legalTemplateIcon(t.id), color: g.accent2, size: 40),
                title: ctx.t('legal.tpl.${t.id}'),
                subtitle: ctx.t('legal.tpl.${t.id}.d'),
                trailing: Icon(Icons.chevron_right_rounded, color: g.textDim),
              ),
            ),
          ),
      ]);
    });
    if (picked == null || !context.mounted) return;
    final d = LegalData.of(s).createDraft(s, picked, s.locale);
    pushPage(context, LegalDraftScreen(draftId: d.id));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final data = LegalData.of(s);
    final gate = serverGate(context, title: context.t('nav.legal'), synced: data.synced, allowed: data.canSeeLegal);
    if (gate != null) return gate;
    final live = data.live;
    final drafts = data.draftsSorted();

    return PageScaffold(
      title: context.t('nav.legal'),
      subtitle: context.t('legal.subtitle'),
      // a new draft is written by the AI lawyer on the web platform; here the real drafts are only read
      fab: live ? null : AppButton(label: context.t('legal.new'), icon: Icons.add_rounded, expanded: false, onPressed: () => _new(context)),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 110),
        children: [
          const DemoNote(),
          const SizedBox(height: 10),
          SoftTile(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(Icons.gavel_rounded, size: 18, color: g.warning),
                const SizedBox(width: 10),
                Expanded(child: Text(context.t('legal.disclaimer'), style: TextStyle(color: g.text, fontSize: 13, height: 1.35, fontWeight: FontWeight.w600))),
              ]),
              const SizedBox(height: 8),
              Text(context.t(live ? 'legal.live_note' : 'legal.never_sends'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35)),
            ]),
          ),
          const SizedBox(height: 18),
          TileSection(
            title: context.t('legal.list_title'),
            empty: live ? context.t('legal.empty') : '${context.t('legal.empty')}. ${context.t('legal.empty_hint')}',
            children: [for (final d in drafts) _DraftTile(draft: d)],
          ),
        ],
      ),
    );
  }
}

class _DraftTile extends StatelessWidget {
  const _DraftTile({required this.draft});
  final LegalDraft draft;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final color = legalStatusColor(g, draft.status);
    final blanks = draft.status == 'draft' ? blanksIn(draft.body) : 0;
    final sub = [
      legalKindLabel(context, draft.templateId),
      if (draft.party.isNotEmpty) draft.party,
      kLocaleNames[draft.language] ?? draft.language,
    ].join(' · ');
    return SoftTile(
      padding: const EdgeInsets.all(14),
      onTap: () => pushPage(context, LegalDraftScreen(draftId: draft.id)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          IconBadge(icon: legalTemplateIcon(draft.templateId), color: color, size: 40),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(draft.title.isEmpty ? context.t('legal.untitled') : draft.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 15, fontWeight: FontWeight.w800)),
              const SizedBox(height: 2),
              Text(sub, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
            ]),
          ),
        ]),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
          StatusPill(label: context.t('legal.status.${draft.status}'), color: color),
          if (draft.status == 'draft') StatusPill(label: blanks == 0 ? context.t('legal.complete') : context.t('legal.blanks_count', {'n': blanks}), color: blanks == 0 ? g.success : g.warning),
          Text(context.date(draft.createdAt), style: TextStyle(color: g.textDim, fontSize: 12)),
        ]),
      ]),
    );
  }
}
