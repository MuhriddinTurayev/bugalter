import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

class CompanyScreen extends StatefulWidget {
  const CompanyScreen({super.key});

  @override
  State<CompanyScreen> createState() => _CompanyScreenState();
}

class _CompanyScreenState extends State<CompanyScreen> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name, _stir, _address, _phone, _director;
  late String _regime;
  late bool _vat, _budget;

  @override
  void initState() {
    super.initState();
    final c = context.readStore.company;
    _name = TextEditingController(text: c.name);
    _stir = TextEditingController(text: c.stir);
    _address = TextEditingController(text: c.address);
    _phone = TextEditingController(text: c.phone);
    _director = TextEditingController(text: c.director);
    _regime = c.regime;
    _vat = c.vatPayer;
    _budget = c.budgetOrg;
  }

  @override
  void dispose() {
    for (final c in [_name, _stir, _address, _phone, _director]) {
      c.dispose();
    }
    super.dispose();
  }

  void _save() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final s = context.readStore;
    s.company
      ..name = _name.text.trim()
      ..stir = _stir.text.trim()
      ..address = _address.text.trim()
      ..phone = _phone.text.trim()
      ..director = _director.text.trim()
      ..regime = _regime
      ..vatPayer = _vat
      ..budgetOrg = _budget;
    s.saveCompany();
    context.toast(context.t('common.saved'));
    Navigator.of(context).maybePop();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final editable = s.role.canSeeTax;
    Widget switchRow(String title, String hint, bool value, ValueChanged<bool> onChanged) => Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(title, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 15)),
                const SizedBox(height: 2),
                Text(hint, style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
              ]),
            ),
            const SizedBox(width: 10),
            Switch(value: value, onChanged: editable ? onChanged : null),
          ]),
        );
    return PageScaffold(
      title: context.t('settings.company'),
      body: Form(
        key: _form,
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
          keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
          children: [
            if (!editable)
              Padding(
                padding: const EdgeInsets.only(bottom: 14),
                child: GlassCard(blur: false, tint: g.warning.withValues(alpha: .12), child: Text(context.t('company.read_only'), style: TextStyle(color: g.text, fontSize: 13.5))),
              ),
            Reveal(
              child: GlassCard(
                child: Column(children: [
                  GlassField(controller: _name, label: context.t('common.name'), icon: Icons.business_rounded, readOnly: !editable, textCapitalization: TextCapitalization.words, textInputAction: TextInputAction.next, validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null),
                  const SizedBox(height: 12),
                  GlassField(controller: _stir, label: context.t('company.stir'), icon: Icons.badge_outlined, readOnly: !editable, keyboardType: TextInputType.number, textInputAction: TextInputAction.next),
                  const SizedBox(height: 12),
                  GlassField(controller: _director, label: context.t('company.director'), icon: Icons.person_outline_rounded, readOnly: !editable, textCapitalization: TextCapitalization.words, textInputAction: TextInputAction.next),
                  const SizedBox(height: 12),
                  GlassField(controller: _phone, label: context.t('common.phone'), icon: Icons.phone_outlined, readOnly: !editable, keyboardType: TextInputType.phone, textInputAction: TextInputAction.next),
                  const SizedBox(height: 12),
                  GlassField(controller: _address, label: context.t('common.address'), icon: Icons.place_outlined, readOnly: !editable, maxLines: 2),
                ]),
              ),
            ),
            const SizedBox(height: 16),
            Reveal(
              index: 1,
              child: GlassCard(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('company.regime'), style: TextStyle(color: g.textDim, fontSize: 13, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 10),
                  IgnorePointer(
                    ignoring: !editable,
                    child: SegmentedGlass(
                      labels: [context.t('company.regime_general'), context.t('company.regime_turnover')],
                      index: _regime == 'turnover' ? 1 : 0,
                      onChanged: (i) => setState(() => _regime = i == 1 ? 'turnover' : 'general'),
                    ),
                  ),
                  AnimatedSize(
                    duration: const Duration(milliseconds: 280),
                    curve: Curves.easeOutCubic,
                    child: _regime == 'turnover'
                        ? const SizedBox(width: double.infinity)
                        : Padding(padding: const EdgeInsets.only(top: 10), child: switchRow(context.t('company.vat'), context.t('company.vat_hint'), _vat, (v) => setState(() => _vat = v))),
                  ),
                  const SizedBox(height: 4),
                  switchRow(context.t('company.budget'), context.t('company.budget_hint'), _budget, (v) => setState(() => _budget = v)),
                ]),
              ),
            ),
            if (editable) ...[
              const SizedBox(height: 18),
              Reveal(index: 2, child: AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _save)),
            ],
          ],
        ),
      ),
    );
  }
}
