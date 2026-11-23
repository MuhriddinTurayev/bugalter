import 'dart:async';
import 'dart:math';

import 'package:flutter/material.dart';

import '../../../core/money.dart';
import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/api.dart';
import '../../widgets/glass.dart';
import '../agent/agent_actions.dart';
import 'cabinet_common.dart';

/// Tax payments of the REAL company, with the web's rules. What is due comes from the cabinet's own figures; a person presses "pay" for exactly one
/// tax, type and amount (or for the whole list they saw, bound by its hash) and the robot makes the payment in the tax cabinet, refusing if the
/// cabinet's form differs from what was approved. "Check without paying" (on by default here) makes the robot fill the form and stop. The phone never
/// moves money; it only sends the person's approval.
class LivePaymentsScreen extends StatefulWidget {
  const LivePaymentsScreen({super.key});

  @override
  State<LivePaymentsScreen> createState() => _LivePaymentsScreenState();
}

class _LivePaymentsScreenState extends State<LivePaymentsScreen> {
  Map<String, dynamic>? _data;
  bool _failed = false, _forbidden = false, _busy = false;
  bool _dry = true; // a check is the default: a real payment needs a deliberate switch and a confirmation
  Timer? _timer;
  final _amount = TextEditingController();
  final _purpose = TextEditingController();
  String _tax = '100';
  String _kind = '08102';
  String _token = _newToken();

  static String _newToken() {
    const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
    final r = Random.secure();
    return List.generate(28, (_) => chars[r.nextInt(chars.length)]).join();
  }

  @override
  void initState() {
    super.initState();
    _load();
    _timer = Timer.periodic(const Duration(seconds: 6), (_) => _load());
  }

  @override
  void dispose() {
    _timer?.cancel();
    _amount.dispose();
    _purpose.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final store = context.readStore;
    try {
      final d = await store.serverRequest('GET', '/api/mobile/payments');
      if (!mounted) return;
      setState(() {
        _data = d;
        _failed = false;
        _forbidden = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _forbidden = e.status == 403;
        _failed = e.status != 403;
      });
    }
  }

  /// Sends one approval; the server's own words (or the friendly ones) come back as a message.
  Future<bool> _send(String path, Map<String, dynamic> body) async {
    if (_busy) return false;
    final store = context.readStore;
    setState(() => _busy = true);
    String message;
    var ok = false;
    try {
      final r = await store.serverRequest('POST', path, body);
      message = r['message'] as String? ?? context.t('agent.refreshed');
      ok = true;
      store.watchAgent();
    } on ApiException catch (e) {
      message = apiErrorText(context, e);
    }
    if (!mounted) return ok;
    setState(() => _busy = false);
    context.toast(message);
    await _load();
    return ok;
  }

  Future<void> _payOne({required String tax, required String kind, required int minor, String? purpose}) async {
    final dry = _dry;
    final yes = await confirmDialog(
      context,
      title: context.t(dry ? 'pay.live.go_dry' : 'pay.confirm_title'),
      message: context.t(dry ? 'pay.live.confirm_dry' : 'pay.live.confirm_real', {'tax': cabTaxName(context, tax), 'kind': payKindName(context, kind), 'amount': context.money(minor)}),
      confirmLabel: context.t(dry ? 'pay.live.go_dry' : 'pay.live.go_real'),
      danger: !dry,
    );
    if (!yes || !mounted) return;
    final ok = await _send('/api/mobile/payments', {'taxCode': tax, 'kind': kind, 'amountMinor': minor, 'purpose': purpose == null || purpose.isEmpty ? null : purpose, 'dryRun': dry, 'clientToken': _token});
    if (ok) setState(() => _token = _newToken());
  }

  Future<void> _payAll(List<Map<String, dynamic>> items, String hash) async {
    final dry = _dry;
    final total = items.fold<int>(0, (a, e) => a + ((e['amountMinor'] as num?)?.toInt() ?? 0));
    final yes = await confirmDialog(
      context,
      title: context.t(dry ? 'pay.live.go_dry' : 'pay.all.title'),
      message: context.t(dry ? 'pay.live.confirm_all_dry' : 'pay.live.confirm_all_real', {'count': items.length, 'total': context.money(total)}),
      confirmLabel: context.t(dry ? 'pay.live.go_dry' : 'pay.live.go_real'),
      danger: !dry,
    );
    if (!yes || !mounted) return;
    await _send('/api/mobile/payments/all', {'plan': hash, 'nonce': _newToken(), 'dryRun': dry});
  }

  Future<void> _cancel(int id) async {
    final yes = await confirmDialog(context, title: context.t('pay.live.cancel'), message: context.t('pay.live.cancel_confirm'), danger: true, confirmLabel: context.t('common.yes'));
    if (!yes || !mounted) return;
    await _send('/api/mobile/payments/$id/cancel', const {});
  }

  Future<void> _submitForm() async {
    final minor = parseMinor(_amount.text);
    if (minor == null || minor < 100) {
      context.toast(context.t('common.required'));
      return;
    }
    await _payOne(tax: _tax, kind: _kind, minor: minor, purpose: _purpose.text.trim());
    if (mounted && _amount.text.isNotEmpty) {
      _amount.clear();
      _purpose.clear();
    }
  }

  Color _statusColor(Glass g, String s) => switch (s) {
        'paid' => g.success,
        'failed' => g.danger,
        'unknown' || 'submitted' => g.warning,
        'cancelled' => g.textDim,
        _ => g.info,
      };

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final title = context.t('nav.tax_payments');
    if (!s.serverMode) {
      return PageScaffold(title: title, body: EmptyState(icon: Icons.payments_outlined, title: context.t('pay.live.demo')));
    }
    if (_forbidden) {
      return PageScaffold(title: title, body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('srv.mod.no_access_text')));
    }
    final d = _data;
    if (d == null) {
      return PageScaffold(
        title: title,
        subtitle: context.t('pay.subtitle'),
        body: _failed ? EmptyState(icon: Icons.cloud_off_rounded, title: context.t('srv.offline'), text: context.t('srv.err_network')) : const Center(child: CircularProgressIndicator()),
      );
    }

    final plan = Map<String, dynamic>.from(d['plan'] as Map? ?? const {});
    final items = [for (final e in (plan['items'] as List? ?? const [])) Map<String, dynamic>.from(e as Map)];
    final hash = '${plan['hash'] ?? ''}';
    final taxes = [for (final t in (d['taxes'] as List? ?? const [])) '${(t as Map)['code']}'];
    final kinds = [for (final k in (d['kinds'] as List? ?? const [])) '$k'];
    final canPay = d['canPay'] == true;
    final online = d['agentOnline'] == true;
    final mine = [for (final p in (d['mine'] as List? ?? const [])) Map<String, dynamic>.from(p as Map)];
    final total = items.fold<int>(0, (a, e) => a + ((e['amountMinor'] as num?)?.toInt() ?? 0));
    final ready = canPay && online && !_busy;
    if (taxes.isNotEmpty && !taxes.contains(_tax)) _tax = taxes.first;
    if (kinds.isNotEmpty && !kinds.contains(_kind)) _kind = kinds.first;

    return PageScaffold(
      title: title,
      subtitle: context.t('pay.subtitle'),
      actions: [GlassIconButton(icon: Icons.refresh_rounded, tooltip: context.t('srv.refresh'), onTap: _load)],
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 8),
        children: [
          SoftTile(
            padding: const EdgeInsets.all(14),
            child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Icon(Icons.verified_user_outlined, color: g.accent2, size: 20),
              const SizedBox(width: 10),
              Expanded(child: Text(context.t('pay.live.note'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35))),
            ]),
          ),
          const SizedBox(height: 10),
          if (!canPay)
            Padding(padding: const EdgeInsets.fromLTRB(4, 0, 4, 10), child: Text(context.t('pay.live.cannot_pay'), style: TextStyle(color: g.warning, fontSize: 13, fontWeight: FontWeight.w700)))
          else if (!online)
            Padding(padding: const EdgeInsets.fromLTRB(4, 0, 4, 10), child: Text(context.t('pay.live.no_agent'), style: TextStyle(color: g.warning, fontSize: 13, fontWeight: FontWeight.w700))),
          // check or really pay: a deliberate switch
          SoftTile(
            padding: const EdgeInsets.fromLTRB(14, 8, 8, 8),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(context.t('pay.live.dry'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
                Switch(value: _dry, onChanged: (v) => setState(() => _dry = v)),
              ]),
              Padding(
                padding: const EdgeInsets.only(right: 8, bottom: 6),
                child: Text(context.t(_dry ? 'pay.live.dry_on' : 'pay.live.dry_off'), style: TextStyle(color: _dry ? g.textDim : g.danger, fontSize: 12.5, height: 1.3, fontWeight: _dry ? FontWeight.w500 : FontWeight.w800)),
              ),
            ]),
          ),
          const SizedBox(height: 16),
          TileSection(
            title: context.t('pay.live.due_title'),
            subtitle: context.t('pay.live.due_hint'),
            empty: items.isEmpty ? context.t('pay.live.nothing_due') : null,
            children: [
              for (final it in items)
                SoftTile(
                  padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      IconBadge(icon: Icons.account_balance_wallet_outlined, color: it['part'] == 'penalty' ? g.warning : g.danger, size: 40),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(cabTaxName(context, '${it['taxCode']}'), maxLines: 3, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                          Text(context.t('pay.live.part.${it['part']}'), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                          Text(context.money((it['amountMinor'] as num).toInt()), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                        ]),
                      ),
                    ]),
                    if (canPay) ...[
                      const SizedBox(height: 10),
                      Align(
                        alignment: Alignment.centerRight,
                        child: AppButton(
                          label: context.t(_dry ? 'pay.live.go_dry' : 'pay.live.go_real'),
                          icon: _dry ? Icons.fact_check_outlined : Icons.payments_rounded,
                          kind: _dry ? ButtonKind.glass : ButtonKind.primary,
                          compact: true,
                          expanded: false,
                          onPressed: ready ? () => _payOne(tax: '${it['taxCode']}', kind: '${it['kind']}', minor: (it['amountMinor'] as num).toInt()) : null,
                        ),
                      ),
                    ],
                  ]),
                ),
            ],
          ),
          if (items.length > 1 && canPay) ...[
            const SizedBox(height: 12),
            AppButton(
              label: context.t(_dry ? 'pay.live.check_all' : 'pay.live.pay_all', {'count': items.length, 'total': context.money(total)}),
              icon: _dry ? Icons.fact_check_outlined : Icons.payments_rounded,
              kind: _dry ? ButtonKind.glass : ButtonKind.primary,
              onPressed: ready ? () => _payAll(items, hash) : null,
            ),
          ],
          if (canPay) ...[
            const SizedBox(height: 20),
            GlassCard(
              padding: const EdgeInsets.all(16),
              child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text(context.t('pay.live.form_title'), style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 4),
                Text(context.t('pay.live.form_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
                const SizedBox(height: 14),
                PickerField<String>(
                  label: context.t('pay.tax'),
                  icon: Icons.account_balance_outlined,
                  value: _tax,
                  options: [for (final t in taxes) (t, cabTaxName(context, t))],
                  onChanged: (v) => setState(() => _tax = v ?? _tax),
                ),
                const SizedBox(height: 12),
                PickerField<String>(
                  label: context.t('pay.kind'),
                  icon: Icons.category_outlined,
                  value: _kind,
                  options: [for (final k in kinds) (k, payKindName(context, k))],
                  onChanged: (v) => setState(() => _kind = v ?? _kind),
                ),
                const SizedBox(height: 12),
                MoneyField(controller: _amount, label: context.t('pay.amount')),
                const SizedBox(height: 12),
                GlassField(controller: _purpose, label: context.t('pay.purpose'), icon: Icons.notes_rounded),
                const SizedBox(height: 16),
                AppButton(
                  label: context.t(_dry ? 'pay.live.go_dry' : 'pay.live.go_real'),
                  icon: _dry ? Icons.fact_check_outlined : Icons.payments_rounded,
                  kind: _dry ? ButtonKind.glass : ButtonKind.primary,
                  onPressed: ready ? _submitForm : null,
                ),
              ]),
            ),
          ],
          const SizedBox(height: 20),
          TileSection(
            title: context.t('pay.live.mine'),
            empty: mine.isEmpty ? context.t('pay.live.mine_empty') : null,
            children: [
              for (final p in mine)
                SoftTile(
                  padding: const EdgeInsets.fromLTRB(14, 12, 14, 12),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Expanded(child: Text(cabTaxName(context, '${p['taxCode']}'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
                      StatusPill(label: context.t('pay.st.${p['status']}'), color: _statusColor(g, '${p['status']}')),
                    ]),
                    const SizedBox(height: 4),
                    Text('${payKindName(context, '${p['kind']}')} · ${context.money((p['amountMinor'] as num).toInt())}', style: TextStyle(color: g.textDim, fontSize: 12.5)),
                    if (p['orderNumber'] != null) Text(context.t('pay.order', {'number': '${p['orderNumber']}'}), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                    if (p['createdAt'] != null) Text(context.dateTime(DateTime.parse('${p['createdAt']}')), style: TextStyle(color: g.textDim, fontSize: 12)),
                    if (p['error'] != null && '${p['error']}'.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text('${p['error']}', style: TextStyle(color: g.danger, fontSize: 12.5, height: 1.3))),
                    if (p['cancellable'] == true && canPay) ...[
                      const SizedBox(height: 8),
                      Align(alignment: Alignment.centerRight, child: AppButton(label: context.t('pay.live.cancel'), kind: ButtonKind.glass, compact: true, expanded: false, onPressed: _busy ? null : () => _cancel((p['id'] as num).toInt()))),
                    ],
                  ]),
                ),
            ],
          ),
          const SizedBox(height: 14),
          Text(context.t('cab.disclaimer'), style: TextStyle(color: g.textDim, fontSize: 12, height: 1.35)),
        ],
      ),
    );
  }
}
