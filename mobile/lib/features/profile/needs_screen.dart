import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// My development needs, and — for managers — the staff needs waiting for a decision.
class NeedsScreen extends ConsumerWidget {
  const NeedsScreen({super.key});

  static const minePath = '/me/needs';
  static const teamPath = '/admin/individual-needs?status=pending_manager';

  static void refresh(WidgetRef ref) {
    ref.invalidate(getProvider(minePath));
    ref.invalidate(getProvider(teamPath));
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final mine = ref.watch(getProvider(minePath));
    final team = ref.watch(getProvider(teamPath)).value;
    final pending = team is Map ? Map<String, dynamic>.from(team).list('data') : <Json>[];

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('needs.title'))),
      body: RefreshIndicator(
        onRefresh: () async {
          refresh(ref);
          await ref.read(getProvider(minePath).future);
        },
        child: ListView(padding: const EdgeInsets.all(20), children: [
          if (pending.isNotEmpty) ...[
            SectionTitle(s.t('needs.team')),
            for (final n in pending) _TeamNeed(n),
            const SizedBox(height: 12),
          ],
          SectionTitle(s.t('needs.mine')),
          AsyncView(
            value: mine,
            onRetry: () => ref.invalidate(getProvider(minePath)),
            builder: (raw) {
              final rows = Map<String, dynamic>.from(raw as Map).list('data');
              if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(24), child: Center(child: Text(s.t('needs.empty'))));
              return Column(children: [
                for (final n in rows)
                  Card(
                    child: ListTile(
                      title: Text(n.obj('skill')?.str(ar ? 'name_ar' : 'name_en') ?? '', style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text(n.str(ar ? 'explanation_ar' : 'explanation_en')),
                      trailing: StatusChip(n.str('status'), label: s.t('needs.st.${n.str('status')}')),
                    ),
                  ),
              ]);
            },
          ),
        ]),
      ),
    );
  }
}

class _TeamNeed extends ConsumerStatefulWidget {
  const _TeamNeed(this.n);

  final Json n;

  @override
  ConsumerState<_TeamNeed> createState() => _TeamNeedState();
}

class _TeamNeedState extends ConsumerState<_TeamNeed> {
  bool _busy = false;

  Future<void> _decide(String decision) async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/admin/individual-needs/decide', {
        'ids': [widget.n.str('id')],
        'decision': decision,
        if (decision == 'rejected') 'note': context.tr('needs.rejectNote'),
      });
      NeedsScreen.refresh(ref);
      if (mounted) showSnack(context, context.tr('needs.decided'));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final n = widget.n;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(n.obj('employee')?.str('name') ?? '', style: const TextStyle(fontWeight: FontWeight.w800)),
          Text(n.obj('skill')?.str(ar ? 'name_ar' : 'name_en') ?? ''),
          const SizedBox(height: 4),
          Text(n.str(ar ? 'explanation_ar' : 'explanation_en'), style: const TextStyle(color: Colors.black54, fontSize: 12)),
          const SizedBox(height: 10),
          Row(children: [
            FilledButton(
              onPressed: _busy ? null : () => _decide('approved'),
              style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
              child: Text(s.t('needs.approve')),
            ),
            const SizedBox(width: 8),
            OutlinedButton(onPressed: _busy ? null : () => _decide('rejected'), child: Text(s.t('needs.reject'))),
          ]),
        ]),
      ),
    );
  }
}
