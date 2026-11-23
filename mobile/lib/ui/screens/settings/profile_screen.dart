import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../widgets/glass.dart';

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({super.key});

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _form = GlobalKey<FormState>();
  final _old = TextEditingController();
  final _new = TextEditingController();
  final _again = TextEditingController();
  bool _hide = true;

  @override
  void dispose() {
    _old.dispose();
    _new.dispose();
    _again.dispose();
    super.dispose();
  }

  void _change() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final s = context.readStore;
    if (context.attempt(() => s.changePassword(_old.text, _new.text))) {
      _old.clear();
      _new.clear();
      _again.clear();
      FocusScope.of(context).unfocus();
      context.toast(context.t('profile.changed'));
    }
  }

  Future<void> _logout() async {
    final s = context.readStore;
    final nav = Navigator.of(context);
    final ok = await confirmDialog(context, title: context.t('nav.logout'), message: context.t('profile.logout_confirm'), confirmLabel: context.t('nav.logout'), danger: true);
    if (!ok) return;
    nav.popUntil((r) => r.isFirst);
    await s.logout();
  }

  String? _required(String? v) => (v == null || v.isEmpty) ? context.t('common.required') : null;

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final u = s.user;
    final eye = IconButton(icon: Icon(_hide ? Icons.visibility_outlined : Icons.visibility_off_outlined, color: g.textDim), onPressed: () => setState(() => _hide = !_hide));
    return PageScaffold(
      title: context.t('nav.profile'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
        keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
        children: [
          if (u != null)
            Reveal(
              child: GlassCard(
                strong: true,
                child: Column(children: [
                  Avatar(name: u.name, size: 84),
                  const SizedBox(height: 12),
                  Text(u.name, textAlign: TextAlign.center, style: TextStyle(color: g.text, fontSize: 20, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 6),
                  StatusPill(label: context.roleName(u.role), color: g.accent),
                  const SizedBox(height: 12),
                  const Hairline(),
                  InfoLine(context.t('profile.login'), u.login),
                  if (u.position.isNotEmpty) InfoLine(context.t('profile.position'), u.position),
                  InfoLine(context.t('settings.company'), s.company.name),
                ]),
              ),
            ),
          const SizedBox(height: 18),
          Reveal(
            index: 1,
            child: GlassCard(
              child: Form(
                key: _form,
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(context.t('profile.password_title'), style: TextStyle(color: g.text, fontSize: 17, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 14),
                  GlassField(controller: _old, label: context.t('profile.current'), icon: Icons.lock_outline_rounded, obscure: _hide, suffix: eye, validator: _required, textInputAction: TextInputAction.next),
                  const SizedBox(height: 12),
                  GlassField(
                    controller: _new,
                    label: context.t('profile.new'),
                    icon: Icons.lock_reset_rounded,
                    obscure: _hide,
                    textInputAction: TextInputAction.next,
                    validator: (v) => (v == null || v.length < 6) ? context.t('err.weak_password') : null,
                  ),
                  const SizedBox(height: 12),
                  GlassField(
                    controller: _again,
                    label: context.t('profile.confirm'),
                    icon: Icons.lock_reset_rounded,
                    obscure: _hide,
                    textInputAction: TextInputAction.done,
                    onSubmitted: (_) => _change(),
                    validator: (v) => v != _new.text ? context.t('profile.mismatch') : null,
                  ),
                  const SizedBox(height: 16),
                  AppButton(label: context.t('profile.change'), icon: Icons.check_rounded, onPressed: _change),
                ]),
              ),
            ),
          ),
          const SizedBox(height: 18),
          Reveal(index: 2, child: AppButton(label: context.t('nav.logout'), icon: Icons.logout_rounded, kind: ButtonKind.danger, onPressed: _logout)),
        ],
      ),
    );
  }
}
