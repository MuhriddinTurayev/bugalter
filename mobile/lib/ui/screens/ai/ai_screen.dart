import 'dart:async';
import 'dart:math' as math;
import 'dart:ui' show FontFeature;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/assistant.dart';
import '../../../data/models.dart';
import '../../../data/voice.dart';
import '../../widgets/ai_fab.dart';
import '../../widgets/glass.dart';
import '../documents/document_form.dart';
import '../tax/tax_report_screen.dart';
import 'proposals_screen.dart';

/// The AI accountant chat. Answers come from the books on this device; it can neither sign nor submit anything.
/// You can type, or hold a voice conversation: the phone turns your speech into words and can read the answers aloud.
class AiScreen extends StatefulWidget {
  const AiScreen({super.key});

  @override
  State<AiScreen> createState() => _AiScreenState();
}

class _AiScreenState extends State<AiScreen> {
  static final Expando<AiAction> _actions = Expando<AiAction>('ai-actions');
  static const _starter = ['balance', 'income', 'overdue', 'tax', 'payroll', 'unclassified'];

  final _ctl = TextEditingController();
  final _scroll = ScrollController();
  final _voice = VoiceService();
  bool _typing = false;

  // voice message being recorded
  bool _recording = false, _transcribing = false, _finishing = false;
  bool _locked = false; // recording continues without holding the button
  bool _pressUp = false; // the finger was lifted before the recorder was ready
  Offset _drag = Offset.zero; // how far the held finger moved (left = cancel, up = lock)
  String _live = '';
  final List<double> _levels = [];
  Timer? _clock;
  int _secs = 0;
  ChatMessage? _speaking;

  @override
  void initState() {
    super.initState();
    Future.microtask(() => aiScreenOpen.value = true);
    _toBottom(jump: true);
  }

  @override
  void dispose() {
    // not synchronously: the widget tree is locked while a screen is being removed
    Future.microtask(() => aiScreenOpen.value = false);
    _clock?.cancel();
    _voice.dispose();
    _ctl.dispose();
    _scroll.dispose();
    super.dispose();
  }

  void _toBottom({bool jump = false}) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_scroll.hasClients) return;
      final end = _scroll.position.maxScrollExtent;
      if (jump) {
        _scroll.jumpTo(end);
      } else {
        _scroll.animateTo(end, duration: const Duration(milliseconds: 380), curve: Curves.easeOutCubic);
      }
    });
  }

  Future<void> _send(String text, {bool voice = false}) async {
    final q = text.trim();
    if (q.isEmpty || _typing) return;
    final s = context.readStore;
    final locale = s.locale;
    _ctl.clear();
    s.addChat(ChatMessage(fromUser: true, text: q, voice: voice));
    setState(() => _typing = true);
    _toBottom();
    await Future<void>.delayed(const Duration(milliseconds: 600));
    final reply = Assistant(s).answer(q, locale);
    final msg = ChatMessage(fromUser: false, text: reply.text, chips: reply.chips);
    _actions[msg] = reply.action;
    s.addChat(msg);
    if (mounted) {
      setState(() => _typing = false);
      _toBottom();
      if (s.aiSpeak) _speak(msg);
    }
  }

  // ───────────── voice ─────────────

  Future<void> _startVoice({required bool locked}) async {
    if (_typing || _recording || _transcribing) return;
    FocusScope.of(context).unfocus();
    await _voice.stopSpeaking();
    final ok = await _voice.init();
    if (!mounted) return;
    if (!ok) {
      final denied = await _voice.micDenied;
      if (mounted) context.toast(context.t(denied ? 'ai.no_mic' : 'ai.no_voice'));
      return;
    }
    _voice.onStatus = (st) {
      // the phone ended the session (a pause in speech): send what was heard
      if ((st == 'done' || st == 'notListening') && _recording && !_finishing) _finishVoice();
    };
    _voice.onError = (e) {
      if (_recording && !_finishing && mounted) {
        _resetVoice();
        context.toast(context.t('ai.voice_failed'));
      }
    };
    setState(() {
      _recording = true;
      _finishing = false;
      // the finger was already lifted while the microphone was being prepared (first-time permission): keep recording, locked
      _locked = locked || _pressUp;
      _live = '';
      _levels.clear();
      _secs = 0;
    });
    _clock = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) setState(() => _secs++);
    });
    try {
      await _voice.listen(
        appLocale: context.readStore.locale,
        onWords: (w, _) {
          if (mounted) setState(() => _live = w);
        },
        onLevel: (l) {
          if (!mounted || !_recording) return;
          setState(() {
            _levels.add(l);
            if (_levels.length > 34) _levels.removeAt(0);
          });
        },
      );
    } catch (_) {
      _resetVoice();
      if (mounted) context.toast(context.t('ai.no_voice'));
    }
  }

  void _resetVoice() {
    _clock?.cancel();
    _finishing = false;
    if (mounted) {
      setState(() {
        _recording = false;
        _transcribing = false;
        _locked = false;
        _drag = Offset.zero;
        _live = '';
      });
    }
  }

  // ───────────── the one round button: mic (hold or tap) / send ─────────────

  bool get _hasText => _ctl.text.trim().isNotEmpty;

  /// Tap: start a locked recording (no need to hold), send the text, or send the recording.
  void _onTap() {
    if (_typing || _transcribing) return;
    if (_recording) {
      if (_locked) _finishVoice();
      return;
    }
    if (_hasText) {
      _send(_ctl.text);
    } else {
      _startVoice(locked: true);
    }
  }

  /// Hold: record while the finger is down. Slide left to cancel, slide up to lock.
  void _onHoldStart() {
    if (_typing || _transcribing || _recording || _hasText) return;
    _pressUp = false;
    _drag = Offset.zero;
    HapticFeedback.mediumImpact();
    _startVoice(locked: false);
  }

  void _onHoldMove(Offset o) {
    if (!_recording || _locked) return;
    if (o.dy < -90) {
      HapticFeedback.lightImpact();
      setState(() {
        _locked = true;
        _drag = Offset.zero;
      });
    } else if (o.dx < -130) {
      HapticFeedback.heavyImpact();
      _cancelVoice();
    } else {
      setState(() => _drag = o);
    }
  }

  void _onHoldEnd() {
    _pressUp = true;
    if (_recording && !_locked) _finishVoice();
  }

  Future<void> _cancelVoice() async {
    await _voice.cancel();
    _resetVoice();
  }

  Future<void> _finishVoice() async {
    if (!_recording || _finishing) return;
    _finishing = true;
    _clock?.cancel();
    setState(() {
      _recording = false;
      _transcribing = true;
    });
    await _voice.stop();
    await Future<void>.delayed(const Duration(milliseconds: 700)); // the last words arrive just after stopping
    if (!mounted) return;
    final text = _live.trim();
    _resetVoice();
    if (text.isEmpty) {
      context.toast(context.t('ai.voice_failed'));
      return;
    }
    _send(text, voice: true);
  }

  Future<void> _speak(ChatMessage m) async {
    if (_speaking == m) {
      await _voice.stopSpeaking();
      if (mounted) setState(() => _speaking = null);
      return;
    }
    final locale = context.readStore.locale;
    await _voice.stopSpeaking();
    final ok = await _voice.speak(m.text, locale, onDone: () {
      if (mounted && _speaking == m) setState(() => _speaking = null);
    });
    if (!mounted) return;
    if (ok) {
      setState(() => _speaking = m);
    } else {
      context.toast(context.t('ai.tts_missing'));
    }
  }

  Future<void> _toggleSpeak() async {
    final s = context.readStore;
    final on = !s.aiSpeak;
    await s.setAiSpeak(on);
    if (!on) {
      await _voice.stopSpeaking();
      if (mounted) setState(() => _speaking = null);
    }
    if (mounted) context.toast(context.t(on ? 'ai.speak_on' : 'ai.speak_off'));
  }

  void _run(AiAction a) {
    switch (a.kind) {
      case AiActionKind.proposals:
        pushPage(context, const ProposalsScreen());
      case AiActionKind.tax:
        final o = a.obligation;
        if (o != null) pushPage(context, TaxReportScreen(obligation: o));
      case AiActionKind.invoice:
        pushPage(context, const DocumentFormScreen());
    }
  }

  Future<void> _clear() async {
    final s = context.readStore;
    final ok = await confirmDialog(context, title: context.t('ai.clear'), message: context.t('ai.clear_confirm'), danger: true, confirmLabel: context.t('common.delete'));
    if (ok) s.clearChat();
  }

  // ───────────── build ─────────────

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final msgs = s.chat;
    final last = msgs.isNotEmpty && !msgs.last.fromUser ? msgs.last : null;
    final chips = _typing ? const <String>[] : (last != null && last.chips.isNotEmpty ? last.chips : [for (final k in _starter) context.t('ai.chip.$k')]);
    final count = 1 + msgs.length + (_typing ? 1 : 0) + 1;

    return PageScaffold(
      title: context.t('nav.ai'),
      subtitle: context.t('ai.note'),
      actions: [
        GlassIconButton(icon: s.aiSpeak ? Icons.volume_up_rounded : Icons.volume_off_rounded, tooltip: context.t(s.aiSpeak ? 'ai.speak_on' : 'ai.speak_off'), color: s.aiSpeak ? context.g.accent : null, onTap: _toggleSpeak),
        if (msgs.isNotEmpty) GlassIconButton(icon: Icons.delete_sweep_outlined, tooltip: context.t('ai.clear'), onTap: _clear),
      ],
      body: ListView.builder(
        controller: _scroll,
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        itemCount: count,
        itemBuilder: (context, i) {
          if (i == 0) return _Bubble(key: const ValueKey('greeting'), text: context.t('ai.greeting'), fromUser: false);
          final mi = i - 1;
          if (mi < msgs.length) {
            final m = msgs[mi];
            final a = m.fromUser ? null : _actions[m];
            return _Bubble(
              key: ObjectKey(m),
              text: m.text,
              fromUser: m.fromUser,
              voice: m.voice,
              action: a,
              onAction: a == null ? null : () => _run(a),
              speaking: _speaking == m,
              onSpeak: m.fromUser ? null : () => _speak(m),
            );
          }
          if (_typing && mi == msgs.length) return const _TypingBubble(key: ValueKey('typing'));
          return Padding(
            padding: const EdgeInsets.only(top: 6, left: 4),
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 300),
              child: Wrap(
                key: ValueKey(chips.join('|')),
                spacing: 8,
                runSpacing: 8,
                children: [for (final c in chips) _SuggestionChip(label: c, onTap: () => _send(c))],
              ),
            ),
          );
        },
      ),
      bottom: SafeArea(
        top: false,
        child: Center(
          heightFactor: 1,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 900),
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 6, 16, 12),
              child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                // above the row: the lock hint while the finger is held, or the words heard so far once locked
                AnimatedSize(
                  duration: const Duration(milliseconds: 240),
                  curve: Curves.easeOutCubic,
                  alignment: Alignment.bottomCenter,
                  child: _recording && !_locked
                      ? Align(alignment: Alignment.centerRight, child: Padding(padding: const EdgeInsets.only(right: 6, bottom: 10), child: _LockHint(progress: (-_drag.dy / 90).clamp(0.0, 1.0))))
                      : (_recording && _locked && _live.isNotEmpty
                          ? Padding(
                              padding: const EdgeInsets.only(bottom: 8),
                              child: GlassCard(blur: false, radius: 18, padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10), child: Text(_live, maxLines: 3, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 15, height: 1.3))),
                            )
                          : const SizedBox(width: double.infinity)),
                ),
                Row(crossAxisAlignment: CrossAxisAlignment.center, children: [
                  Expanded(
                    child: AnimatedSwitcher(
                      duration: const Duration(milliseconds: 260),
                      switchInCurve: Curves.easeOutCubic,
                      transitionBuilder: (c, a) => FadeTransition(opacity: a, child: ScaleTransition(scale: Tween(begin: .96, end: 1.0).animate(a), child: c)),
                      child: _transcribing
                          ? const _TranscribingBar(key: ValueKey('transcribing'))
                          : _recording
                              ? (_locked
                                  ? _LockedBar(key: const ValueKey('locked'), secs: _secs, levels: _levels, onCancel: _cancelVoice)
                                  : _HoldBar(key: const ValueKey('hold'), secs: _secs, dx: _drag.dx))
                              : _InputField(key: const ValueKey('input'), controller: _ctl, onChanged: () => setState(() {}), onSend: () => _send(_ctl.text)),
                    ),
                  ),
                  const SizedBox(width: 10),
                  // one persistent button, so the held finger keeps its gesture while the bar around it changes
                  GestureDetector(
                    behavior: HitTestBehavior.opaque,
                    onTap: _onTap,
                    onLongPressStart: (_) => _onHoldStart(),
                    onLongPressMoveUpdate: (d) => _onHoldMove(d.offsetFromOrigin),
                    onLongPressEnd: (_) => _onHoldEnd(),
                    onLongPressCancel: _onHoldEnd,
                    child: _ActionButton(mode: _recording ? (_locked ? _ActionMode.send : _ActionMode.hold) : (_hasText ? _ActionMode.send : _ActionMode.mic), disabled: _typing || _transcribing),
                  ),
                ]),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

class _InputField extends StatelessWidget {
  const _InputField({super.key, required this.controller, required this.onChanged, required this.onSend});
  final TextEditingController controller;
  final VoidCallback onChanged, onSend;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return TextField(
      controller: controller,
      minLines: 1,
      maxLines: 4,
      textInputAction: TextInputAction.send,
      onSubmitted: (_) => onSend(),
      onChanged: (_) => onChanged(),
      style: TextStyle(color: g.text, fontSize: 16),
      decoration: glassDecoration(context, hint: context.t('ai.input')).copyWith(
        fillColor: g.fillStrong,
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(26), borderSide: BorderSide(color: g.border)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(26), borderSide: BorderSide(color: g.border)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(26), borderSide: BorderSide(color: g.accent, width: 1.6)),
      ),
    );
  }
}

enum _ActionMode { mic, hold, send }

/// The round button on the right: a microphone when the box is empty (hold it to record), an arrow to send.
/// While the finger is held it grows and pulses. Switching between mic and arrow is animated.
class _ActionButton extends StatefulWidget {
  const _ActionButton({required this.mode, required this.disabled});
  final _ActionMode mode;
  final bool disabled;

  @override
  State<_ActionButton> createState() => _ActionButtonState();
}

class _ActionButtonState extends State<_ActionButton> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 1200));

  // the ring pulses only while the finger is held (a running animation needs the screen to redraw)
  @override
  void initState() {
    super.initState();
    if (widget.mode == _ActionMode.hold) _pulse.repeat();
  }

  @override
  void didUpdateWidget(_ActionButton old) {
    super.didUpdateWidget(old);
    final hold = widget.mode == _ActionMode.hold;
    if (hold && !_pulse.isAnimating) {
      _pulse.repeat();
    } else if (!hold && _pulse.isAnimating) {
      _pulse.stop();
    }
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final onAccent = g.dark ? const Color(0xFF04231A) : Colors.white;
    final hold = widget.mode == _ActionMode.hold;
    final isMic = widget.mode != _ActionMode.send;
    final size = hold ? 68.0 : 52.0;
    return SizedBox(
      width: 56,
      height: 56,
      child: OverflowBox(
        maxWidth: 120,
        maxHeight: 120,
        child: Opacity(
          opacity: widget.disabled ? .5 : 1,
          child: Stack(alignment: Alignment.center, children: [
            if (hold)
              AnimatedBuilder(
                animation: _pulse,
                builder: (context, _) => Container(
                  width: 68 + 44 * _pulse.value,
                  height: 68 + 44 * _pulse.value,
                  decoration: BoxDecoration(shape: BoxShape.circle, color: g.danger.withValues(alpha: .28 * (1 - _pulse.value))),
                ),
              ),
            AnimatedContainer(
              duration: const Duration(milliseconds: 260),
              curve: Curves.easeOutBack,
              width: size,
              height: size,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: isMic && !hold ? g.fillStrong : null,
                gradient: isMic && !hold ? null : LinearGradient(colors: hold ? [g.danger, Color.lerp(g.danger, g.accent2, .4)!] : [g.accent, g.accent2]),
                border: isMic && !hold ? Border.all(color: g.border) : null,
                boxShadow: isMic && !hold ? null : [BoxShadow(color: (hold ? g.danger : g.accent).withValues(alpha: .4), blurRadius: 18, spreadRadius: -4, offset: const Offset(0, 6))],
              ),
              child: AnimatedSwitcher(
                duration: const Duration(milliseconds: 300),
                switchInCurve: Curves.easeOutBack,
                transitionBuilder: (c, a) => ScaleTransition(scale: a, child: RotationTransition(turns: Tween(begin: .15, end: 0.0).animate(a), child: FadeTransition(opacity: a, child: c))),
                child: isMic
                    ? Icon(Icons.mic_rounded, key: ValueKey('mic$hold'), color: hold ? Colors.white : g.accent, size: hold ? 32 : 26)
                    : Icon(Icons.arrow_upward_rounded, key: const ValueKey('send'), color: onAccent, size: 26),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}

/// Shown while the finger is held: the blinking red dot, the timer and a "slide to cancel" hint that follows the finger.
class _HoldBar extends StatelessWidget {
  const _HoldBar({super.key, required this.secs, required this.dx});
  final int secs;
  final double dx;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final clock = '${(secs ~/ 60).toString().padLeft(2, '0')}:${(secs % 60).toString().padLeft(2, '0')}';
    final left = (-dx).clamp(0.0, 130.0);
    return Container(
      height: 52,
      padding: const EdgeInsets.symmetric(horizontal: 16),
      decoration: BoxDecoration(color: g.fillStrong, borderRadius: BorderRadius.circular(26), border: Border.all(color: g.border)),
      child: Row(children: [
        AnimatedOpacity(duration: const Duration(milliseconds: 450), opacity: secs.isEven ? 1 : .25, child: Container(width: 11, height: 11, decoration: BoxDecoration(color: g.danger, shape: BoxShape.circle))),
        const SizedBox(width: 10),
        Text(clock, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16, fontFeatures: const [FontFeature.tabularFigures()])),
        Expanded(
          child: Transform.translate(
            offset: Offset(-left * .6, 0),
            child: Opacity(
              opacity: (1 - left / 140).clamp(0.0, 1.0),
              child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                _Loop(builder: (v) => Transform.translate(offset: Offset(-6 * v, 0), child: Icon(Icons.chevron_left_rounded, color: g.textDim, size: 22))),
                const SizedBox(width: 4),
                Flexible(child: Text(context.t('ai.slide_cancel'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 13.5, fontWeight: FontWeight.w600))),
              ]),
            ),
          ),
        ),
      ]),
    );
  }
}

/// Repeats a 0..1 animation forever (back and forth).
class _Loop extends StatefulWidget {
  const _Loop({required this.builder});
  final Widget Function(double) builder;

  @override
  State<_Loop> createState() => _LoopState();
}

class _LoopState extends State<_Loop> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(animation: _c, builder: (context, _) => widget.builder(Curves.easeInOut.transform(_c.value)));
}

/// The little capsule above the button: slide the finger up into it to lock the recording.
class _LockHint extends StatelessWidget {
  const _LockHint({required this.progress});
  final double progress;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Container(
      width: 42,
      height: 92,
      decoration: BoxDecoration(
        color: Color.lerp(g.fillStrong, g.accent.withValues(alpha: .25), progress),
        borderRadius: BorderRadius.circular(21),
        border: Border.all(color: Color.lerp(g.border, g.accent, progress)!),
      ),
      child: Column(mainAxisAlignment: MainAxisAlignment.spaceEvenly, children: [
        Icon(progress > .6 ? Icons.lock_rounded : Icons.lock_open_rounded, size: 20, color: Color.lerp(g.textDim, g.accent, progress)),
        _Loop(builder: (v) => Transform.translate(offset: Offset(0, -5 * v), child: Icon(Icons.keyboard_arrow_up_rounded, size: 24, color: g.textDim.withValues(alpha: 1 - progress)))),
      ]),
    );
  }
}

/// A locked recording: cancel on the left, a pulsing dot, the timer and the level bars; the round button sends it.
class _LockedBar extends StatelessWidget {
  const _LockedBar({super.key, required this.secs, required this.levels, required this.onCancel});
  final int secs;
  final List<double> levels;
  final VoidCallback onCancel;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final clock = '${(secs ~/ 60).toString().padLeft(2, '0')}:${(secs % 60).toString().padLeft(2, '0')}';
    return Row(children: [
      PressScale(
        onTap: onCancel,
        child: Container(
          width: 52,
          height: 52,
          decoration: BoxDecoration(shape: BoxShape.circle, color: g.danger.withValues(alpha: .15), border: Border.all(color: g.danger.withValues(alpha: .5))),
          child: Icon(Icons.delete_outline_rounded, color: g.danger, semanticLabel: context.t('ai.cancel_recording')),
        ),
      ),
      const SizedBox(width: 10),
      Expanded(
        child: Container(
          height: 52,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          decoration: BoxDecoration(color: g.danger.withValues(alpha: .12), borderRadius: BorderRadius.circular(26), border: Border.all(color: g.danger.withValues(alpha: .35))),
          child: Row(children: [
            AnimatedOpacity(duration: const Duration(milliseconds: 450), opacity: secs.isEven ? 1 : .3, child: Container(width: 10, height: 10, decoration: BoxDecoration(color: g.danger, shape: BoxShape.circle))),
            const SizedBox(width: 10),
            Text(clock, style: TextStyle(color: g.danger, fontWeight: FontWeight.w800, fontSize: 15, fontFeatures: const [FontFeature.tabularFigures()])),
            const SizedBox(width: 12),
            Expanded(
              child: LayoutBuilder(builder: (context, c) {
                // only as many bars as fit (each is 3 wide + 3 gap), the newest on the right
                final fit = (c.maxWidth / 6).floor().clamp(1, 60);
                final shown = levels.length > fit ? levels.sublist(levels.length - fit) : levels;
                return Row(mainAxisAlignment: MainAxisAlignment.end, crossAxisAlignment: CrossAxisAlignment.center, children: [
                  for (final l in shown)
                    AnimatedContainer(
                      duration: const Duration(milliseconds: 120),
                      width: 3,
                      height: 4 + l * 26,
                      margin: const EdgeInsets.only(left: 3),
                      decoration: BoxDecoration(color: g.danger.withValues(alpha: .85), borderRadius: BorderRadius.circular(2)),
                    ),
                ]);
              }),
            ),
          ]),
        ),
      ),
    ]);
  }
}

class _TranscribingBar extends StatelessWidget {
  const _TranscribingBar({super.key});

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Container(
      height: 52,
      padding: const EdgeInsets.symmetric(horizontal: 18),
      decoration: BoxDecoration(color: g.fillStrong, borderRadius: BorderRadius.circular(26), border: Border.all(color: g.border)),
      child: Row(children: [
        SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: g.accent)),
        const SizedBox(width: 12),
        Text(context.t('ai.transcribing'), style: TextStyle(color: g.textDim, fontWeight: FontWeight.w600)),
      ]),
    );
  }
}

class _SuggestionChip extends StatelessWidget {
  const _SuggestionChip({required this.label, required this.onTap});
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return PressScale(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: context.width - 48),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          decoration: BoxDecoration(color: g.accent.withValues(alpha: .12), borderRadius: BorderRadius.circular(18), border: Border.all(color: g.accent.withValues(alpha: .4))),
          child: Text(label, style: TextStyle(color: g.text, fontSize: 13.5, fontWeight: FontWeight.w600)),
        ),
      ),
    );
  }
}

class _Bubble extends StatelessWidget {
  const _Bubble({super.key, required this.text, required this.fromUser, this.voice = false, this.action, this.onAction, this.onSpeak, this.speaking = false});
  final String text;
  final bool fromUser, voice, speaking;
  final AiAction? action;
  final VoidCallback? onAction, onSpeak;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final onAccent = g.dark ? const Color(0xFF04231A) : Colors.white;
    final maxW = math.min(context.width * .86, 640.0);
    final radius = BorderRadius.only(
      topLeft: const Radius.circular(22),
      topRight: const Radius.circular(22),
      bottomLeft: Radius.circular(fromUser ? 22 : 6),
      bottomRight: Radius.circular(fromUser ? 6 : 22),
    );
    final content = fromUser
        ? Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            decoration: BoxDecoration(borderRadius: radius, gradient: LinearGradient(colors: [g.accent, Color.lerp(g.accent, g.info, .5)!])),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              if (voice)
                Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(Icons.mic_rounded, size: 15, color: onAccent.withValues(alpha: .75)),
                    const SizedBox(width: 5),
                    Text(context.t('ai.voice_message'), style: TextStyle(color: onAccent.withValues(alpha: .75), fontSize: 11.5, fontWeight: FontWeight.w700)),
                  ]),
                ),
              SelectableText(text, style: TextStyle(color: onAccent, fontSize: 15, fontWeight: FontWeight.w600, height: 1.3)),
            ]),
          )
        : Container(
            padding: const EdgeInsets.fromLTRB(16, 12, 10, 8),
            decoration: BoxDecoration(color: g.fillStrong, borderRadius: radius, border: Border.all(color: g.border)),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
              Padding(padding: const EdgeInsets.only(right: 6), child: SelectableText(text, style: TextStyle(color: g.text, fontSize: 15, height: 1.4))),
              if (action != null && onAction != null) ...[
                const SizedBox(height: 12),
                AppButton(label: context.t('ai.act.${action!.kind.name}'), icon: _icon(action!.kind), compact: true, expanded: false, onPressed: onAction),
              ],
              if (onSpeak != null)
                Align(
                  alignment: Alignment.centerRight,
                  child: GestureDetector(
                    onTap: onSpeak,
                    behavior: HitTestBehavior.opaque,
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(12, 6, 4, 2),
                      child: Row(mainAxisSize: MainAxisSize.min, children: [
                        Icon(speaking ? Icons.stop_circle_outlined : Icons.volume_up_rounded, size: 18, color: speaking ? g.danger : g.textDim),
                        const SizedBox(width: 4),
                        Text(context.t('ai.read_aloud'), style: TextStyle(color: speaking ? g.danger : g.textDim, fontSize: 11.5, fontWeight: FontWeight.w600)),
                      ]),
                    ),
                  ),
                ),
            ]),
          );
    return Reveal(
      dy: 12,
      child: Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(
          mainAxisAlignment: fromUser ? MainAxisAlignment.end : MainAxisAlignment.start,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            if (!fromUser) ...[_Orb(), const SizedBox(width: 8)],
            Flexible(child: ConstrainedBox(constraints: BoxConstraints(maxWidth: maxW), child: content)),
          ],
        ),
      ),
    );
  }

  IconData _icon(AiActionKind k) => switch (k) {
        AiActionKind.proposals => Icons.auto_fix_high_rounded,
        AiActionKind.tax => Icons.receipt_long_rounded,
        AiActionKind.invoice => Icons.add_circle_outline_rounded,
      };
}

class _Orb extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Container(
      width: 30,
      height: 30,
      decoration: BoxDecoration(shape: BoxShape.circle, gradient: LinearGradient(colors: [g.accent2, g.accent])),
      child: const Icon(Icons.auto_awesome_rounded, color: Colors.white, size: 16),
    );
  }
}

class _TypingBubble extends StatefulWidget {
  const _TypingBubble({super.key});

  @override
  State<_TypingBubble> createState() => _TypingBubbleState();
}

class _TypingBubbleState extends State<_TypingBubble> with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 1100))..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Row(children: [
        _Orb(),
        const SizedBox(width: 8),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
          decoration: BoxDecoration(color: g.fillStrong, borderRadius: BorderRadius.circular(22), border: Border.all(color: g.border)),
          child: AnimatedBuilder(
            animation: _c,
            builder: (context, _) => Row(mainAxisSize: MainAxisSize.min, children: [
              for (var i = 0; i < 3; i++)
                Container(
                  width: 8,
                  height: 8,
                  margin: EdgeInsets.only(right: i == 2 ? 0 : 5),
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: g.textDim.withValues(alpha: .35 + .65 * math.max(0, math.sin((_c.value * 2 * math.pi) - i * 0.9))),
                  ),
                ),
            ]),
          ),
        ),
      ]),
    );
  }
}
