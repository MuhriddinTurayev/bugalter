import 'dart:ui';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../core/scope.dart';
import '../core/theme.dart';
import '../data/models.dart';
import 'screens/bank/bank_tab.dart';
import 'screens/common/notifications_sheet.dart';
import 'screens/common/search_screen.dart';
import 'screens/dashboard/dashboard_tab.dart';
import 'screens/documents/documents_tab.dart';
import 'screens/more/more_tab.dart';
import 'screens/tax/tax_tab.dart';
import 'widgets/glass.dart';

enum TabId { home, bank, docs, tax, more }

extension TabIdX on TabId {
  IconData get icon => switch (this) {
        TabId.home => Icons.space_dashboard_rounded,
        TabId.bank => Icons.account_balance_rounded,
        TabId.docs => Icons.description_rounded,
        TabId.tax => Icons.receipt_long_rounded,
        TabId.more => Icons.grid_view_rounded,
      };

  String get label => switch (this) {
        TabId.home => 'nav.tab_home',
        TabId.bank => 'nav.bank',
        TabId.docs => 'nav.documents',
        TabId.tax => 'nav.taxes',
        TabId.more => 'nav.more',
      };
}

/// The signed-in app: header, the current tab and the floating glass navigation (a side rail on wide screens).
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> with SingleTickerProviderStateMixin {
  TabId _tab = TabId.home;
  late final AnimationController _fade = AnimationController(vsync: this, duration: const Duration(milliseconds: 420), value: 1);
  final _pages = <TabId, Widget>{};

  @override
  void dispose() {
    _fade.dispose();
    super.dispose();
  }

  void _go(TabId t) {
    if (t == _tab) return;
    setState(() => _tab = t);
    _fade.forward(from: 0);
  }

  Widget _page(TabId t) => _pages.putIfAbsent(t, () {
        switch (t) {
          case TabId.home:
            return DashboardTab(onTab: _go);
          case TabId.bank:
            return const BankTab();
          case TabId.docs:
            return const DocumentsTab();
          case TabId.tax:
            return const TaxTab();
          case TabId.more:
            return const MoreTab();
        }
      });

  @override
  Widget build(BuildContext context) {
    final s = context.store;
    final tabs = [for (final t in TabId.values) if (t != TabId.tax || s.role.canSeeTax) t];
    if (!tabs.contains(_tab)) _tab = TabId.home;
    // a change the server refused: say why once (the books were already read again from the server)
    final refused = s.lastWriteError;
    if (refused != null) {
      s.lastWriteError = null;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) context.toast(context.t('srv.write_refused', {'reason': refused}));
      });
    }
    final wide = context.isWide;
    final body = FadeTransition(
      opacity: CurvedAnimation(parent: _fade, curve: Curves.easeOut),
      child: AnimatedBuilder(
        animation: _fade,
        builder: (context, child) => Transform.translate(offset: Offset(0, (1 - _fade.value) * 14), child: child),
        child: IndexedStack(index: tabs.indexOf(_tab), children: [for (final t in tabs) _page(t)]),
      ),
    );

    return PopScope(
      canPop: _tab == TabId.home,
      onPopInvokedWithResult: (didPop, _) {
        if (!didPop) _go(TabId.home);
      },
      child: GlassBackground(
        child: Scaffold(
          backgroundColor: Colors.transparent,
          body: wide
              ? Row(children: [
                  _SideRail(tabs: tabs, current: _tab, onSelect: _go),
                  Expanded(child: _Content(tab: _tab, body: body, onTab: _go)),
                ])
              // The content fills the whole screen and scrolls under both the transparent header and the floating navigation bar.
              : Stack(children: [
                  Positioned.fill(child: _Content(tab: _tab, body: body, onTab: _go, bottomInset: 68 + 16 + MediaQuery.paddingOf(context).bottom)),
                  Positioned(left: 0, right: 0, bottom: 0, child: _BottomBar(tabs: tabs, current: _tab, onSelect: _go)),
                ]),
        ),
      ),
    );
  }
}

class _Content extends StatelessWidget {
  const _Content({required this.tab, required this.body, required this.onTab, this.bottomInset = 0});
  final double bottomInset;
  final TabId tab;
  final Widget body;
  final ValueChanged<TabId> onTab;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    // The content fills the whole area and scrolls UNDER the transparent header (it shows through, also behind the status bar);
    // the header only floats above it on a soft scrim that fades to nothing, so there is no hard line.
    final inset = MediaQuery.paddingOf(context).top + 68;
    final scrim = g.bg.first;
    final bottomScrim = g.bg.last;
    return Stack(children: [
      Positioned.fill(
        child: HeaderInset(
          value: inset,
          bottom: bottomInset,
          child: ClipRect(child: ResponsiveBody(maxWidth: 1080, padding: EdgeInsets.symmetric(horizontal: context.isWide ? 24 : 16), child: body)),
        ),
      ),
      Positioned(
        left: 0,
        right: 0,
        top: 0,
        child: IgnorePointer(
          child: Container(
            height: inset + 14,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [scrim.withValues(alpha: .9), scrim.withValues(alpha: .82), scrim.withValues(alpha: .4), scrim.withValues(alpha: 0)],
                stops: const [0, .45, .78, 1],
              ),
            ),
          ),
        ),
      ),
      if (bottomInset > 0)
        Positioned(
          left: 0,
          right: 0,
          bottom: 0,
          child: IgnorePointer(
            child: Container(
              height: bottomInset + 10,
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [bottomScrim.withValues(alpha: 0), bottomScrim.withValues(alpha: .55), bottomScrim.withValues(alpha: .85)],
                  stops: const [0, .5, 1],
                ),
              ),
            ),
          ),
        ),
      Positioned(left: 0, right: 0, top: 0, child: _Header(tab: tab)),
    ]);
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.tab});
  final TabId tab;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final h = DateTime.now().hour;
    final greeting = context.t(h < 12 ? 'dash.greeting_morning' : (h < 18 ? 'dash.greeting_day' : 'dash.greeting_evening'));
    final title = tab == TabId.home ? s.user?.name.split(' ').last ?? '' : context.t(tab.label);
    final sub = tab == TabId.home ? greeting : s.company.name;
    return SafeArea(
      bottom: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(context.isWide ? 28 : 16, 10, context.isWide ? 28 : 16, 8),
        child: Row(children: [
          Expanded(
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 300),
              layoutBuilder: (current, previous) => Stack(alignment: Alignment.centerLeft, children: [...previous, ?current]),
              transitionBuilder: (c, a) => FadeTransition(opacity: a, child: SlideTransition(position: Tween(begin: const Offset(0, .25), end: Offset.zero).animate(a), child: c)),
              child: Column(
                key: ValueKey('$tab${s.locale}${s.user?.id}'),
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(sub, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.textDim, fontSize: 13.5, fontWeight: FontWeight.w600)),
                  Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: g.text, fontSize: 25, fontWeight: FontWeight.w900, letterSpacing: -.6)),
                ],
              ),
            ),
          ),
          const SizedBox(width: 8),
          GlassIconButton(icon: Icons.search_rounded, tooltip: context.t('nav.search'), onTap: () => pushPage(context, const SearchScreen())),
          const SizedBox(width: 8),
          GlassIconButton(icon: Icons.notifications_none_rounded, tooltip: context.t('nav.notifications'), badge: s.unreadCount(), onTap: () => showNotifications(context)),
          const SizedBox(width: 8),
        ]),
      ),
    );
  }
}

class _BottomBar extends StatelessWidget {
  const _BottomBar({required this.tabs, required this.current, required this.onSelect});
  final List<TabId> tabs;
  final TabId current;
  final ValueChanged<TabId> onSelect;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final i = tabs.indexOf(current);
    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 520),
            child: DecoratedBox(
              decoration: BoxDecoration(borderRadius: BorderRadius.circular(32), boxShadow: [BoxShadow(color: g.shadow, blurRadius: 16, offset: const Offset(0, 6), spreadRadius: -4)]),
              child: ClipRRect(
                borderRadius: BorderRadius.circular(32),
                child: DecoratedBox(
                  decoration: const BoxDecoration(),
                  child: Container(
                    height: 68,
                    decoration: BoxDecoration(color: g.dark ? const Color(0xE0101C2E) : const Color(0xE0FFFFFF), borderRadius: BorderRadius.circular(32), border: Border.all(color: g.border)),
                    child: LayoutBuilder(builder: (context, c) {
                      final w = c.maxWidth / tabs.length;
                      return Stack(children: [
                        AnimatedPositioned(
                          duration: const Duration(milliseconds: 380),
                          curve: Curves.easeOutBack,
                          left: w * i + 6,
                          top: 6,
                          bottom: 6,
                          width: w - 12,
                          child: DecoratedBox(
                            decoration: BoxDecoration(
                              borderRadius: BorderRadius.circular(26),
                              gradient: LinearGradient(colors: [g.accent.withValues(alpha: .30), g.accent2.withValues(alpha: .22)]),
                              border: Border.all(color: g.accent.withValues(alpha: .4)),
                            ),
                          ),
                        ),
                        Row(children: [
                          for (final t in tabs)
                            Expanded(
                              child: GestureDetector(
                                behavior: HitTestBehavior.opaque,
                                onTap: () {
                                  HapticFeedback.selectionClick();
                                  onSelect(t);
                                },
                                child: _NavItem(icon: t.icon, label: context.t(t.label), selected: t == current),
                              ),
                            ),
                        ]),
                      ]);
                    }),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _NavItem extends StatelessWidget {
  const _NavItem({required this.icon, required this.label, required this.selected});
  final IconData icon;
  final String label;
  final bool selected;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Column(mainAxisAlignment: MainAxisAlignment.center, children: [
      AnimatedScale(
        scale: selected ? 1.18 : 1,
        duration: const Duration(milliseconds: 320),
        curve: Curves.easeOutBack,
        child: Icon(icon, size: 24, color: selected ? g.accent : g.textDim),
      ),
      const SizedBox(height: 3),
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 2),
        child: FittedBox(
          fit: BoxFit.scaleDown,
          child: AnimatedDefaultTextStyle(
            duration: const Duration(milliseconds: 250),
            style: TextStyle(color: selected ? g.text : g.textDim, fontSize: 10.5, fontWeight: selected ? FontWeight.w800 : FontWeight.w600),
            child: Text(label, maxLines: 1),
          ),
        ),
      ),
    ]);
  }
}

class _SideRail extends StatelessWidget {
  const _SideRail({required this.tabs, required this.current, required this.onSelect});
  final List<TabId> tabs;
  final TabId current;
  final ValueChanged<TabId> onSelect;

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final expanded = context.width >= 1100;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 0, 16),
        child: GlassCard(
          padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 10),
          radius: 30,
          child: SizedBox(
            width: expanded ? 190 : 62,
            child: Column(children: [
              Container(
                width: 48,
                height: 48,
                decoration: BoxDecoration(borderRadius: BorderRadius.circular(16), gradient: LinearGradient(colors: [g.accent, g.accent2])),
                child: const Icon(Icons.account_balance_wallet_rounded, color: Colors.white),
              ),
              const SizedBox(height: 22),
              for (final t in tabs)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: PressScale(
                    onTap: () => onSelect(t),
                    child: AnimatedContainer(
                      duration: const Duration(milliseconds: 250),
                      height: 50,
                      padding: EdgeInsets.symmetric(horizontal: expanded ? 14 : 0),
                      decoration: BoxDecoration(
                        borderRadius: BorderRadius.circular(18),
                        color: t == current ? g.accent.withValues(alpha: .2) : Colors.transparent,
                        border: Border.all(color: t == current ? g.accent.withValues(alpha: .45) : Colors.transparent),
                      ),
                      child: Row(mainAxisAlignment: expanded ? MainAxisAlignment.start : MainAxisAlignment.center, children: [
                        Icon(t.icon, color: t == current ? g.accent : g.textDim),
                        if (expanded) ...[
                          const SizedBox(width: 12),
                          Expanded(child: Text(context.t(t.label), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: t == current ? g.text : g.textDim, fontWeight: FontWeight.w700))),
                        ],
                      ]),
                    ),
                  ),
                ),
            ]),
          ),
        ),
      ),
    );
  }
}
