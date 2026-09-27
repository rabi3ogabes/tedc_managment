import 'package:flutter/material.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

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
  String? _error;

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
    } catch (e) {
      setState(() => _error = ApiException.from(e).message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
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
                icon: const Icon(Icons.language, color: AppColors.gold300),
                label: Text(s.isArabic ? 'English' : 'العربية', style: const TextStyle(color: Colors.white)),
              ),
            ),
            const SizedBox(height: 32),
            // Long-press the emblem to point the app at another server (useful for test builds).
            Center(child: GestureDetector(onLongPress: _serverSettings, child: const OfficialEmblem(size: 104))),
            const SizedBox(height: 24),
            Text(s.t('app.name'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(s.t('app.tagline'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.gold300)),
            const SizedBox(height: 40),
            Container(
              padding: const EdgeInsets.all(22),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(28)),
              child: Form(
                key: _form,
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(s.t('auth.title'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.navy900)),
                  Text(s.t('auth.subtitle'), style: const TextStyle(color: AppColors.muted)),
                  const SizedBox(height: 20),
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
                ]),
              ),
            ),
            if (AppConfig.showDemoAccounts) _DemoAccounts(onPick: _useDemo),
          ]),
        ),
      ]),
    );
  }

  void _useDemo(String email) {
    _email.text = email;
    _password.text = _DemoAccounts.password;
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

/// One-tap sign-in with the demo accounts (all roles share the demo password).
class _DemoAccounts extends StatelessWidget {
  const _DemoAccounts({required this.onPick});

  static const password = 'Tedc@2026!';
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

  final ValueChanged<String> onPick;

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
          const Icon(Icons.verified_user_outlined, color: AppColors.gold300, size: 18),
          const SizedBox(width: 6),
          Text(s.t('auth.demo'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
        ]),
        const SizedBox(height: 4),
        Text.rich(TextSpan(children: [
          TextSpan(text: '${s.t('auth.demoHint')} '),
          const TextSpan(text: '\u2066$password\u2069', style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.gold300)),
        ]), style: TextStyle(color: Colors.white.withValues(alpha: .75), fontSize: 12.5)),
        const SizedBox(height: 12),
        Wrap(spacing: 8, runSpacing: 8, children: [
          for (final (email, role) in accounts)
            ActionChip(
              label: Text(role),
              labelStyle: const TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w700, fontSize: 12),
              backgroundColor: Colors.white,
              side: BorderSide.none,
              onPressed: () => onPick(email),
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
        Text(s.t('server.title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900)),
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
          OutlinedButton(onPressed: _testing ? null : _test, child: Text(s.t('server.test'))),
          const SizedBox(width: 8),
          FilledButton(
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
