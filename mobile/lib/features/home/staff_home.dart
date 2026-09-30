import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config.dart';
import '../../core/l10n/strings.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';

/// Shown instead of the training tabs for accounts that have no employee profile (administrators, coordinators,
/// trainers, executives). Those tabs only exist for employees, so asking the server for them ends in an error.
class StaffHome extends ConsumerWidget {
  const StaffHome({super.key});

  /// The dashboard lives on the same host as the API.
  static Uri get dashboardUri => Uri.parse(AppConfig.apiUrl.replaceFirst(RegExp(r'/api/v1/?$'), '/admin'));

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final me = ref.watch(authProvider).value;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(28),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              const OfficialEmblem(size: 96),
              const SizedBox(height: 24),
              Text(me?.name ?? '', textAlign: TextAlign.center, style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.navy900)),
              const SizedBox(height: 10),
              Text(s.t('staff.text'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 15, height: 1.7, color: AppColors.navy700)),
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: () => launchUrl(dashboardUri, mode: LaunchMode.externalApplication),
                icon: const Icon(Icons.open_in_new),
                label: Text(s.t('staff.dashboard')),
              ),
              const SizedBox(height: 10),
              OutlinedButton.icon(
                onPressed: () => ref.read(authProvider.notifier).logout(),
                icon: const Icon(Icons.logout),
                label: Text(s.t('staff.signOut')),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}
