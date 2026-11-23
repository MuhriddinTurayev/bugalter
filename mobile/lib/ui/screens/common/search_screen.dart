import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import '../bank/transaction_form.dart';
import '../contractors/contractor_form.dart';
import '../documents/document_detail.dart';

/// Global search across contractors, documents and transactions.
class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});

  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _c = TextEditingController();
  String _q = '';

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  bool _match(String? s) => s != null && s.toLowerCase().contains(_q);

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final q = _q.trim();
    final contractors = q.isEmpty ? <Contractor>[] : s.contractors.where((c) => _match(c.name) || _match(c.stir) || _match(c.phone)).take(8).toList();
    final docs = q.isEmpty ? <Doc>[] : s.docs.where((d) => _match(d.number) || _match(s.contractor(d.contractorId)?.name) || _match(d.notes)).take(8).toList();
    final txns = q.isEmpty
        ? <Txn>[]
        : s.txnsSorted().where((t) => _match(t.description) || _match(t.reference) || _match(s.contractor(t.contractorId)?.name) || _match(context.categoryName(s.category(t.categoryId)))).take(8).toList();
    final nothing = q.isNotEmpty && contractors.isEmpty && docs.isEmpty && txns.isEmpty;

    return PageScaffold(
      title: context.t('nav.search'),
      body: ListView(padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace), children: [
        GlassField(
          controller: _c,
          autofocus: true,
          hint: context.t('search.hint'),
          icon: Icons.search_rounded,
          textInputAction: TextInputAction.search,
          onChanged: (v) => setState(() => _q = v.trim().toLowerCase()),
          suffix: q.isEmpty
              ? null
              : IconButton(
                  icon: Icon(Icons.close_rounded, color: g.textDim),
                  onPressed: () => setState(() {
                    _c.clear();
                    _q = '';
                  }),
                ),
        ),
        const SizedBox(height: 18),
        if (q.isEmpty)
          EmptyState(icon: Icons.manage_search_rounded, title: context.t('search.start'))
        else if (nothing)
          EmptyState(icon: Icons.search_off_rounded, title: context.t('common.no_results')),
        if (contractors.isNotEmpty) ...[
          TileSection(
            title: context.t('search.contractors'),
            children: [
              for (final c in contractors)
                SoftTile(
                  onTap: () => pushPage(context, ContractorFormScreen(contractor: c)),
                  child: RowTile(
                    leading: Avatar(name: c.name, size: 40),
                    title: c.name,
                    subtitle: [c.stir, c.phone].where((e) => e.isNotEmpty).join(' · '),
                    trailing: Icon(Icons.chevron_right_rounded, color: g.textDim),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 20),
        ],
        if (docs.isNotEmpty) ...[
          TileSection(
            title: context.t('search.documents'),
            children: [
              for (final d in docs)
                SoftTile(
                  onTap: () => pushPage(context, DocumentDetailScreen(docId: d.id)),
                  child: RowTile(
                    leading: IconBadge(icon: d.type == DocType.invoice ? Icons.receipt_long_rounded : Icons.assignment_turned_in_rounded, size: 40, color: g.info),
                    title: d.number,
                    subtitle: s.contractor(d.contractorId)?.name,
                    trailing: Text(context.money(d.total), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 13.5)),
                  ),
                ),
            ],
          ),
          const SizedBox(height: 20),
        ],
        if (txns.isNotEmpty)
          TileSection(
            title: context.t('search.transactions'),
            children: [
              for (final t in txns)
                SoftTile(
                  onTap: () => pushPage(context, TransactionFormScreen(txn: t)),
                  child: RowTile(
                    leading: IconBadge(icon: t.type == TxType.income ? Icons.south_west_rounded : Icons.north_east_rounded, size: 40, color: t.type == TxType.income ? g.success : g.danger),
                    title: t.description.isEmpty ? context.categoryName(s.category(t.categoryId)) : t.description,
                    subtitle: context.date(t.occurredAt),
                    trailing: Text(context.money(t.signed, sign: true), style: TextStyle(color: t.type == TxType.income ? g.success : g.text, fontWeight: FontWeight.w700, fontSize: 13.5)),
                  ),
                ),
            ],
          ),
      ]),
    );
  }
}
