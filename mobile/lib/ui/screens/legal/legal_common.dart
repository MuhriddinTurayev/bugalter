import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/legal.dart';
import '../../../data/store.dart';
import '../../../l10n/strings.dart' show kAllStrings;

/// Runs an action of the Didox / legal data. A business-rule error becomes a localized message instead of a crash.
bool runLegal(BuildContext context, void Function() action) {
  try {
    action();
    return true;
  } on LegalError catch (e) {
    context.toast(context.t(e.key));
  } on AppError catch (e) {
    context.toast(context.t('err.${e.key}'));
  } catch (_) {
    context.toast(context.t('err.generic'));
  }
  return false;
}

/// The colour of a Didox status, as on the web (waiting for us = red, waiting for the partner = amber, signed = green).
Color didoxStatusColor(Glass g, String status) => switch (status) {
      'signed' => g.success,
      'rejected' => g.danger,
      'deleted' => g.textDim,
      'waiting_me' => g.danger,
      _ => g.warning,
    };

/// The status of a Didox document in words: ours when the app knows the status, else the words Didox itself uses.
String didoxStatusLabel(BuildContext context, DidoxDoc d) {
  if (kAllStrings.containsKey('didox.status.${d.status}')) return context.t('didox.status.${d.status}');
  return d.statusText.isNotEmpty ? d.statusText : d.status;
}

/// The kind of a Didox document: ours, or (for a kind the app has no word for) the words Didox uses.
String didoxKindLabel(BuildContext context, DidoxDoc d) {
  if (d.kind == 'free' && d.kindText.isNotEmpty) return d.kindText;
  return context.t('didox.kind.${d.kind}');
}

/// What a draft is: a template of the phone (demo) or the kind of document the AI lawyer wrote (server).
String legalKindLabel(BuildContext context, String id) {
  if (kAllStrings.containsKey('legal.tpl.$id')) return context.t('legal.tpl.$id');
  if (kAllStrings.containsKey('legal.kind.$id')) return context.t('legal.kind.$id');
  return id;
}

IconData didoxKindIcon(String kind) => switch (kind) {
      'contract' => Icons.handshake_rounded,
      'invoice' => Icons.receipt_long_rounded,
      'act' => Icons.assignment_turned_in_rounded,
      _ => Icons.description_rounded,
    };

IconData legalTemplateIcon(String id) => switch (id) {
      'services' => Icons.design_services_rounded,
      'supply' => Icons.local_shipping_rounded,
      'lease' => Icons.home_work_rounded,
      'nda' => Icons.lock_rounded,
      'agency' => Icons.handshake_rounded,
      'employment' => Icons.badge_rounded,
      'act' => Icons.assignment_turned_in_rounded,
      'agreement' => Icons.edit_note_rounded,
      'power_of_attorney' => Icons.how_to_reg_rounded,
      'contract' => Icons.handshake_rounded,
      'other' => Icons.description_outlined,
      _ => Icons.mail_rounded,
    };

Color legalStatusColor(Glass g, String status) => switch (status) {
      'approved' => g.success,
      'sent' => g.info,
      'failed' => g.danger,
      'draft' => g.warning,
      _ => g.textDim,
    };
