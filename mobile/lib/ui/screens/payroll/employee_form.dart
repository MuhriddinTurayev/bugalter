import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

/// Create or edit an employee.
class EmployeeFormScreen extends StatefulWidget {
  const EmployeeFormScreen({super.key, this.employee});
  final Employee? employee;

  @override
  State<EmployeeFormScreen> createState() => _EmployeeFormScreenState();
}

class _EmployeeFormScreenState extends State<EmployeeFormScreen> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name = TextEditingController(text: widget.employee?.fullName ?? '');
  late final TextEditingController _pinfl = TextEditingController(text: widget.employee?.pinfl ?? '');
  late final TextEditingController _position = TextEditingController(text: widget.employee?.position ?? '');
  late final TextEditingController _department = TextEditingController(text: widget.employee?.department ?? '');
  late final TextEditingController _phone = TextEditingController(text: widget.employee?.phone ?? '');
  late final TextEditingController _salary = TextEditingController(text: widget.employee == null ? '' : formatSom(widget.employee!.salaryMinor));
  late DateTime? _hired = widget.employee?.hiredOn ?? (widget.employee == null ? dateOnly(DateTime.now()) : null);
  late bool _active = widget.employee?.status != EmployeeStatus.inactive;

  @override
  void dispose() {
    for (final c in [_name, _pinfl, _position, _department, _phone, _salary]) {
      c.dispose();
    }
    super.dispose();
  }

  void _save() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final store = context.readStore;
    final e = widget.employee ?? Employee(id: store.nextId(), fullName: '');
    e
      ..fullName = _name.text.trim()
      ..pinfl = _pinfl.text.trim()
      ..position = _position.text.trim()
      ..department = _department.text.trim()
      ..phone = _phone.text.trim()
      ..hiredOn = _hired
      ..salaryMinor = parseMinor(_salary.text) ?? 0
      ..status = _active ? EmployeeStatus.active : EmployeeStatus.inactive;
    if (context.attempt(() => store.saveEmployee(e))) {
      context.toast(context.t('common.saved'));
      Navigator.of(context).pop();
    }
  }

  Future<void> _delete() async {
    final ok = await confirmDialog(context, title: context.t('employees.delete_title'), message: context.t('employees.delete_text'), confirmLabel: context.t('common.delete'), danger: true);
    if (ok && mounted) {
      context.readStore.deleteEmployee(widget.employee!);
      context.toast(context.t('common.deleted'));
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final editing = widget.employee != null;
    return PageScaffold(
      title: context.t(editing ? 'employees.edit' : 'employees.new'),
      maxWidth: 640,
      actions: [if (editing) GlassIconButton(icon: Icons.delete_outline_rounded, color: g.danger, tooltip: context.t('common.delete'), onTap: _delete)],
      body: Form(
        key: _form,
        child: ListView(
          padding: EdgeInsets.fromLTRB(0, 8, 0, 32 + MediaQuery.viewPaddingOf(context).bottom),
          children: [
            Reveal(
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: Column(children: [
                  GlassField(
                    controller: _name,
                    label: context.t('employees.full_name'),
                    icon: Icons.person_outline_rounded,
                    textCapitalization: TextCapitalization.words,
                    validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null,
                  ),
                  const SizedBox(height: 12),
                  GlassField(
                    controller: _pinfl,
                    label: '${context.t('employees.pinfl')} (${context.t('common.optional')})',
                    icon: Icons.badge_outlined,
                    keyboardType: TextInputType.number,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly, LengthLimitingTextInputFormatter(14)],
                    validator: (v) => (v == null || v.isEmpty || v.length == 14) ? null : context.t('employees.pinfl_invalid'),
                  ),
                  const SizedBox(height: 12),
                  GlassField(controller: _position, label: context.t('employees.position'), icon: Icons.work_outline_rounded, textCapitalization: TextCapitalization.sentences),
                  const SizedBox(height: 12),
                  GlassField(controller: _department, label: context.t('employees.department'), icon: Icons.apartment_outlined, textCapitalization: TextCapitalization.sentences),
                  const SizedBox(height: 12),
                  GlassField(controller: _phone, label: context.t('common.phone'), icon: Icons.phone_outlined, keyboardType: TextInputType.phone),
                ]),
              ),
            ),
            const SizedBox(height: 14),
            Reveal(
              index: 1,
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: Column(children: [
                  MoneyField(controller: _salary, label: context.t('employees.salary')),
                  const SizedBox(height: 12),
                  DateField(label: context.t('employees.hired_on'), value: _hired, clearable: true, onChanged: (d) => setState(() => _hired = d)),
                  const SizedBox(height: 6),
                  Row(children: [
                    Expanded(child: Text(context.t('employees.status'), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 15.5))),
                    Switch(value: _active, onChanged: (v) => setState(() => _active = v)),
                  ]),
                ]),
              ),
            ),
            const SizedBox(height: 20),
            Reveal(index: 2, child: AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _save)),
            if (editing) ...[
              const SizedBox(height: 10),
              Reveal(index: 3, child: AppButton(label: context.t('common.delete'), icon: Icons.delete_outline_rounded, kind: ButtonKind.glass, onPressed: _delete)),
            ],
          ],
        ),
      ),
    );
  }
}
