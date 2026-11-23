import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

/// Create or edit a cash desk or a bank account.
class AccountFormScreen extends StatefulWidget {
  const AccountFormScreen({super.key, this.account});
  final Account? account;

  @override
  State<AccountFormScreen> createState() => _AccountFormScreenState();
}

class _AccountFormScreenState extends State<AccountFormScreen> {
  final _form = GlobalKey<FormState>();
  late AccountType _type = widget.account?.type ?? AccountType.bank;
  late bool _active = widget.account?.active ?? true;
  late final TextEditingController _name = TextEditingController(text: widget.account?.name ?? '');
  late final TextEditingController _bank = TextEditingController(text: widget.account?.bankName ?? '');
  late final TextEditingController _number = TextEditingController(text: widget.account?.accountNumber ?? '');
  late final TextEditingController _opening = TextEditingController(text: widget.account == null || widget.account!.openingMinor == 0 ? '' : formatSom(widget.account!.openingMinor));

  @override
  void dispose() {
    _name.dispose();
    _bank.dispose();
    _number.dispose();
    _opening.dispose();
    super.dispose();
  }

  void _save() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final store = context.readStore;
    final a = widget.account ?? Account(id: store.nextId(), name: '', type: _type);
    a
      ..name = _name.text.trim()
      ..type = _type
      ..bankName = _type == AccountType.bank ? _bank.text.trim() : ''
      ..accountNumber = _type == AccountType.bank ? _number.text.trim() : ''
      ..openingMinor = parseMinor(_opening.text) ?? 0
      ..active = _active;
    if (context.attempt(() => store.saveAccount(a))) {
      context.toast(context.t('common.saved'));
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final editing = widget.account != null;
    return PageScaffold(
      title: context.t(editing ? 'bank.edit_account' : 'bank.new_account'),
      maxWidth: 640,
      body: Form(
        key: _form,
        child: ListView(
          padding: EdgeInsets.fromLTRB(0, 8, 0, 32 + MediaQuery.viewPaddingOf(context).bottom),
          children: [
            Reveal(child: SegmentedGlass(labels: [context.t('bank.type_cash'), context.t('bank.type_bank')], index: _type == AccountType.cash ? 0 : 1, onChanged: (i) => setState(() => _type = i == 0 ? AccountType.cash : AccountType.bank))),
            const SizedBox(height: 16),
            Reveal(
              index: 1,
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: AnimatedSize(
                  duration: const Duration(milliseconds: 280),
                  curve: Curves.easeOutCubic,
                  alignment: Alignment.topCenter,
                  child: Column(children: [
                    GlassField(
                      controller: _name,
                      label: context.t('bank.account_name'),
                      icon: Icons.account_balance_wallet_outlined,
                      textCapitalization: TextCapitalization.sentences,
                      validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null,
                    ),
                    if (_type == AccountType.bank) ...[
                      const SizedBox(height: 12),
                      GlassField(controller: _bank, label: context.t('bank.bank_name'), icon: Icons.account_balance_outlined),
                      const SizedBox(height: 12),
                      GlassField(controller: _number, label: context.t('bank.account_number'), icon: Icons.numbers_rounded, keyboardType: TextInputType.number),
                    ],
                    const SizedBox(height: 12),
                    MoneyField(
                      controller: _opening,
                      label: context.t('bank.opening_balance'),
                      validator: (v) => (v == null || v.trim().isEmpty || parseMinor(v) != null) ? null : context.t('common.invalid'),
                    ),
                  ]),
                ),
              ),
            ),
            const SizedBox(height: 14),
            Reveal(
              index: 2,
              child: GlassCard(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                child: Row(children: [
                  Expanded(child: Text(context.t('common.active'), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 15.5))),
                  Switch(value: _active, onChanged: (v) => setState(() => _active = v)),
                ]),
              ),
            ),
            const SizedBox(height: 20),
            Reveal(index: 3, child: AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _save)),
          ],
        ),
      ),
    );
  }
}
