import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/store.dart';
import '../../widgets/glass.dart';

/// A month selector with previous / next buttons.
class MonthStepper extends StatelessWidget {
  const MonthStepper({super.key, required this.month, required this.onChanged});
  final DateTime month;
  final ValueChanged<DateTime> onChanged;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return GlassCard(
      radius: 22,
      padding: const EdgeInsets.all(8),
      child: Row(children: [
        GlassIconButton(icon: Icons.chevron_left_rounded, size: 42, onTap: () => onChanged(DateTime(month.year, month.month - 1, 1))),
        Expanded(
          child: AnimatedSwitcher(
            duration: const Duration(milliseconds: 220),
            child: Text(context.monthYear(month), key: ValueKey('${month.year}-${month.month}-${context.locale}'), textAlign: TextAlign.center, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800)),
          ),
        ),
        GlassIconButton(icon: Icons.chevron_right_rounded, size: 42, onTap: () => onChanged(DateTime(month.year, month.month + 1, 1))),
      ]),
    );
  }
}

/// The message of a business-rule error (with its placeholders), or the generic one.
String errorText(BuildContext context, Object e) => e is AppError ? context.t('err.${e.key}', e.args) : context.t('err.generic');
