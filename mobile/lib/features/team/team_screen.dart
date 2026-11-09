import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// A supervisor's people and the programmes they completed, with the supervisor's evaluation of what changed in their work.
class TeamScreen extends ConsumerWidget {
  const TeamScreen({super.key});

  static const path = '/me/team';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final value = ref.watch(getProvider(path));

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('team.title'))),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final rows = Map<String, dynamic>.from(raw as Map).list('data');
          if (rows.isEmpty) return Center(child: Text(s.t('team.empty')));
          return ListView(padding: const EdgeInsets.all(16), children: [for (final r in rows) _Person(r)]);
        },
      ),
    );
  }
}

class _Person extends ConsumerWidget {
  const _Person(this.r);

  final Json r;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final employee = r.obj('employee') ?? <String, dynamic>{};
    final done = r.list('completed');
    final job = employee.obj('job_title')?.str('name') ?? '';
    final school = employee.obj('school')?.str('name') ?? '';

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(employee.str('name'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
          if (job.isNotEmpty || school.isNotEmpty) Text([job, school].where((e) => e.isNotEmpty).join(' · '), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
          const SizedBox(height: 10),
          if (done.isEmpty)
            Text(s.t('team.none'), style: const TextStyle(color: AppColors.muted))
          else
            for (final c in done)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(c.str('program'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
                      if (c.str('completed_at').isNotEmpty) Text(c.str('completed_at'), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                    ]),
                  ),
                  if (c.flag('evaluated'))
                    Chip(avatar: const Icon(Icons.check_circle, size: 18), label: Text(s.t('team.evaluated')))
                  else
                    FilledButton(
                      onPressed: () => _evaluate(context, ref, c),
                      style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, minimumSize: const Size(0, 44)),
                      child: Text(s.t('team.evaluate')),
                    ),
                ]),
              ),
        ]),
      ),
    );
  }

  Future<void> _evaluate(BuildContext context, WidgetRef ref, Json c) async {
    final sent = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      useSafeArea: true,
      builder: (_) => _EvaluationSheet(registrationId: c.str('registration_id'), program: c.str('program')),
    );
    if (sent == true) ref.invalidate(getProvider(TeamScreen.path));
  }
}

class _EvaluationSheet extends ConsumerStatefulWidget {
  const _EvaluationSheet({required this.registrationId, required this.program});

  final String registrationId;
  final String program;

  @override
  ConsumerState<_EvaluationSheet> createState() => _EvaluationSheetState();
}

class _EvaluationSheetState extends ConsumerState<_EvaluationSheet> {
  double _score = 70;
  final _comments = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _comments.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/me/team/registrations/${widget.registrationId}/evaluation', {
        'application_score': _score.round(),
        if (_comments.text.trim().isNotEmpty) 'comments': _comments.text.trim(),
      });
      if (mounted) {
        showSnack(context, context.s.t('team.sent'));
        Navigator.of(context).pop(true);
      }
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, 20 + MediaQuery.viewInsetsOf(context).bottom),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(s.t('team.evaluate'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900)),
        Text(widget.program, style: const TextStyle(color: AppColors.muted)),
        const SizedBox(height: 16),
        Text('${s.t('team.score')}: ${_score.round()}%', style: const TextStyle(fontWeight: FontWeight.w700)),
        Slider(value: _score, max: 100, divisions: 20, label: '${_score.round()}', onChanged: (v) => setState(() => _score = v)),
        TextField(controller: _comments, maxLines: 3, maxLength: 3000, decoration: InputDecoration(labelText: s.t('team.comments'))),
        const SizedBox(height: 8),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed: _busy ? null : _send,
            style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, minimumSize: const Size(0, 48)),
            child: Text(s.t('team.submit')),
          ),
        ),
      ]),
    );
  }
}
