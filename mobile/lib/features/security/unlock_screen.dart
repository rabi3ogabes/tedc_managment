import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/biometric.dart';
import '../../core/l10n/strings.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';

/// Shown instead of the app while it is locked: the phone's fingerprint / face / lock check opens it.
class UnlockScreen extends ConsumerStatefulWidget {
  const UnlockScreen({super.key});

  @override
  ConsumerState<UnlockScreen> createState() => _UnlockScreenState();
}

class _UnlockScreenState extends ConsumerState<UnlockScreen> {
  bool _busy = false;
  String? _message;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _unlock());
  }

  Future<void> _unlock() async {
    if (_busy || !mounted) return;
    setState(() {
      _busy = true;
      _message = null;
    });
    final s = context.s;
    final result = await ref.read(biometricServiceProvider).authenticate(s.t('lock.reason'));
    if (!mounted) return;
    switch (result) {
      case BiometricResult.success:
        ref.read(appLockProvider.notifier).unlock();
        return;
      case BiometricResult.unavailable:
        // The phone has no lock any more: switch the feature off instead of keeping the user out.
        await ref.read(appLockProvider.notifier).setEnabled(false);
        if (mounted) ref.read(appLockProvider.notifier).unlock();
        return;
      case BiometricResult.lockedOut:
        _message = s.t('lock.lockedOut');
      case BiometricResult.cancelled:
        _message = s.t('lock.failed');
    }
    setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Scaffold(
      body: Container(
        decoration: BoxDecoration(gradient: AppColors.navyGradient),
        child: SafeArea(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 32),
            child: Column(children: [
              const Spacer(flex: 3),
              Image.asset('assets/brand/emblem-white.png', width: 96, height: 96, filterQuality: FilterQuality.high),
              const SizedBox(height: 22),
              Text(s.t('app.name'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
              const SizedBox(height: 10),
              Text(s.t('lock.title'), textAlign: TextAlign.center, style: TextStyle(color: AppColors.gold300, fontSize: 15, fontWeight: FontWeight.w600)),
              const Spacer(flex: 2),
              InkResponse(
                onTap: _unlock,
                radius: 64,
                child: Container(
                  width: 104,
                  height: 104,
                  decoration: BoxDecoration(shape: BoxShape.circle, gradient: AppColors.goldGradient, boxShadow: [BoxShadow(color: AppColors.gold500.withValues(alpha: .35), blurRadius: 32, spreadRadius: 2)]),
                  child: _busy
                      ? Padding(padding: EdgeInsets.all(34), child: CircularProgressIndicator(strokeWidth: 3, color: AppColors.navy950))
                      : Icon(Icons.fingerprint, size: 60, color: AppColors.navy950),
                ),
              ),
              const SizedBox(height: 18),
              SizedBox(
                height: 44,
                child: Text(_message ?? s.t('lock.hint'), textAlign: TextAlign.center, style: TextStyle(color: _message == null ? Colors.white70 : AppColors.gold300, fontSize: 13.5, height: 1.5)),
              ),
              const Spacer(flex: 3),
              TextButton(
                onPressed: () => ref.read(authProvider.notifier).logout().then((_) => ref.read(appLockProvider.notifier).unlock()),
                child: Text(s.t('auth.logout'), style: const TextStyle(color: Colors.white70)),
              ),
              const SizedBox(height: 12),
            ]),
          ),
        ),
      ),
    );
  }
}
