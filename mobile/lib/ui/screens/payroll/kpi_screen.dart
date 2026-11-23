import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/extras.dart';
import '../../../data/models.dart';
import '../../widgets/charts.dart';
import '../../widgets/glass.dart';
import '../server_gate.dart';
import 'month_stepper.dart';

/// KPI bonuses: what each person may earn for a goal this month and how much of it was reached. The payroll adds the bonus to the gross pay,
/// and the taxes are counted on it by the rules table.
class KpiScreen extends StatefulWidget {
  const KpiScreen({super.key});

  @override
  State<KpiScreen> createState() => _KpiScreenState();
}

class _KpiScreenState extends State<KpiScreen> {
  DateTime _month = monthStart(DateTime.now());

  Future<void> _openSheet({KpiBonus? bonus}) async {
    final saved = await showGlassSheet<String>(context, builder: (_) => _KpiSheet(month: _month, bonus: bonus));
    if (saved != null && mounted) context.toast(context.t(saved));
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final live = s.serverMode;
    final gate = serverGate(context, title: context.t('kpi.title'), synced: s.extrasSynced(), allowed: s.extrasAllowed('kpi'));
    if (gate != null) return gate;
    final active = s.employees.where((e) => e.status == EmployeeStatus.active).toList();
    String who(KpiBonus b) => s.employee(b.employeeId)?.fullName ?? (b.employeeName.isNotEmpty ? b.employeeName : context.t('srv.employee_unknown'));
    final list = s.kpiOf(_month)..sort((a, b) => who(a).compareTo(who(b)));
    final total = list.fold(0, (a, b) => a + b.amountMinor);
    final people = list.map((b) => b.employeeId).toSet().length;
    final closed = s.monthClosed(_month);
    final canEdit = s.canEditPeople && !closed;

    String? hint;
    if (live) {
      hint = context.t('srv.kpi.note');
    } else if (!s.canEditPeople) {
      hint = context.t('kpi.read_only');
    } else if (closed) {
      hint = context.t('kpi.closed');
    } else if (active.isEmpty) {
      hint = context.t('kpi.no_staff');
    }

    var i = 0;
    return PageScaffold(
      title: context.t('kpi.title'),
      subtitle: context.monthYear(_month),
      bottom: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: hint != null && !canEdit
              ? Text(hint, textAlign: TextAlign.center, style: TextStyle(color: g.textDim, fontSize: 13))
              : AppButton(label: context.t('kpi.add'), icon: Icons.add_rounded, onPressed: active.isEmpty ? null : () => _openSheet()),
        ),
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Reveal(index: i++, child: MonthStepper(month: _month, onChanged: (m) => setState(() => _month = m))),
          const SizedBox(height: 12),
          Reveal(
            index: i++,
            child: GlassCard(
              strong: true,
              padding: const EdgeInsets.all(18),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t('kpi.total'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                AmountText(total, size: 30),
                Text(context.t('kpi.total_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                const SizedBox(height: 12),
                Row(children: [
                  Icon(Icons.groups_rounded, size: 18, color: g.accent),
                  const SizedBox(width: 8),
                  Expanded(child: Text('${context.t('kpi.people')}: $people / ${active.length}', style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 13.5))),
                ]),
                const SizedBox(height: 10),
                Text(context.t('kpi.formula_text'), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 13.5)),
                Text(context.t('kpi.formula_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5)),
              ]),
            ),
          ),
          const SizedBox(height: 18),
          TileSection(
            title: context.t('kpi.title'),
            empty: context.t('kpi.empty'),
            children: [
              for (final b in list)
                SoftTile(
                  padding: const EdgeInsets.all(14),
                  onTap: canEdit ? () => _openSheet(bonus: b) : null,
                  child: Column(children: [
                    Row(children: [
                      Avatar(name: who(b), size: 40),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(who(b), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                          Text(b.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
                        ]),
                      ),
                      const SizedBox(width: 8),
                      Flexible(flex: 0, child: Text(context.money(b.amountMinor), style: TextStyle(color: g.accent, fontWeight: FontWeight.w800, fontSize: 15))),
                    ]),
                    const SizedBox(height: 12),
                    Row(children: [
                      Expanded(child: MeterBar(fraction: (b.achievementBp / 10000).clamp(0.0, 1.0), color: b.achievementBp >= 10000 ? g.success : g.accent)),
                      const SizedBox(width: 10),
                      StatusPill(label: percentLabel(b.achievementBp), color: b.achievementBp >= 10000 ? g.success : g.info),
                    ]),
                    const SizedBox(height: 6),
                    Align(
                      alignment: Alignment.centerLeft,
                      child: Text('${context.compact(b.maxBonusMinor)} × ${percentLabel(b.achievementBp)}', style: TextStyle(color: g.textDim, fontSize: 12)),
                    ),
                  ]),
                ),
            ],
          ),
        ],
      ),
    );
  }
}

class _KpiSheet extends StatefulWidget {
  const _KpiSheet({required this.month, this.bonus});
  final DateTime month;
  final KpiBonus? bonus;

  @override
  State<_KpiSheet> createState() => _KpiSheetState();
}

class _KpiSheetState extends State<_KpiSheet> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _title;
  late final TextEditingController _max;
  late final TextEditingController _pct;
  late final TextEditingController _note;
  int? _employeeId;
  String? _error;

  bool get _editing => widget.bonus != null;

  @override
  void initState() {
    super.initState();
    final b = widget.bonus;
    _employeeId = b?.employeeId;
    _title = TextEditingController(text: b?.title ?? '');
    _max = TextEditingController(text: b == null ? '' : formatSom(b.maxBonusMinor));
    _pct = TextEditingController(text: b == null ? '' : percentLabel(b.achievementBp).replaceAll('%', ''));
    _note = TextEditingController(text: b?.note ?? '');
  }

  @override
  void dispose() {
    _title.dispose();
    _max.dispose();
    _pct.dispose();
    _note.dispose();
    super.dispose();
  }

  void _save() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final s = context.readStore;
    final e = s.employee(_employeeId);
    final max = parseMinor(_max.text);
    final bp = percentToBp(_pct.text);
    if (e == null || max == null || bp == null) return;
    try {
      if (_editing) {
        s.updateKpi(widget.bonus!, title: _title.text, maxBonusMinor: max, achievementBp: bp, note: _note.text);
      } else {
        s.addKpi(employee: e, month: widget.month, title: _title.text, maxBonusMinor: max, achievementBp: bp, note: _note.text);
      }
      Navigator.pop(context, 'kpi.saved');
    } catch (err) {
      setState(() => _error = errorText(context, err));
    }
  }

  Future<void> _delete() async {
    final ok = await confirmDialog(context, title: context.t('common.delete'), message: context.t('kpi.confirm_delete'), danger: true, confirmLabel: context.t('common.delete'));
    if (!ok || !mounted) return;
    try {
      context.readStore.deleteKpi(widget.bonus!);
      Navigator.pop(context, 'kpi.deleted');
    } catch (err) {
      setState(() => _error = errorText(context, err));
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final active = s.employees.where((e) => e.status == EmployeeStatus.active).toList();
    final max = parseMinor(_max.text), bp = percentToBp(_pct.text);
    final amount = max != null && bp != null ? kpiAmount(max, bp) : null;
    return Form(
      key: _form,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t(_editing ? 'kpi.edit' : 'kpi.add'), style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text(context.monthYear(widget.month), style: TextStyle(color: g.textDim)),
        const SizedBox(height: 14),
        if (_editing)
          InputDecorator(decoration: glassDecoration(context, label: context.t('kpi.f_employee'), icon: Icons.person_outline_rounded), child: Text(s.employee(_employeeId)?.fullName ?? '—', style: TextStyle(color: g.text, fontSize: 16)))
        else
          PickerField<int>(
            label: context.t('kpi.f_employee'),
            icon: Icons.person_outline_rounded,
            value: _employeeId,
            options: [for (final e in active) (e.id, e.fullName)],
            onChanged: (v) => setState(() => _employeeId = v),
            validator: (v) => v == null ? context.t('common.required') : null,
          ),
        const SizedBox(height: 12),
        GlassField(
          controller: _title,
          label: context.t('kpi.f_title'),
          hint: context.t('kpi.title_placeholder'),
          icon: Icons.flag_outlined,
          textCapitalization: TextCapitalization.sentences,
          validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null,
        ),
        const SizedBox(height: 12),
        MoneyField(controller: _max, label: context.t('kpi.f_max'), onChanged: (_) => setState(() {})),
        const SizedBox(height: 12),
        GlassField(
          controller: _pct,
          label: context.t('kpi.f_achievement'),
          icon: Icons.percent_rounded,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          onChanged: (_) => setState(() {}),
          validator: (v) => percentToBp(v ?? '') == null ? context.t('err.kpi_pct') : null,
        ),
        const SizedBox(height: 12),
        GlassField(controller: _note, label: context.t('kpi.f_note'), icon: Icons.notes_rounded, maxLines: 2),
        const SizedBox(height: 12),
        // the amount is counted by the program, never typed in
        Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(color: g.accent.withValues(alpha: .12), borderRadius: BorderRadius.circular(16), border: Border.all(color: g.accent.withValues(alpha: .35))),
          child: Row(children: [
            Expanded(child: Text(context.t('kpi.f_amount'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w700))),
            Text(amount == null ? '—' : context.money(amount), style: TextStyle(color: g.accent, fontWeight: FontWeight.w800, fontSize: 17)),
          ]),
        ),
        AnimatedSize(
          duration: const Duration(milliseconds: 220),
          child: _error == null ? const SizedBox(width: double.infinity) : Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: g.danger, fontWeight: FontWeight.w600, height: 1.3))),
        ),
        const SizedBox(height: 16),
        AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _save),
        if (_editing) ...[
          const SizedBox(height: 10),
          AppButton(label: context.t('common.delete'), icon: Icons.delete_outline_rounded, kind: ButtonKind.danger, onPressed: _delete),
        ],
      ]),
    );
  }
}
