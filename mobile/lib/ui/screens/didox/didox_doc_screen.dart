import 'package:flutter/material.dart';

import '../agent/translate_card.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/legal.dart';
import '../../../data/models.dart';
import '../../../l10n/strings.dart';
import '../../widgets/glass.dart';
import '../documents/document_form.dart';
import '../legal/legal_common.dart';
import '../server_gate.dart';

/// One Didox document: the parties, lines and totals, the text (original and translated), a conclusion made from its data,
/// who signed, and what may be done with it (sign, reject, cancel, make an invoice or an act in the books).
class DidoxDocScreen extends StatefulWidget {
  const DidoxDocScreen({super.key, required this.docId});
  final int docId;

  @override
  State<DidoxDocScreen> createState() => _DidoxDocScreenState();
}

class _DidoxDocScreenState extends State<DidoxDocScreen> {
  bool _original = false;

  /// The conclusion: only facts that are in the document's own data; no outside AI and no invented number.
  List<String> _insight(BuildContext context, DidoxDoc d) {
    final kind = didoxKindLabel(context, d);
    final out = <String>[
      context.t('didox.ins_what', {'kind': kind, 'number': d.number.isEmpty ? '' : '№ ${d.number}', 'party': d.counterparty.isEmpty ? '—' : d.counterparty, 'date': context.date(d.dateOrNull)}),
      if (d.tin.isNotEmpty) context.t('didox.ins_tin', {'tin': d.tin}),
      if (d.lines.isNotEmpty) context.t('didox.ins_amount', {'amount': context.money(d.amount ?? 0), 'vat': context.money(d.linesVat)}),
      if (d.lines.isEmpty && d.amountMinor != null) context.t('didox.ins_amount_plain', {'amount': context.money(d.amountMinor!)}),
      if (d.lines.isNotEmpty) context.t('didox.ins_lines', {'n': d.lines.length}),
      if (d.contract.isNotEmpty) context.t('didox.ins_contract', {'contract': d.contract}),
      context.t('didox.ins_signs', {'n': d.signatures.length}),
      if (kAllStrings.containsKey('didox.ins_status_${d.status}')) context.t('didox.ins_status_${d.status}'),
      if (d.body.isEmpty && d.translations.isEmpty) context.t('didox.ins_nobody'),
    ];
    return out;
  }

  Future<void> _sign(LegalData data, DidoxDoc d) async {
    final s = context.readStore;
    final ok = await confirmDialog(context, title: context.t('didox.sign_title'), message: context.t('didox.sign_confirm_msg'), confirmLabel: context.t('didox.sign_button'));
    if (!ok || !mounted) return;
    if (runLegal(context, () => data.signDoc(s, d))) context.toast(context.t('didox.sign_done'));
  }

  Future<void> _reject(LegalData data, DidoxDoc d) async {
    final s = context.readStore;
    final reason = TextEditingController();
    final go = await showGlassSheet<bool>(context, builder: (ctx) {
      final g = ctx.g;
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(ctx.t('didox.reject_button'), style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 6),
        Text(ctx.t('didox.reject_hint'), style: TextStyle(color: g.textDim, fontSize: 14, height: 1.35)),
        const SizedBox(height: 14),
        GlassField(controller: reason, label: ctx.t('didox.reason_placeholder'), maxLines: 3),
        const SizedBox(height: 16),
        Row(children: [
          Expanded(child: AppButton(label: ctx.t('didox.keep'), kind: ButtonKind.glass, onPressed: () => Navigator.pop(ctx, false))),
          const SizedBox(width: 12),
          Expanded(child: AppButton(label: ctx.t('didox.reject_confirm'), kind: ButtonKind.danger, onPressed: () => Navigator.pop(ctx, true))),
        ]),
      ]);
    });
    final text = reason.text;
    reason.dispose();
    if (go != true || !mounted) return;
    if (runLegal(context, () => data.reject(s, d, text))) context.toast(context.t('didox.reject_done'));
  }

  Future<void> _cancel(LegalData data, DidoxDoc d) async {
    final s = context.readStore;
    final ok = await confirmDialog(context, title: context.t('didox.cancel_button'), message: context.t('didox.cancel_confirm_hint'), danger: true, confirmLabel: context.t('didox.cancel_confirm'));
    if (!ok || !mounted) return;
    if (runLegal(context, () => data.cancel(s, d))) context.toast(context.t('didox.cancel_done'));
  }

  void _make(LegalData data, DidoxDoc d, DocType type) {
    final s = context.readStore;
    late Doc doc;
    var added = false;
    if (!runLegal(context, () {
      final r = data.prepareBookDoc(s, d, type);
      doc = r.$1;
      added = r.$2;
    })) {
      return;
    }
    if (added) context.toast(context.t('didox.contractor_added', {'name': d.counterparty}));
    pushPage(context, DocumentFormScreen(doc: doc));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final data = LegalData.of(s);
    final gate = serverGate(context, title: context.t('nav.didox'), synced: data.synced, allowed: data.canSeeDidox);
    if (gate != null) return gate;
    final d = data.doc(widget.docId);
    if (d == null) return PageScaffold(title: context.t('nav.didox'), body: EmptyState(icon: Icons.search_off_rounded, title: context.t('common.no_results')));

    final color = didoxStatusColor(g, d.status);
    final kind = didoxKindLabel(context, d);
    final aiSummary = d.summaries[s.locale];
    final label = d.number.isEmpty ? kind : '$kind № ${d.number}';
    final locale = s.locale;
    final translated = d.translations[locale];
    final hasChoice = translated != null && d.body.isNotEmpty && locale != d.bodyLang;
    final text = hasChoice ? (_original ? d.body : translated) : (d.textIn(locale) ?? (d.body.isNotEmpty ? d.body : null));
    final related = [for (final id in d.related) ?data.doc(id)];

    return PageScaffold(
      title: label,
      subtitle: d.title.isEmpty ? null : d.title,
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace),
        children: [
          const DemoNote(),
          const SizedBox(height: 12),
          GlassCard(
            strong: true,
            padding: const EdgeInsets.all(16),
            child: Column(children: [
              Row(children: [
                IconBadge(icon: didoxKindIcon(d.kind), color: color, size: 44),
                const SizedBox(width: 12),
                Expanded(child: Text(label, style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800))),
                Flexible(flex: 0, child: StatusPill(label: didoxStatusLabel(context, d), color: color)),
              ]),
              const SizedBox(height: 8),
              InfoLine(context.t('common.date'), context.date(d.dateOrNull)),
              InfoLine(context.t(d.incoming ? 'didox.from' : 'didox.to'), d.counterparty.isEmpty ? '—' : d.counterparty),
              if (d.tin.isNotEmpty) InfoLine('STIR / JSHSHIR', d.tin),
              if (d.contract.isNotEmpty) InfoLine(context.t('didox.kind.contract'), '№ ${d.contract}'),
              if (d.amount != null) InfoLine(context.t('didox.amount'), context.money(d.amount!), bold: true),
              if (d.status == 'rejected' && d.rejectReason.isNotEmpty) InfoLine(context.t('didox.reject_button').replaceAll('…', ''), d.rejectReason),
              if (d.signApprovedBy.isNotEmpty && d.signApprovedAt != null) ...[
                const SizedBox(height: 6),
                Align(alignment: Alignment.centerLeft, child: Text(context.t('didox.signed_by', {'name': d.signApprovedBy, 'date': context.date(d.signApprovedAt)}), style: TextStyle(color: g.textDim, fontSize: 12.5))),
              ],
            ]),
          ),
          if (d.live && (d.waitsForUs || d.signState.isNotEmpty || d.signError.isNotEmpty)) ...[
            const SizedBox(height: 14),
            SoftTile(
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                if (d.waitsForUs) Text(context.t('didox.live_sign_hint'), style: TextStyle(color: g.text, fontSize: 13.5, height: 1.35)),
                if (d.signState.isNotEmpty && kAllStrings.containsKey('didox.sign_state.${d.signState}'))
                  Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('didox.sign_state.${d.signState}'), style: TextStyle(color: g.warning, fontSize: 13, height: 1.3))),
                if (d.signError.isNotEmpty)
                  Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('didox.sign_error', {'error': d.signError}), maxLines: 4, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.danger, fontSize: 12.5, height: 1.3))),
              ]),
            ),
          ],
          if (d.canSign || d.canCancel || d.canMakeInvoice) ...[
            const SizedBox(height: 14),
            GlassCard(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text(context.t('didox.actions_title'), style: TextStyle(color: g.text, fontSize: 16, fontWeight: FontWeight.w800)),
                const SizedBox(height: 8),
                if (d.canSign) ...[
                  Text(context.t('didox.sign_hint'), style: TextStyle(color: g.textDim, fontSize: 13.5, height: 1.35)),
                  const SizedBox(height: 12),
                  AppButton(label: context.t('didox.sign_button'), icon: Icons.draw_rounded, onPressed: () => _sign(data, d)),
                  const SizedBox(height: 10),
                  AppButton(label: context.t('didox.reject_button'), icon: Icons.block_rounded, kind: ButtonKind.glass, onPressed: () => _reject(data, d)),
                ],
                if (d.canCancel) ...[
                  Text(context.t('didox.cancel_hint'), style: TextStyle(color: g.textDim, fontSize: 13.5, height: 1.35)),
                  const SizedBox(height: 12),
                  AppButton(label: context.t('didox.cancel_button'), icon: Icons.delete_outline_rounded, kind: ButtonKind.danger, onPressed: () => _cancel(data, d)),
                ],
                if (d.canMakeInvoice) ...[
                  Text(context.t('didox.make_title'), style: TextStyle(color: g.text, fontSize: 14.5, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text(context.t('didox.make_hint'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35)),
                  const SizedBox(height: 12),
                  Wrap(spacing: 10, runSpacing: 10, children: [
                    AppButton(label: context.t('didox.make_invoice'), icon: Icons.receipt_long_rounded, expanded: false, onPressed: () => _make(data, d, DocType.invoice)),
                    AppButton(label: context.t('didox.make_act'), icon: Icons.assignment_turned_in_rounded, expanded: false, kind: ButtonKind.glass, onPressed: () => _make(data, d, DocType.act)),
                  ]),
                ],
                const SizedBox(height: 10),
                Text(context.t('didox.only_with_approval'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.3)),
              ]),
            ),
          ],
          if (d.lines.isNotEmpty) ...[
            const SizedBox(height: 18),
            TileSection(
              title: context.t('didox.lines_title'),
              children: [
                for (var i = 0; i < d.lines.length; i++)
                  SoftTile(
                    padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text('${i + 1}. ${d.lines[i].name}', style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14.5)),
                          const SizedBox(height: 3),
                          Text('${formatQty(d.lines[i].qtyMilli)} × ${context.money(d.lines[i].unitPriceMinor)}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                          if (d.lines[i].vatBp > 0) Text('${context.t('docs.vat_line', {'rate': formatBp(d.lines[i].vatBp)})} · ${context.money(d.lines[i].vat)}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                        ]),
                      ),
                      const SizedBox(width: 10),
                      Text(context.money(d.lines[i].total), textAlign: TextAlign.end, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                    ]),
                  ),
              ],
            ),
            const SizedBox(height: 10),
            SoftTile(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              child: Column(children: [
                InfoLine(context.t('docs.subtotal'), context.money(d.linesSubtotal)),
                InfoLine(context.t('docs.vat_total'), context.money(d.linesVat)),
                InfoLine(context.t('docs.grand_total'), context.money(d.amount ?? 0), bold: true),
              ]),
            ),
          ],
          const SizedBox(height: 18),
          GlassCard(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(context.t('didox.text_title'), style: TextStyle(color: g.text, fontSize: 16, fontWeight: FontWeight.w800)),
              const SizedBox(height: 10),
              if (text == null)
                Text(context.t('didox.no_text'), style: TextStyle(color: g.textDim, fontSize: 14, height: 1.35))
              else ...[
                if (hasChoice) ...[
                  SegmentedGlass(
                    labels: [context.t('didox.view_translated'), context.t('didox.view_original')],
                    index: _original ? 1 : 0,
                    onChanged: (i) => setState(() => _original = i == 1),
                  ),
                  const SizedBox(height: 8),
                  Text(context.t('didox.translated_note', {'language': kLocaleNames[d.bodyLang] ?? d.bodyLang}), style: TextStyle(color: g.textDim, fontSize: 12)),
                  const SizedBox(height: 10),
                ],
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: g.fill, borderRadius: BorderRadius.circular(16), border: Border.all(color: g.border)),
                  child: SelectableText(text, style: TextStyle(color: g.text, fontSize: 14, height: 1.45)),
                ),
              ],
            ]),
          ),
          if (d.live && d.body.isNotEmpty) ...[
            const SizedBox(height: 14),
            TranslateCard(
              kind: 'didox',
              id: d.id,
              done: aiSummary != null && aiSummary.isNotEmpty && (locale == d.bodyLang || translated != null),
            ),
          ],
          if (aiSummary != null && aiSummary.isNotEmpty) ...[
            const SizedBox(height: 14),
            GlassCard(
              tint: g.accent2.withValues(alpha: .10),
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Row(children: [
                  Icon(Icons.auto_awesome_rounded, size: 18, color: g.accent2),
                  const SizedBox(width: 8),
                  Text(context.t('didox.ai_summary'), style: TextStyle(color: g.text, fontSize: 16, fontWeight: FontWeight.w800)),
                ]),
                const SizedBox(height: 10),
                SelectableText(aiSummary, style: TextStyle(color: g.text, fontSize: 14, height: 1.4)),
                const SizedBox(height: 8),
                Text(context.t('didox.ai_summary_note'), style: TextStyle(color: g.textDim, fontSize: 11.5, height: 1.3)),
              ]),
            ),
          ],
          const SizedBox(height: 14),
          GlassCard(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Row(children: [
                Icon(Icons.auto_awesome_rounded, size: 18, color: g.accent2),
                const SizedBox(width: 8),
                Text(context.t('didox.summary_title'), style: TextStyle(color: g.text, fontSize: 16, fontWeight: FontWeight.w800)),
              ]),
              const SizedBox(height: 10),
              for (final line in _insight(context, d))
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Padding(padding: const EdgeInsets.only(top: 7, right: 10), child: Container(width: 5, height: 5, decoration: BoxDecoration(color: g.accent, shape: BoxShape.circle))),
                    Expanded(child: Text(line, style: TextStyle(color: g.text, fontSize: 14, height: 1.35))),
                  ]),
                ),
              const SizedBox(height: 4),
              Text(context.t('didox.summary_note'), style: TextStyle(color: g.textDim, fontSize: 11.5, height: 1.3)),
            ]),
          ),
          if (d.signatures.isNotEmpty) ...[
            const SizedBox(height: 18),
            TileSection(
              title: context.t('didox.signatures_title'),
              children: [
                for (final sg in d.signatures)
                  SoftTile(
                    padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                    child: Row(children: [
                      IconBadge(icon: sg.ours ? Icons.verified_rounded : Icons.check_circle_outline_rounded, color: sg.ours ? g.success : g.info, size: 38),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(sg.name.isEmpty ? '—' : sg.name, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14.5)),
                          if (sg.at != null) Text(context.dateTime(sg.at!), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                          if (sg.company.isNotEmpty) Text(sg.company, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12)),
                        ]),
                      ),
                      if (sg.sandbox) StatusPill(label: context.t('didox.sandbox_badge'), color: g.warning),
                    ]),
                  ),
              ],
            ),
          ],
          if (related.isNotEmpty) ...[
            const SizedBox(height: 18),
            TileSection(
              title: context.t('didox.related_title'),
              children: [
                for (final r in related)
                  SoftTile(
                    onTap: () => pushPage(context, DidoxDocScreen(docId: r.id)),
                    child: RowTile(
                      leading: IconBadge(icon: didoxKindIcon(r.kind), color: didoxStatusColor(g, r.status), size: 38),
                      title: r.number.isEmpty ? didoxKindLabel(context, r) : '${didoxKindLabel(context, r)} № ${r.number}',
                      subtitle: didoxStatusLabel(context, r),
                      trailing: Icon(Icons.chevron_right_rounded, color: g.textDim),
                    ),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }
}
