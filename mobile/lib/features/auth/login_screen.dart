import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/config.dart';
import '../../core/l10n/strings.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});

  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  final _form = GlobalKey<FormState>();
  bool _loading = false;
  bool _obscure = true;
  bool _showPassword = false;
  String? _error;
  String? _openingShowcase;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ref.read(authProvider.notifier).login(_email.text.trim(), _password.text);
    } on MfaRequired catch (m) {
      if (mounted) await _askSecondFactor(m);
    } catch (e) {
      if (mounted) setState(() => _error = ApiException.from(e).message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  /// One tap on a showcase account (Settings → Showcase accounts on the dashboard): the server opens its session, no password.
  Future<void> _openShowcase(String key, {String? code}) async {
    if (_loading) return;
    setState(() {
      _loading = true;
      _openingShowcase = key;
      _error = null;
    });
    try {
      await ref.read(authProvider.notifier).loginShowcase(key, code: code);
    } catch (e) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiException.from(e).message)));
    } finally {
      if (mounted) {
        setState(() {
          _loading = false;
          _openingShowcase = null;
        });
      }
    }
  }

  /// Whether the server offers the locked section of leadership accounts (opened with the showcase code).
  bool _showcaseLeadership(WidgetRef ref) {
    final res = ref.watch(getProvider('/public/showcase-accounts')).value;
    return res is Map && res['leadership'] == true;
  }

  /// The code is right: the leadership accounts it opens.
  Future<List<Map<String, dynamic>>> _unlockLeadership(String code) async {
    final res = await ref.read(apiProvider).post('/public/showcase-accounts/leadership', {'code': code});
    return [for (final a in (res as Map)['data'] as List) Map<String, dynamic>.from(a as Map)];
  }

  /// The showcase accounts the server offers on the sign-in screen (supervisors, trainers and trainees; empty when switched off).
  List<Map<String, dynamic>> _showcase(WidgetRef ref) {
    final res = ref.watch(getProvider('/public/showcase-accounts')).value;
    if (res is! Map || res['data'] is! List) return const [];
    return [for (final a in res['data'] as List) if (a is Map) Map<String, dynamic>.from(a)];
  }

  /// Single sign-on with the Ministry's identity provider, shown when the server offers it.
  Future<void> _sso() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      await ref.read(authProvider.notifier).loginWithSso();
    } catch (e) {
      if (mounted) setState(() => _error = context.s.t('auth.ssoFailed'));
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  bool _ssoOffered(WidgetRef ref) {
    final config = ref.watch(getProvider('/public/mobile-config')).value;
    return config is Map && config['data'] is Map && (config['data'] as Map)['sso'] == true;
  }

  /// Staff must sign in with the Ministry account: the password form is for administrators and outside accounts, so it waits behind a link.
  bool _ssoMandatory(WidgetRef ref) {
    final config = ref.watch(getProvider('/public/mobile-config')).value;
    return _ssoOffered(ref) && (config as Map)['data']['sso_mandatory'] == true;
  }

  Future<void> _askSecondFactor(MfaRequired m) async {
    final s = context.s;
    final code = TextEditingController();
    var method = m.enrolled ? 'totp' : (m.methods.contains('email') ? 'email' : 'recovery');
    String? err;
    final done = await showDialog<bool>(
      context: context,
      barrierDismissible: false,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, set) => AlertDialog(
          title: Text(s.t('mfa.title')),
          content: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(s.t('mfa.hint')),
            const SizedBox(height: 10),
            Wrap(spacing: 6, children: [
              for (final k in [if (m.enrolled) 'totp', ...m.methods.where((x) => x != 'totp'), 'recovery'])
                ChoiceChip(label: Text(s.t('mfa.$k')), selected: method == k, onSelected: (_) => set(() {
                      method = k;
                      err = null;
                    })),
            ]),
            if (method == 'email' || method == 'sms')
              TextButton(
                onPressed: () async {
                  try {
                    await ref.read(apiProvider).post('/auth/mfa/send', {'mfa_token': m.token, 'method': method});
                  } catch (e) {
                    set(() => err = ApiException.from(e).message);
                  }
                },
                child: Text(s.t('mfa.send')),
              ),
            TextField(controller: code, keyboardType: TextInputType.text, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: s.t('mfa.code'))),
            if (err != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(err!, style: const TextStyle(color: AppColors.danger))),
          ]),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(s.t('common.cancel'))),
            FilledButton(
              onPressed: () async {
                try {
                  final data = await ref.read(apiProvider).post('/auth/mfa/verify', {'mfa_token': m.token, 'method': method, 'code': code.text.trim()}) as Map<String, dynamic>;
                  await ref.read(authProvider.notifier).completeLogin(data);
                  if (ctx.mounted) Navigator.pop(ctx, true);
                } catch (e) {
                  set(() => err = ApiException.from(e).message);
                }
              },
              child: Text(s.t('mfa.verify')),
            ),
          ],
        ),
      ),
    );
    code.dispose();
    if (done != true && mounted) setState(() => _error = null);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Scaffold(
      backgroundColor: AppColors.navy950,
      body: Stack(children: [
        const Positioned.fill(child: DotPattern(opacity: .14)),
        SafeArea(
          child: ListView(padding: const EdgeInsets.all(24), children: [
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: TextButton.icon(
                onPressed: () => ref.read(localeProvider.notifier).toggle(),
                icon: Icon(Icons.language, color: AppColors.gold300),
                label: Text(s.isArabic ? 'English' : 'العربية', style: const TextStyle(color: Colors.white)),
              ),
            ),
            const SizedBox(height: 32),
            // Long-press the emblem to point the app at another server (useful for test builds).
            Center(child: GestureDetector(onLongPress: _serverSettings, child: const OfficialEmblem(size: 104))),
            const SizedBox(height: 24),
            Text(s.t('app.name'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(s.t('app.tagline'), textAlign: TextAlign.center, style: TextStyle(color: AppColors.gold300)),
            const SizedBox(height: 40),
            Container(
              padding: const EdgeInsets.all(22),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(28)),
              child: Form(
                key: _form,
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(s.t('auth.title'), style: TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.navy900)),
                  Text(s.t('auth.subtitle'), style: const TextStyle(color: AppColors.muted)),
                  const SizedBox(height: 20),
                  if (_ssoOffered(ref)) ...[
                    FilledButton.icon(
                      key: const ValueKey('sso'),
                      onPressed: _loading ? null : _sso,
                      style: FilledButton.styleFrom(backgroundColor: AppColors.navy900, minimumSize: const Size.fromHeight(52)),
                      icon: const Icon(Icons.account_balance_outlined),
                      label: Text(s.t('auth.sso')),
                    ),
                    if (_ssoMandatory(ref) && !_showPassword)
                      Padding(
                        padding: const EdgeInsets.only(top: 10),
                        child: TextButton(key: const ValueKey('show-password'), onPressed: () => setState(() => _showPassword = true), child: Text(s.t('auth.usePassword'), textAlign: TextAlign.center)),
                      )
                    else
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        child: Row(children: [
                          Expanded(child: Divider(color: AppColors.navy100)),
                          Padding(padding: const EdgeInsets.symmetric(horizontal: 10), child: Text(s.t('auth.orPassword'), style: const TextStyle(color: AppColors.muted, fontSize: 12.5))),
                          Expanded(child: Divider(color: AppColors.navy100)),
                        ]),
                      ),
                  ],
                  if (!_ssoMandatory(ref) || _showPassword) ...[
                  TextFormField(
                    controller: _email,
                    keyboardType: TextInputType.emailAddress,
                    textDirection: TextDirection.ltr,
                    autofillHints: const [AutofillHints.username],
                    decoration: InputDecoration(labelText: s.t('auth.email'), prefixIcon: const Icon(Icons.mail_outline)),
                    validator: (v) => v != null && v.contains('@') ? null : s.t('common.required'),
                  ),
                  const SizedBox(height: 14),
                  TextFormField(
                    controller: _password,
                    obscureText: _obscure,
                    textDirection: TextDirection.ltr,
                    autofillHints: const [AutofillHints.password],
                    onFieldSubmitted: (_) => _submit(),
                    decoration: InputDecoration(
                      labelText: s.t('auth.password'),
                      prefixIcon: const Icon(Icons.lock_outline),
                      suffixIcon: IconButton(icon: Icon(_obscure ? Icons.visibility_off : Icons.visibility), onPressed: () => setState(() => _obscure = !_obscure)),
                    ),
                    validator: (v) => (v ?? '').isEmpty ? s.t('common.required') : null,
                  ),
                  if (_error != null) ...[
                    const SizedBox(height: 12),
                    Text(_error!, style: const TextStyle(color: AppColors.danger)),
                  ],
                  const SizedBox(height: 20),
                  FilledButton(
                    onPressed: _loading ? null : _submit,
                    child: _loading ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : Text(s.t('auth.signIn')),
                  ),
                  // The reset itself lives on the web portal (the link arrives by e-mail), so the app opens that page.
                  TextButton(
                    key: const ValueKey('forgot-password'),
                    onPressed: () => launchUrl(Uri.parse('${AppConfig.webUrl}/forgot'), mode: LaunchMode.externalApplication),
                    child: Text(s.t('auth.forgot')),
                  ),
                  ],
                  // A sign-in problem also shows when only the single sign-on button is on screen.
                  if (_error != null && _ssoMandatory(ref) && !_showPassword) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_error!, style: const TextStyle(color: AppColors.danger))),
                ]),
              ),
            ),
            // Visitors find their way in the centre without an account (docs/wayfinding.md).
            const SizedBox(height: 18),
            Center(
              child: OutlinedButton.icon(
                key: const ValueKey('centre-guide'),
                onPressed: () => context.push('/map'),
                style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: BorderSide(color: AppColors.gold300.withValues(alpha: .6)), padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 12), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))),
                icon: Icon(Icons.map_outlined, color: AppColors.gold300),
                label: Text(s.t('way.open')),
              ),
            ),
            if (_showcase(ref).isNotEmpty || _showcaseLeadership(ref))
              _ShowcaseAccounts(
                accounts: _showcase(ref),
                opening: _openingShowcase,
                enabled: !_loading,
                onPick: _openShowcase,
                leadership: _showcaseLeadership(ref),
                onUnlock: _unlockLeadership,
                onLeader: (key, code) => _openShowcase(key, code: code),
              ),
            // Demo accounts only where the server is in demo mode (never on a live system by default).
            if (AppConfig.showDemoAccounts && _serverShowsDemo(ref)) _DemoAccounts(onPick: _useDemo, password: _demoPassword(ref)!),
          ]),
        ),
      ]),
    );
  }

  /// The shared demo password is never part of the app: the server sends it only while demo mode is on (never on a live system).
  String? _demoPassword(WidgetRef ref) {
    final config = ref.watch(getProvider('/public/mobile-config')).value;
    if (config is! Map || config['data'] is! Map) return null;
    final data = config['data'] as Map;
    final password = data['demo_password'];
    return data['demo_accounts'] == true && password is String && password.isNotEmpty ? password : null;
  }

  bool _serverShowsDemo(WidgetRef ref) => _demoPassword(ref) != null;

  void _useDemo(String email) {
    final password = _demoPassword(ref);
    if (password == null) return;
    _email.text = email;
    _password.text = password;
    _submit();
  }

  Future<void> _serverSettings() async {
    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => const _ServerSheet(),
    );
    if (saved == true && mounted) {
      ref.invalidate(apiProvider);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.s.t('server.saved'))));
    }
  }
}

/// One tap on a showcase account: supervisors, trainers and trainees with sample training, offered by the server.
class _ShowcaseAccounts extends StatelessWidget {
  const _ShowcaseAccounts({
    required this.accounts,
    required this.opening,
    required this.enabled,
    required this.onPick,
    this.leadership = false,
    this.onUnlock,
    this.onLeader,
  });

  static const _icons = {'supervision': Icons.assignment_ind_outlined, 'training': Icons.co_present_outlined, 'learners': Icons.school_outlined};

  final List<Map<String, dynamic>> accounts;
  final String? opening;
  final bool enabled;
  final ValueChanged<String> onPick;

  /// The locked section of leadership accounts: shown when the server offers it, opened with the showcase code.
  final bool leadership;
  final Future<List<Map<String, dynamic>>> Function(String code)? onUnlock;
  final void Function(String key, String code)? onLeader;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Container(
      key: const ValueKey('showcase-accounts'),
      margin: const EdgeInsets.only(top: 18),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .07),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: AppColors.gold300.withValues(alpha: .35)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Icon(Icons.auto_awesome_outlined, color: AppColors.gold300, size: 18),
          const SizedBox(width: 6),
          Expanded(child: Text(s.t('auth.showcase.title'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800))),
        ]),
        const SizedBox(height: 4),
        Text(s.t('auth.showcase.hint'), style: TextStyle(color: Colors.white.withValues(alpha: .75), fontSize: 12.5)),
        const SizedBox(height: 12),
        for (final a in accounts) ...[
          Material(
            color: Colors.white,
            borderRadius: BorderRadius.circular(16),
            child: InkWell(
              key: ValueKey('showcase-${a['key']}'),
              borderRadius: BorderRadius.circular(16),
              onTap: enabled ? () => onPick(a['key'].toString()) : null,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                child: Row(children: [
                  Container(
                    width: 38,
                    height: 38,
                    decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(12)),
                    child: Icon(_icons[a['group']] ?? Icons.person_outline, color: AppColors.gold300, size: 20),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(a['name'].toString(), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800)),
                      Text(s.t('auth.showcase.${a['group']}'), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                    ]),
                  ),
                  if (opening == a['key'])
                    const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  else
                    Icon(Icons.login_rounded, color: AppColors.gold700, size: 20),
                ]),
              ),
            ),
          ),
          const SizedBox(height: 8),
        ],
        if (leadership && onUnlock != null && onLeader != null) _LeadershipLock(unlock: onUnlock!, open: onLeader!, opening: opening, enabled: enabled),
      ]),
    );
  }
}

/// The leadership accounts (system administrator, head of the centre, of training, of planning): they see the platform's real data, so
/// they open only after the showcase code the system administrator made is typed. The code stays here and goes with each tap.
class _LeadershipLock extends StatefulWidget {
  const _LeadershipLock({required this.unlock, required this.open, required this.opening, required this.enabled});

  final Future<List<Map<String, dynamic>>> Function(String code) unlock;
  final void Function(String key, String code) open;
  final String? opening;
  final bool enabled;

  @override
  State<_LeadershipLock> createState() => _LeadershipLockState();
}

class _LeadershipLockState extends State<_LeadershipLock> {
  final _code = TextEditingController();
  List<Map<String, dynamic>>? _leaders;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _code.dispose();
    super.dispose();
  }

  Future<void> _unlock() async {
    final code = _code.text.trim();
    if (code.isEmpty) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final list = await widget.unlock(code);
      if (mounted) setState(() => _leaders = list);
    } catch (e) {
      if (mounted) setState(() => _error = ApiException.from(e).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final leaders = _leaders;
    return Container(
      key: const ValueKey('showcase-leadership'),
      margin: const EdgeInsets.only(top: 6),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(color: Colors.white.withValues(alpha: .08), borderRadius: BorderRadius.circular(18)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Icon(leaders == null ? Icons.lock_outline_rounded : Icons.lock_open_rounded, color: AppColors.gold300, size: 18),
          const SizedBox(width: 6),
          Expanded(child: Text(s.t('auth.showcase.leadersTitle'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800))),
        ]),
        if (leaders == null) ...[
          const SizedBox(height: 4),
          Text(s.t('auth.showcase.leadersHint'), style: TextStyle(color: Colors.white.withValues(alpha: .75), fontSize: 12.5, height: 1.5)),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(
              child: TextField(
                key: const ValueKey('showcase-code'),
                controller: _code,
                textCapitalization: TextCapitalization.characters,
                textDirection: TextDirection.ltr,
                autocorrect: false,
                enableSuggestions: false,
                onSubmitted: (_) => _unlock(),
                style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800, letterSpacing: 2),
                decoration: InputDecoration(
                  filled: true,
                  fillColor: Colors.white,
                  isDense: true,
                  hintText: s.t('auth.showcase.codePlaceholder'),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none),
                ),
              ),
            ),
            const SizedBox(width: 8),
            FilledButton(
              key: const ValueKey('showcase-unlock'),
              onPressed: _busy || !widget.enabled ? null : _unlock,
              style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, minimumSize: const Size(0, 46)),
              child: _busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : Text(s.t('auth.showcase.unlock'), style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
          ]),
        ] else ...[
          const SizedBox(height: 10),
          for (final a in leaders) ...[
            Material(
              color: Colors.white,
              borderRadius: BorderRadius.circular(16),
              child: InkWell(
                key: ValueKey('showcase-leader-${a['key']}'),
                borderRadius: BorderRadius.circular(16),
                onTap: widget.enabled ? () => widget.open(a['key'].toString(), _code.text.trim()) : null,
                child: Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                  child: Row(children: [
                    Container(
                      width: 38,
                      height: 38,
                      decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(12)),
                      child: Icon(Icons.workspace_premium_outlined, color: AppColors.gold300, size: 20),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(a['name'].toString(), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800)),
                        Text(s.t('auth.showcase.leadership'), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                      ]),
                    ),
                    if (widget.opening == a['key'])
                      const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                    else
                      Icon(Icons.login_rounded, color: AppColors.gold700, size: 20),
                  ]),
                ),
              ),
            ),
            const SizedBox(height: 8),
          ],
        ],
        if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: Color(0xFFFFB4AB), fontWeight: FontWeight.w700))),
      ]),
    );
  }
}

/// One-tap sign-in with the demo accounts (all roles share the demo password, which the server sends only in demo mode).
class _DemoAccounts extends StatelessWidget {
  const _DemoAccounts({required this.onPick, required this.password});

  static const accounts = [
    ('admin@tedc.qa', 'Super Admin'),
    ('center@tedc.qa', 'Center Admin'),
    ('coordinator@tedc.qa', 'Coordinator'),
    ('trainer@tedc.qa', 'Trainer'),
    ('school@tedc.qa', 'School Admin'),
    ('teacher@tedc.qa', 'Employee'),
    ('supervisor@tedc.qa', 'Supervisor'),
    ('executive@tedc.qa', 'Executive'),
  ];

  /// Four test trainees and two test trainers; the labels are translation keys.
  static const testAccounts = [
    ('trainee1@tedc.qa', 'auth.trainee1'),
    ('trainee2@tedc.qa', 'auth.trainee2'),
    ('trainee3@tedc.qa', 'auth.trainee3'),
    ('trainee4@tedc.qa', 'auth.trainee4'),
    ('trainer1@tedc.qa', 'auth.trainer1'),
    ('trainer2@tedc.qa', 'auth.trainer2'),
  ];

  final ValueChanged<String> onPick;
  final String password;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Container(
      margin: const EdgeInsets.only(top: 18),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .07),
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: AppColors.gold300.withValues(alpha: .35)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(Icons.verified_user_outlined, color: AppColors.gold300, size: 18),
          const SizedBox(width: 6),
          Text(s.t('auth.demo'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
        ]),
        const SizedBox(height: 4),
        Text.rich(TextSpan(children: [
          TextSpan(text: '${s.t('auth.demoHint')} '),
          TextSpan(text: '\u2066$password\u2069', style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.gold300)),
        ]), style: TextStyle(color: Colors.white.withValues(alpha: .75), fontSize: 12.5)),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final (email, role) in accounts)
            ActionChip(
              label: Text(role),
              labelStyle: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w700, fontSize: 12),
              backgroundColor: Colors.white,
              side: BorderSide.none,
              onPressed: () => onPick(email),
            ),
        ]),
        const SizedBox(height: 16),
        Row(children: [
          Icon(Icons.science_outlined, color: AppColors.gold300, size: 18),
          const SizedBox(width: 6),
          Expanded(child: Text(s.t('auth.testAccounts'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800))),
        ]),
        const SizedBox(height: 4),
        Text(s.t('auth.testAccountsHint'), style: TextStyle(color: Colors.white.withValues(alpha: .75), fontSize: 12.5)),
        const SizedBox(height: 10),
        // One tap signs in: trainees 1-4 and trainers 1-2 (see Settings → Test accounts on the dashboard).
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final (email, label) in testAccounts)
            FilledButton.tonal(
              style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10), minimumSize: const Size(0, 40)),
              onPressed: () => onPick(email),
              child: Text(s.t(label), style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
        ]),
      ]),
    );
  }
}

/// Server address used by the app (defaults to the address built into the APK).
class _ServerSheet extends StatefulWidget {
  const _ServerSheet();

  @override
  State<_ServerSheet> createState() => _ServerSheetState();
}

class _ServerSheetState extends State<_ServerSheet> {
  late final _url = TextEditingController(text: AppConfig.apiUrl);
  bool _testing = false;
  bool? _ok;

  @override
  void dispose() {
    _url.dispose();
    super.dispose();
  }

  Future<void> _test() async {
    setState(() {
      _testing = true;
      _ok = null;
    });
    try {
      final base = AppConfig.normalizeApiUrl(_url.text);
      await Dio(BaseOptions(connectTimeout: const Duration(seconds: 10))).get('$base/public/theme');
      _ok = true;
    } catch (_) {
      _ok = false;
    }
    if (mounted) setState(() => _testing = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(s.t('server.title'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900)),
        const SizedBox(height: 12),
        TextField(
          controller: _url,
          keyboardType: TextInputType.url,
          textDirection: TextDirection.ltr,
          decoration: InputDecoration(hintText: s.t('server.hint'), prefixIcon: const Icon(Icons.dns_outlined)),
          onChanged: (_) => setState(() => _ok = null),
        ),
        if (_ok != null) ...[
          const SizedBox(height: 8),
          Row(children: [
            Icon(_ok! ? Icons.check_circle : Icons.error_outline, color: _ok! ? AppColors.success : AppColors.danger, size: 18),
            const SizedBox(width: 6),
            Text(s.t(_ok! ? 'server.ok' : 'server.fail'), style: TextStyle(color: _ok! ? AppColors.success : AppColors.danger)),
          ]),
        ],
        const SizedBox(height: 16),
        Row(children: [
          TextButton(
            onPressed: () async {
              await AppConfig.setServer(null);
              if (context.mounted) Navigator.pop(context, true);
            },
            child: Text(s.t('server.reset')),
          ),
          const Spacer(),
          OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: _testing ? null : _test, child: Text(s.t('server.test'))),
          const SizedBox(width: 8),
          FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 48)), 
            onPressed: () async {
              await AppConfig.setServer(_url.text);
              if (context.mounted) Navigator.pop(context, true);
            },
            child: Text(s.t('common.save')),
          ),
        ]),
      ]),
    );
  }
}
