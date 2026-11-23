import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/api.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import '../agent/agent_actions.dart';

/// What the real assistant puts under its answer: a payment proposal, a question about the amount or the tax, a note about a document. The assistant
/// itself pays nothing and signs nothing: a payment starts only when the person presses a button here and confirms, exactly as on the website and in the bot.
class AiExtraCards extends StatelessWidget {
  const AiExtraCards({super.key, required this.message, required this.onAsk});
  final ChatMessage message;

  /// A choice made with a button (the tax or the amount): asks the assistant again with it.
  final void Function(String label, Map<String, dynamic> pick) onAsk;

  @override
  Widget build(BuildContext context) {
    final x = message.extra;
    if (x == null) return const SizedBox.shrink();
    final g = context.g;
    final p = x['proposal'] is Map ? Map<String, dynamic>.from(x['proposal'] as Map) : null;
    final amount = x['askAmount'] is Map ? Map<String, dynamic>.from(x['askAmount'] as Map) : null;
    final tax = x['askTax'] is Map ? Map<String, dynamic>.from(x['askTax'] as Map) : null;
    final doc = x['docAction'] is Map ? Map<String, dynamic>.from(x['docAction'] as Map) : null;
    final draft = x['document'] is Map ? Map<String, dynamic>.from(x['document'] as Map) : null;
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (p != null) _Proposal(message: message, p: p),
        if (amount != null) _AskAmount(a: amount, onAsk: onAsk),
        if (tax != null) _AskTax(t: tax, onAsk: onAsk),
        if (doc != null)
          _Note(icon: Icons.description_rounded, color: g.warning, text: context.t('ai.doc.note', {'title': '${doc['title'] ?? ''}${doc['counterparty'] != null ? ' · ${doc['counterparty']}' : ''}'})),
        if (draft != null) _Note(icon: Icons.gavel_rounded, color: g.accent, text: context.t('ai.doc.draft', {'title': '${draft['title'] ?? ''}'})),
      ]),
    );
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.color, required this.text});
  final IconData icon;
  final Color color;
  final String text;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(icon, size: 18, color: color),
        const SizedBox(width: 8),
        Expanded(child: Text(text, style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35))),
      ]),
    );
  }
}

class _Proposal extends StatelessWidget {
  const _Proposal({required this.message, required this.p});
  final ChatMessage message;
  final Map<String, dynamic> p;

  Future<void> _pay(BuildContext context, {required bool dry}) async {
    final store = context.readStore;
    final minor = (p['amountMinor'] as num).toInt();
    final yes = await confirmDialog(
      context,
      title: context.t(dry ? 'pay.live.go_dry' : 'pay.confirm_title'),
      message: context.t(dry ? 'pay.live.confirm_dry' : 'pay.live.confirm_real', {'tax': '${p['taxName']}', 'kind': '${p['kindName']}', 'amount': context.money(minor)}),
      confirmLabel: context.t(dry ? 'pay.live.go_dry' : 'pay.live.go_real'),
      danger: !dry,
    );
    if (!yes || !context.mounted) return;
    // the check and the real payment are two different requests: the same opened proposal pressed twice is still one payment
    final token = '${p['token']}';
    final clientToken = dry && token.length > 1 ? 'd${token.substring(1)}' : token;
    try {
      final r = await store.serverRequest('POST', '/api/mobile/payments', {
        'taxCode': p['taxCode'], 'kind': p['kind'], 'amountMinor': minor, 'purpose': p['purpose'], 'dryRun': dry, 'clientToken': clientToken,
      });
      message.extra?['done'] = dry ? 'dry' : 'real';
      store.changed();
      store.watchAgent();
      if (context.mounted) context.toast('${r['message'] ?? context.t('agent.refreshed')}');
    } on ApiException catch (e) {
      if (context.mounted) context.toast(apiErrorText(context, e));
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final done = message.extra?['done'];
    final allowed = context.store.permissions.contains('tax.pay');
    return GlassCard(
      tint: g.accent2.withValues(alpha: .10),
      radius: 18,
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Icon(Icons.payments_rounded, color: g.accent2, size: 20),
          const SizedBox(width: 8),
          Expanded(child: Text(context.t('ai.pay.title'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
        ]),
        const SizedBox(height: 8),
        _Line(context.t('pay.tax'), '${p['taxName']}'),
        _Line(context.t('pay.kind'), '${p['kindName']}'),
        _Line(context.t('pay.amount'), '${p['amountLabel']}', bold: true),
        const SizedBox(height: 10),
        if (done != null)
          StatusPill(label: context.t(done == 'dry' ? 'ai.pay.dry_queued' : 'ai.pay.queued'), color: g.success, icon: Icons.check_circle_outline_rounded)
        else if (!allowed)
          Text(context.t('pay.live.cannot_pay'), style: TextStyle(color: g.warning, fontSize: 12.5, fontWeight: FontWeight.w700))
        else ...[
          AppButton(label: context.t('pay.live.go_real'), icon: Icons.payments_rounded, onPressed: () => _pay(context, dry: false)),
          const SizedBox(height: 8),
          AppButton(label: context.t('pay.live.dry'), icon: Icons.fact_check_outlined, kind: ButtonKind.glass, onPressed: () => _pay(context, dry: true)),
          const SizedBox(height: 8),
          Text(context.t('ai.pay.note'), style: TextStyle(color: g.textDim, fontSize: 11.5, height: 1.3)),
        ],
      ]),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line(this.label, this.value, {this.bold = false});
  final String label, value;
  final bool bold;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        SizedBox(width: 96, child: Text(label, style: TextStyle(color: g.textDim, fontSize: 12.5))),
        Expanded(child: Text(value, style: TextStyle(color: g.text, fontSize: bold ? 15 : 13.5, fontWeight: bold ? FontWeight.w800 : FontWeight.w600))),
      ]),
    );
  }
}

class _AskAmount extends StatefulWidget {
  const _AskAmount({required this.a, required this.onAsk});
  final Map<String, dynamic> a;
  final void Function(String, Map<String, dynamic>) onAsk;

  @override
  State<_AskAmount> createState() => _AskAmountState();
}

class _AskAmountState extends State<_AskAmount> {
  final _c = TextEditingController();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return GlassCard(
      radius: 18,
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text('${widget.a['taxName']}', style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
        const SizedBox(height: 4),
        Text(context.t('ai.pay.ask_amount'), style: TextStyle(color: g.textDim, fontSize: 12.5)),
        const SizedBox(height: 10),
        MoneyField(controller: _c, label: context.t('pay.amount')),
        const SizedBox(height: 10),
        AppButton(
          label: context.t('ai.pay.propose'),
          icon: Icons.send_rounded,
          compact: true,
          onPressed: () {
            final digits = _c.text.replaceAll(RegExp(r'[^0-9]'), '');
            if (digits.isEmpty) return;
            widget.onAsk('${widget.a['taxName']} · $digits', {'taxCode': widget.a['taxCode'], 'kind': widget.a['kind'], 'amount': digits});
          },
        ),
      ]),
    );
  }
}

class _AskTax extends StatelessWidget {
  const _AskTax({required this.t, required this.onAsk});
  final Map<String, dynamic> t;
  final void Function(String, Map<String, dynamic>) onAsk;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final options = [for (final o in (t['options'] as List? ?? const [])) if (o is Map) Map<String, dynamic>.from(o)];
    return GlassCard(
      radius: 18,
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t('ai.pay.ask_tax'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
        const SizedBox(height: 10),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final o in options)
            PressScale(
              onTap: () => onAsk('${o['taxName']} · ${t['amount']}', {'taxCode': o['taxCode'], 'kind': t['kind'], 'amount': '${t['amount']}'}),
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                decoration: BoxDecoration(color: g.fill, borderRadius: BorderRadius.circular(16), border: Border.all(color: g.border)),
                child: Text('${o['taxName']}', style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 13)),
              ),
            ),
        ]),
      ]),
    );
  }
}
