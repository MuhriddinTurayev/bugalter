import 'dart:async';

import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/api.dart';
import '../../widgets/glass.dart';

/// The company's computer agent: what the robot on the accountant's computer is doing right now, the steps it took and what it read in the tax
/// cabinet and Didox. Read from the server and refreshed while this page is open. The two buttons only ask the robot to READ again; signing, paying
/// and submitting are never started from here.
class AgentScreen extends StatefulWidget {
  const AgentScreen({super.key});

  @override
  State<AgentScreen> createState() => _AgentScreenState();
}

class _AgentScreenState extends State<AgentScreen> {
  Map<String, dynamic>? _data;
  bool _forbidden = false, _busy = false, _failed = false;
  String? _doing;
  final _open = <int>{};
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    if (context.readStore.serverMode) {
      _load();
      _timer = Timer.periodic(const Duration(seconds: 5), (_) => _load());
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
      final d = await store.serverRequest('GET', '/api/mobile/agent');
      if (!mounted) return;
      setState(() {
        _data = d;
        _failed = false;
        _forbidden = false;
      });
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _forbidden = e.status == 403;
        _failed = e.status != 403;
      });
    }
  }

  Future<void> _ask(String path) async {
    if (_busy) return;
    final store = context.readStore;
    setState(() {
      _busy = true;
      _doing = path;
    });
    String? message;
    try {
      final r = await store.serverRequest('POST', path);
      message = r['message'] as String? ?? context.t('agent.refreshed');
    } on ApiException catch (e) {
      message = e.message ?? context.t(e.status == 403 ? 'agent.no_right' : (e.network ? 'srv.err_network' : 'agent.check_failed'));
    }
    if (!mounted) return;
    setState(() {
      _busy = false;
      _doing = null;
    });
    context.toast(message);
    await _load();
  }

  Color _tone(Glass g, String? tone) => switch (tone) {
        'success' => g.success,
        'danger' => g.danger,
        'warning' => g.warning,
        'neutral' => g.textDim,
        _ => g.info,
      };

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final title = context.t('nav.agent');
    if (!s.serverMode) {
      return PageScaffold(title: title, body: EmptyState(icon: Icons.smart_toy_outlined, title: context.t('agent.demo_title'), text: context.t('agent.demo_text')));
    }
    if (_forbidden) {
      return PageScaffold(title: title, body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('srv.mod.no_access_text')));
    }
    final d = _data;
    if (d == null) {
      return PageScaffold(
        title: title,
        subtitle: context.t('agent.subtitle'),
        body: _failed
            ? EmptyState(icon: Icons.cloud_off_rounded, title: context.t('srv.offline'), text: context.t('srv.err_network'))
            : const Center(child: CircularProgressIndicator()),
      );
    }

    final live = Map<String, dynamic>.from(d['live'] as Map? ?? const {});
    final computers = [for (final c in (d['computers'] as List? ?? const [])) Map<String, dynamic>.from(c as Map)];
    final tasks = [for (final t in (d['tasks'] as List? ?? const [])) Map<String, dynamic>.from(t as Map)];
    final canRun = d['canRun'] == true;
    final active = live['active'] == true;
    final online = (live['online'] as num?)?.toInt() ?? 0;
    final liveColor = active ? _tone(g, live['tone'] as String?) : (online > 0 ? g.success : g.textDim);
    final steps = [for (final x in (live['steps'] as List? ?? const [])) Map<String, dynamic>.from(x as Map)];

    return PageScaffold(
      title: title,
      subtitle: context.t('agent.subtitle'),
      actions: [GlassIconButton(icon: Icons.refresh_rounded, tooltip: context.t('srv.refresh'), onTap: _load)],
      body: ListView(
        padding: EdgeInsets.fromLTRB(16, 8, 16, context.navSpace),
        children: [
          // what the robot does now
          SoftTile(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                IconBadge(icon: active ? Icons.smart_toy_rounded : Icons.smart_toy_outlined, color: liveColor, size: 44),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(context.t('agent.now'), style: TextStyle(color: g.textDim, fontSize: 12.5, fontWeight: FontWeight.w600)),
                    Text(active ? '${live['title']}' : '${live['idle'] ?? context.t('agent.idle')}', style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16)),
                  ]),
                ),
                if (active && live['statusLabel'] != null) StatusPill(label: '${live['statusLabel']}', color: liveColor),
              ]),
              if (active && live['step'] != null) ...[
                const SizedBox(height: 10),
                Text('${live['step']}', style: TextStyle(color: g.text, fontSize: 14, height: 1.3)),
              ],
              if (live['needsYou'] == true) ...[
                const SizedBox(height: 10),
                Row(children: [
                  Icon(Icons.warning_amber_rounded, size: 18, color: g.warning),
                  const SizedBox(width: 8),
                  Expanded(child: Text('${live['waiting'] ?? context.t('agent.needs_you')}', style: TextStyle(color: g.warning, fontWeight: FontWeight.w700, fontSize: 13.5))),
                ]),
              ],
              if (steps.isNotEmpty) ...[
                const SizedBox(height: 12),
                Text(context.t('agent.steps'), style: TextStyle(color: g.textDim, fontSize: 12.5, fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                for (final x in steps) _Step(time: '${x['time']}', text: '${x['text']}'),
              ],
            ]),
          ),
          const SizedBox(height: 14),
          if (canRun) ...[
            AppButton(
              label: context.t(_doing == '/api/mobile/agent/check' ? 'agent.checking' : 'agent.check_cabinet'),
              icon: Icons.account_balance_rounded,
              loading: _doing == '/api/mobile/agent/check',
              onPressed: _busy ? null : () => _ask('/api/mobile/agent/check'),
            ),
            const SizedBox(height: 10),
            AppButton(
              label: context.t(_doing == '/api/mobile/agent/didox-check' ? 'agent.checking' : 'agent.check_didox'),
              icon: Icons.description_rounded,
              kind: ButtonKind.glass,
              loading: _doing == '/api/mobile/agent/didox-check',
              onPressed: _busy ? null : () => _ask('/api/mobile/agent/didox-check'),
            ),
            const SizedBox(height: 10),
          ],
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 4),
            child: Text(context.t('agent.check_hint'), style: TextStyle(color: g.textDim, fontSize: 12.5, height: 1.35)),
          ),
          const SizedBox(height: 20),
          // computers
          TileSection(
            title: context.t('agent.computers'),
            subtitle: context.t('agent.computers_hint'),
            empty: computers.isEmpty ? context.t('agent.no_computer') : null,
            children: [
              for (final c in computers)
                SoftTile(
                  padding: const EdgeInsets.all(14),
                  child: Row(children: [
                    IconBadge(icon: Icons.computer_rounded, color: c['revoked'] == true ? g.textDim : (c['online'] == true ? g.success : g.warning), size: 40),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('${c['name']}', maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15)),
                        const SizedBox(height: 2),
                        Text(
                          c['lastSeenAt'] == null ? context.t('agent.never_seen') : context.t('agent.last_seen', {'time': context.dateTime(DateTime.parse('${c['lastSeenAt']}'))}),
                          style: TextStyle(color: g.textDim, fontSize: 12.5),
                        ),
                      ]),
                    ),
                    StatusPill(
                      label: context.t(c['revoked'] == true ? 'agent.revoked' : (c['online'] == true ? 'agent.online' : 'agent.offline')),
                      color: c['revoked'] == true ? g.textDim : (c['online'] == true ? g.success : g.warning),
                    ),
                  ]),
                ),
            ],
          ),
          const SizedBox(height: 20),
          // tasks, each opens to the steps the robot took
          TileSection(
            title: context.t('agent.tasks'),
            subtitle: context.t('agent.tasks_hint'),
            empty: tasks.isEmpty ? context.t('agent.no_tasks') : null,
            children: [for (final t in tasks) _taskTile(context, g, t)],
          ),
        ],
      ),
    );
  }

  Widget _taskTile(BuildContext context, Glass g, Map<String, dynamic> t) {
    final id = (t['id'] as num).toInt();
    final open = _open.contains(id);
    final color = _tone(g, t['tone'] as String?);
    final steps = [for (final x in (t['steps'] as List? ?? const [])) Map<String, dynamic>.from(x as Map)];
    final seen = t['seen'] is Map ? Map<String, dynamic>.from(t['seen'] as Map) : null;
    final created = t['createdAt'] == null ? null : DateTime.tryParse('${t['createdAt']}');
    return SoftTile(
      padding: const EdgeInsets.all(14),
      onTap: () => setState(() => open ? _open.remove(id) : _open.add(id)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(child: Text('${t['typeLabel']}', style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 15))),
          StatusPill(label: '${t['statusLabel']}', color: color),
          const SizedBox(width: 4),
          Icon(open ? Icons.expand_less_rounded : Icons.expand_more_rounded, color: g.textDim),
        ]),
        if (created != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(context.dateTime(created), style: TextStyle(color: g.textDim, fontSize: 12.5))),
        if (t['needsYou'] == true && t['waiting'] != null)
          Padding(padding: const EdgeInsets.only(top: 8), child: Text('${t['waiting']}', style: TextStyle(color: g.warning, fontWeight: FontWeight.w700, fontSize: 13.5))),
        if (seen != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(context.t('agent.seen', {'reports': seen['reports'] ?? 0, 'accepted': seen['accepted'] ?? 0, 'todo': seen['todo'] ?? 0}), style: TextStyle(color: g.text, fontSize: 13.5, height: 1.3)),
          ),
        if (t['error'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text('${t['error']}', style: TextStyle(color: g.danger, fontSize: 13, height: 1.3))),
        if (open) ...[
          const SizedBox(height: 10),
          if ('${t['actor']}'.isNotEmpty) Text(context.t('agent.by', {'name': '${t['actor']}'}), style: TextStyle(color: g.textDim, fontSize: 12.5)),
          if (t['computer'] != null) Text(context.t('agent.on_computer', {'name': '${t['computer']}'}), style: TextStyle(color: g.textDim, fontSize: 12.5)),
          const SizedBox(height: 8),
          Text(context.t('agent.steps'), style: TextStyle(color: g.textDim, fontSize: 12.5, fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          if (steps.isEmpty) Text(context.t('agent.no_steps'), style: TextStyle(color: g.textDim, fontSize: 13)) else for (final x in steps) _Step(time: '${x['time']}', text: '${x['text']}'),
        ],
      ]),
    );
  }
}

class _Step extends StatelessWidget {
  const _Step({required this.time, required this.text});
  final String time, text;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(time, style: TextStyle(color: g.textDim, fontSize: 12.5, fontFeatures: const [FontFeature.tabularFigures()])),
        const SizedBox(width: 10),
        Expanded(child: Text(text, style: TextStyle(color: g.text, fontSize: 13.5, height: 1.3))),
      ]),
    );
  }
}
