import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import '../contractors/contractor_form.dart';
import 'document_form.dart';
import 'documents_tab.dart';

/// One document: status, parties, lines, totals and the actions allowed in its current status.
class DocumentDetailScreen extends StatefulWidget {
  const DocumentDetailScreen({super.key, required this.docId});
  final int docId;

  @override
  State<DocumentDetailScreen> createState() => _DocumentDetailScreenState();
}

class _DocumentDetailScreenState extends State<DocumentDetailScreen> {
  bool _popped = false;

  String _qtyPrice(BuildContext context, DocLine l) => context
      .t('docs.qty_price', {'qty': formatQty(l.qtyMilli), 'unit': l.unit, 'price': context.money(l.unitPriceMinor)})
      .replaceAll(RegExp(r'\s{2,}'), ' ');

  String _plainText(BuildContext context, Doc d) {
    final s = context.readStore;
    final b = StringBuffer()
      ..writeln('${d.number} — ${context.t(d.type == DocType.invoice ? 'doctype.invoice' : 'doctype.act')}')
      ..writeln('${context.t('docs.contractor')}: ${s.contractor(d.contractorId)?.name ?? '—'}')
      ..writeln('${context.t('docs.issue_date')}: ${context.date(d.issueDate)}');
    if (d.dueDate != null) b.writeln('${context.t('docs.due_date')}: ${context.date(d.dueDate)}');
    b.writeln('${context.t('common.status')}: ${context.docStatusName(d)}');
    b.writeln();
    for (var i = 0; i < d.lines.length; i++) {
      final l = d.lines[i];
      b.writeln('${i + 1}. ${l.name} — ${_qtyPrice(context, l)} = ${context.money(l.total)}');
    }
    b
      ..writeln()
      ..writeln('${context.t('docs.subtotal')}: ${context.money(d.subtotal)}')
      ..writeln('${context.t('docs.vat_total')}: ${context.money(d.vat)}')
      ..writeln('${context.t('docs.grand_total')}: ${context.money(d.total)}');
    if (d.notes.isNotEmpty) b.writeln('\n${context.t('docs.notes')}: ${d.notes}');
    return b.toString().trim();
  }

  Future<void> _copy(Doc d) async {
    await Clipboard.setData(ClipboardData(text: _plainText(context, d)));
    if (mounted) context.toast(context.t('docs.copied'));
  }

  Future<void> _change(Doc d, DocStatus to, {required String confirmKey, bool danger = false}) async {
    final ok = await confirmDialog(context, title: context.t(confirmKey), danger: danger, confirmLabel: context.t(danger ? 'common.yes' : 'common.ok'));
    if (ok && mounted) context.attempt(() => context.readStore.setDocStatus(d, to));
  }

  Future<void> _pay(Doc d) async {
    final s = context.readStore;
    if (s.activeAccounts.isEmpty) {
      context.toast(context.t('err.no_account'));
      return;
    }
    final id = await showGlassSheet<int>(context, builder: (ctx) {
      final g = ctx.g;
      final st = ctx.store;
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(ctx.t(d.outgoing ? 'docs.pay_to' : 'docs.pay_from'), style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text(ctx.t('docs.pay_hint'), style: TextStyle(color: g.textDim, fontSize: 13)),
        const SizedBox(height: 14),
        for (final a in st.activeAccounts)
          PressScale(
            onTap: () => Navigator.pop(ctx, a.id),
            child: Container(
              margin: const EdgeInsets.only(bottom: 8),
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: g.fill, borderRadius: BorderRadius.circular(18), border: Border.all(color: g.border)),
              child: Row(children: [
                IconBadge(icon: a.type == AccountType.cash ? Icons.payments_outlined : Icons.account_balance_outlined, size: 40),
                const SizedBox(width: 12),
                Expanded(child: Text(a.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 15))),
                const SizedBox(width: 8),
                Flexible(child: Text(ctx.money(st.balanceOf(a)), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 13))),
              ]),
            ),
          ),
      ]);
    });
    if (id != null && mounted) context.attempt(() => s.setDocStatus(d, DocStatus.paid, accountId: id));
  }

  Future<void> _delete(Doc d) async {
    final ok = await confirmDialog(context, title: context.t('docs.confirm_delete'), danger: true, confirmLabel: context.t('common.delete'));
    if (!ok || !mounted) return;
    if (context.attempt(() => context.readStore.deleteDraft(d))) context.toast(context.t('common.deleted'));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final d = s.doc(widget.docId);
    if (d == null) {
      if (!_popped) {
        _popped = true;
        WidgetsBinding.instance.addPostFrameCallback((_) {
          if (mounted) Navigator.of(context).maybePop();
        });
      }
      return const SizedBox.shrink();
    }
    final now = DateTime.now();
    final contractor = s.contractor(d.contractorId);
    final color = docStatusColor(context, d);
    final overdue = d.isOverdue(now);
    final h = context.isWide ? 24.0 : 16.0;
    final daysLate = overdue && d.dueDate != null ? dateOnly(now).difference(dateOnly(d.dueDate!)).inDays : 0;

    final cards = <Widget>[
      // header
      GlassCard(
        strong: true,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            IconBadge(icon: d.type == DocType.invoice ? Icons.receipt_long_rounded : Icons.assignment_turned_in_rounded, size: 48, color: d.outgoing ? g.accent : g.warning),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t(d.type == DocType.invoice ? 'doctype.invoice' : 'doctype.act'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 13)),
                Text(context.t(d.outgoing ? 'docs.dir_out' : 'docs.dir_in'), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14)),
              ]),
            ),
            const SizedBox(width: 8),
            Flexible(flex: 0, child: StatusPill(label: context.docStatusName(d), color: color)),
          ]),
          const SizedBox(height: 16),
          AmountText(d.total, size: 32),
          if (overdue)
            Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.t('docs.overdue_days', {'n': daysLate}), style: TextStyle(color: g.danger, fontWeight: FontWeight.w700, fontSize: 13))),
          const SizedBox(height: 18),
          _Steps(doc: d),
        ]),
      ),
      // parties and dates
      GlassCard(
        child: Column(children: [
          InfoLine(context.t('docs.contractor'), contractor?.name ?? '—', bold: true),
          if (contractor != null && contractor.stir.isNotEmpty) InfoLine(context.t('contractors.stir').split(' (').first, contractor.stir),
          if (contractor != null && contractor.phone.isNotEmpty) InfoLine(context.t('common.phone'), contractor.phone),
          const Hairline(),
          InfoLine(context.t('docs.issue_date'), context.date(d.issueDate)),
          InfoLine(context.t('docs.due_date'), context.date(d.dueDate), color: overdue ? g.danger : null),
          if (d.paidAt != null) InfoLine(context.t('docs.paid_on'), context.date(d.paidAt), color: g.success),
          if (contractor != null)
            Align(
              alignment: Alignment.centerRight,
              child: Padding(
                padding: const EdgeInsets.only(top: 6),
                child: AppButton(label: context.t('contractors.edit'), icon: Icons.open_in_new_rounded, kind: ButtonKind.glass, expanded: false, compact: true, onPressed: () => pushPage(context, ContractorFormScreen(contractor: contractor))),
              ),
            ),
        ]),
      ),
      // lines
      TileSection(
        title: context.t('docs.lines_title'),
        children: [
          for (var i = 0; i < d.lines.length; i++)
            SoftTile(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${i + 1}. ${d.lines[i].name}', style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14.5)),
                    const SizedBox(height: 3),
                    Text(_qtyPrice(context, d.lines[i]), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                    if (d.lines[i].vatBp > 0) Text('${context.t('docs.vat_line', {'rate': formatBp(d.lines[i].vatBp)})} · ${context.money(d.lines[i].vat)}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                  ]),
                ),
                const SizedBox(width: 10),
                Flexible(flex: 0, child: Text(context.money(d.lines[i].total), textAlign: TextAlign.end, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5))),
              ]),
            ),
        ],
      ),
      // totals
      GlassCard(
        child: Column(children: [
          InfoLine(context.t('docs.subtotal'), context.money(d.subtotal)),
          InfoLine(context.t('docs.vat_total'), context.money(d.vat)),
          const Hairline(),
          InfoLine(context.t('docs.grand_total'), context.money(d.total), bold: true),
        ]),
      ),
      if (d.notes.isNotEmpty)
        GlassCard(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(context.t('docs.notes'), style: TextStyle(color: g.textDim, fontSize: 13, fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Text(d.notes, style: TextStyle(color: g.text, fontSize: 14.5, height: 1.35)),
          ]),
        ),
      // actions
      if (d.status == DocStatus.draft || d.status == DocStatus.sent)
        Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (d.status == DocStatus.draft) ...[
            AppButton(label: context.t('docs.send'), icon: Icons.send_rounded, onPressed: () => _change(d, DocStatus.sent, confirmKey: 'docs.confirm_send')),
            const SizedBox(height: 10),
            AppButton(label: context.t('docs.edit'), icon: Icons.edit_outlined, kind: ButtonKind.glass, onPressed: () => pushPage(context, DocumentFormScreen(doc: d))),
            const SizedBox(height: 10),
            AppButton(label: context.t('docs.cancel_doc'), icon: Icons.block_rounded, kind: ButtonKind.glass, onPressed: () => _change(d, DocStatus.cancelled, confirmKey: 'docs.confirm_cancel', danger: true)),
            const SizedBox(height: 10),
            AppButton(label: context.t('docs.delete_doc'), icon: Icons.delete_outline_rounded, kind: ButtonKind.danger, onPressed: () => _delete(d)),
          ] else ...[
            AppButton(label: context.t('docs.mark_paid'), icon: Icons.check_circle_outline_rounded, onPressed: () => _pay(d)),
            const SizedBox(height: 10),
            AppButton(label: context.t('docs.cancel_doc'), icon: Icons.block_rounded, kind: ButtonKind.danger, onPressed: () => _change(d, DocStatus.cancelled, confirmKey: 'docs.confirm_cancel', danger: true)),
          ],
        ]),
    ];

    return PageScaffold(
      title: d.number,
      subtitle: context.t(d.type == DocType.invoice ? 'doctype.invoice' : 'doctype.act_short'),
      maxWidth: 720,
      actions: [GlassIconButton(icon: Icons.copy_rounded, tooltip: context.t('docs.copy'), onTap: () => _copy(d))],
      body: ListView.builder(
        padding: EdgeInsets.fromLTRB(h, 4, h, 28),
        itemCount: cards.length,
        itemBuilder: (context, i) => Reveal(index: i, child: Padding(padding: const EdgeInsets.only(bottom: 12), child: cards[i])),
      ),
    );
  }
}

/// draft → sent → paid, with the reached steps lit; a cancelled document shows a single danger step.
class _Steps extends StatelessWidget {
  const _Steps({required this.doc});
  final Doc doc;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    if (doc.status == DocStatus.cancelled) {
      return Row(children: [
        Icon(Icons.cancel_rounded, color: g.warning),
        const SizedBox(width: 8),
        Expanded(child: Text(context.t('status.cancelled'), style: TextStyle(color: g.warning, fontWeight: FontWeight.w800))),
      ]);
    }
    const steps = [DocStatus.draft, DocStatus.sent, DocStatus.paid];
    final cur = steps.indexOf(doc.status);
    final onAccent = g.dark ? const Color(0xFF04231A) : Colors.white;
    Widget step(int i) {
      final reached = i <= cur;
      final c = reached ? (i == cur && doc.isOverdue(DateTime.now()) ? g.danger : g.accent) : g.textDim;
      return SizedBox(
        width: 76,
        child: Column(children: [
          AnimatedContainer(
            duration: const Duration(milliseconds: 400),
            curve: Curves.easeOutCubic,
            width: 28,
            height: 28,
            decoration: BoxDecoration(shape: BoxShape.circle, color: reached ? c : Colors.transparent, border: Border.all(color: c.withValues(alpha: reached ? 1 : .5), width: 2)),
            child: reached ? Icon(Icons.check_rounded, size: 17, color: onAccent) : null,
          ),
          const SizedBox(height: 6),
          Text(context.t('status.${steps[i].name}'), textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: reached ? g.text : g.textDim, fontSize: 11.5, fontWeight: reached ? FontWeight.w700 : FontWeight.w500)),
        ]),
      );
    }

    Widget line(int i) => Expanded(
          child: Padding(
            padding: const EdgeInsets.only(top: 13),
            child: AnimatedContainer(duration: const Duration(milliseconds: 400), height: 3, decoration: BoxDecoration(borderRadius: BorderRadius.circular(2), color: i < cur ? g.accent : g.border)),
          ),
        );

    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [step(0), line(0), step(1), line(1), step(2)]);
  }
}
