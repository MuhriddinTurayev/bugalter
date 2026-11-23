import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/extras.dart';
import '../../widgets/glass.dart';
import '../server_gate.dart';

/// The ERI (E-IMZO) keys of the company as the server vault lists them: owner, serial, validity and status.
/// The key files and their passwords are secrets: they stay encrypted on the server and are never handled on the phone.
class SigningKeysScreen extends StatelessWidget {
  const SigningKeysScreen({super.key});

  (String, Color, IconData) _state(BuildContext context, EriKey k, DateTime now) {
    final g = context.g;
    return switch (k.state(now)) {
      EriState.valid => (context.t('eri.st_valid'), g.success, Icons.verified_rounded),
      EriState.expiring30 || EriState.expiring14 => (context.t('eri.st_expiring'), g.warning, Icons.schedule_rounded),
      EriState.expiring3 => (context.t('eri.st_expiring'), g.danger, Icons.alarm_rounded),
      EriState.expired => (context.t('eri.st_expired'), g.danger, Icons.event_busy_rounded),
      EriState.revoked => (context.t('eri.st_revoked'), g.textDim, Icons.block_rounded),
    };
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final live = s.serverMode;
    final gate = serverGate(context, title: context.t('eri.title'), synced: s.extrasSynced(), allowed: s.extrasAllowed('keys'));
    if (gate != null) return gate;
    final now = DateTime.now();
    final keys = s.eriKeys;
    final expiring = keys.where((k) => const [EriState.expiring30, EriState.expiring14, EriState.expiring3].contains(k.state(now))).length;
    var i = 0;

    return PageScaffold(
      title: context.t('eri.title'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
        children: [
          Reveal(index: i++, child: const DemoNote()),
          const SizedBox(height: 12),
          Reveal(
            index: i++,
            child: GlassCard(
              padding: const EdgeInsets.all(16),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                IconBadge(icon: Icons.lock_rounded, size: 44, color: g.accent2),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t('eri.note_title'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15.5)),
                    const SizedBox(height: 4),
                    Text(context.t('eri.note'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.4)),
                    const SizedBox(height: 8),
                    Text(context.t(live ? 'srv.keys.note' : 'eri.subtitle'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35)),
                  ]),
                ),
              ]),
            ),
          ),
          if (expiring > 0) ...[
            const SizedBox(height: 12),
            Reveal(
              index: i++,
              child: SoftTile(
                padding: const EdgeInsets.all(14),
                tint: g.warning.withValues(alpha: .14),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  IconBadge(icon: Icons.warning_amber_rounded, size: 40, color: g.warning),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(context.t('eri.warn_title'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 14.5)),
                      const SizedBox(height: 3),
                      Text(context.t('eri.warn_body', {'n': expiring}), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35)),
                    ]),
                  ),
                ]),
              ),
            ),
          ],
          const SizedBox(height: 18),
          TileSection(
            title: context.t('eri.title'),
            empty: context.t('eri.empty'),
            children: [
              for (final k in keys)
                () {
                  final (label, color, icon) = _state(context, k, now);
                  final days = k.daysLeft(now);
                  return SoftTile(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        IconBadge(icon: Icons.vpn_key_rounded, size: 42, color: color),
                        const SizedBox(width: 12),
                        Expanded(child: Text(k.label.isEmpty ? k.serial : k.label, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15.5))),
                        const SizedBox(width: 8),
                        Flexible(flex: 0, child: StatusPill(label: label, color: color, icon: icon)),
                      ]),
                      const SizedBox(height: 10),
                      if (k.ownerName.isNotEmpty) InfoLine(context.t('eri.owner'), k.ownerName),
                      if (k.ownerTin.isNotEmpty) InfoLine(context.t('eri.tin'), k.ownerTin),
                      if (k.serial.isNotEmpty) InfoLine(context.t('eri.serial'), k.serial),
                      InfoLine(context.t('eri.valid_from'), context.date(k.validFrom)),
                      InfoLine(context.t('eri.valid_to'), context.date(k.validTo), bold: true),
                      if (!k.revoked && days >= 0 && days <= 30)
                        Padding(
                          padding: const EdgeInsets.only(top: 6),
                          child: Text(context.t('eri.days_left', {'days': days}), style: TextStyle(color: color, fontWeight: FontWeight.w800, fontSize: 13)),
                        ),
                    ]),
                  );
                }(),
            ],
          ),
        ],
      ),
    );
  }
}
