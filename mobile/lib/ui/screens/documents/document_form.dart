import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../data/store.dart';
import '../../../data/tax.dart';
import '../../widgets/glass.dart';
import '../contractors/contractor_form.dart';

/// "1,5" / "2" from a quantity stored in thousandths.
String formatQty(int milli) {
  final whole = milli ~/ 1000;
  final frac = milli % 1000;
  if (frac == 0) return '$whole';
  return '$whole,${frac.toString().padLeft(3, '0').replaceFirst(RegExp(r'0+$'), '')}';
}

/// Parses "1,5" or "2.25" into thousandths (up to 3 decimals). Returns null when it is not a positive number.
int? parseQtyMilli(String input) {
  final s = input.replaceAll(RegExp(r'[\s ]'), '').replaceAll(',', '.');
  if (s.isEmpty) return null;
  final parts = s.split('.');
  if (parts.length > 2) return null;
  final whole = int.tryParse(parts[0].isEmpty ? '0' : parts[0]);
  if (whole == null) return null;
  var frac = 0;
  if (parts.length == 2 && parts[1].isNotEmpty) {
    frac = int.tryParse(parts[1].padRight(3, '0').substring(0, 3)) ?? -1;
    if (frac < 0) return null;
  }
  final v = whole * 1000 + frac;
  return v > 0 ? v : null;
}

String _moneyInput(int minor) {
  final frac = minor.abs() % kTiyin;
  final whole = formatSom(minor - (minor >= 0 ? frac : -frac));
  return frac == 0 ? whole : '$whole,${frac.toString().padLeft(2, '0')}';
}

class _LineEdit {
  _LineEdit({String name = '', String unit = '', String qty = '1', String price = '', this.withVat = false})
      : name = TextEditingController(text: name),
        unit = TextEditingController(text: unit),
        qty = TextEditingController(text: qty),
        price = TextEditingController(text: price);

  static int _counter = 0;
  final int uid = ++_counter;
  final TextEditingController name, unit, qty, price;
  bool withVat;

  void dispose() {
    name.dispose();
    unit.dispose();
    qty.dispose();
    price.dispose();
  }

  DocLine toLine(int vatBp) => DocLine(
        name: name.text.trim(),
        unit: unit.text.trim(),
        qtyMilli: parseQtyMilli(qty.text) ?? 0,
        unitPriceMinor: parseMinor(price.text) ?? 0,
        vatBp: withVat ? vatBp : 0,
      );
}

/// Creates a document, or edits a draft. Pops with the saved [Doc].
class DocumentFormScreen extends StatefulWidget {
  const DocumentFormScreen({super.key, this.doc, this.type});
  final Doc? doc;
  final DocType? type;

  @override
  State<DocumentFormScreen> createState() => _DocumentFormScreenState();
}

class _DocumentFormScreenState extends State<DocumentFormScreen> {
  final _form = GlobalKey<FormState>();
  final _notes = TextEditingController();
  final _lines = <_LineEdit>[];
  late DocType _type;
  late bool _outgoing;
  late DateTime _issue;
  DateTime? _due;
  int? _contractorId;
  bool _inited = false;

  @override
  void dispose() {
    _notes.dispose();
    for (final l in _lines) {
      l.dispose();
    }
    super.dispose();
  }

  /// Reads the store once (needs the context), after which the screen owns its own editing state.
  void _init(AppStore s) {
    if (_inited) return;
    _inited = true;
    final d = widget.doc;
    final today = dateOnly(DateTime.now());
    _type = d?.type ?? widget.type ?? DocType.invoice;
    _outgoing = d?.outgoing ?? true;
    _issue = d?.issueDate ?? today;
    _due = d != null ? d.dueDate : today.add(const Duration(days: 14));
    _contractorId = d?.contractorId;
    _notes.text = d?.notes ?? '';
    if (d != null) {
      for (final l in d.lines) {
        _lines.add(_LineEdit(name: l.name, unit: l.unit, qty: formatQty(l.qtyMilli), price: _moneyInput(l.unitPriceMinor), withVat: l.vatBp > 0));
      }
    }
    if (_lines.isEmpty) _lines.add(_LineEdit(withVat: s.company.vatPayer && _stdVat(s) != null));
  }

  int? _stdVat(AppStore s) {
    try {
      return s.book.rule('vat.standard', _issue).value;
    } on MissingTaxRule {
      return null;
    }
  }

  List<DocLine> _docLines(int vatBp) => [for (final l in _lines) l.toLine(vatBp)];

  void _save() {
    final s = context.readStore;
    if (!(_form.currentState?.validate() ?? false)) return;
    if (_due != null && _due!.isBefore(_issue)) {
      context.toast(context.t('docs.due_before_issue'));
      return;
    }
    final vatBp = _stdVat(s) ?? 0;
    final lines = _docLines(vatBp);
    final existing = widget.doc;
    final Doc doc;
    if (existing == null) {
      doc = Doc(
        id: s.nextId(),
        type: _type,
        number: s.nextNumber(_type, _issue),
        contractorId: _contractorId!,
        issueDate: _issue,
        dueDate: _due,
        outgoing: _outgoing,
        lines: lines,
        notes: _notes.text.trim(),
      );
    } else {
      final renumber = existing.type != _type || existing.issueDate.year != _issue.year;
      doc = existing
        ..number = renumber ? s.nextNumber(_type, _issue) : existing.number
        ..type = _type
        ..contractorId = _contractorId!
        ..issueDate = _issue
        ..dueDate = _due
        ..outgoing = _outgoing
        ..lines = lines
        ..notes = _notes.text.trim();
    }
    if (context.attempt(() => s.saveDoc(doc))) {
      context.toast(context.t('common.saved'));
      Navigator.of(context).pop(doc);
    }
  }

  Future<void> _quickAddContractor() async {
    final c = await pushPage<Contractor>(context, const ContractorFormScreen());
    if (c != null && mounted) setState(() => _contractorId = c.id);
  }

  Widget _caption(String text) {
    final g = context.g;
    return Padding(padding: const EdgeInsets.fromLTRB(4, 14, 4, 8), child: Text(text, style: TextStyle(color: g.textDim, fontSize: 13, fontWeight: FontWeight.w800, letterSpacing: .3)));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    _init(s);
    final editing = widget.doc;
    final h = context.isWide ? 24.0 : 16.0;
    final title = context.t(editing == null ? 'docs.form_new' : 'docs.form_edit');

    if (editing != null && editing.status != DocStatus.draft) {
      return PageScaffold(title: title, body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('docs.only_draft')));
    }

    final std = _stdVat(s);
    final lines = _docLines(std ?? 0);
    final subtotal = lines.fold(0, (a, l) => a + l.subtotal);
    final vat = lines.fold(0, (a, l) => a + l.vat);
    final contractors = s.contractors.where((c) => c.active || c.id == _contractorId).toList()..sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()));
    final number = editing?.number ?? s.nextNumber(_type, _issue);

    return PageScaffold(
      title: title,
      subtitle: number,
      maxWidth: 720,
      bottom: SafeArea(
        top: false,
        child: Padding(
          padding: EdgeInsets.fromLTRB(h, 8, h, 12),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 720),
            child: AppButton(label: context.t('docs.save'), icon: Icons.check_rounded, onPressed: _save),
          ),
        ),
      ),
      body: Form(
        key: _form,
        child: ListView(padding: EdgeInsets.fromLTRB(h, 0, h, 24), children: [
          _caption(context.t('docs.type')),
          SegmentedGlass(
            labels: [context.t('doctype.invoice'), context.t('doctype.act_short')],
            index: _type.index,
            onChanged: (i) => setState(() => _type = DocType.values[i]),
          ),
          _caption(context.t('docs.direction')),
          SegmentedGlass(
            labels: [context.t('docs.dir_out'), context.t('docs.dir_in')],
            index: _outgoing ? 0 : 1,
            onChanged: (i) => setState(() => _outgoing = i == 0),
          ),
          Padding(
            padding: const EdgeInsets.fromLTRB(6, 8, 6, 0),
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 220),
              child: Text(context.t(_outgoing ? 'docs.dir_out_long' : 'docs.dir_in_long'), key: ValueKey(_outgoing), style: TextStyle(color: g.textDim, fontSize: 12.5)),
            ),
          ),
          const SizedBox(height: 16),
          GlassField(key: ValueKey(number), initialValue: number, label: context.t('docs.number'), icon: Icons.tag_rounded, readOnly: true),
          const SizedBox(height: 12),
          if (contractors.isEmpty)
            GlassCard(blur: false, child: Text(context.t('docs.no_contractors'), style: TextStyle(color: g.textDim)))
          else
            PickerField<int>(
              key: ValueKey('c$_contractorId'),
              label: context.t('docs.contractor'),
              icon: Icons.business_rounded,
              value: _contractorId,
              options: [for (final c in contractors) (c.id, c.name)],
              onChanged: (v) => setState(() => _contractorId = v),
              validator: (v) => v == null ? context.t('docs.pick_contractor') : null,
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: Padding(
              padding: const EdgeInsets.only(top: 8),
              child: AppButton(label: context.t('docs.quick_add'), icon: Icons.person_add_alt_1_rounded, kind: ButtonKind.glass, expanded: false, compact: true, onPressed: _quickAddContractor),
            ),
          ),
          const SizedBox(height: 14),
          LayoutBuilder(builder: (context, c) {
            final issue = DateField(
              label: context.t('docs.issue_date'),
              value: _issue,
              onChanged: (d) => setState(() {
                final shift = _due?.difference(_issue);
                _issue = d ?? _issue;
                if (shift != null) _due = _issue.add(shift);
              }),
            );
            final due = DateField(label: context.t('docs.due_date'), value: _due, clearable: true, onChanged: (d) => setState(() => _due = d));
            if (c.maxWidth < 420) return Column(children: [issue, const SizedBox(height: 12), due]);
            return Row(children: [Expanded(child: issue), const SizedBox(width: 12), Expanded(child: due)]);
          }),
          _caption(context.t('docs.lines_section')),
          for (var i = 0; i < _lines.length; i++)
            AnimatedSize(
              key: ValueKey(_lines[i].uid),
              duration: const Duration(milliseconds: 260),
              curve: Curves.easeOutCubic,
              alignment: Alignment.topCenter,
              child: Reveal(dy: 10, child: Padding(padding: const EdgeInsets.only(bottom: 12), child: _lineCard(i, std))),
            ),
          AppButton(label: context.t('docs.add_line'), icon: Icons.add_rounded, kind: ButtonKind.glass, onPressed: () => setState(() => _lines.add(_LineEdit(withVat: std != null && _lines.isNotEmpty ? _lines.last.withVat : (s.company.vatPayer && std != null))))),
          const SizedBox(height: 16),
          GlassField(controller: _notes, label: context.t('docs.notes'), icon: Icons.notes_rounded, maxLines: 3, textCapitalization: TextCapitalization.sentences),
          _caption(context.t('docs.totals')),
          GlassCard(
            strong: true,
            child: Column(children: [
              InfoLine(context.t('docs.subtotal'), context.money(subtotal)),
              InfoLine(context.t('docs.vat_total'), context.money(vat)),
              const Hairline(),
              const SizedBox(height: 10),
              Align(alignment: Alignment.centerLeft, child: Text(context.t('docs.grand_total'), style: TextStyle(color: g.textDim, fontSize: 13))),
              Align(alignment: Alignment.centerLeft, child: AmountText(subtotal + vat, size: 28)),
            ]),
          ),
        ]),
      ),
    );
  }

  Widget _lineCard(int i, int? std) {
    final g = context.g;
    final l = _lines[i];
    final total = l.toLine(std ?? 0).total;
    return GlassCard(
      blur: false,
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Text(context.t('docs.line_n', {'n': i + 1}), style: TextStyle(color: g.accent, fontWeight: FontWeight.w800, fontSize: 13.5))),
          if (_lines.length > 1)
            GestureDetector(
              onTap: () {
                late final _LineEdit removed;
                setState(() => removed = _lines.removeAt(i));
                // The field widgets are still mounted during this frame; free their controllers after it.
                WidgetsBinding.instance.addPostFrameCallback((_) => removed.dispose());
              },
              child: Padding(padding: const EdgeInsets.all(4), child: Icon(Icons.delete_outline_rounded, color: g.danger, size: 22)),
            ),
        ]),
        const SizedBox(height: 10),
        GlassField(
          controller: l.name,
          label: context.t('docs.line_name'),
          icon: Icons.inventory_2_outlined,
          textCapitalization: TextCapitalization.sentences,
          onChanged: (_) => setState(() {}),
          validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null,
        ),
        const SizedBox(height: 12),
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(flex: 2, child: GlassField(controller: l.unit, label: context.t('docs.unit'))),
          const SizedBox(width: 10),
          Expanded(
            flex: 3,
            child: GlassField(
              controller: l.qty,
              label: context.t('docs.qty'),
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              onChanged: (_) => setState(() {}),
              validator: (v) => parseQtyMilli(v ?? '') == null ? context.t('docs.qty_invalid') : null,
            ),
          ),
        ]),
        const SizedBox(height: 12),
        MoneyField(controller: l.price, label: context.t('docs.price'), onChanged: (_) => setState(() {})),
        if (std != null) ...[
          const SizedBox(height: 12),
          SegmentedGlass(
            labels: [context.t('docs.vat_none'), context.t('docs.vat_with', {'rate': formatBp(std)})],
            index: l.withVat ? 1 : 0,
            onChanged: (v) => setState(() => l.withVat = v == 1),
          ),
        ],
        const SizedBox(height: 12),
        Row(children: [
          Expanded(child: Text(context.t('docs.line_total'), style: TextStyle(color: g.textDim, fontSize: 13.5))),
          Text(context.money(total), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16)),
        ]),
      ]),
    );
  }
}
