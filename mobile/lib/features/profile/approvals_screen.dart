import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// Registrations of my staff waiting for my approval as their direct manager.
class ApprovalsScreen extends ConsumerWidget {
  const ApprovalsScreen({super.key});

  static const path = '/admin/approvals/manager';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final value = ref.watch(getProvider(path));

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('approvals.title'))),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final rows = Map<String, dynamic>.from(raw as Map).list('data');
          if (rows.isEmpty) return Center(child: Text(s.t('approvals.empty')));
          return ListView(padding: const EdgeInsets.all(20), children: [for (final r in rows) _ApprovalCard(r)]);
        },
      ),
    );
  }
}

class _ApprovalCard extends ConsumerStatefulWidget {
  const _ApprovalCard(this.r);

  final Json r;

  @override
  ConsumerState<_ApprovalCard> createState() => _ApprovalCardState();
}

class _ApprovalCardState extends ConsumerState<_ApprovalCard> {
  bool _busy = false;

  Future<void> _decide(String decision) async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/admin/registrations/${widget.r.str('id')}/manager-decision', {
        'decision': decision,
        if (decision == 'rejected') 'note': context.tr('approvals.rejectNote'),
      });
      ref.invalidate(getProvider(ApprovalsScreen.path));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final r = widget.r;

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(r.str('employee'), style: const TextStyle(fontWeight: FontWeight.w800)),
          Text(r.obj('program')?.str('title') ?? ''),
          Text(r.str('school'), style: const TextStyle(color: Colors.black54, fontSize: 12)),
          const SizedBox(height: 10),
          Row(children: [
            FilledButton(
              onPressed: _busy ? null : () => _decide('approved'),
              style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, minimumSize: const Size(0, 48)),
              child: Text(s.t('approvals.approve')),
            ),
            const SizedBox(width: 8),
            OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: _busy ? null : () => _decide('rejected'), child: Text(s.t('approvals.reject'))),
          ]),
        ]),
      ),
    );
  }
}
