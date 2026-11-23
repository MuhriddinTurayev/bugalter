import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/models.dart';
import '../../../l10n/strings.dart';
import '../../widgets/glass.dart';
import 'company_screen.dart';
import 'profile_screen.dart';
import 'users_screen.dart';

const String kAppVersion = '1.0.0';

class SettingsScreen extends StatelessWidget {
  const SettingsScreen({super.key});

  Future<void> _reset(BuildContext context) async {
    final s = context.readStore;
    final ok = await confirmDialog(context, title: context.t('settings.reset'), message: context.t('settings.reset_confirm'), danger: true, confirmLabel: context.t('settings.reset'));
    if (!ok || !context.mounted) return;
    await s.resetDemo();
    if (context.mounted) context.toast(context.t('settings.reset_done'));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final g = context.g;
    final u = s.user;
    return PageScaffold(
      title: context.t('nav.settings'),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 4, 16, 32),
        children: [
          if (u != null)
            Reveal(
              child: GlassCard(
                strong: true,
                onTap: () => pushPage(context, const ProfileScreen()),
                child: Row(children: [
                  Avatar(name: u.name, size: 56),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(u.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 17)),
                      const SizedBox(height: 4),
                      Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
                        StatusPill(label: context.roleName(u.role), color: g.accent),
                        Text(u.login, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 12.5)),
                      ]),
                    ]),
                  ),
                  Icon(Icons.chevron_right_rounded, color: g.textDim),
                ]),
              ),
            ),
          const SizedBox(height: 18),
          Reveal(
            index: 1,
            child: _Group(title: context.t('settings.account'), children: [
              _Row(icon: Icons.lock_person_outlined, title: context.t('settings.profile'), onTap: () => pushPage(context, const ProfileScreen())),
              _Row(icon: Icons.business_rounded, title: context.t('settings.company'), subtitle: s.company.name, onTap: () => pushPage(context, const CompanyScreen())),
              if (s.role.canManagePeople) _Row(icon: Icons.groups_2_outlined, title: context.t('settings.users'), subtitle: context.t('settings.users_hint'), onTap: () => pushPage(context, const UsersScreen())),
            ]),
          ),
          const SizedBox(height: 18),
          Reveal(
            index: 2,
            child: _Group(title: context.t('settings.appearance'), children: [
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(context.t('lang.title'), style: TextStyle(color: g.textDim, fontSize: 13, fontWeight: FontWeight.w600)),
                  const SizedBox(height: 10),
                  SegmentedGlass(labels: [for (final l in kLocales) kLocaleNames[l]!], index: kLocales.indexOf(s.locale), onChanged: (i) => s.setLocale(kLocales[i])),
                ]),
              ),
              _Row(
                icon: s.dark ? Icons.dark_mode_rounded : Icons.light_mode_rounded,
                title: context.t(s.dark ? 'theme.dark' : 'theme.light'),
                trailing: Switch(value: s.dark, onChanged: s.setDark),
                onTap: () => s.setDark(!s.dark),
              ),
            ]),
          ),
          const SizedBox(height: 18),
          Reveal(
            index: 3,
            child: _Group(title: context.t('settings.data'), children: [
              if (!s.serverMode) _Row(icon: Icons.restart_alt_rounded, title: context.t('settings.reset'), subtitle: context.t('settings.reset_hint'), color: g.danger, onTap: () => _reset(context)),
            ]),
          ),
          const SizedBox(height: 18),
          Reveal(
            index: 4,
            child: _Group(title: context.t('settings.about'), children: [
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Row(children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(borderRadius: BorderRadius.circular(15), gradient: LinearGradient(colors: [g.accent, g.accent2])),
                    child: const Icon(Icons.account_balance_wallet_rounded, color: Colors.white),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(context.t('app.name'), style: TextStyle(color: g.text, fontWeight: FontWeight.w800, fontSize: 16)),
                      Text(context.t('settings.version', {'v': kAppVersion}), style: TextStyle(color: g.textDim, fontSize: 12.5)),
                    ]),
                  ),
                ]),
              ),
              Padding(padding: const EdgeInsets.only(bottom: 10), child: Text(context.t('settings.local_note'), style: TextStyle(color: g.textDim, fontSize: 13, height: 1.4))),
            ]),
          ),
        ],
      ),
    );
  }
}

class _Group extends StatelessWidget {
  const _Group({required this.title, required this.children});
  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    // every row is its own tile, with a gap between them
    return TileSection(title: title, children: [for (final c in children) SoftTile(padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4), child: c)]);
  }
}

class _Row extends StatelessWidget {
  const _Row({required this.icon, required this.title, this.subtitle, this.onTap, this.trailing, this.color});
  final IconData icon;
  final String title;
  final String? subtitle;
  final VoidCallback? onTap;
  final Widget? trailing;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return RowTile(
      leading: IconBadge(icon: icon, color: color ?? g.accent2, size: 40),
      title: title,
      subtitle: subtitle,
      trailing: trailing ?? (onTap == null ? null : Icon(Icons.chevron_right_rounded, color: g.textDim)),
      onTap: onTap,
    );
  }
}
