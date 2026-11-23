import 'package:flutter/material.dart';

import '../../../core/scope.dart';
import '../../../core/theme.dart';
import '../../../data/api.dart';
import '../../../l10n/strings.dart';
import '../../widgets/glass.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _form = GlobalKey<FormState>();
  final _address = TextEditingController();
  final _login = TextEditingController();
  final _password = TextEditingController();
  final _domain = TextEditingController();
  bool _busy = false, _hide = true, _advanced = false;
  int _mode = 1; // 0 demo company on this phone, 1 the real company on the server
  String? _error;

  @override
  void initState() {
    super.initState();
    _domain.text = context.readStore.serverDomain;
  }

  @override
  void dispose() {
    _address.dispose();
    _login.dispose();
    _password.dispose();
    _domain.dispose();
    super.dispose();
  }

  String _message(ApiException e) => switch (e.code) {
        'invalid_credentials' => context.t('srv.err_credentials'),
        'throttled' => context.t('srv.err_throttled', {'seconds': e.seconds ?? 60}),
        'deactivated' => context.t('srv.err_deactivated'),
        'network' => context.t('srv.err_network'),
        'timeout' => context.t('srv.err_timeout'),
        'not_found' => context.t('srv.err_company'),
        'unauthenticated' => context.t('srv.err_session'),
        _ => context.t('srv.err_server'),
      };

  Future<void> _submit([String? login, String? password]) async {
    final demo = _mode == 0;
    if (login == null && !(_form.currentState?.validate() ?? false)) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final store = context.readStore;
    final user = login ?? _login.text, pass = password ?? _password.text;
    String? failure;
    try {
      if (demo) {
        await Future<void>.delayed(const Duration(milliseconds: 450));
        if (!await store.login(user, pass)) failure = context.t('login.error');
      } else {
        await store.setServerDomain(_domain.text);
        await store.connectServer(address: _address.text, login: user, password: pass);
      }
    } on ApiException catch (e) {
      failure = _message(e);
    } catch (_) {
      failure = context.t('err.generic');
    }
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = failure;
    });
  }

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    final s = context.store;
    final demo = _mode == 0;
    final demos = [
      ('owner@bugalter.test', Icons.workspace_premium_rounded, 'role.owner'),
      ('accountant@bugalter.test', Icons.calculate_rounded, 'role.chief_accountant'),
      ('cashier@bugalter.test', Icons.point_of_sale_rounded, 'role.cashier'),
    ];
    return GlassBackground(
      child: Scaffold(
        backgroundColor: Colors.transparent,
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(20),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 440),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  Reveal(
                    child: SegmentedGlass(
                      labels: [for (final l in kLocales) kLocaleNames[l]!],
                      index: kLocales.indexOf(s.locale),
                      onChanged: (i) => s.setLocale(kLocales[i]),
                    ),
                  ),
                  const SizedBox(height: 24),
                  Reveal(index: 1, child: const _Logo()),
                  const SizedBox(height: 16),
                  Reveal(index: 2, child: Text(context.t('app.name'), style: TextStyle(color: g.text, fontSize: 34, fontWeight: FontWeight.w900, letterSpacing: -1))),
                  const SizedBox(height: 4),
                  Reveal(index: 2, child: Text(context.t('app.tagline'), textAlign: TextAlign.center, style: TextStyle(color: g.textDim, fontSize: 15))),
                  const SizedBox(height: 22),
                  Reveal(
                    index: 3,
                    child: SegmentedGlass(
                      labels: [context.t('srv.tab_demo'), context.t('srv.tab_server')],
                      index: _mode,
                      onChanged: (i) => setState(() {
                        _mode = i;
                        _error = null;
                      }),
                    ),
                  ),
                  const SizedBox(height: 14),
                  Reveal(
                    index: 4,
                    child: GlassCard(
                      strong: true,
                      padding: const EdgeInsets.all(20),
                      child: Form(
                        key: _form,
                        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                          Text(context.t(demo ? 'login.title' : 'srv.title'), style: TextStyle(color: g.text, fontSize: 20, fontWeight: FontWeight.w800)),
                          const SizedBox(height: 4),
                          Text(context.t(demo ? 'login.subtitle' : 'srv.subtitle'), style: TextStyle(color: g.textDim, fontSize: 13.5)),
                          const SizedBox(height: 18),
                          if (!demo) ...[
                            GlassField(
                              controller: _address,
                              label: context.t('srv.address'),
                              hint: context.t('srv.address_hint'),
                              icon: Icons.apartment_rounded,
                              keyboardType: TextInputType.url,
                              textInputAction: TextInputAction.next,
                              validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null,
                            ),
                            const SizedBox(height: 12),
                          ],
                          GlassField(
                            controller: _login,
                            label: context.t('login.login'),
                            icon: Icons.alternate_email_rounded,
                            keyboardType: TextInputType.emailAddress,
                            textInputAction: TextInputAction.next,
                            validator: (v) => (v == null || v.trim().isEmpty) ? context.t('common.required') : null,
                          ),
                          const SizedBox(height: 12),
                          GlassField(
                            controller: _password,
                            label: context.t('login.password'),
                            icon: Icons.lock_outline_rounded,
                            obscure: _hide,
                            textInputAction: TextInputAction.done,
                            onSubmitted: (_) => _submit(),
                            suffix: IconButton(icon: Icon(_hide ? Icons.visibility_outlined : Icons.visibility_off_outlined, color: g.textDim), onPressed: () => setState(() => _hide = !_hide)),
                            validator: (v) => (v == null || v.isEmpty) ? context.t('common.required') : null,
                          ),
                          if (!demo) ...[
                            const SizedBox(height: 6),
                            Align(
                              alignment: Alignment.centerLeft,
                              child: GestureDetector(
                                onTap: () => setState(() => _advanced = !_advanced),
                                child: Padding(
                                  padding: const EdgeInsets.symmetric(vertical: 6),
                                  child: Text(context.t('srv.advanced'), style: TextStyle(color: g.accent, fontWeight: FontWeight.w700, fontSize: 13)),
                                ),
                              ),
                            ),
                            AnimatedSize(
                              duration: const Duration(milliseconds: 220),
                              child: _advanced
                                  ? Padding(padding: const EdgeInsets.only(bottom: 6), child: GlassField(controller: _domain, label: context.t('srv.domain'), icon: Icons.dns_rounded, keyboardType: TextInputType.url))
                                  : const SizedBox(width: double.infinity),
                            ),
                          ],
                          AnimatedSize(
                            duration: const Duration(milliseconds: 250),
                            child: _error == null
                                ? const SizedBox(width: double.infinity)
                                : Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: TextStyle(color: g.danger, fontWeight: FontWeight.w600))),
                          ),
                          const SizedBox(height: 18),
                          AppButton(label: context.t(demo ? 'login.submit' : 'srv.connect'), icon: demo ? Icons.arrow_forward_rounded : Icons.cloud_done_rounded, loading: _busy, onPressed: () => _submit()),
                        ]),
                      ),
                    ),
                  ),
                  if (demo) ...[
                    const SizedBox(height: 22),
                    Reveal(
                      index: 5,
                      child: Column(children: [
                        Text(context.t('login.demo_title'), style: TextStyle(color: g.textDim, fontSize: 12.5, fontWeight: FontWeight.w600)),
                        const SizedBox(height: 10),
                        Wrap(spacing: 10, runSpacing: 10, alignment: WrapAlignment.center, children: [
                          for (final d in demos)
                            PressScale(
                              onTap: _busy ? null : () => _submit(d.$1, 'Demo#2026pass'),
                              child: Container(
                                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                                decoration: BoxDecoration(color: g.fill, borderRadius: BorderRadius.circular(18), border: Border.all(color: g.border)),
                                child: Row(mainAxisSize: MainAxisSize.min, children: [
                                  Icon(d.$2, size: 18, color: g.accent),
                                  const SizedBox(width: 8),
                                  Text(context.t(d.$3), style: TextStyle(color: g.text, fontWeight: FontWeight.w700, fontSize: 13.5)),
                                ]),
                              ),
                            ),
                        ]),
                        const SizedBox(height: 10),
                        Text(context.t('login.demo_hint'), style: TextStyle(color: g.textDim, fontSize: 12)),
                      ]),
                    ),
                  ],
                ]),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// The logo. Static: nothing on the login page moves by itself (an endless animation keeps the screen redrawing and heats the phone).
class _Logo extends StatelessWidget {
  const _Logo();

  @override
  Widget build(BuildContext context) {
    final g = context.g;
    return Container(
      width: 92,
      height: 92,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(30),
        gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [g.accent, g.info, g.accent2]),
        boxShadow: [BoxShadow(color: g.accent.withValues(alpha: .35), blurRadius: 28, spreadRadius: -4, offset: const Offset(0, 12))],
      ),
      child: const Icon(Icons.account_balance_wallet_rounded, size: 46, color: Colors.white),
    );
  }
}
