import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../data/api.dart';
import '../../widgets/glass.dart';
import 'agent_screen.dart';

/// Asks the company's computer agent to READ the tax cabinet or Didox again, then opens the agent panel so the person sees the robot work.
/// Only a read is queued; the server refuses with its own reason when there is no computer or no access.
Future<void> askAgent(BuildContext context, String path) async {
  final store = context.readStore;
  String message;
  var ok = false;
  try {
    final r = await store.serverRequest('POST', path);
    message = r['message'] as String? ?? context.t('agent.refreshed');
    ok = true;
  } on ApiException catch (e) {
    message = e.message ?? context.t(e.status == 403 ? 'agent.no_right' : (e.network ? 'srv.err_network' : 'agent.check_failed'));
  }
  if (!context.mounted) return;
  context.toast(message);
  if (ok) pushPage(context, const AgentScreen());
}

/// Does the signed-in person have the right to start the robot (and is this the real company)?
bool canAskAgent(BuildContext context) {
  final s = context.store;
  return s.serverMode && s.permissions.contains('agent.run');
}

/// The "check with the agent" button of the cabinet and Didox pages.
class AgentAskButton extends StatefulWidget {
  const AgentAskButton({super.key, required this.path, required this.label, required this.icon, this.kind = ButtonKind.primary});
  final String path, label;
  final IconData icon;
  final ButtonKind kind;

  @override
  State<AgentAskButton> createState() => _AgentAskButtonState();
}

class _AgentAskButtonState extends State<AgentAskButton> {
  bool _busy = false;

  @override
  Widget build(BuildContext context) {
    return AppButton(
      label: context.t(_busy ? 'agent.checking' : widget.label),
      icon: widget.icon,
      kind: widget.kind,
      loading: _busy,
      onPressed: _busy
          ? null
          : () async {
              setState(() => _busy = true);
              await askAgent(context, widget.path);
              if (mounted) setState(() => _busy = false);
            },
    );
  }
}
