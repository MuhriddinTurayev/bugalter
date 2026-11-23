import 'package:flutter/cupertino.dart' show CupertinoPageTransitionsBuilder;
import 'package:flutter/material.dart';

/// Colours of the glass design. A [ThemeExtension] so widgets read them from the theme and animate between modes.
@immutable
class Glass extends ThemeExtension<Glass> {
  const Glass({
    required this.bg,
    required this.blobs,
    required this.fill,
    required this.fillStrong,
    required this.border,
    required this.text,
    required this.textDim,
    required this.accent,
    required this.accent2,
    required this.success,
    required this.warning,
    required this.danger,
    required this.info,
    required this.shadow,
    required this.dark,
  });

  final List<Color> bg, blobs;
  final Color fill, fillStrong, border, text, textDim, accent, accent2, success, warning, danger, info, shadow;
  final bool dark;

  static const darkTheme = Glass(
    bg: [Color(0xFF07111F), Color(0xFF0B2230), Color(0xFF0D1B3A)],
    blobs: [Color(0xFF10B981), Color(0xFF6366F1), Color(0xFF22D3EE)],
    fill: Color(0x1AFFFFFF),
    fillStrong: Color(0x2EFFFFFF),
    border: Color(0x33FFFFFF),
    text: Color(0xFFF1F5F9),
    textDim: Color(0xB3CBD5E1),
    accent: Color(0xFF34D399),
    accent2: Color(0xFF818CF8),
    success: Color(0xFF34D399),
    warning: Color(0xFFFBBF24),
    danger: Color(0xFFFB7185),
    info: Color(0xFF38BDF8),
    shadow: Color(0x66000000),
    dark: true,
  );

  static const lightTheme = Glass(
    bg: [Color(0xFFE6F4EF), Color(0xFFEEF0FF), Color(0xFFE3F1FB)],
    blobs: [Color(0xFF34D399), Color(0xFF818CF8), Color(0xFF38BDF8)],
    fill: Color(0x99FFFFFF),
    fillStrong: Color(0xCCFFFFFF),
    border: Color(0xCCFFFFFF),
    text: Color(0xFF0F172A),
    textDim: Color(0xFF475569),
    accent: Color(0xFF0F7B5F),
    accent2: Color(0xFF4F46E5),
    success: Color(0xFF059669),
    warning: Color(0xFFD97706),
    danger: Color(0xFFE11D48),
    info: Color(0xFF0284C7),
    shadow: Color(0x1A0F172A),
    dark: false,
  );

  @override
  Glass copyWith() => this;

  @override
  Glass lerp(ThemeExtension<Glass>? other, double t) {
    if (other is! Glass) return this;
    Color l(Color a, Color b) => Color.lerp(a, b, t)!;
    List<Color> ll(List<Color> a, List<Color> b) => [for (var i = 0; i < a.length; i++) l(a[i], b[i])];
    return Glass(
      bg: ll(bg, other.bg), blobs: ll(blobs, other.blobs), fill: l(fill, other.fill), fillStrong: l(fillStrong, other.fillStrong),
      border: l(border, other.border), text: l(text, other.text), textDim: l(textDim, other.textDim), accent: l(accent, other.accent),
      accent2: l(accent2, other.accent2), success: l(success, other.success), warning: l(warning, other.warning),
      danger: l(danger, other.danger), info: l(info, other.info), shadow: l(shadow, other.shadow), dark: t < .5 ? dark : other.dark,
    );
  }
}

extension GlassContext on BuildContext {
  Glass get g => Theme.of(this).extension<Glass>()!;
}

ThemeData buildTheme(bool dark) {
  final g = dark ? Glass.darkTheme : Glass.lightTheme;
  final base = ThemeData(brightness: dark ? Brightness.dark : Brightness.light, useMaterial3: true);
  final scheme = ColorScheme.fromSeed(seedColor: g.accent, brightness: dark ? Brightness.dark : Brightness.light).copyWith(
    primary: g.accent,
    secondary: g.accent2,
    surface: Colors.transparent,
    error: g.danger,
  );
  return base.copyWith(
    colorScheme: scheme,
    scaffoldBackgroundColor: Colors.transparent,
    canvasColor: Colors.transparent,
    extensions: [g],
    splashFactory: InkSparkle.splashFactory,
    textTheme: base.textTheme.apply(bodyColor: g.text, displayColor: g.text, fontFamily: null),
    iconTheme: IconThemeData(color: g.text),
    dividerColor: g.border,
    pageTransitionsTheme: PageTransitionsTheme(builders: const {
      TargetPlatform.android: FadeForwardsPageTransitionsBuilder(),
      TargetPlatform.iOS: CupertinoPageTransitionsBuilder(),
      TargetPlatform.windows: FadeForwardsPageTransitionsBuilder(),
    }),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      backgroundColor: dark ? const Color(0xE6203040) : const Color(0xE60F172A),
      contentTextStyle: const TextStyle(color: Colors.white, fontSize: 14),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
    ),
    textSelectionTheme: TextSelectionThemeData(cursorColor: g.accent, selectionHandleColor: g.accent),
    progressIndicatorTheme: ProgressIndicatorThemeData(color: g.accent),
    switchTheme: SwitchThemeData(
      thumbColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? Colors.white : g.textDim),
      trackColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? g.accent : g.fill),
      trackOutlineColor: WidgetStateProperty.all(g.border),
    ),
    checkboxTheme: CheckboxThemeData(fillColor: WidgetStateProperty.resolveWith((s) => s.contains(WidgetState.selected) ? g.accent : Colors.transparent), side: BorderSide(color: g.border, width: 1.5)),
  );
}
