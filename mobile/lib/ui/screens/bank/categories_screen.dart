import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import 'category_icons.dart';

/// Income and expense categories: the system ones (translated) and the company's own.
class CategoriesScreen extends StatefulWidget {
  const CategoriesScreen({super.key});

  @override
  State<CategoriesScreen> createState() => _CategoriesScreenState();
}

class _CategoriesScreenState extends State<CategoriesScreen> {
  int _seg = 1; // 0 income, 1 expense

  TxType get _type => _seg == 0 ? TxType.income : TxType.expense;

  Future<void> _add() async {
    final name = await showGlassSheet<String>(context, builder: (_) => _NewCategorySheet(type: _type));
    if (name != null && name.isNotEmpty && mounted) {
      final store = context.readStore;
      store.saveCategory(Category(id: store.nextId(), name: name, type: _type));
      context.toast(context.t('common.saved'));
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final all = s.categories.where((c) => c.type == _type).toList();
    final system = all.where((c) => c.key != null).toList();
    final custom = all.where((c) => c.key == null).toList();
    var i = 0;

    Widget tile(Category c) => Padding(
          padding: const EdgeInsets.only(bottom: 8),
          child: Reveal(
            index: i++,
            dy: 10,
            child: GlassCard(
              blur: false,
              padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
              child: Row(children: [
                IconBadge(icon: categoryIcon(c), size: 40, color: _type == TxType.income ? g.success : g.accent2),
                const SizedBox(width: 12),
                Expanded(child: Text(context.categoryName(c), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: c.active ? g.text : g.textDim, fontWeight: FontWeight.w700, fontSize: 15))),
                Switch(value: c.active, onChanged: (_) => s.toggleCategory(c)),
              ]),
            ),
          ),
        );

    return PageScaffold(
      title: context.t('bank.categories'),
      fab: AppButton(label: context.t('bank.new_category'), icon: Icons.add_rounded, expanded: false, onPressed: _add),
      body: ListView(
        padding: EdgeInsets.fromLTRB(0, 8, 0, 100 + MediaQuery.viewPaddingOf(context).bottom),
        children: [
          SegmentedGlass(labels: [context.t('txn.income'), context.t('txn.expense')], index: _seg, onChanged: (v) => setState(() => _seg = v)),
          const SizedBox(height: 14),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 300),
            child: Column(
              key: ValueKey(_seg),
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                SectionTitle(context.t('bank.custom_category')),
                if (custom.isEmpty)
                  Padding(padding: const EdgeInsets.fromLTRB(4, 0, 4, 12), child: Text(context.t('bank.no_custom'), style: TextStyle(color: g.textDim)))
                else
                  ...custom.map(tile),
                const SizedBox(height: 8),
                SectionTitle(context.t('bank.system_category')),
                ...system.map(tile),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _NewCategorySheet extends StatefulWidget {
  const _NewCategorySheet({required this.type});
  final TxType type;

  @override
  State<_NewCategorySheet> createState() => _NewCategorySheetState();
}

class _NewCategorySheetState extends State<_NewCategorySheet> {
  final _form = GlobalKey<FormState>();
  final _name = TextEditingController();

  @override
  void dispose() {
    _name.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Form(
      key: _form,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('bank.new_category'), style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text(context.t(widget.type == TxType.income ? 'txn.income' : 'txn.expense'), style: TextStyle(color: g.textDim)),
        const SizedBox(height: 14),
        GlassField(
          controller: _name,
          label: context.t('bank.category_name'),
          icon: Icons.category_outlined,
          autofocus: true,
          textCapitalization: TextCapitalization.sentences,
          validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null,
          onSubmitted: (_) => _submit(),
        ),
        const SizedBox(height: 16),
        AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _submit),
      ]),
    );
  }

  void _submit() {
    if (_form.currentState?.validate() ?? false) Navigator.pop(context, _name.text.trim());
  }
}
