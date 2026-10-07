import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/biometric.dart';
import '../../core/l10n/strings.dart';
import '../../core/theme/app_theme.dart';

/// Turns fingerprint sign-in on after the phone confirms it is the owner. Returns whether it is on.
Future<bool> enableBiometricLogin(BuildContext context, WidgetRef ref) async {
  final s = context.s;
  final messenger = ScaffoldMessenger.of(context);
  final result = await ref.read(biometricServiceProvider).authenticate(s.t('bio.enableReason'));
  if (result == BiometricResult.success) {
    await ref.read(appLockProvider.notifier).setEnabled(true);
    return true;
  }
  if (result == BiometricResult.unavailable) messenger.showSnackBar(SnackBar(content: Text(s.t('bio.unavailable'))));
  return false;
}

/// The one-time invitation shown on the home screen.
class BiometricOfferSheet extends StatelessWidget {
  const BiometricOfferSheet({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(28, 4, 28, 24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 76,
            height: 76,
            decoration: BoxDecoration(shape: BoxShape.circle, gradient: AppColors.goldGradient),
            child: Icon(Icons.fingerprint, size: 44, color: AppColors.navy950),
          ),
          const SizedBox(height: 16),
          Text(s.t('bio.offerTitle'), textAlign: TextAlign.center, style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: AppColors.navy900)),
          const SizedBox(height: 8),
          Text(s.t('bio.offerBody'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, height: 1.5)),
          const SizedBox(height: 20),
          Row(children: [
            Expanded(child: OutlinedButton(onPressed: () => Navigator.pop(context, false), child: Text(s.t('bio.later')))),
            const SizedBox(width: 12),
            Expanded(child: FilledButton(onPressed: () => Navigator.pop(context, true), child: Text(s.t('bio.enable')))),
          ]),
        ]),
      ),
    );
  }
}
