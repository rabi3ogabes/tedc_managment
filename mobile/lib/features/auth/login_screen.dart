import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
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
            const Center(child: BrandMark(size: 88)),
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
          ]),
        ),
      ]),
    );
  }
}
