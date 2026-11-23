import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../data/store.dart';
import '../../widgets/glass.dart';
import 'document_detail.dart';
import 'document_form.dart';

/// Colour of a document's status pill; overdue documents are always shown in the danger colour.
Color docStatusColor(BuildContext context, Doc d) {
  final g = context.g;
  if (d.isOverdue(DateTime.now())) return g.danger;
  return switch (d.status) {
    DocStatus.draft => g.textDim,
    DocStatus.sent => g.info,
    DocStatus.paid => g.success,
    DocStatus.cancelled => g.warning,
  };
}

/// The documents tab: totals, search, filters and the list of invoices and acts.
class DocumentsTab extends StatefulWidget {
  const DocumentsTab({super.key});

  @override
  State<DocumentsTab> createState() => _DocumentsTabState();
}

class _DocumentsTabState extends State<DocumentsTab> {
  final _q = TextEditingController();
  int _status = 0; // all, draft, sent, paid, overdue, cancelled
  int _dir = 0; // all, outgoing, incoming

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  bool _match(Doc d, AppStore s, DateTime now) {
    switch (_status) {
      case 1:
        if (d.status != DocStatus.draft) return false;
      case 2:
        if (d.status != DocStatus.sent) return false;
      case 3:
        if (d.status != DocStatus.paid) return false;
      case 4:
        if (!d.isOverdue(now)) return false;
      case 5:
        if (d.status != DocStatus.cancelled) return false;
    }
    if (_dir == 1 && !d.outgoing) return false;
    if (_dir == 2 && d.outgoing) return false;
    final q = _q.text.trim().toLowerCase();
    if (q.isEmpty) return true;
    final hay = '${d.number} ${s.contractor(d.contractorId)?.name ?? ''} ${d.notes} ${d.lines.map((l) => l.name).join(' ')}'.toLowerCase();
    return hay.contains(q);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final now = DateTime.now();
    final all = s.docs.toList()
      ..sort((a, b) {
        final c = b.issueDate.compareTo(a.issueDate);
        return c != 0 ? c : b.id.compareTo(a.id);
      });
    final list = all.where((d) => _match(d, s, now)).toList();
    final overdue = s.overdueDocs(now).fold(0, (a, d) => a + d.total);

    final header = <Widget>[
      Reveal(
        child: AdaptiveGrid(minColumnWidth: 150, spacing: 10, children: [
          _SummaryCard(icon: Icons.south_west_rounded, color: g.success, label: context.t('dash.receivable'), minor: s.receivable),
          _SummaryCard(icon: Icons.north_east_rounded, color: g.warning, label: context.t('dash.payable'), minor: s.payable),
          _SummaryCard(icon: Icons.schedule_rounded, color: g.danger, label: context.t('dash.overdue'), minor: overdue),
        ]),
      ),
      const SizedBox(height: 14),
      Reveal(
        index: 1,
        child: GlassField(
          controller: _q,
          hint: context.t('common.search'),
          icon: Icons.search_rounded,
          onChanged: (_) => setState(() {}),
          suffix: _q.text.isEmpty ? null : IconButton(icon: Icon(Icons.close_rounded, color: g.textDim, size: 20), onPressed: () => setState(_q.clear)),
        ),
      ),
      const SizedBox(height: 10),
      Reveal(
        index: 2,
        child: ChipBar(
          labels: [
            context.t('common.all'),
            context.t('status.draft'),
            context.t('status.sent'),
            context.t('status.paid'),
            context.t('status.overdue'),
            context.t('status.cancelled'),
          ],
          index: _status,
          onChanged: (i) => setState(() => _status = i),
        ),
      ),
      const SizedBox(height: 10),
      Reveal(
        index: 3,
        child: SegmentedGlass(
          labels: [context.t('common.all'), context.t('docs.dir_out'), context.t('docs.dir_in')],
          index: _dir,
          onChanged: (i) => setState(() => _dir = i),
        ),
      ),
      const SizedBox(height: 14),
    ];

    final bottom = context.navSpace + 76;
    return Stack(children: [
      ListView.builder(
        padding: EdgeInsets.only(top: context.headerSpace + 4, bottom: bottom),
        itemCount: header.length + (list.isEmpty ? 1 : list.length),
        itemBuilder: (context, i) {
          if (i < header.length) return header[i];
          if (list.isEmpty) {
            return Padding(
              padding: const EdgeInsets.only(top: 20),
              child: EmptyState(
                icon: Icons.description_outlined,
                title: context.t(all.isEmpty ? 'docs.empty_title' : 'common.no_results'),
                text: context.t(all.isEmpty ? 'docs.empty_text' : 'docs.empty_filter'),
              ),
            );
          }
          final d = list[i - header.length];
          return Reveal(
            key: ValueKey(d.id),
            index: i - header.length,
            child: Padding(padding: const EdgeInsets.only(bottom: 10), child: _DocTile(doc: d)),
          );
        },
      ),
      Positioned(
        right: 0,
        bottom: context.navSpace - 12,
        child: AppButton(
          label: context.t('docs.new'),
          icon: Icons.add_rounded,
          expanded: false,
          onPressed: () => pushPage(context, const DocumentFormScreen()),
        ),
      ),
    ]);
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.icon, required this.color, required this.label, required this.minor});
  final IconData icon;
  final Color color;
  final String label;
  final int minor;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return GlassCard(
      padding: const EdgeInsets.all(14),
      radius: 22,
      child: Row(children: [
        IconBadge(icon: icon, color: color, size: 40),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5, fontWeight: FontWeight.w600)),
            const SizedBox(height: 2),
            AmountText(minor, size: 19),
          ]),
        ),
      ]),
    );
  }
}

class _DocTile extends StatelessWidget {
  const _DocTile({required this.doc});
  final Doc doc;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final color = docStatusColor(context, doc);
    final party = s.contractor(doc.contractorId)?.name ?? '—';
    final dates = [context.date(doc.issueDate), if (doc.dueDate != null) context.t('docs.due', {'date': context.date(doc.dueDate)})].join(' · ');
    return GlassCard(
      blur: false,
      padding: const EdgeInsets.all(14),
      onTap: () => pushPage(context, DocumentDetailScreen(docId: doc.id)),
      child: Row(children: [
        IconBadge(
          icon: doc.type == DocType.invoice ? Icons.receipt_long_rounded : Icons.assignment_turned_in_rounded,
          color: doc.outgoing ? g.accent : g.warning,
          size: 44,
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(child: Text(doc.number, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
              const SizedBox(width: 8),
              Flexible(
                flex: 0,
                child: Text(context.money(doc.total), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: doc.status == DocStatus.cancelled ? g.textDim : g.text, fontWeight: FontWeight.w800, fontSize: 14.5, decoration: doc.status == DocStatus.cancelled ? TextDecoration.lineThrough : null)),
              ),
            ]),
            const SizedBox(height: 2),
            Row(children: [
              Icon(doc.outgoing ? Icons.call_made_rounded : Icons.call_received_rounded, size: 14, color: g.textDim),
              const SizedBox(width: 4),
              Expanded(child: Text(party, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 13))),
            ]),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: Text(dates, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12))),
              const SizedBox(width: 8),
              Flexible(flex: 0, child: StatusPill(label: context.docStatusName(doc), color: color)),
            ]),
          ]),
        ),
      ]),
    );
  }
}
