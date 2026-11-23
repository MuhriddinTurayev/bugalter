import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/store.dart';
import '../../widgets/glass.dart';
import '../documents/document_detail.dart';

String noticeText(BuildContext context, AppNotice n) {
  switch (n.kind) {
    case 'overdue':
      return context.t('notif.overdue', n.args);
    case 'tax':
      final days = int.tryParse(n.args['days'] ?? '') ?? 0;
      final type = context.t('taxtype.${n.args['type']}');
      return days >= 0 ? context.t('notif.tax_left', {'type': type, 'days': days}) : context.t('notif.tax_late', {'type': type, 'days': -days});
    case 'ai':
      return context.t('notif.ai', {'text': n.args['text'] ?? ''});
    case 'task':
      return context.t('notif.task', {'title': n.args['title'] ?? '', 'status': n.args['status'] ?? ''});
    case 'payroll':
      return context.t('notif.payroll', {'month': context.monthName(int.tryParse(n.args['month'] ?? '1') ?? 1)});
    default:
      return n.key;
  }
}

IconData _icon(String kind) => switch (kind) {
      'overdue' => Icons.event_busy_rounded,
      'tax' => Icons.receipt_long_rounded,
      'payroll' => Icons.groups_rounded,
      'ai' => Icons.auto_awesome_rounded,
      'task' => Icons.smart_toy_rounded,
      _ => Icons.notifications_rounded,
    };

Future<void> showNotifications(BuildContext context) {
  return showGlassSheet<void>(context, builder: (ctx) {
    final g = ctx.g;
    final s = ctx.store;
    final list = s.notices();
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      Row(children: [
        Expanded(child: Text(ctx.t('notif.title'), style: TextStyle(color: g.text, fontSize: 20, fontWeight: FontWeight.w800))),
        if (list.isNotEmpty)
          GestureDetector(
            onTap: () {
              s.markAllRead();
              Navigator.pop(ctx);
            },
            child: Text(ctx.t('notif.mark_read'), style: TextStyle(color: g.accent, fontWeight: FontWeight.w700, fontSize: 13)),
          ),
      ]),
      const SizedBox(height: 14),
      if (list.isEmpty)
        Padding(
          padding: const EdgeInsets.symmetric(vertical: 30),
          child: Column(children: [
            IconBadge(icon: Icons.notifications_off_outlined, size: 60, color: g.accent2),
            const SizedBox(height: 12),
            Text(ctx.t('notif.none'), style: TextStyle(color: g.textDim)),
          ]),
        )
      else
        for (var i = 0; i < list.length; i++)
          Reveal(
            index: i,
            child: Padding(
              padding: const EdgeInsets.only(bottom: 10),
              child: GlassCard(
                blur: false,
                padding: const EdgeInsets.all(14),
                radius: 20,
                onTap: () {
                  final n = list[i];
                  Navigator.pop(ctx);
                  if (n.kind == 'overdue') {
                    final id = int.tryParse(n.key.split(':').last);
                    if (id != null) pushPage(context, DocumentDetailScreen(docId: id));
                  }
                },
                child: Row(children: [
                  IconBadge(icon: _icon(list[i].kind), size: 40, color: list[i].kind == 'overdue' ? g.danger : (list[i].kind == 'tax' ? g.warning : g.info)),
                  const SizedBox(width: 12),
                  Expanded(child: Text(noticeText(ctx, list[i]), style: TextStyle(color: g.text, fontWeight: s.readNotices.contains(list[i].key) ? FontWeight.w500 : FontWeight.w700, fontSize: 14.5))),
                  if (!s.readNotices.contains(list[i].key)) Container(width: 9, height: 9, decoration: BoxDecoration(color: g.accent, shape: BoxShape.circle)),
                ]),
              ),
            ),
          ),
    ]);
  });
}
