import 'dart:async';

import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/cabinet.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import '../tax/tax_common.dart';
import '../tax/tax_report_screen.dart';

/// The five steps of the automatic preparation; [current] is 1..5.
class RunStepper extends StatelessWidget {
  const RunStepper({super.key, required this.current});
  final int current;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    const keys = ['company', 'analysis', 'review', 'approval', 'submission'];
    final onAccent = g.dark ? const Color(0xFF04231A) : Colors.white;
    return Wrap(spacing: 8, runSpacing: 8, children: [
      for (var i = 0; i < keys.length; i++)
        AnimatedContainer(
          duration: const Duration(milliseconds: 300),
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
          decoration: BoxDecoration(
            color: i + 1 == current ? g.accent : g.fill,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: i + 1 <= current ? g.accent : g.border),
          ),
          child: Row(mainAxisSize: MainAxisSize.min, children: [
            Container(
              width: 20,
              height: 20,
              alignment: Alignment.center,
              decoration: BoxDecoration(shape: BoxShape.circle, color: i + 1 == current ? onAccent.withValues(alpha: .18) : g.accent.withValues(alpha: i + 1 < current ? .25 : .0)),
              child: i + 1 < current
                  ? Icon(Icons.check_rounded, size: 13, color: g.accent)
                  : Text('${i + 1}', style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: i + 1 == current ? onAccent : g.textDim)),
            ),
            const SizedBox(width: 6),
            Text(context.t('run.stepper.${keys[i]}'), style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: i + 1 == current ? onAccent : (i + 1 < current ? g.text : g.textDim))),
          ]),
        ),
    ]);
  }
}

/// One run: the analysis steps, the review of the reports, the approval of the selected versions and each report's submission state.
class RunScreen extends StatefulWidget {
  const RunScreen({super.key, required this.runId, this.animate = false});
  final int runId;
  final bool animate;

  @override
  State<RunScreen> createState() => _RunScreenState();
}

class _RunScreenState extends State<RunScreen> {
  int _shown = 0;
  Timer? _timer;
  final Set<int> _sel = {};
  bool _confirm = false, _declared = false;

  @override
  void initState() {
    super.initState();
    final run = CabinetData.of(context.readStore).runs.where((e) => e.id == widget.runId).firstOrNull;
    final n = run?.steps.length ?? 0;
    if (widget.animate && n > 0) {
      // a one-shot reveal of the steps that were already computed: it ends by itself
      _timer = Timer.periodic(const Duration(milliseconds: 420), (t) {
        if (!mounted) return;
        setState(() => _shown++);
        if (_shown >= n) t.cancel();
      });
    } else {
      _shown = n;
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  String _detail(CabRun run, RunStep st) {
    final p = <String, Object?>{...st.params};
    if (st.key == 'profile') p['regime'] = context.t('run.regime.${st.params['regime']}');
    return context.t('run.details.${st.key}', p);
  }

  Color _stateColor(Glass g, RowState s) => switch (s) {
        RowState.onCabinet || RowState.submitted => g.success,
        RowState.ready => g.accent,
        RowState.awaiting => g.warning,
        RowState.approved => g.accent2,
        RowState.dataMissing || RowState.validationFailed => g.danger,
      };

  Future<void> _approve(CabinetData d, CabRun run, List<RunRow> rows) async {
    ({int approved, List<String> refused})? result;
    final ok = context.attempt(() {
      result = d.approveRun(run, rows, declaration: _declared);
    });
    final r = result;
    if (!ok || r == null || !mounted) return;
    if (r.approved > 0) context.toast(context.t('run.batch_done', {'count': r.approved}));
    if (r.refused.isNotEmpty) context.toast(context.t('err.${r.refused.first}'));
    setState(() {
      _confirm = false;
      _declared = false;
      _sel.clear();
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final d = CabinetData.of(s);
    final run = d.runs.where((e) => e.id == widget.runId).firstOrNull;
    if (run == null) return PageScaffold(title: context.t('nav.runs'), body: EmptyState(icon: Icons.auto_mode_rounded, title: context.t('run.no_runs')));

    final steps = run.steps;
    final revealing = _shown < steps.length;
    final states = [for (final r in run.rows) d.stateOf(r)];
    final anySubmitted = states.any((e) => e == RowState.submitted || e == RowState.approved);
    final canApprove = s.role.canApprove;
    final selected = [for (var i = 0; i < run.rows.length; i++) if (_sel.contains(i) && d.selectable(run.rows[i])) run.rows[i]];
    final total = selected.fold(0, (a, r) => a + d.payable(r));
    final warnings = {for (final r in selected) ...r.warnings};
    final current = revealing ? 2 : (anySubmitted ? 5 : (_confirm ? 4 : 3));
    final notReq = d.notRequiredFor(run.month);

    final children = <Widget>[
      const DemoNote(),
      const SizedBox(height: 12),
      RunStepper(current: current),
      const SizedBox(height: 14),
      GlassCard(
        tint: g.info.withValues(alpha: .10),
        padding: const EdgeInsets.all(12),
        child: Row(children: [
          Icon(Icons.science_outlined, color: g.info, size: 18),
          const SizedBox(width: 10),
          Expanded(child: Text(context.t('run.sandbox_short'), style: TextStyle(color: g.textDim, fontSize: 12.5))),
        ]),
      ),
      const SizedBox(height: 16),
      TileSection(
        title: context.t('run.analysis'),
        children: [
          for (var i = 0; i < steps.length && i < _shown; i++)
            SoftTile(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                IconBadge(icon: steps[i].status == 'skipped' ? Icons.skip_next_rounded : Icons.check_rounded, color: steps[i].status == 'skipped' ? g.textDim : g.success, size: 36),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t('run.steps.${steps[i].key}'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                    const SizedBox(height: 2),
                    Text(_detail(run, steps[i]), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
                  ]),
                ),
              ]),
            ),
          if (revealing)
            SoftTile(
              padding: const EdgeInsets.fromLTRB(14, 14, 14, 14),
              child: Row(children: [
                SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: g.accent)),
                const SizedBox(width: 12),
                Text(context.t('run.analyzing'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w600)),
              ]),
            ),
        ],
      ),
    ];

    if (!revealing) {
      if (!_confirm) {
        children
          ..add(const SizedBox(height: 20))
          ..add(Text(context.t('run.review_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)))
          ..add(const SizedBox(height: 10))
          ..add(
            TileSection(
              title: context.t('run.review'),
              empty: context.t('run.none_ready'),
              children: [
                for (var i = 0; i < run.rows.length; i++) _rowTile(d, run, i, states[i]),
              ],
            ),
          );
        if (notReq.isNotEmpty) {
          children
            ..add(const SizedBox(height: 12))
            ..add(Text(context.t('run.not_required_list', {'reports': notReq.map((t) => context.t('taxtype.${t.name}')).join(', ')}), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)));
        }
        children.add(const SizedBox(height: 16));
        if (!canApprove) {
          children.add(Text(context.t('run.needs_permissions'), style: TextStyle(color: g.warning, fontSize: 13, height: 1.3)));
        } else {
          children.add(AppButton(label: context.t('run.review_selected'), icon: Icons.fact_check_outlined, onPressed: selected.isEmpty ? null : () => setState(() => _confirm = true)));
        }
      } else {
        children
          ..add(const SizedBox(height: 20))
          ..add(
            TileSection(
              title: context.t('run.confirm_title'),
              subtitle: context.t('run.confirm_subtitle'),
              children: [
                for (final r in selected)
                  SoftTile(
                    padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                    child: Row(children: [
                      IconBadge(icon: taxIcon(r.type), color: g.accent, size: 40),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(context.t('taxtype.${r.type.name}'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                          Text(periodLabel(context, r.start, r.end), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                        ]),
                      ),
                      Text(context.money(d.payable(r)), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14)),
                    ]),
                  ),
              ],
            ),
          )
          ..add(const SizedBox(height: 14))
          ..add(
            GlassCard(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(context.t('run.total_payable'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w700))),
                  AmountText(total, size: 20, animate: false),
                ]),
                if (warnings.isNotEmpty) ...[
                  const SizedBox(height: 12),
                  Text(context.t('run.warnings_title'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 13.5)),
                  const SizedBox(height: 6),
                  Wrap(spacing: 8, runSpacing: 8, children: [for (final w in warnings) StatusPill(label: context.t('run.warn.$w'), color: g.warning, icon: Icons.warning_amber_rounded)]),
                ],
                const SizedBox(height: 12),
                Text(context.t('run.version_hint'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.3)),
              ]),
            ),
          )
          ..add(const SizedBox(height: 14))
          ..add(
            GlassCard(
              padding: const EdgeInsets.fromLTRB(8, 8, 14, 8),
              onTap: () => setState(() => _declared = !_declared),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Checkbox(value: _declared, onChanged: (v) => setState(() => _declared = v ?? false)),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 10),
                    child: Text(
                      context.t('run.declaration', {'company': s.company.name, 'month': context.monthYear(run.month), 'count': selected.length}),
                      style: TextStyle(color: g.text, fontSize: 13.5, height: 1.4),
                    ),
                  ),
                ),
              ]),
            ),
          )
          ..add(const SizedBox(height: 16))
          ..add(AppButton(label: context.t('run.approve_and_submit'), icon: Icons.verified_rounded, onPressed: (_declared && selected.isNotEmpty) ? () => _approve(d, run, selected) : null))
          ..add(const SizedBox(height: 10))
          ..add(AppButton(label: context.t('run.back_to_review'), icon: Icons.arrow_back_rounded, kind: ButtonKind.glass, onPressed: () => setState(() => _confirm = false)));
      }
    }

    return PageScaffold(
      title: context.monthYear(run.month),
      subtitle: context.t('run.run_subtitle', {'month': context.dateTime(run.startedAt), 'who': run.by}),
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 8),
        children: [
          for (var i = 0; i < children.length; i++) children[i],
          const SizedBox(height: 14),
          Text(context.t('cab.disclaimer'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.35)),
        ],
      ),
    );
  }

  Widget _rowTile(CabinetData d, CabRun run, int i, RowState state) {
    final g = context.g;
    final r = run.rows[i];
    final selectable = d.selectable(r);
    final left = DateTime(r.due.year, r.due.month, r.due.day).difference(DateTime(DateTime.now().year, DateTime.now().month, DateTime.now().day)).inDays;
    final rep = d.reportOf(r);
    final color = _stateColor(g, state);
    return SoftTile(
      padding: const EdgeInsets.fromLTRB(8, 10, 14, 12),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (selectable)
            Checkbox(value: _sel.contains(i), onChanged: (v) => setState(() => v == true ? _sel.add(i) : _sel.remove(i)))
          else
            const SizedBox(width: 12),
          Expanded(
            child: GestureDetector(
              behavior: HitTestBehavior.opaque,
              onTap: rep == null ? null : () => pushPage(context, TaxReportScreen(obligation: obligationOf(context.readStore, rep))),
              child: Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('taxtype.${r.type.name}'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                  const SizedBox(height: 2),
                  Text('${periodLabel(context, r.start, r.end)} · ${context.date(r.due)}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                ]),
              ),
            ),
          ),
          const SizedBox(width: 8),
          if (rep != null && !r.onCabinet) Padding(padding: const EdgeInsets.only(top: 8), child: Text(context.money(rep.taxMinor), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 13.5))),
        ]),
        Padding(
          padding: const EdgeInsets.only(left: 12, top: 8),
          child: Wrap(spacing: 8, runSpacing: 6, crossAxisAlignment: WrapCrossAlignment.center, children: [
            StatusPill(label: context.t('run.check.${state.name}'), color: color),
            if (!r.onCabinet && state != RowState.submitted) DaysChip(left),
            for (final w in r.warnings) StatusPill(label: context.t('run.warn.$w'), color: g.warning, icon: Icons.warning_amber_rounded),
          ]),
        ),
        for (final p in r.problems) Padding(padding: const EdgeInsets.only(left: 12, top: 6), child: Text(context.t('run.problem.$p'), style: TextStyle(color: g.danger, fontSize: 12.5, height: 1.3))),
      ]),
    );
  }
}
