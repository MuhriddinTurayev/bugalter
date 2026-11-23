import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

/// Creates or edits a contractor. Pops with the saved [Contractor] so callers (the document form) can select it.
class ContractorFormScreen extends StatefulWidget {
  const ContractorFormScreen({super.key, this.contractor});
  final Contractor? contractor;

  @override
  State<ContractorFormScreen> createState() => _ContractorFormScreenState();
}

class _ContractorFormScreenState extends State<ContractorFormScreen> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name, _stir, _phone, _email, _address, _bankName, _bankAccount, _mfo, _notes;
  late ContractorType _type;
  late bool _active;

  @override
  void initState() {
    super.initState();
    final c = widget.contractor;
    _name = TextEditingController(text: c?.name ?? '');
    _stir = TextEditingController(text: c?.stir ?? '');
    _phone = TextEditingController(text: c?.phone ?? '');
    _email = TextEditingController(text: c?.email ?? '');
    _address = TextEditingController(text: c?.address ?? '');
    _bankName = TextEditingController(text: c?.bankName ?? '');
    _bankAccount = TextEditingController(text: c?.bankAccount ?? '');
    _mfo = TextEditingController(text: c?.mfo ?? '');
    _notes = TextEditingController(text: c?.notes ?? '');
    _type = c?.type ?? ContractorType.customer;
    _active = c?.active ?? true;
  }

  @override
  void dispose() {
    for (final c in [_name, _stir, _phone, _email, _address, _bankName, _bankAccount, _mfo, _notes]) {
      c.dispose();
    }
    super.dispose();
  }

  String? _digits(String? v, int len, String error) {
    final s = (v ?? '').replaceAll(RegExp(r'\s'), '');
    if (s.isEmpty) return null;
    return RegExp('^\\d{$len}\$').hasMatch(s) ? null : error;
  }

  void _save() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final store = context.readStore;
    final c = widget.contractor ??
        Contractor(id: store.nextId(), name: '');
    c
      ..name = _name.text.trim()
      ..stir = _stir.text.trim()
      ..type = _type
      ..phone = _phone.text.trim()
      ..email = _email.text.trim()
      ..address = _address.text.trim()
      ..bankName = _bankName.text.trim()
      ..bankAccount = _bankAccount.text.trim()
      ..mfo = _mfo.text.trim()
      ..notes = _notes.text.trim()
      ..active = _active;
    if (context.attempt(() => store.saveContractor(c))) {
      context.toast(context.t('common.saved'));
      Navigator.of(context).pop(c);
    }
  }

  String _typeName(ContractorType t) => context.t('contractors.type_${t.name}');

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final editing = widget.contractor;
    final docs = editing == null ? <Doc>[] : s.docs.where((d) => d.contractorId == editing.id && d.status != DocStatus.cancelled).toList();
    final receivable = docs.where((d) => d.open && d.outgoing).fold(0, (a, d) => a + d.total);
    final payable = docs.where((d) => d.open && !d.outgoing).fold(0, (a, d) => a + d.total);
    final turnover = docs.where((d) => d.status == DocStatus.paid).fold(0, (a, d) => a + d.total);

    Widget section(String key) => Padding(padding: const EdgeInsets.fromLTRB(4, 18, 4, 10), child: Text(context.t(key), style: TextStyle(color: g.textDim, fontSize: 13, fontWeight: FontWeight.w800, letterSpacing: .4)));

    return PageScaffold(
      title: context.t(editing == null ? 'contractors.new' : 'contractors.edit'),
      maxWidth: 720,
      bottom: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720),
            child: AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _save),
          ),
        ),
      ),
      body: Form(
        key: _form,
        child: ListView(padding: EdgeInsets.fromLTRB(context.isWide ? 24 : 16, 0, context.isWide ? 24 : 16, 24), children: [
          if (editing != null)
            Reveal(
              child: GlassCard(
                margin: const EdgeInsets.only(top: 4),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Avatar(name: editing.name, size: 40),
                    const SizedBox(width: 12),
                    Expanded(child: Text(context.t('contractors.stats'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16))),
                  ]),
                  const SizedBox(height: 8),
                  InfoLine(context.t('contractors.docs_count'), '${docs.length}'),
                  InfoLine(context.t('contractors.turnover'), context.money(turnover)),
                  InfoLine(context.t('contractors.open_receivable'), context.money(receivable), color: receivable > 0 ? g.success : null),
                  InfoLine(context.t('contractors.open_payable'), context.money(payable), color: payable > 0 ? g.warning : null),
                ]),
              ),
            ),
          section('contractors.section_main'),
          GlassField(
            controller: _name,
            label: context.t('common.name'),
            icon: Icons.business_rounded,
            textCapitalization: TextCapitalization.words,
            textInputAction: TextInputAction.next,
            validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null,
          ),
          const SizedBox(height: 12),
          GlassField(
            controller: _stir,
            label: context.t('contractors.stir'),
            icon: Icons.badge_outlined,
            keyboardType: TextInputType.number,
            textInputAction: TextInputAction.next,
            validator: (v) => _digits(v, 9, context.t('contractors.stir_invalid')),
          ),
          const SizedBox(height: 12),
          PickerField<ContractorType>(
            label: context.t('contractors.type'),
            icon: Icons.swap_horiz_rounded,
            value: _type,
            options: [for (final t in ContractorType.values) (t, _typeName(t))],
            onChanged: (v) => setState(() => _type = v ?? _type),
          ),
          section('contractors.section_contact'),
          GlassField(controller: _phone, label: context.t('common.phone'), icon: Icons.phone_outlined, keyboardType: TextInputType.phone, textInputAction: TextInputAction.next),
          const SizedBox(height: 12),
          GlassField(
            controller: _email,
            label: context.t('common.email'),
            icon: Icons.mail_outline_rounded,
            keyboardType: TextInputType.emailAddress,
            textInputAction: TextInputAction.next,
            validator: (v) {
              final s = (v ?? '').trim();
              if (s.isEmpty) return null;
              return RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(s) ? null : context.t('contractors.email_invalid');
            },
          ),
          const SizedBox(height: 12),
          GlassField(controller: _address, label: context.t('common.address'), icon: Icons.place_outlined, maxLines: 2, textCapitalization: TextCapitalization.sentences),
          section('contractors.section_bank'),
          GlassField(controller: _bankName, label: context.t('contractors.bank_name'), icon: Icons.account_balance_outlined, textInputAction: TextInputAction.next),
          const SizedBox(height: 12),
          GlassField(controller: _bankAccount, label: context.t('contractors.bank_account'), icon: Icons.credit_card_outlined, keyboardType: TextInputType.number, textInputAction: TextInputAction.next),
          const SizedBox(height: 12),
          GlassField(
            controller: _mfo,
            label: context.t('contractors.mfo'),
            icon: Icons.pin_outlined,
            keyboardType: TextInputType.number,
            validator: (v) => _digits(v, 5, context.t('contractors.mfo_invalid')),
          ),
          const SizedBox(height: 16),
          GlassField(controller: _notes, label: context.t('common.notes'), icon: Icons.notes_rounded, maxLines: 3, textCapitalization: TextCapitalization.sentences),
          const SizedBox(height: 16),
          GlassCard(
            blur: false,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('contractors.active_switch'), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 15)),
                  const SizedBox(height: 2),
                  Text(context.t('contractors.active_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                ]),
              ),
              Switch(value: _active, onChanged: (v) => setState(() => _active = v)),
            ]),
          ),
        ]),
      ),
    );
  }
}
