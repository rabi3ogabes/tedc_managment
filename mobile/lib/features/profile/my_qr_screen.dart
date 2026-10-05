import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// My personal attendance code: staff scan it to record attendance. Trainers can also scan a session code from here.
class MyQrScreen extends ConsumerWidget {
  const MyQrScreen({super.key});

  static const path = '/me/attendance-qr';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final value = ref.watch(getProvider(path));

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('myqr.title'))),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final data = Map<String, dynamic>.from(Map<String, dynamic>.from(raw as Map)['data'] as Map);
          return ListView(padding: const EdgeInsets.all(24), children: [
            Center(
              child: Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24)),
                child: QrImageView(data: data.str('payload'), size: 240),
              ),
            ),
            const SizedBox(height: 16),
            Text(s.t('myqr.hint'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54)),
            if (data.str('kind') == 'trainer') ...[
              const SizedBox(height: 20),
              FilledButton.icon(
                onPressed: () => context.push('/scan?trainer=1'),
                icon: const Icon(Icons.qr_code_scanner),
                label: Text(s.t('myqr.scanTrainer')),
                style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
              ),
            ],
          ]);
        },
      ),
    );
  }
}
