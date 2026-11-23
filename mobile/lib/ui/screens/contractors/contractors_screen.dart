import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import 'contractor_form.dart';

class ContractorsScreen extends StatefulWidget {
  const ContractorsScreen({super.key});

  @override
  State<ContractorsScreen> createState() => _ContractorsScreenState();
}

class _ContractorsScreenState extends State<ContractorsScreen> {
  final _q = TextEditingController();
  int _filter = 0; // 0 all, 1 customer, 2 supplier, 3 both, 4 inactive

  @override
  void dispose() {
    _q.dispose();
    super.dispose();
  }

  bool _match(Contractor c) {
    final q = _q.text.trim().toLowerCase();
    if (q.isNotEmpty && !('${c.name} ${c.stir} ${c.phone} ${c.email}'.toLowerCase().contains(q))) return false;
    return switch (_filter) {
      1 => c.active && c.type == ContractorType.customer,
      2 => c.active && c.type == ContractorType.supplier,
      3 => c.active && c.type == ContractorType.both,
      4 => !c.active,
      _ => true,
    };
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final h = context.isWide ? 24.0 : 16.0;
    final list = s.contractors.where(_match).toList()
      ..sort((a, b) {
        if (a.active != b.active) return a.active ? -1 : 1;
        return a.name.toLowerCase().compareTo(b.name.toLowerCase());
      });

    return PageScaffold(
      title: context.t('nav.contractors'),
      subtitle: context.t('common.items', {'n': s.contractors.length}),
      fab: AppButton(
        label: context.t('common.add'),
        icon: Icons.add_rounded,
        expanded: false,
        onPressed: () => pushPage(context, const ContractorFormScreen()),
      ),
      body: Column(children: [
        Padding(
          padding: EdgeInsets.fromLTRB(h, 4, h, 10),
          child: GlassField(
            controller: _q,
            hint: context.t('common.search'),
            icon: Icons.search_rounded,
            onChanged: (_) => setState(() {}),
            suffix: _q.text.isEmpty ? null : IconButton(icon: Icon(Icons.close_rounded, color: g.textDim, size: 20), onPressed: () => setState(_q.clear)),
          ),
        ),
        Padding(
          padding: EdgeInsets.symmetric(horizontal: h),
          child: ChipBar(
            labels: [
              context.t('common.all'),
              context.t('contractors.type_customer'),
              context.t('contractors.type_supplier'),
              context.t('contractors.type_both'),
              context.t('common.inactive'),
            ],
            index: _filter,
            onChanged: (i) => setState(() => _filter = i),
          ),
        ),
        const SizedBox(height: 10),
        Expanded(
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 250),
            child: list.isEmpty
                ? EmptyState(
                    key: ValueKey('empty$_filter${_q.text}'),
                    icon: Icons.groups_2_outlined,
                    title: context.t(s.contractors.isEmpty ? 'contractors.empty_title' : 'common.no_results'),
                    text: s.contractors.isEmpty ? context.t('contractors.empty_text') : null,
                  )
                : ListView.builder(
                    key: ValueKey('list$_filter'),
                    padding: EdgeInsets.fromLTRB(h, 0, h, 96),
                    itemCount: list.length,
                    itemBuilder: (context, i) => Reveal(
                      key: ValueKey(list[i].id),
                      index: i,
                      child: Padding(padding: const EdgeInsets.only(bottom: 10), child: _ContractorTile(c: list[i])),
                    ),
                  ),
          ),
        ),
      ]),
    );
  }
}

class _ContractorTile extends StatelessWidget {
  const _ContractorTile({required this.c});
  final Contractor c;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final open = s.docs.where((d) => d.contractorId == c.id && d.open);
    final owes = open.where((d) => d.outgoing).fold(0, (a, d) => a + d.total);
    final owed = open.where((d) => !d.outgoing).fold(0, (a, d) => a + d.total);
    final sub = [if (c.stir.isNotEmpty) c.stir, if (c.phone.isNotEmpty) c.phone].join(' · ');
    return Opacity(
      opacity: c.active ? 1 : .6,
      child: GlassCard(
        blur: false,
        padding: const EdgeInsets.all(14),
        onTap: () => pushPage(context, ContractorFormScreen(contractor: c)),
        child: Row(children: [
          Avatar(name: c.name, size: 46),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(c.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15.5)),
              if (sub.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(sub, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5))),
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Wrap(spacing: 6, runSpacing: 6, children: [
                  StatusPill(label: context.t('contractors.type_${c.type.name}'), color: g.accent2),
                  if (owes > 0) StatusPill(label: context.t('contractors.owes_us', {'amount': context.compact(owes)}), color: g.success, icon: Icons.south_west_rounded),
                  if (owed > 0) StatusPill(label: context.t('contractors.we_owe', {'amount': context.compact(owed)}), color: g.warning, icon: Icons.north_east_rounded),
                ]),
              ),
            ]),
          ),
          const SizedBox(width: 6),
          Switch(value: c.active, onChanged: (_) => s.toggleContractor(c)),
        ]),
      ),
    );
  }
}
