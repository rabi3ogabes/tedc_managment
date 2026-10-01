import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/notification_route.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The notification history. Opening it records that the notifications were seen, and tapping one marks it as
/// read — both are visible to the administrators who track a notification.
class NotificationsScreen extends ConsumerStatefulWidget {
  const NotificationsScreen({super.key});

  static const path = '/me/notifications';

  @override
  ConsumerState<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends ConsumerState<NotificationsScreen> {
  final _reportedSeen = <String>{};

  void _refresh() {
    ref.invalidate(getProvider(NotificationsScreen.path));
    ref.invalidate(getProvider('/me/notifications?unread=1&per_page=1'));
  }

  /// Tells the server which notifications were shown on screen (once each).
  void _markSeen(List<Map<String, dynamic>> items) {
    final ids = items.where((n) => !n.flag('seen') && !n.flag('read')).map((n) => n.str('id')).where((id) => id.isNotEmpty && !_reportedSeen.contains(id)).take(100).toList();
    if (ids.isEmpty) return;
    _reportedSeen.addAll(ids);
    WidgetsBinding.instance.addPostFrameCallback((_) {
      ref.read(apiProvider).post('/me/notifications/seen', {'ids': ids}).catchError((_) => null);
    });
  }

  /// Marks the notification read and opens the page it is about (attendance → the session, a task → the task ...).
  Future<void> _open(Map<String, dynamic> n) async {
    final data = n.obj('data') ?? <String, dynamic>{};
    final route = NotificationRoute.resolve(n.str('type'), {...data, 'type': n.str('type')});
    if (!n.flag('read')) {
      await ref.read(apiProvider).post('/me/notifications/${n.str('id')}/read');
      _refresh();
    }
    if (!mounted || route == '/notifications') return;
    NotificationRoute.isTab(route) ? context.go(route) : context.push(route);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    return Scaffold(
      appBar: AppBar(
        title: Text(s.t('notifications.title')),
        actions: [
          IconButton(
            tooltip: s.t('notifications.readAll'),
            icon: const Icon(Icons.done_all),
            onPressed: () async {
              await ref.read(apiProvider).post('/me/notifications/read-all');
              _refresh();
            },
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(getProvider(NotificationsScreen.path).future),
        child: AsyncView(
          value: ref.watch(getProvider(NotificationsScreen.path)),
          onRetry: _refresh,
          builder: (raw) {
            final items = Map<String, dynamic>.from(raw as Map).list('data');
            _markSeen(items);
            if (items.isEmpty) return ListView(children: const [EmptyView(icon: Icons.notifications_none)]);
            return ListView.separated(
              itemCount: items.length,
              separatorBuilder: (_, _) => const Divider(height: 1, color: AppColors.navy100),
              itemBuilder: (_, i) {
                final n = items[i];
                final unread = !n.flag('read');
                return ListTile(
                  tileColor: unread ? AppColors.gold100.withValues(alpha: .4) : null,
                  leading: CircleAvatar(backgroundColor: unread ? AppColors.navy900 : AppColors.navy100, child: Icon(_icon(n.str('type')), color: unread ? AppColors.gold300 : AppColors.muted, size: 20)),
                  title: Text(n.str('title'), style: TextStyle(fontWeight: unread ? FontWeight.w800 : FontWeight.w600)),
                  subtitle: Text('${n.str('body')}\n${fmt.dateTime(n.date('created_at'))}'),
                  isThreeLine: true,
                  trailing: unread ? const Icon(Icons.circle, size: 10, color: AppColors.gold500) : const Icon(Icons.done_all, size: 18, color: AppColors.muted),
                  onTap: () => _open(n),
                );
              },
            );
          },
        ),
      ),
    );
  }

  IconData _icon(String type) {
    if (type.startsWith('certificate')) return Icons.workspace_premium_outlined;
    if (type.startsWith('profile')) return Icons.badge_outlined;
    if (type.startsWith('registration') || type.startsWith('program')) return Icons.how_to_reg_outlined;
    if (type.startsWith('task')) return Icons.assignment_outlined;
    if (type.startsWith('impact') || type.startsWith('survey')) return Icons.insights;
    if (type.startsWith('needs_survey')) return Icons.assignment_outlined;
    if (type.startsWith('session')) return Icons.event_outlined;
    if (type == 'announcement') return Icons.campaign_outlined;
    return Icons.notifications_outlined;
  }
}
