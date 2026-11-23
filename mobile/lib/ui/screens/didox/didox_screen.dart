import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/legal.dart';
import '../../widgets/glass.dart';
import '../legal/legal_common.dart';
import '../server_gate.dart';
import 'didox_doc_screen.dart';

/// The company's documents in Didox (contracts, invoices, acts): incoming and outgoing, what waits for our signature, search.
/// The live documents are read by the desktop agent on the server; here they are demo documents kept on the device.
class DidoxScreen extends StatefulWidget {
  const DidoxScreen({super.key});

  @override
  State<DidoxScreen> createState() => _DidoxScreenState();
}

class _DidoxScreenState extends State<DidoxScreen> {
  static const _filters = ['all', 'open', 'signed', 'rejected', 'deleted'];
  static const _dirs = ['all', 'in', 'out'];

  final _search = TextEditingController();
  int _filter = 0;
  int _dir = 0;
  String _q = '';

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  bool _match(DidoxDoc d) {
    final q = _q.trim().toLowerCase();
    if (q.isEmpty) return true;
    return [d.number, d.counterparty, d.title, d.tin, d.contract].any((e) => e.toLowerCase().contains(q));
  }

  Future<void> _signAll(LegalData data) async {
    final s = context.readStore;
    final list = data.toSign;
    final sum = list.fold<int>(0, (a, d) => a + (d.amount ?? 0));
    final ok = await confirmDialog(
      context,
      title: context.t('didox.sign_all_confirm_title'),
      message: context.t('didox.sign_all_confirm', {'n': list.length, 'sum': context.money(sum)}),
      confirmLabel: context.t('didox.sign_button'),
    );
    if (!ok || !mounted) return;
    var n = 0;
    if (runLegal(context, () => n = data.signAll(s))) context.toast(context.t('didox.sign_all_done', {'n': n}));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final data = LegalData.of(s);
    final gate = serverGate(context, title: context.t('nav.didox'), synced: data.synced, allowed: data.canSeeDidox);
    if (gate != null) return gate;
    final live = data.live;
    final all = data.sorted();
    final filter = _filters[_filter];
    final dir = _dirs[_dir];
    final shown = all.where((d) {
      if (dir != 'all' && d.direction != dir) return false;
      if (filter == 'open' && !d.isOpen) return false;
      if (filter != 'all' && filter != 'open' && d.status != filter) return false;
      return _match(d);
    }).toList();
    final toSign = data.toSign;
    final waiting = live ? all.where((d) => d.waitsForUs).toList() : const <DidoxDoc>[];

    return PageScaffold(
      title: context.t('nav.didox'),
      subtitle: context.t('didox.subtitle'),
      actions: [
        GlassIconButton(icon: Icons.refresh_rounded, tooltip: context.t('didox.refresh'), onTap: () async {
          if (live) {
            // the real company: read the server again
            final st = context.readStore;
            await st.syncNow();
            if (context.mounted) context.toast(context.t(st.syncError == null ? 'srv.synced_ok' : (st.offline ? 'srv.err_network' : 'srv.err_server')));
            return;
          }
          setState(() {});
          context.toast(context.t('didox.refreshed'));
        }),
      ],
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace),
        children: [
          const DemoNote(),
          const SizedBox(height: 10),
          SoftTile(
            padding: const EdgeInsets.all(14),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(Icons.verified_user_outlined, size: 18, color: g.warning),
              const SizedBox(width: 10),
              Expanded(child: Text(context.t(live ? 'didox.live_note' : 'didox.sandbox_note'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3))),
            ]),
          ),
          const SizedBox(height: 16),
          if (waiting.isNotEmpty) ...[
            TileSection(
              title: context.t('didox.status.waiting_me'),
              subtitle: context.t('didox.live_sign_hint'),
              children: [for (final d in waiting) _DocTile(doc: d, compact: true)],
            ),
            const SizedBox(height: 20),
          ],
          if (toSign.isNotEmpty) ...[
            TileSection(
              title: context.t('didox.sign_all_title'),
              subtitle: context.t('didox.sign_all_hint'),
              children: [for (final d in toSign) _DocTile(doc: d, compact: true)],
            ),
            const SizedBox(height: 12),
            AppButton(label: context.t('didox.sign_all_button', {'n': toSign.length}), icon: Icons.draw_rounded, onPressed: () => _signAll(data)),
            const SizedBox(height: 20),
          ],
          GlassField(
            controller: _search,
            hint: context.t('common.search'),
            icon: Icons.search_rounded,
            onChanged: (v) => setState(() => _q = v),
            suffix: _q.isEmpty
                ? null
                : IconButton(
                    icon: Icon(Icons.close_rounded, color: g.textDim),
                    onPressed: () => setState(() {
                      _search.clear();
                      _q = '';
                    }),
                  ),
          ),
          const SizedBox(height: 12),
          SegmentedGlass(labels: [for (final d in _dirs) context.t('didox.direction.$d')], index: _dir, onChanged: (i) => setState(() => _dir = i)),
          const SizedBox(height: 10),
          ChipBar(
            labels: [for (final f in _filters) '${context.t('didox.filter.$f')} ${data.countStatus(f)}'],
            index: _filter,
            onChanged: (i) => setState(() => _filter = i),
          ),
          const SizedBox(height: 14),
          if (shown.isEmpty)
            EmptyState(
              icon: Icons.folder_open_rounded,
              title: context.t(all.isEmpty ? 'didox.empty' : 'didox.empty_filter'),
              text: all.isEmpty ? context.t('didox.empty_hint') : null,
            )
          else
            for (var i = 0; i < shown.length; i++)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Reveal(index: i, dy: 10, child: _DocTile(doc: shown[i])),
              ),
        ],
      ),
    );
  }
}

/// One Didox document as its own tile: kind and number, the other party, amount, status, who has signed.
class _DocTile extends StatelessWidget {
  const _DocTile({required this.doc, this.compact = false});
  final DidoxDoc doc;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final color = didoxStatusColor(g, doc.status);
    final amount = doc.amount;
    final kind = didoxKindLabel(context, doc);
    return SoftTile(
      padding: const EdgeInsets.all(14),
      onTap: () => pushPage(context, DidoxDocScreen(docId: doc.id)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          IconBadge(icon: didoxKindIcon(doc.kind), color: color, size: 40),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(doc.number.isEmpty ? kind : '$kind № ${doc.number}', maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 15, fontWeight: FontWeight.w800)),
              if (doc.title.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(doc.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5))),
            ]),
          ),
          if (amount != null) ...[
            const SizedBox(width: 10),
            Flexible(flex: 0, child: ConstrainedBox(constraints: BoxConstraints(maxWidth: context.width * .34), child: FittedBox(fit: BoxFit.scaleDown, child: Text(context.money(amount), style: TextStyle(color: g.text, fontSize: 14, fontWeight: FontWeight.w800)))))
          ],
        ]),
        const SizedBox(height: 10),
        Text(
          '${context.t(doc.incoming ? 'didox.from' : 'didox.to')}: ${doc.counterparty.isEmpty ? '—' : doc.counterparty}${doc.tin.isEmpty ? '' : ' · ${doc.tin}'}',
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: TextStyle(color: g.textDim, fontSize: 13),
        ),
        if (!compact) ...[
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            StatusPill(label: didoxStatusLabel(context, doc), color: color),
            if (doc.weSigned) StatusPill(label: context.t('didox.we_signed'), color: g.success, icon: Icons.check_rounded),
            if (doc.partnerSigned) StatusPill(label: context.t('didox.partner_signed'), color: g.info, icon: Icons.check_rounded),
            Text(context.date(doc.dateOrNull) + (doc.contract.isEmpty ? '' : ' · ${context.t('didox.contract', {'number': doc.contract})}'), style: TextStyle(color: g.textDim, fontSize: 12)),
          ]),
        ] else
          Padding(padding: const EdgeInsets.only(top: 6), child: Text(context.date(doc.dateOrNull), style: TextStyle(color: g.textDim, fontSize: 12))),
      ]),
    );
  }
}
