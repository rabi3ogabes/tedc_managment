import 'package:flutter/material.dart';

import '../push/push_service.dart';
import '../theme/app_theme.dart';

/// In-app banner for a push notification received while the app is open: slides in from the top in the
/// brand's maroon, opens the related screen on tap and can be swiped away.
class PushBanner extends StatelessWidget {
  const PushBanner({super.key, required this.message, required this.onTap, required this.onDismiss});

  final PushMessage? message;
  final ValueChanged<String> onTap;
  final VoidCallback onDismiss;

  @override
  Widget build(BuildContext context) {
    final m = message;
    return Positioned(
      top: 0,
      left: 0,
      right: 0,
      child: SafeArea(
        child: AnimatedSwitcher(
          duration: const Duration(milliseconds: 320),
          switchInCurve: Curves.easeOutCubic,
          switchOutCurve: Curves.easeInCubic,
          transitionBuilder: (child, animation) => SlideTransition(
            position: Tween(begin: const Offset(0, -1.2), end: Offset.zero).animate(animation),
            child: FadeTransition(opacity: animation, child: child),
          ),
          child: m == null
              ? const SizedBox.shrink(key: ValueKey('none'))
              : Dismissible(
                  key: ValueKey(m),
                  direction: DismissDirection.up,
                  onDismissed: (_) => onDismiss(),
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(12, 8, 12, 0),
                    child: Material(
                      color: Colors.transparent,
                      child: InkWell(
                        borderRadius: BorderRadius.circular(20),
                        onTap: () => onTap(m.route),
                        child: Ink(
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(
                            gradient: AppColors.navyGradient,
                            borderRadius: BorderRadius.circular(20),
                            border: Border.all(color: AppColors.gold300.withValues(alpha: .35)),
                            boxShadow: [BoxShadow(color: AppColors.navy950.withValues(alpha: .35), blurRadius: 24, offset: const Offset(0, 10))],
                          ),
                          child: Row(children: [
                            Container(
                              width: 40,
                              height: 40,
                              decoration: BoxDecoration(color: AppColors.gold500.withValues(alpha: .22), borderRadius: BorderRadius.circular(12)),
                              child: Icon(Icons.notifications_active_rounded, color: AppColors.gold300),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                                Text(m.title, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                                if (m.body != null && m.body!.isNotEmpty)
                                  Text(m.body!, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: Colors.white.withValues(alpha: .8), fontSize: 13)),
                              ]),
                            ),
                            Icon(Icons.chevron_right_rounded, color: AppColors.gold300),
                          ]),
                        ),
                      ),
                    ),
                  ),
                ),
        ),
      ),
    );
  }
}
