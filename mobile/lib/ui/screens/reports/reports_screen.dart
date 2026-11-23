import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../data/reports.dart';
import '../../widgets/charts.dart';
import '../../widgets/glass.dart';

/// Income and expense for a period: totals, a chart, categories, cash flow by account, debts and the main contractors.
/// It is a sum of the operations done, not a tax or accounting return.
class ReportsScreen extends StatefulWidget {
  const ReportsScreen({super.key});

  @override
  State<ReportsScreen> createState() => _ReportsScreenState();
}

class _ReportsScreenState extends State<ReportsScreen> {
  static const _presets = ['month', 'quarter', 'year', 'custom'];
  int _preset = 0;
  late DateTime _from;
  late DateTime _to;

  @override
  void initState() {
    super.initState();
    final r = presetRange('month', DateTime.now());
    _from = r.$1;
    _to = r.$2;
  }

  void _pick(int i) {
    setState(() {
      _preset = i;
      if (_presets[i] != 'custom') {
        final r = presetRange(_presets[i], DateTime.now());
        _from = r.$1;
        _to = r.$2;
      }
    });
  }

  String _text(BuildContext context, ReportData d) {
    final s = context.readStore;
    final b = StringBuffer()
      ..writeln(context.t('rep.txt_head', {'from': context.date(d.from), 'to': context.date(d.to)}))
      ..writeln('${context.t('rep.income')}: ${context.money(d.income)}')
      ..writeln('${context.t('rep.expense')}: ${context.money(d.expense)}')
      ..writeln('${context.t('rep.net')}: ${context.money(d.net)}');
    void block(String titleKey, List<CategoryTotal> rows) {
      if (rows.isEmpty) return;
      b
        ..writeln()
        ..writeln(context.t(titleKey));
      for (final r in rows) {
        b.writeln('• ${context.categoryName(s.category(r.categoryId))}: ${context.money(r.amount)}');
      }
    }

    block('rep.income_by_cat', d.incomeRows);
    block('rep.expense_by_cat', d.expenseRows);
    if (d.flows.isNotEmpty) {
      b
        ..writeln()
        ..writeln(context.t('rep.flow'));
      for (final f in d.flows) {
        b.writeln('• ${f.account.name}: +${context.money(f.income)} / −${context.money(f.expense)}');
      }
    }
    b
      ..writeln()
      ..writeln(context.t('rep.disclaimer'));
    return b.toString();
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final data = buildReport(s, _from, _to);
    final live = s.serverMode; // the real company: its taxes are in the tax cabinet, the report below is only a sum of its operations
    final taxReady = s.book.rules.isNotEmpty;
    var i = 0;

    final palette = [g.accent, g.accent2, g.info, g.warning, g.danger, g.textDim];
    final exp = data.expenseRows;
    final slices = <DonutSlice>[];
    var other = 0;
    for (var k = 0; k < exp.length; k++) {
      if (k < 5) {
        slices.add(DonutSlice(context.categoryName(s.category(exp[k].categoryId)), exp[k].amount, palette[k]));
      } else {
        other += exp[k].amount;
      }
    }
    if (other > 0) slices.add(DonutSlice('…', other, palette[5]));

    Widget stat(String label, int v, Color c) => Expanded(
          child: GlassCard(
            padding: const EdgeInsets.all(12),
            radius: 20,
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12, fontWeight: FontWeight.w600)),
              const SizedBox(height: 4),
              FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(context.compact(v), style: TextStyle(color: c, fontSize: 18, fontWeight: FontWeight.w800))),
            ]),
          ),
        );

    Widget categoryRows(String titleKey, List<CategoryTotal> rows, int total, Color color) => TileSection(
          title: context.t(titleKey),
          empty: context.t('rep.empty'),
          children: [
            for (final r in rows)
              SoftTile(
                padding: const EdgeInsets.all(14),
                child: Column(children: [
                  Row(children: [
                    Expanded(child: Text(context.categoryName(s.category(r.categoryId)), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 14.5))),
                    const SizedBox(width: 8),
                    Text(context.money(r.amount), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14)),
                  ]),
                  const SizedBox(height: 8),
                  Row(children: [
                    Expanded(child: MeterBar(fraction: total == 0 ? 0 : r.amount / total, color: color, height: 7)),
                    const SizedBox(width: 10),
                    SizedBox(width: 40, child: Text(total == 0 ? '' : '${(r.amount * 100 / total).round()}%', textAlign: TextAlign.end, style: TextStyle(color: g.textDim, fontSize: 12, fontWeight: FontWeight.w700))),
                  ]),
                ]),
              ),
          ],
        );

    final groups = [
      for (final b in data.buckets) BarGroup(b.monthly ? context.t('mon.${b.start.month}') : '${b.start.day}.${b.start.month}', b.income, b.expense),
    ];

    return PageScaffold(
      title: context.t('rep.title'),
      subtitle: '${context.date(data.from)} — ${context.date(data.to)}',
      actions: [
        GlassIconButton(
          icon: Icons.copy_rounded,
          tooltip: context.t('rep.copy'),
          onTap: () async {
            await Clipboard.setData(ClipboardData(text: _text(context, data)));
            if (context.mounted) context.toast(context.t('rep.copied'));
          },
        ),
      ],
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
        children: [
          if (live)
            Reveal(
              index: i++,
              child: SoftTile(
                padding: const EdgeInsets.all(14),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Icon(Icons.cloud_done_outlined, size: 20, color: g.success),
                  const SizedBox(width: 10),
                  Expanded(child: Text(context.t('srv.rep.note'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35))),
                ]),
              ),
            )
          else
          Reveal(
            index: i++,
            child: SoftTile(
              padding: const EdgeInsets.all(14),
              tint: (taxReady ? g.accent : g.warning).withValues(alpha: .12),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                IconBadge(icon: taxReady ? Icons.calculate_rounded : Icons.warning_amber_rounded, size: 42, color: taxReady ? g.accent : g.warning),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t(taxReady ? 'rep.tax_ready_title' : 'rep.tax_missing_title'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                    const SizedBox(height: 3),
                    Text(context.t(taxReady ? 'rep.tax_ready_body' : 'rep.tax_missing_body'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35)),
                  ]),
                ),
              ]),
            ),
          ),
          const SizedBox(height: 14),
          Reveal(index: i++, child: ChipBar(labels: [for (final p in _presets) context.t('rep.p_$p')], index: _preset, onChanged: _pick)),
          if (_presets[_preset] == 'custom') ...[
            const SizedBox(height: 12),
            Row(children: [
              Expanded(
                child: DateField(
                  label: context.t('rep.from'),
                  value: _from,
                  onChanged: (d) => setState(() {
                    _from = d ?? _from;
                    if (_from.isAfter(_to)) _to = _from;
                  }),
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: DateField(
                  label: context.t('rep.to'),
                  value: _to,
                  onChanged: (d) => setState(() {
                    _to = d ?? _to;
                    if (_to.isBefore(_from)) _from = _to;
                  }),
                ),
              ),
            ]),
          ],
          const SizedBox(height: 14),
          Reveal(index: i++, child: Row(children: [stat(context.t('rep.income'), data.income, g.success), const SizedBox(width: 10), stat(context.t('rep.expense'), data.expense, g.danger), const SizedBox(width: 10), stat(context.t('rep.net'), data.net, data.net >= 0 ? g.accent : g.warning)])),
          const SizedBox(height: 6),
          Align(alignment: Alignment.centerRight, child: Padding(padding: const EdgeInsets.only(right: 4), child: Text(context.t('rep.ops', {'n': data.count}), style: TextStyle(color: g.textDim, fontSize: 12)))),
          const SizedBox(height: 10),
          Reveal(
            index: i++,
            child: GlassCard(
              padding: const EdgeInsets.all(18),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(context.t('rep.chart'), style: TextStyle(color: g.text, fontSize: 16.5, fontWeight: FontWeight.w800)),
                Text(context.t(data.buckets.isNotEmpty && data.buckets.first.monthly ? 'rep.chart_month' : 'rep.chart_week'), style: TextStyle(color: g.textDim, fontSize: 12)),
                const SizedBox(height: 14),
                if (groups.isEmpty || (data.income == 0 && data.expense == 0))
                  Padding(padding: const EdgeInsets.symmetric(vertical: 24), child: Center(child: Text(context.t('rep.empty'), style: TextStyle(color: g.textDim))))
                else
                  BarsChart(labelA: context.t('txn.income'), labelB: context.t('txn.expense'), groups: groups),
              ]),
            ),
          ),
          const SizedBox(height: 18),
          if (slices.isNotEmpty)
            Reveal(
              index: i++,
              child: GlassCard(
                padding: const EdgeInsets.all(18),
                child: LayoutBuilder(builder: (context, c) {
                  final narrow = c.maxWidth < 330;
                  final donut = DonutChart(
                    size: narrow ? 130 : 140,
                    slices: slices,
                    center: Column(mainAxisSize: MainAxisSize.min, children: [
                      Text(context.compact(data.expense), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                      Text(context.t('txn.expense'), style: TextStyle(color: g.textDim, fontSize: 11)),
                    ]),
                  );
                  final legend = Column(children: [
                    for (final sl in slices)
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4),
                        child: Row(children: [
                          Container(width: 10, height: 10, decoration: BoxDecoration(color: sl.color, shape: BoxShape.circle)),
                          const SizedBox(width: 8),
                          Expanded(child: Text(sl.label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 13))),
                          Text(data.expense <= 0 ? '' : '${(sl.value * 100 / data.expense).round()}%', style: TextStyle(color: g.textDim, fontSize: 12.5, fontWeight: FontWeight.w700)),
                        ]),
                      ),
                  ]);
                  return narrow ? Column(children: [donut, const SizedBox(height: 12), legend]) : Row(children: [donut, const SizedBox(width: 16), Expanded(child: legend)]);
                }),
              ),
            ),
          if (slices.isNotEmpty) const SizedBox(height: 18),
          Reveal(index: i++, child: categoryRows('rep.income_by_cat', data.incomeRows, data.income, g.success)),
          const SizedBox(height: 22),
          Reveal(index: i++, child: categoryRows('rep.expense_by_cat', data.expenseRows, data.expense, g.danger)),
          const SizedBox(height: 22),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('rep.flow'),
              empty: context.t('rep.empty'),
              children: [
                for (final f in data.flows)
                  SoftTile(
                    padding: const EdgeInsets.all(14),
                    child: Row(children: [
                      IconBadge(icon: f.account.type == AccountType.cash ? Icons.payments_rounded : Icons.account_balance_rounded, size: 42, color: f.account.type == AccountType.cash ? g.warning : g.info),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(f.account.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                          const SizedBox(height: 2),
                          Text('+${context.compact(f.income)}  −${context.compact(f.expense)}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                        ]),
                      ),
                      const SizedBox(width: 8),
                      Flexible(flex: 0, child: Text(context.money(f.net, sign: true), style: TextStyle(color: f.net >= 0 ? g.success : g.danger, fontWeight: FontWeight.w800, fontSize: 14.5))),
                    ]),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('rep.debts'),
              children: [
                SoftTile(
                  padding: const EdgeInsets.all(14),
                  child: Row(children: [
                    for (final c in [
                      (context.t('dash.receivable'), data.receivable, g.success),
                      (context.t('dash.payable'), data.payable, g.warning),
                      (context.t('rep.overdue'), data.overdue, g.danger),
                    ])
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(c.$1, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 11.5, height: 1.1)),
                          const SizedBox(height: 4),
                          FittedBox(fit: BoxFit.scaleDown, alignment: Alignment.centerLeft, child: Text(context.compact(c.$2), style: TextStyle(color: c.$3, fontWeight: FontWeight.w800, fontSize: 15))),
                        ]),
                      ),
                  ]),
                ),
              ],
            ),
          ),
          const SizedBox(height: 22),
          Reveal(
            index: i++,
            child: TileSection(
              title: context.t('rep.top'),
              subtitle: context.t('rep.top_hint'),
              empty: context.t('rep.empty'),
              children: [
                for (final c in data.contractors)
                  SoftTile(
                    padding: const EdgeInsets.all(14),
                    child: Row(children: [
                      Avatar(name: c.contractor.name, size: 40),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(c.contractor.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                          const SizedBox(height: 2),
                          Text('+${context.compact(c.income)}  −${context.compact(c.expense)}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                        ]),
                      ),
                      const SizedBox(width: 8),
                      Flexible(flex: 0, child: Text(context.money(c.volume), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14))),
                    ]),
                  ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          Text(context.t('rep.disclaimer'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.35)),
        ],
      ),
    );
  }
}
