import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

import 'core/scope.dart';
import 'core/theme.dart';
import 'data/store.dart';
import 'ui/screens/auth/login_screen.dart';
import 'ui/shell.dart';
import 'ui/widgets/ai_fab.dart';

class BugalterApp extends StatefulWidget {
  const BugalterApp({super.key, required this.store});
  final AppStore store;

  @override
  State<BugalterApp> createState() => _BugalterAppState();
}

class _BugalterAppState extends State<BugalterApp> with WidgetsBindingObserver {
  final _navKey = GlobalKey<NavigatorState>();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    // the app was closed with a real company open: read it again at once (the copy on the phone is shown meanwhile)
    WidgetsBinding.instance.addPostFrameCallback((_) => widget.store.syncIfStale(const Duration(minutes: 1)));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Save everything the moment the app leaves the screen.
    if (state != AppLifecycleState.resumed) {
      widget.store.flush();
    } else {
      // back in the app: read the real company again when the copy on the phone has got old
      widget.store.syncIfStale();
      widget.store.resumeAi();
      widget.store.watchAgent(window: Duration.zero);
    }
  }

  /// Material's own widgets (date picker, text-selection menu) have no Tajik table, so Tajik uses the Russian one.
  Locale _materialLocale(String code) => code == 'uz' ? const Locale('uz') : const Locale('ru');

  @override
  Widget build(BuildContext context) {
    return AppScope(
      store: widget.store,
      child: Builder(builder: (context) {
        final s = context.store;
        final dark = s.dark;
        return AnnotatedRegion<SystemUiOverlayStyle>(
          value: SystemUiOverlayStyle(
            statusBarColor: Colors.transparent,
            systemNavigationBarColor: Colors.transparent,
            systemNavigationBarContrastEnforced: false,
            statusBarIconBrightness: dark ? Brightness.light : Brightness.dark,
            statusBarBrightness: dark ? Brightness.dark : Brightness.light,
            systemNavigationBarIconBrightness: dark ? Brightness.light : Brightness.dark,
          ),
          child: MaterialApp(
            title: 'Bugalter',
            debugShowCheckedModeBanner: false,
            theme: buildTheme(dark),
            themeAnimationDuration: const Duration(milliseconds: 450),
            locale: _materialLocale(s.locale),
            supportedLocales: const [Locale('uz'), Locale('ru')],
            localizationsDelegates: GlobalMaterialLocalizations.delegates,
            navigatorKey: _navKey,
            builder: (context, child) => MediaQuery(
              // Respect the user's font size, but keep layouts from breaking at extreme settings.
              data: MediaQuery.of(context).copyWith(textScaler: MediaQuery.textScalerOf(context).clamp(minScaleFactor: .85, maxScaleFactor: 1.3)),
              // The AI button floats above every screen (not on the login page).
              child: Stack(children: [
                Positioned.fill(child: child!),
                if (s.signedIn) Positioned.fill(child: AiFab(navigatorKey: _navKey)),
              ]),
            ),
            home: AnimatedSwitcher(
              duration: const Duration(milliseconds: 450),
              switchInCurve: Curves.easeOutCubic,
              child: s.signedIn ? const HomeShell(key: ValueKey('shell')) : const LoginScreen(key: ValueKey('login')),
            ),
          ),
        );
      }),
    );
  }
}
