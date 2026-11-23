import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/legal.dart';
import '../../../data/models.dart';
import '../../../data/store.dart';
import '../../../l10n/strings.dart';
import '../../widgets/glass.dart';
import '../didox/didox_doc_screen.dart';
import '../server_gate.dart';
import 'legal_common.dart';

String _moneyText(int minor) {
  final frac = minor.abs() % kTiyin;
  final whole = formatSom(minor - frac);
  return frac == 0 ? whole : '$whole,${frac.toString().padLeft(2, '0')}';
}

/// One draft of the lawyer: collecting answers, the generated text (view / edit), approval, and "send to Didox" (sandbox).
class LegalDraftScreen extends StatefulWidget {
  const LegalDraftScreen({super.key, required this.draftId});
  final int draftId;

  @override
  State<LegalDraftScreen> createState() => _LegalDraftScreenState();
}

class _LegalDraftScreenState extends State<LegalDraftScreen> {
  final Map<String, TextEditingController> _c = {};
  final Map<String, DateTime?> _dates = {};
  final _title = TextEditingController();
  final _body = TextEditingController();
  final _didoxNumber = TextEditingController();
  late AppStore _store;
  bool _inited = false, _deleted = false, _edit = false;
  int? _contractorId;
  String _lang = 'uz';
  int _side = 0;
  DateTime _sendDate = dateOnly(DateTime.now());

  /// Reads the draft once (needs the store); afterwards the screen owns its editing state.
  void _init(AppStore s, LegalDraft d) {
    if (_inited) return;
    _inited = true;
    _store = s;
    for (final f in d.template.fields) {
      switch (LegalTemplate.kindOf(f)) {
        case FieldKind.text || FieldKind.multiline:
          _c[f] = TextEditingController(text: d.answers[f] ?? '');
        case FieldKind.money:
          final m = int.tryParse(d.answers[f] ?? '');
          _c[f] = TextEditingController(text: m == null ? '' : _moneyText(m));
        case FieldKind.date:
          _dates[f] = DateTime.tryParse(d.answers[f] ?? '');
        case FieldKind.side:
          _side = int.tryParse(d.answers[f] ?? '') ?? 0;
      }
    }
    _lang = d.language;
    _contractorId = d.counterpartyId;
    _title.text = d.title;
    _body.text = d.body;
    _didoxNumber.text = d.answers['number'] ?? '';
  }

  Map<String, String> _collect(LegalTemplate t) {
    final a = <String, String>{};
    for (final f in t.fields) {
      switch (LegalTemplate.kindOf(f)) {
        case FieldKind.text || FieldKind.multiline:
          final v = _c[f]?.text.trim() ?? '';
          if (v.isNotEmpty) a[f] = v;
        case FieldKind.money:
          final m = parseMinor(_c[f]?.text ?? '');
          if (m != null && m > 0) a[f] = '$m';
        case FieldKind.date:
          final d = _dates[f];
          if (d != null) a[f] = d.toIso8601String();
        case FieldKind.side:
          a[f] = '$_side';
      }
    }
    return a;
  }

  @override
  void dispose() {
    if (_inited && !_deleted) {
      // keep what was typed, so leaving the form does not lose the answers (done after this frame: the tree is locked now)
      final data = LegalData.of(_store);
      final d = data.draft(widget.draftId);
      if (d != null && !d.locked && (d.status == 'collecting' || d.status == 'failed')) {
        final answers = _collect(d.template);
        final cid = _contractorId;
        final lang = _lang;
        final st = _store;
        final id = widget.draftId;
        Future.microtask(() {
          final dd = LegalData.of(st).draft(id);
          if (dd != null && !dd.locked && (dd.status == 'collecting' || dd.status == 'failed')) LegalData.of(st).saveAnswers(st, dd, answers, contractorId: cid, language: lang);
        });
      }
    }
    for (final c in _c.values) {
      c.dispose();
    }
    _title.dispose();
    _body.dispose();
    _didoxNumber.dispose();
    super.dispose();
  }

  // ───────────── actions ─────────────

  void _pickContractor(LegalDraft d, int? id) {
    setState(() => _contractorId = id);
    final c = context.readStore.contractor(id);
    if (c == null) return;
    void put(String f, String v) {
      if (v.isNotEmpty && _c.containsKey(f)) _c[f]!.text = v;
    }

    put('party', c.name);
    put('tin', c.stir);
    put('addr', c.address);
    put('bank', [c.bankName, c.bankAccount, c.mfo.isEmpty ? '' : 'MFO ${c.mfo}'].where((e) => e.isNotEmpty).join(' · '));
  }

  void _fill(LegalData data, LegalDraft d) {
    final s = context.readStore;
    if (!runLegal(context, () {
      data.saveAnswers(s, d, _collect(d.template), contractorId: _contractorId, language: _lang);
      data.fill(s, d);
    })) {
      return;
    }
    if (d.status == 'draft') {
      _title.text = d.title;
      _body.text = d.body;
      setState(() => _edit = false);
      context.toast(context.t('legal.filled'));
    } else {
      setState(() {});
    }
  }

  Future<void> _refill(LegalData data, LegalDraft d) async {
    final ok = await confirmDialog(context, title: context.t('legal.refill'), message: context.t('legal.refill_confirm'), confirmLabel: context.t('legal.fill'));
    if (!ok || !mounted) return;
    final s = context.readStore;
    if (runLegal(context, () => data.fill(s, d))) {
      _title.text = d.title;
      _body.text = d.body;
      setState(() => _edit = false);
      context.toast(context.t('legal.filled'));
    }
  }

  void _editAnswers(LegalData data, LegalDraft d) {
    final s = context.readStore;
    runLegal(context, () => data.saveAnswers(s, d, d.answers, contractorId: d.counterpartyId));
  }

  void _saveText(LegalData data, LegalDraft d) {
    final s = context.readStore;
    if (runLegal(context, () => data.saveText(s, d, title: _title.text, body: _body.text))) {
      setState(() => _edit = false);
      context.toast(context.t('legal.saved'));
    }
  }

  Future<void> _approve(LegalData data, LegalDraft d) async {
    final s = context.readStore;
    // approve what is on the screen: a pending edit is saved first
    if (_edit && (_body.text.trim() != d.body || _title.text.trim() != d.title)) {
      if (!runLegal(context, () => data.saveText(s, d, title: _title.text, body: _body.text))) return;
    }
    final ok = await confirmDialog(context, title: context.t('legal.approve_confirm_title'), message: context.t('legal.approve_confirm_msg'), confirmLabel: context.t('legal.approve'));
    if (!ok || !mounted) return;
    if (runLegal(context, () => data.approve(s, d))) {
      setState(() => _edit = false);
      context.toast(context.t('legal.approved_done'));
    }
  }

  Future<void> _copy(LegalDraft d) async {
    await Clipboard.setData(ClipboardData(text: d.body));
    if (mounted) context.toast(context.t('legal.copied'));
  }

  Future<void> _delete(LegalData data, LegalDraft d) async {
    final s = context.readStore;
    final ok = await confirmDialog(context, title: context.t('legal.confirm_delete'), danger: true, confirmLabel: context.t('legal.delete'));
    if (!ok || !mounted) return;
    _deleted = true;
    data.deleteDraft(s, d);
    context.toast(context.t('legal.deleted'));
    Navigator.of(context).pop();
  }

  Future<void> _send(LegalData data, LegalDraft d) async {
    final s = context.readStore;
    final ok = await confirmDialog(context, title: context.t('legal.didox_confirm_title'), message: context.t('legal.didox_confirm_msg'), confirmLabel: context.t('legal.didox_send').replaceAll('…', ''));
    if (!ok || !mounted) return;
    if (runLegal(context, () => data.sendToDidox(s, d, number: _didoxNumber.text, date: _sendDate))) context.toast(context.t('legal.didox_sent'));
  }

  // ───────────── pieces ─────────────

  Widget _field(LegalTemplate t, String f) {
    final label = context.t(t.labelKey(f)) + (t.required.contains(f) ? ' *' : '');
    switch (LegalTemplate.kindOf(f)) {
      case FieldKind.side:
        final r = t.roles!;
        return PickerField<int>(
          label: context.t('legal.q.side'),
          icon: Icons.swap_horiz_rounded,
          value: _side,
          options: [(0, context.t('legal.t.role.${r.$1}')), (1, context.t('legal.t.role.${r.$2}'))],
          onChanged: (v) => setState(() => _side = v ?? 0),
        );
      case FieldKind.money:
        return MoneyField(controller: _c[f]!, label: label, validator: (_) => null);
      case FieldKind.date:
        return DateField(label: label, value: _dates[f], onChanged: (v) => setState(() => _dates[f] = v), clearable: f != 'date');
      case FieldKind.multiline:
        return GlassField(controller: _c[f]!, label: label, maxLines: 4);
      case FieldKind.text:
        return GlassField(controller: _c[f]!, label: label, keyboardType: f == 'tin' ? TextInputType.number : null);
    }
  }

  Widget _collectForm(LegalData data, LegalDraft d) {
    final g = context.g;
    final s = context.store;
    final t = d.template;
    final contractors = s.contractors.where((c) => c.active || c.id == _contractorId).toList()..sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()));
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (d.status == 'failed' && d.errorKey.isNotEmpty) ...[
        GlassCard(
          tint: g.danger.withValues(alpha: .12),
          padding: const EdgeInsets.all(14),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(Icons.error_outline_rounded, color: g.danger),
            const SizedBox(width: 10),
            Expanded(child: Text(context.t(d.errorKey), style: TextStyle(color: g.text, fontSize: 14, height: 1.35))),
          ]),
        ),
        const SizedBox(height: 14),
      ],
      Text(context.t('legal.answers_title'), style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800)),
      const SizedBox(height: 4),
      Text(context.t('legal.answers_hint'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35)),
      const SizedBox(height: 14),
      Text(context.t('legal.language'), style: TextStyle(color: g.textDim, fontSize: 12.5, fontWeight: FontWeight.w700)),
      const SizedBox(height: 6),
      SegmentedGlass(labels: [for (final l in kLocales) kLocaleNames[l]!], index: kLocales.indexOf(_lang).clamp(0, 2), onChanged: (i) => setState(() => _lang = kLocales[i])),
      const SizedBox(height: 14),
      if (t.fields.contains('party') && t.id != 'letter' && contractors.isNotEmpty) ...[
        PickerField<int>(
          label: context.t('legal.pick_contractor'),
          icon: Icons.business_rounded,
          value: _contractorId,
          allowNone: true,
          options: [for (final c in contractors) (c.id, c.name)],
          onChanged: (v) => _pickContractor(d, v),
        ),
        const SizedBox(height: 12),
      ],
      for (final f in t.fields) ...[_field(t, f), const SizedBox(height: 12)],
      const SizedBox(height: 4),
      AppButton(label: context.t(d.status == 'failed' ? 'legal.retry' : 'legal.fill'), icon: Icons.auto_fix_high_rounded, onPressed: () => _fill(data, d)),
    ]);
  }

  Widget _textCard(LegalDraft d) {
    final g = context.g;
    final blanks = blanksIn(d.body);
    return GlassCard(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Expanded(child: Text(context.t('legal.text'), style: TextStyle(color: g.text, fontSize: 16, fontWeight: FontWeight.w800))),
          if (!d.locked) StatusPill(label: blanks == 0 ? context.t('legal.complete') : context.t('legal.blanks_count', {'n': blanks}), color: blanks == 0 ? g.success : g.warning),
        ]),
        const SizedBox(height: 10),
        if (_edit && d.status == 'draft') ...[
          GlassField(controller: _title, label: context.t('legal.field_title')),
          const SizedBox(height: 12),
          TextField(
            controller: _body,
            minLines: 12,
            maxLines: 40,
            keyboardType: TextInputType.multiline,
            style: TextStyle(color: g.text, fontSize: 14, height: 1.45),
            decoration: glassDecoration(context, label: context.t('legal.text')),
          ),
          const SizedBox(height: 12),
          AppButton(label: context.t('legal.save'), icon: Icons.save_rounded, onPressed: () => _saveText(LegalData.of(context.readStore), d)),
        ] else
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: g.fill, borderRadius: BorderRadius.circular(16), border: Border.all(color: g.border)),
            child: _BlankText(d.body),
          ),
      ]),
    );
  }

  Widget _ready(LegalData data, LegalDraft d) {
    final g = context.g;
    final t = d.template;
    final missing = [for (final f in d.missing) context.t(t.labelKey(f))];
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      if (d.status == 'draft') ...[
        if (missing.isNotEmpty)
          GlassCard(
            tint: g.warning.withValues(alpha: .12),
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${context.t('legal.missing_title')} · ${context.t('legal.missing_count', {'n': missing.length})}', style: TextStyle(color: g.text, fontSize: 14.5, fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              Text(context.t('legal.missing_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35)),
              const SizedBox(height: 10),
              Wrap(spacing: 8, runSpacing: 8, children: [for (final m in missing) StatusPill(label: m, color: g.warning)]),
            ]),
          ),
        if (missing.isNotEmpty) const SizedBox(height: 12),
        SegmentedGlass(labels: [context.t('legal.view_tab'), context.t('legal.edit_tab')], index: _edit ? 1 : 0, onChanged: (i) => setState(() => _edit = i == 1)),
        const SizedBox(height: 12),
      ],
      if (d.locked)
        Padding(
          padding: const EdgeInsets.only(bottom: 12),
          child: SoftTile(
            padding: const EdgeInsets.all(14),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(Icons.verified_rounded, color: g.success, size: 20),
              const SizedBox(width: 10),
              Expanded(child: Text(context.t('legal.approved_note', {'name': d.approvedBy.isEmpty ? '—' : d.approvedBy, 'date': context.date(d.approvedAt)}), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35))),
            ]),
          ),
        ),
      _textCard(d),
      const SizedBox(height: 14),
      if (d.status == 'draft') ...[
        AppButton(label: context.t('legal.approve'), icon: Icons.verified_rounded, onPressed: () => _approve(data, d)),
        const SizedBox(height: 10),
        Wrap(spacing: 10, runSpacing: 10, children: [
          AppButton(label: context.t('legal.edit_answers'), icon: Icons.edit_note_rounded, expanded: false, kind: ButtonKind.glass, onPressed: () => _editAnswers(data, d)),
          AppButton(label: context.t('legal.refill'), icon: Icons.refresh_rounded, expanded: false, kind: ButtonKind.glass, onPressed: () => _refill(data, d)),
        ]),
        const SizedBox(height: 10),
      ],
      AppButton(label: context.t('legal.copy'), icon: Icons.copy_rounded, kind: ButtonKind.glass, onPressed: () => _copy(d)),
      if (d.status == 'approved') ...[const SizedBox(height: 18), _sendCard(data, d)],
      if (d.status == 'sent') ...[
        const SizedBox(height: 14),
        SoftTile(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Icon(Icons.outbox_rounded, color: g.info),
              const SizedBox(width: 10),
              Expanded(child: Text(context.t('legal.sent_note', {'date': context.date(d.sentAt)}), style: TextStyle(color: g.text, fontSize: 14))),
            ]),
            if (d.didoxId != null && data.doc(d.didoxId) != null) ...[
              const SizedBox(height: 12),
              AppButton(label: context.t('legal.didox_open'), icon: Icons.open_in_new_rounded, kind: ButtonKind.glass, compact: true, onPressed: () => pushPage(context, DidoxDocScreen(docId: d.didoxId!))),
            ],
          ]),
        ),
      ],
    ]);
  }

  Widget _sendCard(LegalData data, LegalDraft d) {
    final g = context.g;
    return GlassCard(
      padding: const EdgeInsets.all(16),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('legal.didox_title'), style: TextStyle(color: g.text, fontSize: 16, fontWeight: FontWeight.w800)),
        const SizedBox(height: 6),
        Text(context.t('legal.didox_hint'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35)),
        const SizedBox(height: 14),
        GlassField(controller: _didoxNumber, label: context.t('legal.didox_number'), icon: Icons.tag_rounded),
        const SizedBox(height: 12),
        DateField(label: context.t('legal.didox_date'), value: _sendDate, onChanged: (v) => setState(() => _sendDate = v ?? _sendDate)),
        const SizedBox(height: 14),
        AppButton(label: context.t('legal.didox_send'), icon: Icons.send_rounded, onPressed: () => _send(data, d)),
      ]),
    );
  }

  /// A draft of the real company (server mode): the lawyer's text and what is known about it, read-only. It is edited, approved and sent to
  /// Didox on the web platform.
  Widget _liveView(BuildContext context, LegalDraft d) {
    final g = context.g;
    final color = legalStatusColor(g, d.status);
    final amount = int.tryParse(d.answers['amount'] ?? '');
    final didoxState = d.didoxState.isEmpty ? null : (kAllStrings.containsKey('legal.didox_state.${d.didoxState}') ? context.t('legal.didox_state.${d.didoxState}') : d.didoxState);
    return PageScaffold(
      title: legalKindLabel(context, d.templateId),
      subtitle: d.title.isEmpty ? null : d.title,
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 24),
        children: [
          Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            StatusPill(label: context.t('legal.status.${d.status}'), color: color),
            Text(kLocaleNames[d.language] ?? d.language, style: TextStyle(color: g.textDim, fontSize: 12.5)),
            if (d.actorName.isNotEmpty) Text('${context.t('legal.written_by')}: ${d.actorName}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
          ]),
          const SizedBox(height: 10),
          SoftTile(
            padding: const EdgeInsets.all(14),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(Icons.info_outline_rounded, size: 18, color: g.warning),
              const SizedBox(width: 10),
              Expanded(child: Text(context.t('legal.live_note'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35))),
            ]),
          ),
          if (d.errorText.isNotEmpty) ...[
            const SizedBox(height: 12),
            GlassCard(
              tint: g.danger.withValues(alpha: .12),
              padding: const EdgeInsets.all(14),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(Icons.error_outline_rounded, color: g.danger),
                const SizedBox(width: 10),
                Expanded(child: Text(context.t('legal.live_error', {'error': d.errorText}), style: TextStyle(color: g.text, fontSize: 14, height: 1.35))),
              ]),
            ),
          ],
          if (d.approvedBy.isNotEmpty) ...[
            const SizedBox(height: 12),
            SoftTile(
              padding: const EdgeInsets.all(14),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Icon(Icons.verified_rounded, color: g.success, size: 20),
                const SizedBox(width: 10),
                Expanded(child: Text(context.t('legal.approved_note', {'name': d.approvedBy, 'date': context.date(d.approvedAt)}), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35))),
              ]),
            ),
          ],
          if (d.party.isNotEmpty || (d.answers['tin'] ?? '').isNotEmpty || (amount != null && amount > 0) || didoxState != null) ...[
            const SizedBox(height: 12),
            SoftTile(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
              child: Column(children: [
                if (d.party.isNotEmpty) InfoLine(context.t('legal.q.party'), d.party),
                if ((d.answers['tin'] ?? '').isNotEmpty) InfoLine('STIR / JSHSHIR', d.answers['tin']!),
                if (amount != null && amount > 0) InfoLine(context.t('legal.q.amount'), context.money(amount), bold: true),
                if (didoxState != null) InfoLine('Didox', didoxState),
              ]),
            ),
          ],
          if (d.missing.isNotEmpty) ...[
            const SizedBox(height: 12),
            GlassCard(
              tint: g.warning.withValues(alpha: .12),
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${context.t('legal.missing_title')} · ${context.t('legal.missing_count', {'n': d.missing.length})}', style: TextStyle(color: g.text, fontSize: 14.5, fontWeight: FontWeight.w800)),
                const SizedBox(height: 8),
                for (final m in d.missing)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 4),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Padding(padding: const EdgeInsets.only(top: 7, right: 10), child: Container(width: 5, height: 5, decoration: BoxDecoration(color: g.warning, shape: BoxShape.circle))),
                      Expanded(child: Text(m, style: TextStyle(color: g.text, fontSize: 13.5, height: 1.35))),
                    ]),
                  ),
              ]),
            ),
          ],
          const SizedBox(height: 14),
          GlassCard(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(context.t('legal.text'), style: TextStyle(color: g.text, fontSize: 16, fontWeight: FontWeight.w800)),
              const SizedBox(height: 10),
              if (d.body.trim().isEmpty)
                Text(context.t('legal.live_no_body'), style: TextStyle(color: g.textDim, fontSize: 14, height: 1.35))
              else
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: g.fill, borderRadius: BorderRadius.circular(16), border: Border.all(color: g.border)),
                  child: _BlankText(d.body),
                ),
              if (d.body.trim().isNotEmpty) ...[
                const SizedBox(height: 12),
                AppButton(label: context.t('legal.copy'), icon: Icons.copy_rounded, kind: ButtonKind.glass, compact: true, expanded: false, onPressed: () => _copy(d)),
              ],
            ]),
          ),
          if (d.filled.isNotEmpty) ...[
            const SizedBox(height: 18),
            TileSection(
              title: context.t('legal.live_filled'),
              children: [
                for (final f in d.filled)
                  SoftTile(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(f['fact'] ?? '', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                      const SizedBox(height: 2),
                      Text(f['value'] ?? '', style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14, height: 1.3)),
                    ]),
                  ),
              ],
            ),
          ],
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final data = LegalData.of(s);
    final gate = serverGate(context, title: context.t('nav.legal'), synced: data.synced, allowed: data.canSeeLegal);
    if (gate != null) return gate;
    final d = data.draft(widget.draftId);
    if (d == null) return PageScaffold(title: context.t('nav.legal'), body: EmptyState(icon: Icons.search_off_rounded, title: context.t('common.no_results')));
    if (d.live) return _liveView(context, d);
    _init(s, d);

    final collecting = d.status == 'collecting' || d.status == 'failed';
    final color = legalStatusColor(g, d.status);
    return PageScaffold(
      title: context.t('legal.tpl.${d.templateId}'),
      subtitle: d.title.isEmpty ? null : d.title,
      actions: [GlassIconButton(icon: Icons.delete_outline_rounded, tooltip: context.t('legal.delete'), color: g.danger, onTap: () => _delete(data, d))],
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 24),
        children: [
          const DemoNote(),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            StatusPill(label: context.t('legal.status.${d.status}'), color: color),
            Text(kLocaleNames[d.language] ?? d.language, style: TextStyle(color: g.textDim, fontSize: 12.5)),
            if (d.actorName.isNotEmpty) Text('${context.t('legal.written_by')}: ${d.actorName}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
          ]),
          const SizedBox(height: 10),
          SoftTile(
            padding: const EdgeInsets.all(14),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(Icons.info_outline_rounded, size: 18, color: g.warning),
              const SizedBox(width: 10),
              Expanded(child: Text(context.t('legal.never_sends'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35))),
            ]),
          ),
          const SizedBox(height: 16),
          AnimatedSwitcher(
            duration: const Duration(milliseconds: 260),
            child: KeyedSubtree(key: ValueKey(collecting ? 'collect' : 'ready${d.status}'), child: collecting ? _collectForm(data, d) : _ready(data, d)),
          ),
        ],
      ),
    );
  }
}

/// The draft text with every blank (`__________`) marked, like the yellow blanks of the web page.
class _BlankText extends StatelessWidget {
  const _BlankText(this.text);
  final String text;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final base = TextStyle(color: g.text, fontSize: 14, height: 1.5);
    final blank = base.copyWith(backgroundColor: g.warning.withValues(alpha: .35), fontWeight: FontWeight.w700);
    final spans = <TextSpan>[];
    var at = 0;
    for (final m in RegExp(RegExp.escape(kBlank)).allMatches(text)) {
      if (m.start > at) spans.add(TextSpan(text: text.substring(at, m.start)));
      spans.add(TextSpan(text: m.group(0), style: blank));
      at = m.end;
    }
    if (at < text.length) spans.add(TextSpan(text: text.substring(at)));
    return SelectableText.rich(TextSpan(style: base, children: spans));
  }
}
