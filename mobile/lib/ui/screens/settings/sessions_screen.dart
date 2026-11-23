import 'dart:async';

import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/api.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';
import '../agent/agent_actions.dart';

/// Where the people of the company are signed in (phones with this app and web browsers). Everyone sees and can end their own sign-ins ("sign out from
/// all other places"); the owner (or a person who manages users) sees everybody's and can sign somebody out. An ended sign-in stops at once.
class SessionsScreen extends StatefulWidget {
  const SessionsScreen({super.key});

  @override
  State<SessionsScreen> createState() => _SessionsScreenState();
}

class _SessionsScreenState extends State<SessionsScreen> {
  Map<String, dynamic>? _data;
  bool _failed = false, _busy = false;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    if (context.readStore.serverMode) {
      _load();
      _timer = Timer.periodic(const Duration(seconds: 20), (_) => _load());
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _load() async {
    final store = context.readStore;
    try {
      final d = await store.serverRequest('GET', '/api/mobile/sessions');
      if (!mounted) return;
      setState(() {
        _data = d;
        _failed = false;
      });
    } on ApiException {
      if (mounted) setState(() => _failed = true);
    }
  }

  Future<void> _do(String method, String path, [Map<String, dynamic>? body]) async {
    if (_busy) return;
    final store = context.readStore;
    setState(() => _busy = true);
    String message;
    try {
      await store.serverRequest(method, path, body);
      message = context.t('sess.ended');
    } on ApiException catch (e) {
      message = apiErrorText(context, e);
    }
    if (!mounted) return;
    setState(() => _busy = false);
    context.toast(message);
    await _load();
  }

  Future<void> _end(Map<String, dynamic> s) async {
    final yes = await confirmDialog(context, title: context.t('sess.end'), message: context.t('sess.end_confirm', {'device': _deviceName(context, s), 'name': '${s['userName'] ?? ''}'}), danger: true, confirmLabel: context.t('sess.end'));
    if (!yes || !mounted) return;
    await _do('DELETE', '/api/mobile/sessions/${s['kind']}/${Uri.encodeComponent('${s['id']}')}');
  }

  Future<void> _endAll(int userId, {required bool mine, String name = ''}) async {
    final yes = await confirmDialog(context, title: context.t(mine ? 'sess.end_others' : 'sess.end_all'), message: context.t(mine ? 'sess.end_others_confirm' : 'sess.end_all_confirm', {'name': name}), danger: true, confirmLabel: context.t('sess.end'));
    if (!yes || !mounted) return;
    await _do('POST', '/api/mobile/sessions/revoke-others', {'userId': userId});
  }

  String _deviceName(BuildContext context, Map<String, dynamic> s) {
    final d = '${s['device'] ?? ''}'.trim();
    if (d.isNotEmpty) return d;
    final p = '${s['platform'] ?? ''}';
    return p.isEmpty ? context.t(s['kind'] == 'web' ? 'sess.web' : 'sess.mobile') : context.t('sess.platform.$p');
  }

  IconData _icon(Map<String, dynamic> s) => switch ('${s['platform']}') {
        'android' || 'ios' => Icons.smartphone_rounded,
        'windows' || 'mac' || 'linux' => Icons.laptop_rounded,
        _ => s['kind'] == 'web' ? Icons.language_rounded : Icons.smartphone_rounded,
      };

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final title = context.t('nav.sessions');
    if (!context.store.serverMode) {
      return PageScaffold(title: title, body: EmptyState(icon: Icons.devices_rounded, title: context.t('sess.demo')));
    }
    final d = _data;
    if (d == null) {
      return PageScaffold(
        title: title,
        subtitle: context.t('sess.subtitle'),
        body: _failed ? EmptyState(icon: Icons.cloud_off_rounded, title: context.t('srv.offline'), text: context.t('srv.err_network')) : const Center(child: CircularProgressIndicator()),
      );
    }
    final rows = [for (final r in (d['sessions'] as List? ?? const [])) Map<String, dynamic>.from(r as Map)];
    final manager = d['canManage'] == true;
    final me = (d['me'] as num?)?.toInt() ?? 0;

    // by person: mine first
    final byPerson = <int, List<Map<String, dynamic>>>{};
    for (final r in rows) {
      byPerson.putIfAbsent((r['userId'] as num?)?.toInt() ?? 0, () => []).add(r);
    }
    final people = byPerson.keys.toList()..sort((a, b) => a == me ? -1 : (b == me ? 1 : a.compareTo(b)));

    return PageScaffold(
      title: title,
      subtitle: context.t('sess.subtitle'),
      actions: [GlassIconButton(icon: Icons.refresh_rounded, tooltip: context.t('srv.refresh'), onTap: _load)],
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace + 8),
        children: [
          Padding(padding: const EdgeInsets.symmetric(horizontal: 4), child: Text(context.t(manager ? 'sess.note_manager' : 'sess.note'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35))),
          const SizedBox(height: 14),
          if (rows.isEmpty) EmptyState(icon: Icons.devices_rounded, title: context.t('sess.empty')),
          for (final uid in people) ...[
            TileSection(
              title: uid == me ? context.t('sess.mine') : '${byPerson[uid]!.first['userName'] ?? ''}',
              subtitle: uid == me ? null : context.roleName(_role('${byPerson[uid]!.first['role']}')),
              children: [
                for (final s in byPerson[uid]!)
                  SoftTile(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        IconBadge(icon: _icon(s), color: s['current'] == true ? g.success : g.info, size: 42),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(_deviceName(context, s), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                            Text(context.t(s['kind'] == 'web' ? 'sess.web' : 'sess.mobile'), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                            if (s['lastSeenAt'] != null) Text(context.t('sess.last_seen', {'time': context.dateTime(DateTime.parse('${s['lastSeenAt']}'))}), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                            if (s['ip'] != null) Text('${s['ip']}', style: TextStyle(color: g.textDim, fontSize: 12)),
                          ]),
                        ),
                        if (s['current'] == true) StatusPill(label: context.t('sess.this'), color: g.success),
                      ]),
                      if (s['canRevoke'] == true) ...[
                        const SizedBox(height: 10),
                        Align(alignment: Alignment.centerRight, child: AppButton(label: context.t('sess.end'), icon: Icons.logout_rounded, kind: ButtonKind.danger, compact: true, expanded: false, onPressed: _busy ? null : () => _end(s))),
                      ],
                    ]),
                  ),
              ],
            ),
            if (uid == me && byPerson[uid]!.length > 1) ...[
              const SizedBox(height: 10),
              AppButton(label: context.t('sess.end_others'), icon: Icons.phonelink_erase_rounded, kind: ButtonKind.glass, onPressed: _busy ? null : () => _endAll(me, mine: true)),
            ],
            if (uid != me && manager && byPerson[uid]!.any((r) => r['canRevoke'] == true)) ...[
              const SizedBox(height: 10),
              AppButton(label: context.t('sess.end_all'), icon: Icons.phonelink_erase_rounded, kind: ButtonKind.glass, onPressed: _busy ? null : () => _endAll(uid, mine: false, name: '${byPerson[uid]!.first['userName'] ?? ''}')),
            ],
            const SizedBox(height: 18),
          ],
        ],
      ),
    );
  }
}

Role _role(String key) => Role.values.firstWhere((r) => r.key == key, orElse: () => Role.cashier);
