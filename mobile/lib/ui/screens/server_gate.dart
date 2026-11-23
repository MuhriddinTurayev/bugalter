import 'package:flutter/material.dart';

import '../../core/scope.dart';
import '../widgets/glass.dart';

/// In server mode a module shows the real company's data. When the phone has not read the server yet, or the person has no right to this
/// part (the server leaves it out of its answer), this is the page to show instead; null when the data is there (and always in demo mode).
Widget? serverGate(BuildContext context, {required String title, required bool synced, required bool allowed}) {
  if (!synced) {
    return PageScaffold(title: title, body: EmptyState(icon: Icons.cloud_sync_outlined, title: context.t('srv.mod.not_synced'), text: context.t('srv.mod.not_synced_text')));
  }
  if (!allowed) {
    return PageScaffold(title: title, body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('srv.mod.no_access_text')));
  }
  return null;
}
