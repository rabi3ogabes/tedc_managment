import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});

  static const path = '/me/notifications';

  void _refresh(WidgetRef ref) {
    ref.invalidate(getProvider(path));
    ref.invalidate(getProvider('/me/notifications?unread=1&per_page=1'));
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
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
              _refresh(ref);
            },
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(getProvider(path).future),
        child: AsyncView(
          value: ref.watch(getProvider(path)),
          onRetry: () => _refresh(ref),
          builder: (raw) {
            final items = Map<String, dynamic>.from(raw as Map).list('data');
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
                  onTap: () async {
                    final surveyId = n.obj('data')?.str('needs_survey_id') ?? '';
                    if (surveyId.isNotEmpty) context.push('/needs-surveys/$surveyId');
                    if (unread) {
                      await ref.read(apiProvider).post('/me/notifications/${n.str('id')}/read');
                      _refresh(ref);
                    }
                  },
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
    if (type.startsWith('registration')) return Icons.how_to_reg_outlined;
    if (type.startsWith('task')) return Icons.assignment_outlined;
    if (type.startsWith('impact')) return Icons.insights;
    if (type.startsWith('needs_survey')) return Icons.assignment_outlined;
    if (type.startsWith('session')) return Icons.event_outlined;
    if (type == 'announcement') return Icons.campaign_outlined;
    return Icons.notifications_outlined;
  }
}
