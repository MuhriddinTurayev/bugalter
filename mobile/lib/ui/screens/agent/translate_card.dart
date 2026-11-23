import 'dart:async';

import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/api.dart';
import '../../widgets/glass.dart';
import 'agent_actions.dart';
import 'server_pdf.dart';

/// "Translate and explain" for a letter of the tax authority or a Didox document of the REAL company, and the letter's original PDF. The server makes the
/// text in the language of the app (and the AI's explanation) in the background and keeps it; this card asks once, then asks again every few seconds until
/// it is kept, and reads the company again so the page shows it. It never changes the letter or the document.
class TranslateCard extends StatefulWidget {
  const TranslateCard({super.key, required this.kind, required this.id, required this.done, this.pdfName});

  /// 'letters' or 'didox' (the part of the server's address).
  final String kind;
  final int id;

  /// Is everything kept already for the language of the app (then only the PDF button is shown)?
  final bool done;

  /// The letter's PDF is offered when this is set.
  final String? pdfName;

  @override
  State<TranslateCard> createState() => _TranslateCardState();
}

class _TranslateCardState extends State<TranslateCard> {
  bool _working = false;
  Timer? _poll;
  int _tries = 0;

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  String get _path => '/api/mobile/${widget.kind}/${widget.id}';

  Future<void> _start() async {
    if (_working) return;
    final store = context.readStore;
    setState(() => _working = true);
    try {
      final r = await store.serverRequest('POST', '$_path/translate');
      if (!mounted) return;
      if (r['canTranslate'] != true && r['done'] != true) {
        setState(() => _working = false);
        context.toast(context.t('tr.no_ai'));
        return;
      }
      if (r["done"] == true) {
        await _finish();
        return;
      }
      _tries = 0;
      _poll = Timer.periodic(const Duration(seconds: 4), (_) => _check());
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _working = false);
      context.toast(apiErrorText(context, e));
    }
  }

  Future<void> _check() async {
    final store = context.readStore;
    _tries++;
    try {
      final r = await store.serverRequest('GET', _path);
      if (!mounted) return;
      if (r["done"] == true) {
        await _finish();
        return;
      }
    } on ApiException {
      // a short break in the network: ask again on the next tick
    }
    if (_tries >= 40 && mounted) {
      _poll?.cancel();
      setState(() => _working = false);
      context.toast(context.t('tr.failed'));
    }
  }

  Future<void> _finish() async {
    _poll?.cancel();
    final store = context.readStore;
    await store.syncNow();
    if (!mounted) return;
    setState(() => _working = false);
    context.toast(context.t('tr.done'));
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final showTranslate = !widget.done;
    if (!showTranslate && widget.pdfName == null) return const SizedBox.shrink();
    return GlassCard(
      tint: g.accent2.withValues(alpha: .08),
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (showTranslate) ...[
          Row(children: [
            Icon(Icons.translate_rounded, color: g.accent2, size: 20),
            const SizedBox(width: 8),
            Expanded(child: Text(context.t('tr.title'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
          ]),
          const SizedBox(height: 6),
          Text(context.t(_working ? 'tr.working_hint' : 'tr.hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3)),
          const SizedBox(height: 10),
          AppButton(label: context.t(_working ? 'tr.working' : 'tr.button'), icon: Icons.auto_awesome_rounded, loading: _working, onPressed: _working ? null : _start),
        ],
        if (widget.pdfName != null) ...[
          if (showTranslate) const SizedBox(height: 10),
          AppButton(label: context.t('tr.open_pdf'), icon: Icons.picture_as_pdf_rounded, kind: ButtonKind.glass, onPressed: () => openServerPdf(context, '$_path/document', widget.pdfName!)),
        ],
      ]),
    );
  }
}
