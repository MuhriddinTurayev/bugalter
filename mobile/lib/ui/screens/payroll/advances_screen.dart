import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/extras.dart';
import '../../../data/models.dart';
import '../../../data/store.dart';
import '../../widgets/glass.dart';
import '../server_gate.dart';
import 'month_stepper.dart';

/// Advances on pay: money given to a person during the month. It is booked as an expense at once and the month's payroll deducts it,
/// so only the rest is paid at the end. For the real company (server mode) the advances are only shown: they are given on the web platform.
class AdvancesScreen extends StatefulWidget {
  const AdvancesScreen({super.key});

  @override
  State<AdvancesScreen> createState() => _AdvancesScreenState();
}

class _AdvancesScreenState extends State<AdvancesScreen> {
  DateTime _month = monthStart(DateTime.now());

  Future<void> _openSheet({Advance? advance, Employee? employee}) async {
    final saved = await showGlassSheet<String>(context, builder: (_) => _AdvanceSheet(month: _month, advance: advance, employee: employee));
    if (saved != null && mounted) context.toast(context.t(saved));
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final live = s.serverMode;
    final gate = serverGate(context, title: context.t('adv.title'), synced: s.extrasSynced(), allowed: s.extrasAllowed('advances'));
    if (gate != null) return gate;
    final active = s.employees.where((e) => e.status == EmployeeStatus.active).toList()..sort((a, b) => a.fullName.compareTo(b.fullName));
    final list = s.advancesOf(_month);
    final byPerson = <int, List<Advance>>{};
    if (live) {
      for (final a in list) {
        (byPerson[a.employeeId] ??= []).add(a);
      }
    }
    final given = s.advanceTotals(_month);
    final bonuses = s.kpiTotals(_month);
    final closed = s.monthClosed(_month);
    final canEdit = s.canEditPeople && !closed;
    final total = list.fold(0, (a, x) => a + x.amountMinor);

    String? hint;
    if (live) {
      hint = context.t('srv.adv.note');
    } else if (!s.canEditPeople) {
      hint = context.t('adv.read_only');
    } else if (closed) {
      hint = context.t('adv.closed');
    } else if (active.isEmpty) {
      hint = context.t('adv.no_staff');
    } else if (s.activeAccounts.isEmpty) {
      hint = context.t('adv.no_account');
    }

    var i = 0;
    return PageScaffold(
      title: context.t('adv.title'),
      subtitle: context.monthYear(_month),
      bottom: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: hint != null && !canEdit
              ? Text(hint, textAlign: TextAlign.center, style: TextStyle(color: g.textDim, fontSize: 13))
              : AppButton(label: context.t('adv.add'), icon: Icons.add_rounded, onPressed: active.isEmpty || s.activeAccounts.isEmpty ? null : () => _openSheet()),
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
                Text(context.t('adv.month_total'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                AmountText(total, size: 30),
                const SizedBox(height: 10),
                Text(context.t('adv.subtitle'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35)),
              ]),
            ),
          ),
          const SizedBox(height: 18),
          TileSection(
            title: context.t('adv.people'),
            empty: context.t(live ? 'adv.empty' : 'adv.no_staff'),
            children: [
              if (live)
                for (final entry in byPerson.entries)
                  SoftTile(
                    padding: const EdgeInsets.all(14),
                    child: Row(children: [
                      Avatar(name: _who(context, s, entry.value.first), size: 40),
                      const SizedBox(width: 12),
                      Expanded(child: Text(_who(context, s, entry.value.first), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
                      const SizedBox(width: 8),
                      Flexible(flex: 0, child: Text(context.money(entry.value.fold(0, (a, x) => a + x.amountMinor)), style: TextStyle(color: g.warning, fontWeight: FontWeight.w800, fontSize: 14.5))),
                    ]),
                  )
              else
              for (final e in active)
                () {
                  final gross = e.salaryMinor + (bonuses[e.id] ?? 0);
                  final net = s.estimatedNet(e, _month);
                  final paid = given[e.id] ?? 0;
                  return SoftTile(
                    padding: const EdgeInsets.all(14),
                    onTap: canEdit ? () => _openSheet(employee: e) : null,
                    child: Column(children: [
                      Row(children: [
                        Avatar(name: e.fullName, size: 40),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(e.fullName, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                            if (e.position.isNotEmpty) Text(e.position, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
                          ]),
                        ),
                        if (canEdit) Icon(Icons.add_circle_outline_rounded, color: g.accent),
                      ]),
                      const SizedBox(height: 10),
                      Row(children: [
                        Expanded(child: _cell(context, context.t('adv.gross'), gross)),
                        Expanded(child: _cell(context, context.t('adv.net'), net)),
                        Expanded(child: _cell(context, context.t('adv.given'), paid, color: paid > 0 ? g.warning : null)),
                        Expanded(child: _cell(context, context.t('adv.left'), net - paid, color: g.accent)),
                      ]),
                    ]),
                  );
                }(),
            ],
          ),
          const SizedBox(height: 22),
          TileSection(
            title: context.t('adv.history'),
            empty: context.t('adv.empty'),
            children: [
              for (final a in list)
                SoftTile(
                  padding: const EdgeInsets.all(14),
                  onTap: canEdit ? () => _openSheet(advance: a) : null,
                  child: Row(children: [
                    IconBadge(icon: Icons.request_quote_rounded, size: 42, color: g.warning),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(_who(context, s, a), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                        const SizedBox(height: 2),
                        Text([context.date(a.givenOn), s.account(a.accountId)?.name ?? '', if (a.note.isNotEmpty) a.note].where((e) => e.isNotEmpty).join(' · '), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
                      ]),
                    ),
                    const SizedBox(width: 8),
                    Flexible(flex: 0, child: Text(context.money(a.amountMinor), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5))),
                  ]),
                ),
            ],
          ),
          const SizedBox(height: 16),
          Text(context.t('adv.how'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.4)),
        ],
      ),
    );
  }

  /// The person of an advance: the employee card on the phone, else the name the server kept.
  String _who(BuildContext context, AppStore s, Advance a) {
    final e = s.employee(a.employeeId);
    if (e != null) return e.fullName;
    return a.employeeName.isNotEmpty ? a.employeeName : context.t('srv.employee_unknown');
  }

  Widget _cell(BuildContext context, String label, int v, {Color? color}) {
    final g = context.g;
    return Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
      Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 11.5)),
      FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(context.compact(v), style: TextStyle(color: color ?? g.text, fontWeight: FontWeight.w800, fontSize: 13.5))),
    ]);
  }
}

class _AdvanceSheet extends StatefulWidget {
  const _AdvanceSheet({required this.month, this.advance, this.employee});
  final DateTime month;
  final Advance? advance;
  final Employee? employee;

  @override
  State<_AdvanceSheet> createState() => _AdvanceSheetState();
}

class _AdvanceSheetState extends State<_AdvanceSheet> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _amount;
  late final TextEditingController _note;
  int? _employeeId;
  int? _accountId;
  late DateTime _date;
  String? _error;

  bool get _editing => widget.advance != null;

  @override
  void initState() {
    super.initState();
    final a = widget.advance;
    final today = DateTime.now();
    _employeeId = a?.employeeId ?? widget.employee?.id;
    _accountId = a?.accountId;
    _date = a?.givenOn ?? DateTime(today.year, today.month, today.day);
    _amount = TextEditingController(text: a == null ? '' : formatSom(a.amountMinor));
    _note = TextEditingController(text: a?.note ?? '');
  }

  @override
  void dispose() {
    _amount.dispose();
    _note.dispose();
    super.dispose();
  }

  void _save() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final s = context.readStore;
    final e = s.employee(_employeeId);
    final minor = parseMinor(_amount.text);
    if (e == null || minor == null || _accountId == null) return;
    try {
      if (_editing) {
        s.updateAdvance(widget.advance!, givenOn: _date, amountMinor: minor, accountId: _accountId!, note: _note.text);
      } else {
        s.giveAdvance(employee: e, month: widget.month, givenOn: _date, amountMinor: minor, accountId: _accountId!, note: _note.text);
      }
      Navigator.pop(context, _editing ? 'adv.updated' : 'adv.saved');
    } catch (err) {
      setState(() => _error = errorText(context, err));
    }
  }

  Future<void> _delete() async {
    final ok = await confirmDialog(context, title: context.t('common.delete'), message: context.t('adv.confirm_delete'), danger: true, confirmLabel: context.t('common.delete'));
    if (!ok || !mounted) return;
    try {
      context.readStore.deleteAdvance(widget.advance!);
      Navigator.pop(context, 'adv.deleted');
    } catch (err) {
      setState(() => _error = errorText(context, err));
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final active = s.employees.where((e) => e.status == EmployeeStatus.active).toList();
    _accountId ??= s.activeAccounts.isEmpty ? null : s.activeAccounts.first.id;
    final emp = s.employee(_employeeId);
    final left = emp == null ? null : s.advanceAllowance(emp, widget.month) + (widget.advance?.amountMinor ?? 0);
    return Form(
      key: _form,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t(_editing ? 'adv.edit' : 'adv.add'), style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 4),
        Text(context.monthYear(widget.month), style: TextStyle(color: g.textDim)),
        const SizedBox(height: 14),
        if (_editing)
          InputDecorator(decoration: glassDecoration(context, label: context.t('adv.f_employee'), icon: Icons.person_outline_rounded), child: Text(emp?.fullName ?? '—', style: TextStyle(color: g.text, fontSize: 16)))
        else
          PickerField<int>(
            label: context.t('adv.f_employee'),
            icon: Icons.person_outline_rounded,
            value: _employeeId,
            options: [for (final e in active) (e.id, e.fullName)],
            onChanged: (v) => setState(() => _employeeId = v),
            validator: (v) => v == null ? context.t('common.required') : null,
          ),
        const SizedBox(height: 12),
        DateField(label: context.t('adv.f_date'), value: _date, last: DateTime.now(), onChanged: (d) => setState(() => _date = d ?? _date)),
        const SizedBox(height: 12),
        PickerField<int>(
          label: context.t('adv.f_account'),
          icon: Icons.account_balance_wallet_outlined,
          value: _accountId,
          options: [for (final a in s.activeAccounts) (a.id, a.name)],
          onChanged: (v) => setState(() => _accountId = v),
          validator: (v) => v == null ? context.t('common.required') : null,
        ),
        const SizedBox(height: 12),
        MoneyField(controller: _amount, label: context.t('adv.f_amount')),
        if (left != null)
          Padding(padding: const EdgeInsets.only(top: 6, left: 6), child: Text(context.t('adv.max_hint', {'left': context.money(left < 0 ? 0 : left)}), style: TextStyle(color: g.textDim, fontSize: 12.5))),
        const SizedBox(height: 12),
        GlassField(controller: _note, label: context.t('adv.f_note'), icon: Icons.notes_rounded, maxLines: 2),
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
