import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../../core/scope.dart';
import '../../core/theme.dart';

class BarGroup {
  const BarGroup(this.label, this.a, this.b);
  final String label;
  final int a, b; // two series in tiyin (for example income and expense)
}

/// Grouped rounded bars that grow in; tap a group to read its values. The last group is highlighted.
class BarsChart extends StatefulWidget {
  const BarsChart({super.key, required this.groups, this.height = 190, required this.labelA, required this.labelB});
  final List<BarGroup> groups;
  final double height;
  final String labelA, labelB;

  @override
  State<BarsChart> createState() => _BarsChartState();
}

class _BarsChartState extends State<BarsChart> {
  int? _sel;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final sel = _sel ?? (widget.groups.isEmpty ? null : widget.groups.length - 1);
    final grp = sel == null ? null : widget.groups[sel];
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SizedBox(
        height: 22,
        child: grp == null
            ? null
            : AnimatedSwitcher(
                duration: const Duration(milliseconds: 200),
                child: Row(key: ValueKey(sel), children: [
                  _dot(g.accent),
                  Text(' ${widget.labelA}: ${context.compact(grp.a)}   ', style: TextStyle(color: g.text, fontSize: 12.5, fontWeight: FontWeight.w700)),
                  _dot(g.accent2),
                  Flexible(child: Text(' ${widget.labelB}: ${context.compact(grp.b)}', overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 12.5, fontWeight: FontWeight.w700))),
                ]),
              ),
      ),
      const SizedBox(height: 6),
      SizedBox(
        height: widget.height,
        child: LayoutBuilder(builder: (context, c) {
          return GestureDetector(
            behavior: HitTestBehavior.opaque,
            onTapDown: (d) {
              if (widget.groups.isEmpty) return;
              final w = c.maxWidth / widget.groups.length;
              setState(() => _sel = (d.localPosition.dx / w).floor().clamp(0, widget.groups.length - 1));
            },
            child: TweenAnimationBuilder<double>(
              key: ValueKey(widget.groups.length),
              tween: Tween(begin: 0, end: 1),
              duration: Duration.zero,
              curve: Curves.easeOutCubic,
              builder: (context, p, _) => CustomPaint(
                size: Size(c.maxWidth, widget.height),
                painter: _BarsPainter(widget.groups, p, sel, g),
              ),
            ),
          );
        }),
      ),
    ]);
  }

  Widget _dot(Color c) => Container(width: 9, height: 9, decoration: BoxDecoration(color: c, shape: BoxShape.circle));
}

class _BarsPainter extends CustomPainter {
  _BarsPainter(this.groups, this.p, this.sel, this.g);
  final List<BarGroup> groups;
  final double p;
  final int? sel;
  final Glass g;

  @override
  void paint(Canvas canvas, Size size) {
    const labelH = 22.0;
    final h = size.height - labelH;
    final grid = Paint()..color = g.border.withValues(alpha: .5)..strokeWidth = 1;
    for (var i = 0; i <= 3; i++) {
      final y = h * i / 3;
      canvas.drawLine(Offset(0, y), Offset(size.width, y), grid);
    }
    if (groups.isEmpty) return;
    final maxV = math.max(1, groups.fold<int>(0, (m, e) => math.max(m, math.max(e.a, e.b))));
    final gw = size.width / groups.length;
    final bw = math.min(18.0, gw * .3);
    for (var i = 0; i < groups.length; i++) {
      final cx = gw * i + gw / 2;
      final active = i == sel;
      void bar(int v, double dx, Color color) {
        final bh = math.max(3.0, h * (v / maxV) * p);
        final r = RRect.fromRectAndRadius(Rect.fromLTWH(cx + dx - bw / 2, h - bh, bw, bh), const Radius.circular(8));
        final paint = Paint()
          ..shader = LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [color.withValues(alpha: active ? 1 : .55), color.withValues(alpha: active ? .55 : .22)])
              .createShader(r.outerRect);
        canvas.drawRRect(r, paint);
      }

      bar(groups[i].a, -bw * .6, g.accent);
      bar(groups[i].b, bw * .6, g.accent2);
      final tp = TextPainter(
        text: TextSpan(text: groups[i].label, style: TextStyle(color: active ? g.text : g.textDim, fontSize: 11, fontWeight: active ? FontWeight.w800 : FontWeight.w500)),
        textDirection: TextDirection.ltr,
      )..layout(maxWidth: gw);
      tp.paint(canvas, Offset(cx - tp.width / 2, h + 6));
    }
  }

  @override
  bool shouldRepaint(_BarsPainter o) => o.p != p || o.sel != sel || o.groups != groups || o.g != g;
}

/// A smooth line with a gradient area under it; values are in tiyin.
class AreaChart extends StatelessWidget {
  const AreaChart({super.key, required this.values, required this.labels, this.height = 150, this.color});
  final List<int> values;
  final List<String> labels;
  final double height;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return SizedBox(
      height: height,
      child: LayoutBuilder(
        builder: (context, c) => TweenAnimationBuilder<double>(
          key: ValueKey(values.join(',')),
          tween: Tween(begin: 0, end: 1),
          duration: Duration.zero,
          curve: Curves.easeOutCubic,
          builder: (context, p, _) => CustomPaint(size: Size(c.maxWidth, height), painter: _AreaPainter(values, labels, p, color ?? g.accent, g)),
        ),
      ),
    );
  }
}

class _AreaPainter extends CustomPainter {
  _AreaPainter(this.v, this.labels, this.p, this.color, this.g);
  final List<int> v;
  final List<String> labels;
  final double p;
  final Color color;
  final Glass g;

  @override
  void paint(Canvas canvas, Size size) {
    const labelH = 20.0;
    final h = size.height - labelH - 8;
    if (v.length < 2) return;
    final maxV = math.max(1, v.reduce(math.max));
    final dx = size.width / (v.length - 1);
    final pts = [for (var i = 0; i < v.length; i++) Offset(dx * i, 8 + h - h * (v[i] / maxV) * p)];
    final line = Path()..moveTo(pts.first.dx, pts.first.dy);
    for (var i = 0; i < pts.length - 1; i++) {
      final a = pts[i], b = pts[i + 1];
      final mx = (a.dx + b.dx) / 2;
      line.cubicTo(mx, a.dy, mx, b.dy, b.dx, b.dy);
    }
    final area = Path.from(line)
      ..lineTo(pts.last.dx, 8 + h)
      ..lineTo(pts.first.dx, 8 + h)
      ..close();
    canvas.drawPath(area, Paint()..shader = LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [color.withValues(alpha: .38), color.withValues(alpha: 0)]).createShader(Rect.fromLTWH(0, 0, size.width, size.height)));
    canvas.drawPath(line, Paint()..color = color..style = PaintingStyle.stroke..strokeWidth = 3..strokeCap = StrokeCap.round);
    final last = pts.last;
    canvas.drawCircle(last, 7, Paint()..color = color.withValues(alpha: .25));
    canvas.drawCircle(last, 4, Paint()..color = color);
    for (var i = 0; i < labels.length && i < v.length; i++) {
      final tp = TextPainter(text: TextSpan(text: labels[i], style: TextStyle(color: g.textDim, fontSize: 11)), textDirection: TextDirection.ltr)..layout();
      final x = (dx * i - tp.width / 2).clamp(0.0, size.width - tp.width);
      tp.paint(canvas, Offset(x, size.height - tp.height));
    }
  }

  @override
  bool shouldRepaint(_AreaPainter o) => o.p != p || o.v != v || o.color != color;
}

class DonutSlice {
  const DonutSlice(this.label, this.value, this.color);
  final String label;
  final int value;
  final Color color;
}

class DonutChart extends StatelessWidget {
  const DonutChart({super.key, required this.slices, this.size = 150, this.center});
  final List<DonutSlice> slices;
  final double size;
  final Widget? center;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: size,
      height: size,
      child: Stack(alignment: Alignment.center, children: [
        TweenAnimationBuilder<double>(
          key: ValueKey(slices.map((e) => e.value).join(',')),
          tween: Tween(begin: 0, end: 1),
          duration: Duration.zero,
          curve: Curves.easeOutCubic,
          builder: (context, p, _) => CustomPaint(size: Size.square(size), painter: _DonutPainter(slices, p, context.g)),
        ),
        if (center != null) center!,
      ]),
    );
  }
}

class _DonutPainter extends CustomPainter {
  _DonutPainter(this.slices, this.p, this.g);
  final List<DonutSlice> slices;
  final double p;
  final Glass g;

  @override
  void paint(Canvas canvas, Size size) {
    final total = slices.fold<int>(0, (a, b) => a + b.value);
    final stroke = size.width * .17;
    final rect = Rect.fromLTWH(stroke / 2, stroke / 2, size.width - stroke, size.height - stroke);
    canvas.drawArc(rect, 0, math.pi * 2, false, Paint()..style = PaintingStyle.stroke..strokeWidth = stroke..color = g.fill);
    if (total == 0) return;
    var start = -math.pi / 2;
    const gap = .035;
    for (final s in slices) {
      final sweep = (s.value / total) * math.pi * 2 * p;
      if (sweep <= gap) continue;
      canvas.drawArc(rect, start + gap / 2, sweep - gap, false, Paint()..style = PaintingStyle.stroke..strokeWidth = stroke..strokeCap = StrokeCap.round..color = s.color);
      start += sweep;
    }
  }

  @override
  bool shouldRepaint(_DonutPainter o) => o.p != p || o.slices != slices;
}

/// A horizontal progress bar with a glass track.
class MeterBar extends StatelessWidget {
  const MeterBar({super.key, required this.fraction, this.color, this.height = 8});
  final double fraction;
  final Color? color;
  final double height;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final c = color ?? g.accent;
    return ClipRRect(
      borderRadius: BorderRadius.circular(height),
      child: Container(
        height: height,
        color: g.fill,
        alignment: Alignment.centerLeft,
        child: TweenAnimationBuilder<double>(
          tween: Tween(end: fraction.clamp(0, 1).toDouble()),
          duration: Duration.zero,
          curve: Curves.easeOutCubic,
          builder: (context, v, _) => FractionallySizedBox(widthFactor: v, child: DecoratedBox(decoration: BoxDecoration(gradient: LinearGradient(colors: [c, c.withValues(alpha: .6)])))),
        ),
      ),
    );
  }
}
