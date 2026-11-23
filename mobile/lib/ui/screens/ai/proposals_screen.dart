import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/assistant.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

/// Category suggestions of the AI accountant for transactions without a category. Nothing changes until a person applies it.
class ProposalsScreen extends StatefulWidget {
  const ProposalsScreen({super.key});

  @override
  State<ProposalsScreen> createState() => _ProposalsScreenState();
}

class _ProposalsScreenState extends State<ProposalsScreen> {
  final _list = GlobalKey<AnimatedListState>();
  late final List<Proposal> _items;
  bool _showEmpty = false;

  @override
  void initState() {
    super.initState();
    _items = Assistant(context.readStore).proposals();
    _showEmpty = _items.isEmpty;
  }

  void _afterRemove() {
    if (_items.isEmpty) {
      Future<void>.delayed(const Duration(milliseconds: 420), () {
        if (mounted) setState(() => _showEmpty = true);
      });
    }
  }

  void _removeAt(int i) {
    final removed = _items.removeAt(i);
    _list.currentState?.removeItem(
      i,
      (ctx, anim) => SizeTransition(sizeFactor: anim, child: FadeTransition(opacity: anim, child: _Card(proposal: removed, onApply: null, onDecline: null))),
      duration: const Duration(milliseconds: 380),
    );
    setState(() {});
    _afterRemove();
  }

  void _applyOne(Proposal p) {
    final s = context.readStore;
    final t = s.txns.where((e) => e.id == p.txnId).firstOrNull;
    if (t != null) {
      t.categoryId = p.categoryId;
      s.logAi('proposal.applied', '${t.id}');
      s.changed();
    }
  }

  void _apply(int i) {
    _applyOne(_items[i]);
    _removeAt(i);
    context.toast(context.t('ai.prop.applied'));
  }

  void _decline(int i) {
    final s = context.readStore;
    s.declinedProposals.add(_items[i].txnId);
    s.logAi('proposal.declined', '${_items[i].txnId}');
    s.changed();
    _removeAt(i);
  }

  void _applyAll() {
    final n = _items.length;
    for (var i = _items.length - 1; i >= 0; i--) {
      _applyOne(_items[i]);
      _removeAt(i);
    }
    context.toast(context.t('ai.prop.applied_n', {'n': n}));
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    context.store; // rebuild on language change
    return PageScaffold(
      title: context.t('ai.prop.title'),
      subtitle: context.t('ai.prop.subtitle', {'n': _items.length}),
      body: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16),
        child: Column(children: [
          GlassCard(
            blur: false,
            tint: g.accent2.withValues(alpha: .12),
            padding: const EdgeInsets.all(14),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(Icons.auto_awesome_rounded, color: g.accent2, size: 20),
              const SizedBox(width: 10),
              Expanded(child: Text(context.t('ai.prop.note'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35))),
            ]),
          ),
          AnimatedSize(
            duration: const Duration(milliseconds: 300),
            curve: Curves.easeOutCubic,
            child: _items.isEmpty ? const SizedBox(width: double.infinity) : Padding(padding: const EdgeInsets.only(top: 12), child: AppButton(label: context.t('ai.prop.apply_all'), icon: Icons.done_all_rounded, onPressed: _applyAll)),
          ),
          const SizedBox(height: 12),
          Expanded(
            child: _showEmpty
                ? EmptyState(icon: Icons.task_alt_rounded, title: context.t('ai.prop.empty'), text: context.t('ai.prop.empty_text'))
                : AnimatedList(
                    key: _list,
                    initialItemCount: _items.length,
                    padding: const EdgeInsets.only(bottom: 28),
                    itemBuilder: (ctx, i, anim) {
                      if (i >= _items.length) return const SizedBox.shrink();
                      return SizeTransition(
                        sizeFactor: CurvedAnimation(parent: anim, curve: Curves.easeOutCubic),
                        child: FadeTransition(opacity: anim, child: _Card(proposal: _items[i], onApply: () => _apply(i), onDecline: () => _decline(i))),
                      );
                    },
                  ),
          ),
        ]),
      ),
    );
  }
}

class _Card extends StatelessWidget {
  const _Card({required this.proposal, required this.onApply, required this.onDecline});
  final Proposal proposal;
  final VoidCallback? onApply, onDecline;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final t = s.txns.where((e) => e.id == proposal.txnId).firstOrNull;
    final cat = s.category(proposal.categoryId);
    if (t == null) return const SizedBox.shrink();
    final income = t.type == TxType.income;
    final who = s.contractor(t.contractorId)?.name;
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: GlassCard(
        blur: false,
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            IconBadge(icon: income ? Icons.south_west_rounded : Icons.north_east_rounded, color: income ? g.success : g.danger, size: 42),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(t.description.isEmpty ? context.t('ai.prop.no_desc') : t.description, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 15)),
                const SizedBox(height: 2),
                Text([context.dateTime(t.occurredAt), ?who].join(' · '), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
              ]),
            ),
            const SizedBox(width: 8),
            Flexible(
              flex: 0,
              child: ConstrainedBox(
                constraints: BoxConstraints(maxWidth: context.width * .34),
                child: FittedBox(fit: BoxFit.scaleDown, child: Text(context.money(t.signed, sign: true), style: TextStyle(color: income ? g.success : g.text, fontWeight: FontWeight.w800, fontSize: 15))),
              ),
            ),
          ]),
          const SizedBox(height: 12),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: g.accent.withValues(alpha: .10), borderRadius: BorderRadius.circular(16), border: Border.all(color: g.accent.withValues(alpha: .3))),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(Icons.auto_awesome_rounded, color: g.accent, size: 18),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.categoryName(cat), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                  const SizedBox(height: 2),
                  Text(context.t(proposal.reasonKey), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
                ]),
              ),
            ]),
          ),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: AppButton(label: context.t('ai.prop.decline'), icon: Icons.close_rounded, kind: ButtonKind.glass, compact: true, onPressed: onDecline)),
            const SizedBox(width: 10),
            Expanded(child: AppButton(label: context.t('ai.prop.apply'), icon: Icons.check_rounded, compact: true, onPressed: onApply)),
          ]),
        ]),
      ),
    );
  }
}
