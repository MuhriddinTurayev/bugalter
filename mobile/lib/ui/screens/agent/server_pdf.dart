import 'dart:io';

import 'package:flutter/material.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../../../core/scope.dart';
import '../../../data/api.dart';
import 'agent_actions.dart';

/// Downloads a PDF of the company's server (with the person's token) into the phone's temporary folder and opens it in the phone's own viewer.
Future<void> openServerPdf(BuildContext context, String path, String fileName) async {
  final store = context.readStore;
  try {
    final bytes = await store.serverBytes(path);
    final dir = await getTemporaryDirectory();
    final file = File('${dir.path}/$fileName');
    await file.writeAsBytes(bytes, flush: true);
    final r = await OpenFilex.open(file.path, type: 'application/pdf');
    if (r.type != ResultType.done && context.mounted) context.toast(context.t('tr.pdf_failed'));
  } on ApiException catch (e) {
    if (context.mounted) context.toast(e.status == 404 ? context.t('tr.pdf_missing') : apiErrorText(context, e));
  } catch (_) {
    if (context.mounted) context.toast(context.t('tr.pdf_failed'));
  }
}
