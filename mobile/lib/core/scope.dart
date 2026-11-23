import 'package:flutter/material.dart';

import '../data/models.dart';
import '../data/store.dart';
import '../l10n/strings.dart';
import 'money.dart';

/// Hands the [AppStore] to the whole widget tree; widgets that read it rebuild when the books or the language change.
class AppScope extends InheritedNotifier<AppStore> {
  const AppScope({super.key, required AppStore store, required super.child}) : super(notifier: store);
}

extension AppContext on BuildContext {
  /// The store; the calling widget rebuilds when it changes.
  AppStore get store => dependOnInheritedWidgetOfExactType<AppScope>()!.notifier!;

  /// The store without subscribing (event handlers, initState).
  AppStore get readStore => getInheritedWidgetOfExactType<AppScope>()!.notifier!;

  String get locale => store.locale;

  /// Translates [key] into the current language.
  String t(String key, [Map<String, Object?>? args]) => translate(store.locale, key, args);

  String money(int minor, {bool sign = false}) => formatMoney(minor, t('currency'), sign: sign);

  String compact(int minor) => formatCompact(minor, thousand: t('unit.thousand'), million: t('unit.million'), billion: t('unit.billion'));

  String date(DateTime? d) => d == null ? '—' : '${d.day.toString().padLeft(2, '0')}.${d.month.toString().padLeft(2, '0')}.${d.year}';

  String dateTime(DateTime d) => '${date(d)} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

  String monthName(int m) => t('month.$m');

  String monthYear(DateTime d) => '${t('month.${d.month}')} ${d.year}';

  String categoryName(Category? c) {
    if (c == null) return t('cat.none');
    // a system category the app has a translation for; any other (from the server) keeps its own name
    if (c.key != null && kAllStrings.containsKey('cat.${c.key}')) return t('cat.${c.key}');
    return c.name.isNotEmpty ? c.name : t('cat.none');
  }

  String roleName(Role r) => t('role.${r.key}');

  String docStatusName(Doc d) => d.isOverdue(DateTime.now()) ? t('status.overdue') : t('status.${d.status.name}');

  /// Shows a floating message.
  void toast(String message) {
    final m = ScaffoldMessenger.maybeOf(this);
    m?.hideCurrentSnackBar();
    m?.showSnackBar(SnackBar(content: Text(message), duration: const Duration(seconds: 3)));
  }

  /// Runs [action]; business-rule errors become a localized message.
  bool attempt(void Function() action) {
    try {
      action();
      return true;
    } on AppError catch (e) {
      toast(t('err.${e.key}'));
    } catch (e) {
      toast('${t('err.generic')}');
    }
    return false;
  }
}
