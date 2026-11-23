import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/scope.dart';
import '../../core/theme.dart';
import '../screens/ai/ai_screen.dart';
import 'glass.dart';

/// True while the AI chat is on screen (the floating button hides itself then).
final ValueNotifier<bool> aiScreenOpen = ValueNotifier(false);

/// The AI accountant button. It floats over every screen and can be dragged anywhere; where it is left, it stays (also after a restart).
class AiFab extends StatefulWidget {
  const AiFab({super.key, required this.navigatorKey});
  final GlobalKey<NavigatorState> navigatorKey;

  @override
  State<AiFab> createState() => _AiFabState();
}

class _AiFabState extends State<AiFab> with SingleTickerProviderStateMixin {
  static const double _d = 58;
  late final AnimationController _pulse = AnimationController(vsync: this, duration: const Duration(seconds: 3));
  double? _fx, _fy;
  bool _dragging = false;

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  void _open() {
    final ctx = widget.navigatorKey.currentContext;
    if (ctx != null && !aiScreenOpen.value) pushPage(ctx, const AiScreen());
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final store = context.readStore;
    _fx ??= store.orbX;
    _fy ??= store.orbY;
    return ValueListenableBuilder<bool>(
      valueListenable: aiScreenOpen,
      builder: (context, open, _) => LayoutBuilder(builder: (context, c) {
        final pad = MediaQuery.paddingOf(context);
        final kb = MediaQuery.viewInsetsOf(context).bottom;
        final left0 = 8.0, top0 = pad.top + 6;
        final areaW = (c.maxWidth - _d - 16).clamp(1.0, double.infinity);
        final areaH = (c.maxHeight - pad.top - pad.bottom - _d - 12 - kb).clamp(1.0, double.infinity);
        final left = left0 + _fx!.clamp(0.0, 1.0) * areaW;
        final top = top0 + _fy!.clamp(0.0, 1.0) * areaH;
        return Stack(children: [AnimatedPositioned(
          duration: _dragging ? Duration.zero : const Duration(milliseconds: 260),
          curve: Curves.easeOutCubic,
          left: left,
          top: top,
          width: _d,
          height: _d,
          child: IgnorePointer(
            ignoring: open,
            child: AnimatedScale(
              scale: open ? 0 : 1,
              duration: const Duration(milliseconds: 220),
              curve: Curves.easeOutBack,
              child: GestureDetector(
                onTap: () {
                  HapticFeedback.selectionClick();
                  _open();
                },
                onPanStart: (_) {
                  HapticFeedback.lightImpact();
                  setState(() => _dragging = true);
                },
                // add to the current fractions (several events can arrive before the next frame)
                onPanUpdate: (d) => setState(() {
                  _fx = (_fx! + d.delta.dx / areaW).clamp(0.0, 1.0);
                  _fy = (_fy! + d.delta.dy / areaH).clamp(0.0, 1.0);
                }),
                onPanEnd: (_) {
                  setState(() => _dragging = false);
                  store.saveOrb(_fx!, _fy!);
                },
                child: AnimatedBuilder(
                  animation: _pulse,
                  builder: (context, _) => AnimatedScale(
                    scale: _dragging ? 1.14 : 1,
                    duration: const Duration(milliseconds: 160),
                    child: Container(
                      decoration: BoxDecoration(
                        shape: BoxShape.circle,
                        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [g.accent2, g.accent]),
                        border: Border.all(color: Colors.white.withValues(alpha: .35), width: 1.2),
                        boxShadow: [BoxShadow(color: g.accent2.withValues(alpha: (_dragging ? .6 : .3) + .25 * _pulse.value), blurRadius: (_dragging ? 26 : 12) + 12 * _pulse.value, spreadRadius: -2, offset: const Offset(0, 6))],
                      ),
                      child: const Icon(Icons.auto_awesome_rounded, color: Colors.white, size: 28),
                    ),
                  ),
                ),
              ),
            ),
          ),
        )]);
      }),
    );
  }
}
