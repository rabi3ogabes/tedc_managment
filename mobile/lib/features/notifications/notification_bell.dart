import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/l10n/strings.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';

/// The notifications button, pinned to the left of the home screen. Shows how many are unread and opens the
/// notification history.
class NotificationBell extends ConsumerWidget {
  const NotificationBell({super.key, this.onDark = true});

  final bool onDark;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final unread = ref.watch(getProvider('/me/notifications?unread=1&per_page=1')).value;
    final meta = unread is Map ? unread['meta'] : null;
    final int count = meta is Map ? ((meta['total'] as num?) ?? 0).toInt() : 0;

    return Semantics(
      button: true,
      label: context.s.t('nav.notifications'),
      child: Material(
        color: onDark ? Colors.white.withValues(alpha: .16) : AppColors.navy100,
        shape: const CircleBorder(),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: () => context.push('/notifications'),
          child: SizedBox(
            width: 46,
            height: 46,
            child: Center(
              child: Badge(
                isLabelVisible: count > 0,
                label: Text(count > 99 ? '99+' : '$count'),
                backgroundColor: AppColors.gold500,
                textColor: AppColors.navy950,
                child: Icon(count > 0 ? Icons.notifications_active : Icons.notifications_outlined, color: onDark ? Colors.white : AppColors.navy900),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
