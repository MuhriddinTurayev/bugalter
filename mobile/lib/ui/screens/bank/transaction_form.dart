import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

/// Create or edit an income / expense operation.
class TransactionFormScreen extends StatefulWidget {
  const TransactionFormScreen({super.key, this.txn, this.type});
  final Txn? txn;
  final TxType? type;

  @override
  State<TransactionFormScreen> createState() => _TransactionFormScreenState();
}

class _TransactionFormScreenState extends State<TransactionFormScreen> {
  final _form = GlobalKey<FormState>();
  late TxType _type = widget.txn?.type ?? widget.type ?? TxType.income;
  int? _accountId;
  int? _categoryId;
  int? _contractorId;
  late DateTime _date;
  late TimeOfDay _time;
  late final TextEditingController _amount;
  late final TextEditingController _desc;
  late final TextEditingController _ref;

  @override
  void initState() {
    super.initState();
    final t = widget.txn;
    final store = context.readStore;
    _accountId = t?.accountId ?? store.activeAccounts.firstOrNull?.id;
    _categoryId = t?.categoryId;
    _contractorId = t?.contractorId;
    final at = t?.occurredAt ?? DateTime.now();
    _date = dateOnly(at);
    _time = TimeOfDay(hour: at.hour, minute: at.minute);
    _amount = TextEditingController(text: t == null ? '' : formatSom(t.amountMinor));
    _desc = TextEditingController(text: t?.description ?? '');
    _ref = TextEditingController(text: t?.reference ?? '');
  }

  @override
  void dispose() {
    _amount.dispose();
    _desc.dispose();
    _ref.dispose();
    super.dispose();
  }

  void _setType(TxType t) {
    setState(() {
      _type = t;
      final c = context.readStore.category(_categoryId);
      if (c != null && c.type != t) _categoryId = null;
    });
  }

  Future<void> _pickTime() async {
    final g = context.g;
    final picked = await showTimePicker(
      context: context,
      initialTime: _time,
      builder: (ctx, child) => Theme(
        data: Theme.of(ctx).copyWith(
          colorScheme: Theme.of(ctx).colorScheme.copyWith(primary: g.accent, surface: g.dark ? const Color(0xFF12243A) : Colors.white, onSurface: g.text),
        ),
        child: MediaQuery(data: MediaQuery.of(ctx).copyWith(alwaysUse24HourFormat: true), child: child!),
      ),
    );
    if (picked != null) setState(() => _time = picked);
  }

  void _save() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final store = context.readStore;
    final minor = parseMinor(_amount.text);
    if (minor == null) return;
    final at = DateTime(_date.year, _date.month, _date.day, _time.hour, _time.minute);
    final old = widget.txn;
    final t = old ??
        Txn(id: store.nextId(), accountId: _accountId!, type: _type, amountMinor: minor, occurredAt: at);
    t
      ..accountId = _accountId!
      ..type = _type
      ..amountMinor = minor
      ..occurredAt = at
      ..categoryId = _categoryId
      ..contractorId = _contractorId
      ..description = _desc.text.trim()
      ..reference = _ref.text.trim();
    if (context.attempt(() => store.saveTxn(t))) {
      context.toast(context.t('common.saved'));
      Navigator.of(context).pop();
    }
  }

  Future<void> _delete() async {
    final ok = await confirmDialog(context, title: context.t('bank.delete_txn_title'), message: context.t('bank.delete_txn_text'), confirmLabel: context.t('common.delete'), danger: true);
    if (ok && mounted) {
      context.readStore.deleteTxn(widget.txn!);
      context.toast(context.t('common.deleted'));
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final editing = widget.txn != null;
    final cats = s.categories.where((c) => c.type == _type && (c.active || c.id == _categoryId)).toList();
    final accent = _type == TxType.income ? g.success : g.danger;
    return PageScaffold(
      title: context.t(editing ? 'bank.edit_txn' : 'bank.new_txn'),
      maxWidth: 640,
      actions: [if (editing) GlassIconButton(icon: Icons.delete_outline_rounded, color: g.danger, tooltip: context.t('common.delete'), onTap: _delete)],
      body: Form(
        key: _form,
        child: ListView(
          padding: EdgeInsets.fromLTRB(0, 8, 0, 32 + MediaQuery.viewPaddingOf(context).bottom),
          children: [
            Reveal(child: SegmentedGlass(labels: [context.t('txn.income'), context.t('txn.expense')], index: _type == TxType.income ? 0 : 1, onChanged: (i) => _setType(i == 0 ? TxType.income : TxType.expense))),
            const SizedBox(height: 16),
            Reveal(
              index: 1,
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: Column(children: [
                  Row(children: [
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 300),
                      width: 6,
                      height: 52,
                      decoration: BoxDecoration(color: accent, borderRadius: BorderRadius.circular(3)),
                    ),
                    const SizedBox(width: 12),
                    Expanded(child: MoneyField(controller: _amount)),
                  ]),
                ]),
              ),
            ),
            const SizedBox(height: 14),
            Reveal(
              index: 2,
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: Column(children: [
                  PickerField<int>(
                    label: context.t('bank.account'),
                    icon: Icons.account_balance_wallet_outlined,
                    value: _accountId,
                    options: [for (final a in s.accounts.where((a) => a.active || a.id == _accountId)) (a.id, a.name)],
                    onChanged: (v) => setState(() => _accountId = v),
                    validator: (v) => v == null ? context.t('err.no_account') : null,
                  ),
                  const SizedBox(height: 12),
                  PickerField<int>(
                    label: context.t('bank.category'),
                    icon: Icons.category_outlined,
                    value: _categoryId,
                    allowNone: true,
                    options: [for (final c in cats) (c.id, context.categoryName(c))],
                    onChanged: (v) => setState(() => _categoryId = v),
                  ),
                  const SizedBox(height: 12),
                  PickerField<int>(
                    label: context.t('bank.contractor'),
                    icon: Icons.business_outlined,
                    value: _contractorId,
                    allowNone: true,
                    options: [for (final c in s.contractors.where((c) => c.active || c.id == _contractorId)) (c.id, c.name)],
                    onChanged: (v) => setState(() => _contractorId = v),
                  ),
                ]),
              ),
            ),
            const SizedBox(height: 14),
            Reveal(
              index: 3,
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: Column(children: [
                  LayoutBuilder(builder: (context, c) {
                    final dateField = DateField(label: context.t('common.date'), value: _date, onChanged: (d) => setState(() => _date = d ?? _date));
                    final timeField = GestureDetector(
                      behavior: HitTestBehavior.opaque,
                      onTap: _pickTime,
                      child: InputDecorator(
                        decoration: glassDecoration(context, label: context.t('bank.time'), icon: Icons.schedule_rounded),
                        child: Text('${_time.hour.toString().padLeft(2, '0')}:${_time.minute.toString().padLeft(2, '0')}', style: TextStyle(color: g.text, fontSize: 16)),
                      ),
                    );
                    if (c.maxWidth < 360) return Column(children: [dateField, const SizedBox(height: 12), timeField]);
                    return Row(crossAxisAlignment: CrossAxisAlignment.start, children: [Expanded(flex: 6, child: dateField), const SizedBox(width: 12), Expanded(flex: 4, child: timeField)]);
                  }),
                  const SizedBox(height: 12),
                  GlassField(controller: _desc, label: context.t('bank.description'), icon: Icons.notes_rounded, maxLines: 2, textCapitalization: TextCapitalization.sentences),
                  const SizedBox(height: 12),
                  GlassField(controller: _ref, label: context.t('bank.reference'), icon: Icons.tag_rounded),
                ]),
              ),
            ),
            const SizedBox(height: 20),
            Reveal(index: 4, child: AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _save)),
            if (editing) ...[
              const SizedBox(height: 10),
              Reveal(index: 5, child: AppButton(label: context.t('common.delete'), icon: Icons.delete_outline_rounded, kind: ButtonKind.glass, onPressed: _delete)),
            ],
          ],
        ),
      ),
    );
  }
}
