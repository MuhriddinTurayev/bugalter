import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../widgets/glass.dart';

/// People who can sign in to the company: the owner adds them and chooses their role.
class UsersScreen extends StatelessWidget {
  const UsersScreen({super.key});

  Color _roleColor(Glass g, Role r) => switch (r) { Role.owner => g.accent2, Role.chiefAccountant => g.accent, Role.cashier => g.info };

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    if (!s.role.canManagePeople) {
      return PageScaffold(title: context.t('users.title'), body: EmptyState(icon: Icons.lock_outline_rounded, title: context.t('tax.no_access_title'), text: context.t('users.no_access')));
    }
    return PageScaffold(
      title: context.t('users.title'),
      subtitle: context.t('common.items', {'n': s.users.length}),
      fab: AppButton(label: context.t('users.add'), icon: Icons.person_add_alt_1_rounded, expanded: false, onPressed: () => showGlassSheet<void>(context, builder: (_) => const _UserForm())),
      body: ListView.builder(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 100),
        itemCount: s.users.length,
        itemBuilder: (context, i) {
          final u = s.users[i];
          final me = u.id == s.currentUserId;
          return Reveal(
            index: i,
            child: Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: GlassCard(
                blur: false,
                onTap: () => showGlassSheet<void>(context, builder: (_) => _UserForm(user: u)),
                child: Row(children: [
                  Opacity(opacity: u.active ? 1 : .45, child: Avatar(name: u.name, size: 48)),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(u.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: u.active ? g.text : g.textDim, fontWeight: FontWeight.w800, fontSize: 15.5)),
                      const SizedBox(height: 2),
                      Text(u.login, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
                      const SizedBox(height: 6),
                      Wrap(spacing: 6, runSpacing: 4, children: [
                        StatusPill(label: context.roleName(u.role), color: _roleColor(g, u.role)),
                        if (!u.active) StatusPill(label: context.t('common.inactive'), color: g.textDim),
                        if (me) StatusPill(label: context.t('users.you'), color: g.success),
                      ]),
                    ]),
                  ),
                  Icon(Icons.edit_outlined, color: g.textDim, size: 20),
                ]),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _UserForm extends StatefulWidget {
  const _UserForm({this.user});
  final AppUser? user;

  @override
  State<_UserForm> createState() => _UserFormState();
}

class _UserFormState extends State<_UserForm> {
  final _form = GlobalKey<FormState>();
  late final TextEditingController _name, _login, _password, _position;
  late Role _role;
  late bool _active;

  bool get _isNew => widget.user == null;

  @override
  void initState() {
    super.initState();
    final u = widget.user;
    _name = TextEditingController(text: u?.name ?? '');
    _login = TextEditingController(text: u?.login ?? '');
    _password = TextEditingController();
    _position = TextEditingController(text: u?.position ?? '');
    _role = u?.role ?? Role.cashier;
    _active = u?.active ?? true;
  }

  @override
  void dispose() {
    for (final c in [_name, _login, _password, _position]) {
      c.dispose();
    }
    super.dispose();
  }

  void _save() {
    if (!(_form.currentState?.validate() ?? false)) return;
    final s = context.readStore;
    final pw = _password.text;
    if ((_isNew || pw.isNotEmpty) && pw.length < 6) {
      context.toast(context.t('err.weak_password'));
      return;
    }
    bool ok;
    if (_isNew) {
      final u = AppUser(id: s.nextId(), name: _name.text.trim(), login: _login.text.trim(), password: pw, role: _role, position: _position.text.trim(), active: _active);
      ok = context.attempt(() => s.saveUser(u));
    } else {
      final u = widget.user!;
      final old = (u.name, u.login, u.password, u.role, u.position, u.active);
      u
        ..name = _name.text.trim()
        ..login = _login.text.trim()
        ..role = _role
        ..position = _position.text.trim()
        ..active = _active;
      if (pw.isNotEmpty) u.password = pw;
      ok = context.attempt(() => s.saveUser(u));
      if (!ok) {
        u
          ..name = old.$1
          ..login = old.$2
          ..password = old.$3
          ..role = old.$4
          ..position = old.$5
          ..active = old.$6;
      }
    }
    if (ok) {
      context.toast(context.t('common.saved'));
      Navigator.of(context).pop();
    }
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final self = widget.user != null && widget.user!.id == s.currentUserId;
    return Form(
      key: _form,
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(context.t(_isNew ? 'users.new' : 'users.edit'), style: TextStyle(color: g.text, fontSize: 19, fontWeight: FontWeight.w800)),
        const SizedBox(height: 16),
        GlassField(controller: _name, label: context.t('users.name'), icon: Icons.person_outline_rounded, textCapitalization: TextCapitalization.words, textInputAction: TextInputAction.next, validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null),
        const SizedBox(height: 12),
        GlassField(controller: _login, label: context.t('users.login'), icon: Icons.alternate_email_rounded, keyboardType: TextInputType.emailAddress, textInputAction: TextInputAction.next, validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null),
        const SizedBox(height: 12),
        GlassField(controller: _password, label: context.t(_isNew ? 'users.password' : 'users.password_edit'), icon: Icons.lock_outline_rounded, textInputAction: TextInputAction.next),
        const SizedBox(height: 12),
        if (self)
          InfoLine(context.t('users.role'), context.roleName(_role), bold: true)
        else
          PickerField<Role>(label: context.t('users.role'), icon: Icons.admin_panel_settings_outlined, value: _role, options: [for (final r in Role.values) (r, context.roleName(r))], onChanged: (r) => setState(() => _role = r ?? _role)),
        const SizedBox(height: 12),
        GlassField(controller: _position, label: context.t('profile.position'), icon: Icons.work_outline_rounded, textCapitalization: TextCapitalization.sentences),
        if (!self)
          Padding(
            padding: const EdgeInsets.only(top: 6),
            child: Row(children: [
              Expanded(child: Text(context.t('common.active'), style: TextStyle(color: g.text, fontWeight: FontWeight.w700))),
              Switch(value: _active, onChanged: (v) => setState(() => _active = v)),
            ]),
          )
        else
          Padding(padding: const EdgeInsets.only(top: 8), child: Text(context.t('users.role_locked'), style: TextStyle(color: g.textDim, fontSize: 12.5))),
        const SizedBox(height: 16),
        Row(children: [
          Expanded(child: AppButton(label: context.t('common.cancel'), kind: ButtonKind.glass, onPressed: () => Navigator.of(context).pop())),
          const SizedBox(width: 12),
          Expanded(child: AppButton(label: context.t('common.save'), icon: Icons.check_rounded, onPressed: _save)),
        ]),
      ]),
    );
  }
}
