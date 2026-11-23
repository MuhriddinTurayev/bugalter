import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/csv_import.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import '../payroll/month_stepper.dart';

/// Bank statement import, in the web's steps: the text of a CSV file, the columns, the date format and the account, a look at the rows, confirm.
/// No file picker is needed: paste the text (or load the sample).
class ImportScreen extends StatefulWidget {
  const ImportScreen({super.key});

  @override
  State<ImportScreen> createState() => _ImportScreenState();
}

class _ImportScreenState extends State<ImportScreen> {
  final _text = TextEditingController();
  CsvTable? _table;
  ImportMap _map = ImportMap();
  List<ImportRow> _rows = const [];
  int? _accountId;
  bool _includeDups = false;
  bool _busy = false;
  bool _isSample = false;
  String? _error;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  String _sample() {
    final d = DateTime.now();
    String day(int back) {
      final x = d.subtract(Duration(days: back));
      return '${x.day.toString().padLeft(2, '0')}.${x.month.toString().padLeft(2, '0')}.${x.year}';
    }

    return 'Sana;Summa;Izoh;Hujjat raqami\n'
        '${day(1)};12 500 000,00;Toʻlov: Orient Trade MChJ;4081\n'
        '${day(2)};-1 750 000;Reklama xizmati;4082\n'
        '${day(3)};"-2 300 000,50";"Elektr energiya, oylik";4083\n'
        '${day(4)};9 600 000;Realizatsiya uchun toʻlov;4084\n'
        'sana notoʻgʻri;100 000;Xato qator namunasi;4085\n';
  }

  void _recompute() {
    final t = _table;
    if (t == null) return;
    _rows = buildImportRows(t, _map, existing: context.readStore.txns);
  }

  void _read() {
    final s = context.readStore;
    final t = parseCsv(_text.text);
    if (t.body.isEmpty) {
      setState(() {
        _table = null;
        _error = context.t('imp.no_rows');
      });
      return;
    }
    final guess = guessColumns(t.headers);
    // choose the date format that reads the most rows
    var bestFormat = kDateFormats.first;
    var bestOk = -1;
    for (final f in kDateFormats) {
      guess.dateFormat = f;
      final ok = buildImportRows(t, guess).where((r) => r.date != null).length;
      if (ok > bestOk) {
        bestOk = ok;
        bestFormat = f;
      }
    }
    guess.dateFormat = bestFormat;
    setState(() {
      _error = null;
      _table = t;
      _map = guess;
      _accountId ??= s.activeAccounts.isEmpty ? null : s.activeAccounts.first.id;
      _includeDups = false;
      _recompute();
    });
  }

  void _clear() => setState(() {
        _text.clear();
        _isSample = false;
        _table = null;
        _rows = const [];
        _error = null;
      });

  Future<void> _confirm() async {
    final s = context.readStore;
    if (_accountId == null) return;
    setState(() => _busy = true);
    try {
      final created = s.importRows(_rows, _accountId!, includeDuplicates: _includeDups);
      final skipped = _rows.length - created;
      if (!mounted) return;
      context.toast(context.t('imp.done', {'created': created, 'skipped': skipped}));
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) setState(() => _error = errorText(context, e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<(int, String)> _columns() => [for (var i = 0; i < (_table?.headers.length ?? 0); i++) (i, '${i + 1}. ${_table!.headers[i]}')];

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    if (!s.role.canApprove) {
      return PageScaffold(title: context.t('imp.title'), body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('imp.read_only')));
    }
    if (s.activeAccounts.isEmpty) {
      return PageScaffold(title: context.t('imp.title'), body: EmptyState(icon: Icons.account_balance_wallet_outlined, title: context.t('imp.no_account')));
    }

    final table = _table;
    final okRows = _rows.where((r) => r.ok && (!r.duplicate || _includeDups)).length;
    final dups = _rows.where((r) => r.ok && r.duplicate).length;
    final bad = _rows.where((r) => !r.ok).length;
    var i = 0;

    return PageScaffold(
      title: context.t('imp.title'),
      subtitle: context.t('imp.subtitle'),
      bottom: table == null
          ? null
          : SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                child: Row(children: [
                  Expanded(child: AppButton(label: context.t('imp.cancel'), kind: ButtonKind.glass, onPressed: _clear)),
                  const SizedBox(width: 12),
                  Expanded(flex: 2, child: AppButton(label: '${context.t('imp.confirm')} ($okRows)', icon: Icons.download_done_rounded, loading: _busy, onPressed: okRows == 0 || !_map.valid ? null : _confirm)),
                ]),
              ),
            ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Reveal(
            index: i++,
            child: GlassCard(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text(context.t('imp.step1'), style: TextStyle(color: g.text, fontSize: 16.5, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4),
                Text(context.t('imp.help'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35)),
                const SizedBox(height: 12),
                GlassField(controller: _text, label: context.t('imp.paste'), icon: Icons.table_chart_outlined, maxLines: 6, keyboardType: TextInputType.multiline),
                const SizedBox(height: 12),
                Wrap(spacing: 10, runSpacing: 10, children: [
                  AppButton(label: context.t('imp.read'), icon: Icons.fact_check_outlined, expanded: false, compact: true, onPressed: () => _read()),
                  AppButton(
                    label: context.t('imp.sample'),
                    icon: Icons.auto_awesome_motion_rounded,
                    expanded: false,
                    compact: true,
                    kind: ButtonKind.glass,
                    onPressed: () {
                      _text.text = _sample();
                      _isSample = true;
                      _read();
                    },
                  ),
                  AppButton(label: context.t('imp.clear'), icon: Icons.clear_rounded, expanded: false, compact: true, kind: ButtonKind.glass, onPressed: _clear),
                ]),
                if (_isSample) Padding(padding: const EdgeInsets.only(top: 10), child: Text(context.t('imp.sample_note'), style: TextStyle(color: g.textDim, fontSize: 12))),
                AnimatedSize(
                  duration: const Duration(milliseconds: 220),
                  child: _error == null ? const SizedBox(width: double.infinity) : Padding(padding: const EdgeInsets.only(top: 10), child: Text(_error!, style: TextStyle(color: g.danger, fontWeight: FontWeight.w600))),
                ),
              ]),
            ),
          ),
          if (table != null) ...[
            const SizedBox(height: 14),
            Reveal(
              index: i++,
              child: GlassCard(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(context.t('imp.step2'), style: TextStyle(color: g.text, fontSize: 16.5, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 4),
                  Text(context.t('imp.map_help'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.35)),
                  const SizedBox(height: 14),
                  PickerField<int>(label: context.t('imp.c_date'), icon: Icons.event_outlined, value: _map.date, options: _columns(), onChanged: (v) => setState(() {
                        _map.date = v ?? 0;
                        _recompute();
                      })),
                  const SizedBox(height: 12),
                  SegmentedGlass(
                    labels: [context.t('imp.mode_amount'), context.t('imp.mode_dc')],
                    index: _map.useDebitCredit ? 1 : 0,
                    onChanged: (k) => setState(() {
                      if (k == 0) {
                        _map.amount ??= _map.debit ?? _map.credit ?? (table.headers.length > 1 ? 1 : 0);
                        _map.debit = null;
                        _map.credit = null;
                      } else {
                        _map.debit ??= _map.amount ?? 1;
                        _map.credit ??= table.headers.length > 2 ? 2 : null;
                        _map.amount = null;
                      }
                      _recompute();
                    }),
                  ),
                  const SizedBox(height: 12),
                  if (!_map.useDebitCredit)
                    PickerField<int>(label: context.t('imp.c_amount'), icon: Icons.payments_outlined, value: _map.amount, options: _columns(), onChanged: (v) => setState(() {
                          _map.amount = v;
                          _recompute();
                        }))
                  else ...[
                    PickerField<int>(label: context.t('imp.c_debit'), icon: Icons.north_east_rounded, value: _map.debit, allowNone: true, options: _columns(), onChanged: (v) => setState(() {
                          _map.debit = v;
                          _recompute();
                        })),
                    const SizedBox(height: 12),
                    PickerField<int>(label: context.t('imp.c_credit'), icon: Icons.south_west_rounded, value: _map.credit, allowNone: true, options: _columns(), onChanged: (v) => setState(() {
                          _map.credit = v;
                          _recompute();
                        })),
                  ],
                  const SizedBox(height: 12),
                  PickerField<int>(label: context.t('imp.c_description'), icon: Icons.notes_rounded, value: _map.description, allowNone: true, options: _columns(), onChanged: (v) => setState(() {
                        _map.description = v;
                        _recompute();
                      })),
                  const SizedBox(height: 12),
                  PickerField<int>(label: context.t('imp.c_type'), icon: Icons.swap_vert_rounded, value: _map.type, allowNone: true, options: _columns(), onChanged: (v) => setState(() {
                        _map.type = v;
                        _recompute();
                      })),
                  const SizedBox(height: 12),
                  PickerField<int>(label: context.t('imp.c_reference'), icon: Icons.tag_rounded, value: _map.reference, allowNone: true, options: _columns(), onChanged: (v) => setState(() {
                        _map.reference = v;
                        _recompute();
                      })),
                  const SizedBox(height: 12),
                  PickerField<String>(label: context.t('imp.date_format'), icon: Icons.calendar_month_outlined, value: _map.dateFormat, options: [for (final f in kDateFormats) (f, f.replaceAll('d', 'DD').replaceAll('m', 'MM').replaceAll('Y', 'YYYY'))], onChanged: (v) => setState(() {
                        _map.dateFormat = v ?? 'd.m.Y';
                        _recompute();
                      })),
                  const SizedBox(height: 12),
                  PickerField<int>(label: context.t('imp.account'), icon: Icons.account_balance_wallet_outlined, value: _accountId, options: [for (final a in s.activeAccounts) (a.id, a.name)], onChanged: (v) => setState(() => _accountId = v)),
                  const SizedBox(height: 10),
                  Text(context.t('imp.sign_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35)),
                ]),
              ),
            ),
            const SizedBox(height: 18),
            Reveal(
              index: i++,
              child: Wrap(spacing: 8, runSpacing: 8, children: [
                StatusPill(label: context.t('imp.ok_n', {'n': okRows}), color: g.success, icon: Icons.check_circle_outline_rounded),
                if (dups > 0) StatusPill(label: context.t('imp.dup_n', {'n': dups}), color: g.warning, icon: Icons.copy_rounded),
                if (bad > 0) StatusPill(label: context.t('imp.bad_n', {'n': bad}), color: g.danger, icon: Icons.error_outline_rounded),
              ]),
            ),
            if (dups > 0) ...[
              const SizedBox(height: 12),
              SoftTile(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(child: Text(context.t('imp.include_dups'), style: TextStyle(color: g.text, fontWeight: FontWeight.w700))),
                    Switch(value: _includeDups, onChanged: (v) => setState(() => _includeDups = v)),
                  ]),
                  Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(context.t('imp.dups_hint'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.3))),
                ]),
              ),
            ],
            const SizedBox(height: 18),
            TileSection(
              title: context.t('imp.preview'),
              children: [
                for (final r in _rows.take(40))
                  SoftTile(
                    padding: const EdgeInsets.all(12),
                    tint: r.ok ? null : g.danger.withValues(alpha: .1),
                    child: Row(children: [
                      IconBadge(
                        icon: !r.ok ? Icons.error_outline_rounded : (r.type == TxType.income ? Icons.south_west_rounded : Icons.north_east_rounded),
                        size: 38,
                        color: !r.ok ? g.danger : (r.duplicate ? g.warning : (r.type == TxType.income ? g.success : g.danger)),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(r.description.isEmpty ? context.t('imp.row', {'n': r.line}) : r.description, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14)),
                          const SizedBox(height: 2),
                          Text(
                            r.ok ? context.date(r.date) : '${context.t('imp.row', {'n': r.line})} · ${context.t(r.problem == ImportProblem.badDate ? 'imp.bad_date' : 'imp.bad_amount')}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(color: r.ok ? g.textDim : g.danger, fontSize: 12.5),
                          ),
                        ]),
                      ),
                      const SizedBox(width: 8),
                      if (r.ok)
                        Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisSize: MainAxisSize.min, children: [
                          Text(context.money(r.type == TxType.income ? r.minor : -r.minor, sign: true), style: TextStyle(color: r.type == TxType.income ? g.success : g.text, fontWeight: FontWeight.w800, fontSize: 13.5)),
                          if (r.duplicate) Padding(padding: const EdgeInsets.only(top: 3), child: StatusPill(label: context.t('imp.dup'), color: g.warning)),
                        ]),
                    ]),
                  ),
              ],
            ),
            if (_rows.length > 40) Padding(padding: const EdgeInsets.only(top: 10), child: Text('+${_rows.length - 40}', textAlign: TextAlign.center, style: TextStyle(color: g.textDim))),
          ],
        ],
      ),
    );
  }
}
