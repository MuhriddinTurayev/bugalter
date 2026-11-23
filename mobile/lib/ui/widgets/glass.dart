import 'dart:math' as math;
import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../core/money.dart';
import '../../core/scope.dart';
import '../../core/theme.dart';

// ───────────────────────── layout helpers ─────────────────────────

/// Width classes used to adapt the layout to any phone, tablet or desktop window.
extension Responsive on BuildContext {
  double get width => MediaQuery.sizeOf(this).width;
  bool get isWide => width >= 760;
  bool get isCompact => width < 360;

  /// Columns for card grids: 1 on phones, 2 on large phones and tablets, 3 on wide windows.
  int columns({double min = 320}) => math.max(1, math.min(3, (width / min).floor()));

  /// Space a tab page must leave at its bottom so the floating navigation bar never covers content.
  double get navSpace => 24 + HeaderInset.bottomOf(this);

  /// Space a tab page must leave at its top: the scrolling content runs under the transparent header, so it starts below it.
  double get headerSpace => HeaderInset.of(this);
}

/// The height of the transparent header that floats above the tab pages (0 where there is none).
class HeaderInset extends InheritedWidget {
  const HeaderInset({super.key, required this.value, this.bottom = 0, required super.child});
  final double value, bottom;

  static double of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<HeaderInset>()?.value ?? 0;

  /// The same for the bottom: the floating navigation bar the content scrolls under.
  static double bottomOf(BuildContext context) => context.dependOnInheritedWidgetOfExactType<HeaderInset>()?.bottom ?? 0;

  @override
  bool updateShouldNotify(HeaderInset old) => old.value != value || old.bottom != bottom;
}

/// Centres page content and keeps lines readable on wide screens.
class ResponsiveBody extends StatelessWidget {
  const ResponsiveBody({super.key, required this.child, this.maxWidth = 900, this.padding});

  final Widget child;
  final double maxWidth;
  final EdgeInsetsGeometry? padding;

  @override
  Widget build(BuildContext context) {
    final w = context.width;
    final side = w >= 760 ? 24.0 : 16.0;
    return Align(
      alignment: Alignment.topCenter,
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: maxWidth),
        child: Padding(padding: padding ?? EdgeInsets.symmetric(horizontal: side), child: child),
      ),
    );
  }
}

/// Lays children out in [columns] equal columns (rows wrap), without needing a fixed height.
class AdaptiveGrid extends StatelessWidget {
  const AdaptiveGrid({super.key, required this.children, this.columns, this.spacing = 14, this.minColumnWidth = 320});

  final List<Widget> children;
  final int? columns;
  final double spacing, minColumnWidth;

  @override
  Widget build(BuildContext context) => LayoutBuilder(builder: (context, c) {
        final n = columns ?? math.max(1, math.min(3, (c.maxWidth / minColumnWidth).floor()));
        if (n == 1) {
          return Column(children: [for (var i = 0; i < children.length; i++) Padding(padding: EdgeInsets.only(bottom: i == children.length - 1 ? 0 : spacing), child: children[i])]);
        }
        final w = (c.maxWidth - spacing * (n - 1)) / n;
        return Wrap(spacing: spacing, runSpacing: spacing, children: [for (final ch in children) SizedBox(width: w, child: ch)]);
      });
}

// ───────────────────────── background ─────────────────────────

/// The gradient with soft colour glows behind every screen. It is painted once and never animated,
/// so it costs the phone nothing while you scroll (an animated full-screen background heats the device).
class GlassBackground extends StatelessWidget {
  const GlassBackground({super.key, required this.child});
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Stack(fit: StackFit.expand, children: [
      DecoratedBox(
        decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: g.bg)),
      ),
      RepaintBoundary(child: CustomPaint(painter: _GlowPainter(g.blobs, g.dark), size: Size.infinite)),
      child,
    ]);
  }
}

class _GlowPainter extends CustomPainter {
  _GlowPainter(this.colors, this.dark);
  final List<Color> colors;
  final bool dark;

  // fixed places of the three glows (fractions of the screen)
  static const _spots = [Offset(.12, .10), Offset(.95, .45), Offset(.25, .92)];

  @override
  void paint(Canvas canvas, Size size) {
    final r = math.max(size.width, size.height) * (size.width > 700 ? .34 : .55);
    for (var i = 0; i < colors.length; i++) {
      final c = Offset(size.width * _spots[i].dx, size.height * _spots[i].dy);
      final radius = r * (.7 + .12 * i);
      canvas.drawCircle(
        c,
        radius,
        Paint()..shader = RadialGradient(colors: [colors[i].withValues(alpha: dark ? .30 : .45), colors[i].withValues(alpha: 0)]).createShader(Rect.fromCircle(center: c, radius: radius)),
      );
    }
  }

  @override
  bool shouldRepaint(_GlowPainter old) => old.dark != dark || old.colors != colors;
}

// ───────────────────────── press feedback & entrance animation ─────────────────────────

/// Gives any child a subtle press-down scale and handles taps.
class PressScale extends StatefulWidget {
  const PressScale({super.key, required this.child, this.onTap, this.onLongPress, this.scale = .97});
  final Widget child;
  final VoidCallback? onTap, onLongPress;
  final double scale;

  @override
  State<PressScale> createState() => _PressScaleState();
}

class _PressScaleState extends State<PressScale> {
  bool _down = false;

  void _set(bool v) {
    if (_down != v && widget.onTap != null) setState(() => _down = v);
  }

  @override
  Widget build(BuildContext context) => GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTapDown: (_) => _set(true),
        onTapUp: (_) => _set(false),
        onTapCancel: () => _set(false),
        onTap: widget.onTap == null
            ? null
            : () {
                HapticFeedback.selectionClick();
                widget.onTap!();
              },
        onLongPress: widget.onLongPress,
        child: AnimatedScale(scale: _down ? widget.scale : 1, duration: const Duration(milliseconds: 110), curve: Curves.easeOut, child: widget.child),
      );
}

/// Fades and slides its child in when first shown; [index] staggers a list.
class Reveal extends StatefulWidget {
  const Reveal({super.key, required this.child, this.index = 0, this.dy = 18});
  final Widget child;
  final int index;
  final double dy;

  @override
  State<Reveal> createState() => _RevealState();
}

class _RevealState extends State<Reveal> with SingleTickerProviderStateMixin {
  late final AnimationController _c;
  late final Animation<double> _a;

  @override
  void initState() {
    super.initState();
    final delay = math.min(widget.index, 10) * 55;
    _c = AnimationController(vsync: this, duration: Duration(milliseconds: 520 + delay))..forward();
    _a = CurvedAnimation(parent: _c, curve: Interval(delay / (520 + delay), 1, curve: Curves.easeOutCubic));
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AnimatedBuilder(
        animation: _a,
        builder: (context, child) => Opacity(opacity: _a.value, child: Transform.translate(offset: Offset(0, (1 - _a.value) * widget.dy), child: child)),
        child: widget.child,
      );
}

/// Animates a number from its previous value to the new one.
class CountUp extends StatelessWidget {
  const CountUp({super.key, required this.value, required this.builder, this.duration = const Duration(milliseconds: 900)});
  final int value;
  final Widget Function(BuildContext, int) builder;
  final Duration duration;

  @override
  Widget build(BuildContext context) => TweenAnimationBuilder<double>(
        tween: Tween(end: value.toDouble()),
        duration: duration,
        curve: Curves.easeOutCubic,
        builder: (c, v, _) => builder(c, v.round()),
      );
}

// ───────────────────────── glass surfaces ─────────────────────────

class GlassCard extends StatelessWidget {
  const GlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(16),
    this.margin,
    this.radius = 24,
    this.blur = false,
    this.strong = false,
    this.onTap,
    this.onLongPress,
    this.tint,
    this.gradient,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final EdgeInsetsGeometry? margin;
  final double radius;

  /// Real background blur. Turn it off for long lists so scrolling stays smooth.
  final bool blur;
  final bool strong;
  final VoidCallback? onTap, onLongPress;
  final Color? tint;
  final Gradient? gradient;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final br = BorderRadius.circular(radius);
    Widget body = Container(
      padding: padding,
      decoration: BoxDecoration(
        color: gradient == null ? (tint ?? (strong ? g.fillStrong : g.fill)) : null,
        gradient: gradient,
        borderRadius: br,
        border: Border.all(color: g.border, width: 1),
      ),
      child: child,
    );
    if (blur) {
      body = ClipRRect(borderRadius: br, child: BackdropFilter(filter: ImageFilter.blur(sigmaX: 20, sigmaY: 20), child: body));
    }
    body = DecoratedBox(
      decoration: BoxDecoration(borderRadius: br, boxShadow: [BoxShadow(color: g.shadow, blurRadius: 14, offset: const Offset(0, 6), spreadRadius: -4)]),
      child: body,
    );
    if (onTap != null || onLongPress != null) body = PressScale(onTap: onTap, onLongPress: onLongPress, child: body);
    return margin == null ? body : Padding(padding: margin!, child: body);
  }
}

class GlassIconButton extends StatelessWidget {
  const GlassIconButton({super.key, required this.icon, required this.onTap, this.size = 44, this.badge = 0, this.tooltip, this.color});
  final IconData icon;
  final VoidCallback onTap;
  final double size;
  final int badge;
  final String? tooltip;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    Widget b = PressScale(
      onTap: onTap,
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(shape: BoxShape.circle, color: g.fill, border: Border.all(color: g.border)),
        child: Icon(icon, size: size * .5, color: color ?? g.text),
      ),
    );
    if (badge > 0) {
      b = Stack(clipBehavior: Clip.none, children: [
        b,
        Positioned(
          right: -2,
          top: -2,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
            constraints: const BoxConstraints(minWidth: 18),
            decoration: BoxDecoration(color: g.danger, borderRadius: BorderRadius.circular(9)),
            child: Text('$badge', textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 10.5, fontWeight: FontWeight.w700)),
          ),
        ),
      ]);
    }
    return tooltip == null ? b : Tooltip(message: tooltip!, child: b);
  }
}

// ───────────────────────── buttons ─────────────────────────

enum ButtonKind { primary, glass, danger }

class AppButton extends StatelessWidget {
  const AppButton({super.key, required this.label, this.icon, this.onPressed, this.kind = ButtonKind.primary, this.loading = false, this.expanded = true, this.compact = false});

  final String label;
  final IconData? icon;
  final VoidCallback? onPressed;
  final ButtonKind kind;
  final bool loading, expanded, compact;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final enabled = onPressed != null && !loading;
    final onAccent = g.dark ? const Color(0xFF04231A) : Colors.white;
    final Color fg = switch (kind) { ButtonKind.primary => onAccent, ButtonKind.glass => g.text, ButtonKind.danger => Colors.white };
    final deco = switch (kind) {
      ButtonKind.primary => BoxDecoration(
          gradient: LinearGradient(colors: [g.accent, Color.lerp(g.accent, g.info, g.dark ? .55 : .35)!]),
          borderRadius: BorderRadius.circular(18),
          boxShadow: [BoxShadow(color: g.accent.withValues(alpha: .35), blurRadius: 20, offset: const Offset(0, 8), spreadRadius: -6)],
        ),
      ButtonKind.glass => BoxDecoration(color: g.fill, borderRadius: BorderRadius.circular(18), border: Border.all(color: g.border)),
      ButtonKind.danger => BoxDecoration(gradient: LinearGradient(colors: [g.danger, Color.lerp(g.danger, const Color(0xFF9F1239), .5)!]), borderRadius: BorderRadius.circular(18)),
    };
    final content = Row(
      mainAxisSize: expanded ? MainAxisSize.max : MainAxisSize.min,
      mainAxisAlignment: MainAxisAlignment.center,
      children: [
        if (loading)
          SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2.2, color: fg))
        else if (icon != null) ...[Icon(icon, size: 20, color: fg), const SizedBox(width: 8)],
        if (!loading || label.isNotEmpty) Flexible(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: fg, fontWeight: FontWeight.w700, fontSize: compact ? 14 : 15.5))),
      ],
    );
    return Opacity(
      opacity: enabled ? 1 : .5,
      child: PressScale(
        onTap: enabled ? onPressed : null,
        child: Container(
          constraints: BoxConstraints(minHeight: compact ? 40 : 52),
          padding: EdgeInsets.symmetric(horizontal: compact ? 14 : 20, vertical: compact ? 8 : 14),
          decoration: deco,
          child: content,
        ),
      ),
    );
  }
}

// ───────────────────────── small pieces ─────────────────────────

class StatusPill extends StatelessWidget {
  const StatusPill({super.key, required this.label, required this.color, this.icon});
  final String label;
  final Color color;
  final IconData? icon;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: color.withValues(alpha: .16), borderRadius: BorderRadius.circular(20), border: Border.all(color: color.withValues(alpha: .35))),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 13, color: color), const SizedBox(width: 4)],
          Flexible(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: color, fontSize: 12, fontWeight: FontWeight.w700))),
        ]),
      );
}

class IconBadge extends StatelessWidget {
  const IconBadge({super.key, required this.icon, this.color, this.size = 44});
  final IconData icon;
  final Color? color;
  final double size;

  @override
  Widget build(BuildContext context) {
    final c = color ?? context.g.accent;
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * .34),
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [c.withValues(alpha: .32), c.withValues(alpha: .12)]),
        border: Border.all(color: c.withValues(alpha: .35)),
      ),
      child: Icon(icon, color: c, size: size * .5),
    );
  }
}

class Avatar extends StatelessWidget {
  const Avatar({super.key, required this.name, this.size = 44});
  final String name;
  final double size;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final parts = name.trim().split(RegExp(r'\s+')).where((e) => e.isNotEmpty).toList();
    final ini = parts.isEmpty ? '?' : (parts.first[0] + (parts.length > 1 ? parts[1][0] : '')).toUpperCase();
    final hue = (name.codeUnits.fold(0, (a, b) => a + b) * 37) % 360;
    final c = HSLColor.fromAHSL(1, hue.toDouble(), .6, g.dark ? .6 : .45).toColor();
    return Container(
      width: size,
      height: size,
      alignment: Alignment.center,
      decoration: BoxDecoration(shape: BoxShape.circle, gradient: LinearGradient(colors: [c, Color.lerp(c, g.accent2, .5)!])),
      child: Text(ini, style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: size * .36)),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.title, {super.key, this.action, this.onAction, this.subtitle});
  final String title;
  final String? subtitle, action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 4, 4, 10),
      child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800, letterSpacing: -.2)),
            if (subtitle != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(subtitle!, style: TextStyle(color: g.textDim, fontSize: 12.5))),
          ]),
        ),
        if (action != null)
          GestureDetector(onTap: onAction, child: Text(action!, style: TextStyle(color: g.accent, fontWeight: FontWeight.w700, fontSize: 13.5))),
      ]),
    );
  }
}

class EmptyState extends StatelessWidget {
  const EmptyState({super.key, required this.icon, required this.title, this.text, this.action});
  final IconData icon;
  final String title;
  final String? text;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Reveal(child: IconBadge(icon: icon, size: 72, color: g.accent2)),
          const SizedBox(height: 16),
          Text(title, textAlign: TextAlign.center, style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800)),
          if (text != null) Padding(padding: const EdgeInsets.only(top: 6), child: Text(text!, textAlign: TextAlign.center, style: TextStyle(color: g.textDim, fontSize: 14))),
          if (action != null) Padding(padding: const EdgeInsets.only(top: 18), child: action!),
        ]),
      ),
    );
  }
}

/// A two-line list row: leading widget, title, subtitle and a trailing amount or chevron.
class RowTile extends StatelessWidget {
  const RowTile({super.key, required this.title, this.subtitle, this.leading, this.trailing, this.onTap, this.onLongPress});
  final String title;
  final String? subtitle;
  final Widget? leading, trailing;
  final VoidCallback? onTap, onLongPress;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return PressScale(
      onTap: onTap,
      onLongPress: onLongPress,
      scale: .985,
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 9),
        child: Row(children: [
          if (leading != null) ...[leading!, const SizedBox(width: 12)],
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 15)),
              if (subtitle != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(subtitle!, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5))),
            ]),
          ),
          if (trailing != null) ...[const SizedBox(width: 10), trailing!],
        ]),
      ),
    );
  }
}

class Hairline extends StatelessWidget {
  const Hairline({super.key});
  @override
  Widget build(BuildContext context) => Divider(height: 1, thickness: 1, color: context.g.border.withValues(alpha: context.g.dark ? .5 : .9));
}

/// A glass pill selector with an animated thumb (periods, tabs, filters).
class SegmentedGlass extends StatelessWidget {
  const SegmentedGlass({super.key, required this.labels, required this.index, required this.onChanged});
  final List<String> labels;
  final int index;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final onAccent = g.dark ? const Color(0xFF04231A) : Colors.white;
    return LayoutBuilder(builder: (context, c) {
      final w = c.maxWidth / labels.length;
      return Container(
        height: 46,
        decoration: BoxDecoration(color: g.fill, borderRadius: BorderRadius.circular(23), border: Border.all(color: g.border)),
        child: Stack(children: [
          AnimatedPositioned(
            duration: const Duration(milliseconds: 280),
            curve: Curves.easeOutCubic,
            left: w * index + 3,
            top: 3,
            bottom: 3,
            width: w - 6,
            child: DecoratedBox(decoration: BoxDecoration(borderRadius: BorderRadius.circular(20), gradient: LinearGradient(colors: [g.accent, Color.lerp(g.accent, g.info, .4)!]))),
          ),
          Row(children: [
            for (var i = 0; i < labels.length; i++)
              Expanded(
                child: GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTap: () => onChanged(i),
                  child: Center(
                    child: AnimatedDefaultTextStyle(
                      duration: const Duration(milliseconds: 200),
                      style: TextStyle(color: i == index ? onAccent : g.textDim, fontWeight: FontWeight.w700, fontSize: 13.5),
                      child: Text(labels[i], maxLines: 1, overflow: TextOverflow.ellipsis),
                    ),
                  ),
                ),
              ),
          ]),
        ]),
      );
    });
  }
}

/// Horizontally scrolling filter chips.
class ChipBar extends StatelessWidget {
  const ChipBar({super.key, required this.labels, required this.index, required this.onChanged});
  final List<String> labels;
  final int index;
  final ValueChanged<int> onChanged;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final onAccent = g.dark ? const Color(0xFF04231A) : Colors.white;
    return SizedBox(
      height: 40,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.zero,
        itemCount: labels.length,
        separatorBuilder: (_, __) => const SizedBox(width: 8),
        itemBuilder: (context, i) {
          final sel = i == index;
          return PressScale(
            onTap: () => onChanged(i),
            child: AnimatedContainer(
              duration: const Duration(milliseconds: 220),
              padding: const EdgeInsets.symmetric(horizontal: 16),
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: sel ? g.accent : g.fill,
                borderRadius: BorderRadius.circular(20),
                border: Border.all(color: sel ? g.accent : g.border),
              ),
              child: Text(labels[i], style: TextStyle(color: sel ? onAccent : g.text, fontWeight: FontWeight.w700, fontSize: 13.5)),
            ),
          );
        },
      ),
    );
  }
}

// ───────────────────────── page scaffolds ─────────────────────────

class GlassAppBar extends StatelessWidget implements PreferredSizeWidget {
  const GlassAppBar({super.key, required this.title, this.actions = const [], this.leading, this.subtitle});
  final String title;
  final String? subtitle;
  final List<Widget> actions;
  final Widget? leading;

  @override
  Size get preferredSize => const Size.fromHeight(68);

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final canPop = Navigator.of(context).canPop();
    return SafeArea(
      bottom: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
        child: Row(children: [
          if (leading != null)
            leading!
          else if (canPop)
            GlassIconButton(icon: Icons.arrow_back_ios_new_rounded, onTap: () => Navigator.of(context).maybePop()),
          if (leading != null || canPop) const SizedBox(width: 12),
          Expanded(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 21, fontWeight: FontWeight.w800, letterSpacing: -.3)),
              if (subtitle != null) Text(subtitle!, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
            ]),
          ),
          for (final a in actions) Padding(padding: const EdgeInsets.only(left: 8), child: a),
        ]),
      ),
    );
  }
}

/// A full screen of the app on its own glass background (every pushed page uses this).
class PageScaffold extends StatelessWidget {
  const PageScaffold({super.key, required this.title, required this.body, this.actions = const [], this.fab, this.subtitle, this.bottom, this.maxWidth = 900, this.resizeForKeyboard = true});

  final String title;
  final String? subtitle;
  final Widget body;
  final List<Widget> actions;
  final Widget? fab, bottom;
  final double maxWidth;
  final bool resizeForKeyboard;

  @override
  Widget build(BuildContext context) {
    return GlassBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        resizeToAvoidBottomInset: resizeForKeyboard,
        appBar: GlassAppBar(title: title, subtitle: subtitle, actions: actions),
        floatingActionButton: fab,
        body: Column(children: [
          Expanded(child: ResponsiveBody(maxWidth: maxWidth, padding: EdgeInsets.zero, child: body)),
          if (bottom != null) bottom!,
        ]),
      ),
    );
  }
}

/// Opens a page with a fade + slide transition.
Future<T?> pushPage<T>(BuildContext context, Widget page) => Navigator.of(context).push<T>(PageRouteBuilder<T>(
      transitionDuration: const Duration(milliseconds: 380),
      reverseTransitionDuration: const Duration(milliseconds: 280),
      pageBuilder: (_, __, ___) => page,
      transitionsBuilder: (_, a, __, child) {
        final curved = CurvedAnimation(parent: a, curve: Curves.easeOutCubic, reverseCurve: Curves.easeInCubic);
        return FadeTransition(opacity: curved, child: SlideTransition(position: Tween(begin: const Offset(.06, 0), end: Offset.zero).animate(curved), child: child));
      },
    ));

// ───────────────────────── sheets & dialogs ─────────────────────────

Future<T?> showGlassSheet<T>(BuildContext context, {required WidgetBuilder builder, bool scroll = true}) {
  return showModalBottomSheet<T>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    barrierColor: Colors.black.withValues(alpha: .45),
    useSafeArea: true,
    constraints: BoxConstraints(maxWidth: math.min(640, MediaQuery.sizeOf(context).width)),
    builder: (ctx) {
      final g = ctx.g;
      return Padding(
        padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(ctx).bottom),
        child: ClipRRect(
          borderRadius: const BorderRadius.vertical(top: Radius.circular(30)),
          child: BackdropFilter(
            filter: ImageFilter.blur(sigmaX: 26, sigmaY: 26),
            child: Container(
              decoration: BoxDecoration(
                color: g.dark ? const Color(0xCC0E1B2C) : const Color(0xE6F4F8F8),
                border: Border(top: BorderSide(color: g.border)),
              ),
              child: SafeArea(
                top: false,
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  const SizedBox(height: 10),
                  Container(width: 42, height: 4, decoration: BoxDecoration(color: g.textDim.withValues(alpha: .5), borderRadius: BorderRadius.circular(2))),
                  Flexible(child: scroll ? SingleChildScrollView(padding: const EdgeInsets.fromLTRB(20, 14, 20, 20), child: builder(ctx)) : builder(ctx)),
                ]),
              ),
            ),
          ),
        ),
      );
    },
  );
}

Future<bool> confirmDialog(BuildContext context, {required String title, String? message, String? confirmLabel, bool danger = false}) async {
  final r = await showGeneralDialog<bool>(
    context: context,
    barrierDismissible: true,
    barrierLabel: 'dialog',
    barrierColor: Colors.black.withValues(alpha: .5),
    transitionDuration: const Duration(milliseconds: 260),
    pageBuilder: (ctx, _, __) {
      final g = ctx.g;
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 400),
            child: Material(
              color: Colors.transparent,
              child: GlassCard(
                strong: true,
                padding: const EdgeInsets.all(22),
                child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(title, style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
                  if (message != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(message, style: TextStyle(color: g.textDim, fontSize: 14.5, height: 1.35))),
                  const SizedBox(height: 20),
                  Row(children: [
                    Expanded(child: AppButton(label: ctx.t('common.cancel'), kind: ButtonKind.glass, onPressed: () => Navigator.pop(ctx, false))),
                    const SizedBox(width: 12),
                    Expanded(child: AppButton(label: confirmLabel ?? ctx.t('common.ok'), kind: danger ? ButtonKind.danger : ButtonKind.primary, onPressed: () => Navigator.pop(ctx, true))),
                  ]),
                ]),
              ),
            ),
          ),
        ),
      );
    },
    transitionBuilder: (_, a, __, child) {
      final c = CurvedAnimation(parent: a, curve: Curves.easeOutBack);
      return FadeTransition(opacity: a, child: ScaleTransition(scale: Tween(begin: .9, end: 1.0).animate(c), child: child));
    },
  );
  return r ?? false;
}

// ───────────────────────── form fields ─────────────────────────

InputDecoration glassDecoration(BuildContext context, {String? label, String? hint, IconData? icon, Widget? suffix, String? prefixText}) {
  final g = context.g;
  OutlineInputBorder b(Color c, [double w = 1]) => OutlineInputBorder(borderRadius: BorderRadius.circular(18), borderSide: BorderSide(color: c, width: w));
  return InputDecoration(
    labelText: label,
    hintText: hint,
    prefixIcon: icon == null ? null : Icon(icon, color: g.textDim, size: 20),
    suffixIcon: suffix,
    prefixText: prefixText,
    filled: true,
    fillColor: g.fill,
    labelStyle: TextStyle(color: g.textDim),
    hintStyle: TextStyle(color: g.textDim.withValues(alpha: .7)),
    floatingLabelStyle: TextStyle(color: g.accent, fontWeight: FontWeight.w600),
    errorStyle: TextStyle(color: g.danger),
    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
    border: b(g.border),
    enabledBorder: b(g.border),
    focusedBorder: b(g.accent, 1.6),
    errorBorder: b(g.danger),
    focusedErrorBorder: b(g.danger, 1.6),
  );
}

class GlassField extends StatelessWidget {
  const GlassField({
    super.key,
    this.controller,
    this.label,
    this.hint,
    this.icon,
    this.suffix,
    this.keyboardType,
    this.validator,
    this.maxLines = 1,
    this.obscure = false,
    this.readOnly = false,
    this.onTap,
    this.onChanged,
    this.inputFormatters,
    this.textInputAction,
    this.onSubmitted,
    this.autofocus = false,
    this.prefixText,
    this.initialValue,
    this.textCapitalization = TextCapitalization.none,
  });

  final TextEditingController? controller;
  final String? label, hint, prefixText, initialValue;
  final IconData? icon;
  final Widget? suffix;
  final TextInputType? keyboardType;
  final String? Function(String?)? validator;
  final int maxLines;
  final bool obscure, readOnly, autofocus;
  final VoidCallback? onTap;
  final ValueChanged<String>? onChanged, onSubmitted;
  final List<TextInputFormatter>? inputFormatters;
  final TextInputAction? textInputAction;
  final TextCapitalization textCapitalization;

  @override
  Widget build(BuildContext context) => TextFormField(
        controller: controller,
        initialValue: controller == null ? initialValue : null,
        keyboardType: keyboardType,
        validator: validator,
        maxLines: obscure ? 1 : maxLines,
        obscureText: obscure,
        readOnly: readOnly,
        onTap: onTap,
        onChanged: onChanged,
        onFieldSubmitted: onSubmitted,
        inputFormatters: inputFormatters,
        textInputAction: textInputAction,
        textCapitalization: textCapitalization,
        autofocus: autofocus,
        style: TextStyle(color: context.g.text, fontSize: 16),
        decoration: glassDecoration(context, label: label, hint: hint, icon: icon, suffix: suffix, prefixText: prefixText),
      );
}

/// Adds thin spaces between thousands while typing ("1 250 000"); keeps an optional decimal part.
class ThousandsFormatter extends TextInputFormatter {
  @override
  TextEditingValue formatEditUpdate(TextEditingValue old, TextEditingValue neu) {
    var raw = neu.text.replaceAll(RegExp(r'[^0-9.,]'), '').replaceAll('.', ',');
    final firstComma = raw.indexOf(',');
    var frac = '';
    if (firstComma >= 0) {
      frac = ',${raw.substring(firstComma + 1).replaceAll(',', '')}';
      if (frac.length > 3) frac = frac.substring(0, 3);
      raw = raw.substring(0, firstComma);
    }
    final b = StringBuffer();
    for (var i = 0; i < raw.length; i++) {
      if (i > 0 && (raw.length - i) % 3 == 0) b.write(' ');
      b.write(raw[i]);
    }
    final text = '$b$frac';
    return TextEditingValue(text: text, selection: TextSelection.collapsed(offset: text.length));
  }
}

/// An amount field in so'm; read it with `parseMinor(controller.text)`.
class MoneyField extends StatelessWidget {
  const MoneyField({super.key, required this.controller, this.label, this.validator, this.onChanged});
  final TextEditingController controller;
  final String? label;
  final String? Function(String?)? validator;
  final ValueChanged<String>? onChanged;

  @override
  Widget build(BuildContext context) => GlassField(
        controller: controller,
        label: label ?? context.t('common.amount'),
        icon: Icons.payments_outlined,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        inputFormatters: [ThousandsFormatter()],
        onChanged: onChanged,
        suffix: Padding(padding: const EdgeInsets.only(right: 14), child: Center(widthFactor: 1, child: Text(context.t('currency'), style: TextStyle(color: context.g.textDim)))),
        validator: validator ??
            (v) {
              final m = parseMinor(v ?? '');
              if (m == null) return context.t('common.invalid');
              if (m <= 0) return context.t('err.amount');
              return null;
            },
      );
}

class DateField extends StatelessWidget {
  const DateField({super.key, required this.label, required this.value, required this.onChanged, this.first, this.last, this.clearable = false});
  final String label;
  final DateTime? value;
  final ValueChanged<DateTime?> onChanged;
  final DateTime? first, last;
  final bool clearable;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: () async {
        final now = DateTime.now();
        final d = await showDatePicker(
          context: context,
          initialDate: value ?? now,
          firstDate: first ?? DateTime(now.year - 6),
          lastDate: last ?? DateTime(now.year + 3),
          builder: (ctx, child) => Theme(
            data: Theme.of(ctx).copyWith(
              colorScheme: Theme.of(ctx).colorScheme.copyWith(primary: g.accent, surface: g.dark ? const Color(0xFF12243A) : Colors.white, onSurface: g.text),
              dialogTheme: DialogThemeData(backgroundColor: g.dark ? const Color(0xFF12243A) : Colors.white),
            ),
            child: child!,
          ),
        );
        if (d != null) onChanged(DateTime(d.year, d.month, d.day));
      },
      child: InputDecorator(
        decoration: glassDecoration(context, label: label, icon: Icons.event_outlined, suffix: clearable && value != null ? IconButton(icon: Icon(Icons.close, color: g.textDim, size: 18), onPressed: () => onChanged(null)) : null),
        child: Text(value == null ? '—' : context.date(value), style: TextStyle(color: g.text, fontSize: 16)),
      ),
    );
  }
}

/// A select field that opens a glass bottom sheet with the options.
class PickerField<T> extends StatelessWidget {
  const PickerField({super.key, required this.label, required this.value, required this.options, required this.onChanged, this.icon, this.allowNone = false, this.validator});
  final String label;
  final T? value;
  final List<(T, String)> options;
  final ValueChanged<T?> onChanged;
  final IconData? icon;
  final bool allowNone;
  final String? Function(T?)? validator;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final current = options.where((o) => o.$1 == value).firstOrNull;
    return FormField<T>(
      initialValue: value,
      validator: validator,
      builder: (state) => GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: () async {
          FocusScope.of(context).unfocus();
          final picked = await showGlassSheet<_Picked<T>>(context, builder: (ctx) {
            return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(label, style: TextStyle(color: g.text, fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 10),
              if (allowNone) _opt(ctx, context.t('common.none'), value == null, () => Navigator.pop(ctx, _Picked<T>(null))),
              for (final o in options) _opt(ctx, o.$2, o.$1 == value, () => Navigator.pop(ctx, _Picked<T>(o.$1))),
            ]);
          });
          if (picked != null) {
            onChanged(picked.value);
            state.didChange(picked.value);
          }
        },
        child: InputDecorator(
          isEmpty: current == null,
          decoration: glassDecoration(context, label: label, icon: icon, suffix: Icon(Icons.keyboard_arrow_down_rounded, color: g.textDim)).copyWith(errorText: state.errorText),
          child: Text(current?.$2 ?? '', maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 16)),
        ),
      ),
    );
  }

  Widget _opt(BuildContext ctx, String text, bool selected, VoidCallback onTap) {
    final g = ctx.g;
    return PressScale(
      onTap: onTap,
      child: Container(
        margin: const EdgeInsets.only(bottom: 8),
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
        decoration: BoxDecoration(
          color: selected ? g.accent.withValues(alpha: .18) : g.fill,
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: selected ? g.accent : g.border),
        ),
        child: Row(children: [
          Expanded(child: Text(text, style: TextStyle(color: g.text, fontWeight: selected ? FontWeight.w800 : FontWeight.w600, fontSize: 15.5))),
          if (selected) Icon(Icons.check_circle_rounded, color: g.accent),
        ]),
      ),
    );
  }
}

class _Picked<T> {
  _Picked(this.value);
  final T? value;
}

/// A labelled value line used in detail pages.
class InfoLine extends StatelessWidget {
  const InfoLine(this.label, this.value, {super.key, this.bold = false, this.color});
  final String label, value;
  final bool bold;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Expanded(flex: 4, child: Text(label, style: TextStyle(color: g.textDim, fontSize: 14))),
        const SizedBox(width: 12),
        Expanded(flex: 6, child: Text(value, textAlign: TextAlign.end, style: TextStyle(color: color ?? g.text, fontSize: 14.5, fontWeight: bold ? FontWeight.w800 : FontWeight.w600))),
      ]),
    );
  }
}

/// A small note that the data on a screen is demo data kept on this device (the live data needs the server).
class DemoNote extends StatelessWidget {
  const DemoNote({super.key});

  @override
  Widget build(BuildContext context) {
    // the real company from the server: nothing here is demo data
    if (context.store.serverMode) return const SizedBox.shrink();
    final g = context.g;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(color: g.info.withValues(alpha: .12), borderRadius: BorderRadius.circular(16), border: Border.all(color: g.info.withValues(alpha: .35))),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(Icons.info_outline_rounded, size: 18, color: g.info),
        const SizedBox(width: 10),
        Expanded(child: Text(context.t('common.demo_data'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.3))),
      ]),
    );
  }
}

/// One row of a list as its own glass tile (rows are never merged into one big card).
class SoftTile extends StatelessWidget {
  const SoftTile({super.key, required this.child, this.onTap, this.onLongPress, this.tint, this.padding = const EdgeInsets.symmetric(horizontal: 14, vertical: 4), this.radius = 20});
  final Widget child;
  final VoidCallback? onTap, onLongPress;
  final Color? tint;
  final EdgeInsetsGeometry padding;
  final double radius;

  @override
  Widget build(BuildContext context) => GlassCard(blur: false, radius: radius, padding: padding, tint: tint, onTap: onTap, onLongPress: onLongPress, child: child);
}

/// A titled list: the title and the rows are separate blocks with a gap between every row.
class TileSection extends StatelessWidget {
  const TileSection({super.key, required this.title, this.subtitle, this.trailing, required this.children, this.empty, this.gap = 10});
  final String title;
  final String? subtitle, empty;
  final Widget? trailing;
  final List<Widget> children;
  final double gap;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(4, 0, 4, 10),
        child: Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(title, style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800, letterSpacing: -.2)),
              if (subtitle != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(subtitle!, style: TextStyle(color: g.textDim, fontSize: 12))),
            ]),
          ),
          ?trailing,
        ]),
      ),
      if (children.isEmpty)
        SoftTile(padding: const EdgeInsets.all(16), child: Text(empty ?? '', style: TextStyle(color: g.textDim, fontSize: 14)))
      else
        for (var i = 0; i < children.length; i++) ...[
          if (i > 0) SizedBox(height: gap),
          Reveal(index: i, dy: 10, child: children[i]),
        ],
    ]);
  }
}

/// Big amount text where the part after the first digits is muted, like the reference design.
class AmountText extends StatelessWidget {
  const AmountText(this.minor, {super.key, this.size = 28, this.color, this.sign = false, this.animate = true});
  final int minor;
  final double size;
  final Color? color;
  final bool sign, animate;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    Widget build(int v) => FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text.rich(TextSpan(children: [
            TextSpan(text: formatSom(v, sign: sign), style: TextStyle(color: color ?? g.text, fontSize: size, fontWeight: FontWeight.w800, letterSpacing: -.5)),
            TextSpan(text: ' ${context.t('currency')}', style: TextStyle(color: g.textDim, fontSize: size * .5, fontWeight: FontWeight.w600)),
          ])),
        );
    return animate ? CountUp(value: minor, builder: (_, v) => build(v)) : build(minor);
  }
}
