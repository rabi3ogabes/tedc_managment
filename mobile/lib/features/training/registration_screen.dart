import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// A registration: sessions & attendance, materials, evaluation and certificate status.
class RegistrationScreen extends ConsumerWidget {
  const RegistrationScreen({super.key, required this.id});

  final String id;

  String get path => '/me/registrations/$id';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);

    return Scaffold(
      appBar: AppBar(),
      body: AsyncView(
        value: ref.watch(getProvider(path)),
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final r = Map<String, dynamic>.from(raw['data'] as Map);
          final p = r.obj('program') ?? {};
          final attendance = {for (final a in r.list('attendance')) a.str('program_session_id'): a};
          final active = ['approved', 'completed'].contains(r.str('status'));

          return ListView(padding: const EdgeInsets.all(20), children: [
            Text(p.str('title'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.navy900)),
            const SizedBox(height: 8),
            Row(children: [StatusChip(r.str('status')), const SizedBox(width: 8), StatusChip(r.str('certificate_status'))]),
            const SizedBox(height: 16),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(children: [
                  Row(children: [Text(s.t('training.attendance')), const Spacer(), Text(fmt.percent(r.number('attendance_percent')), style: const TextStyle(fontWeight: FontWeight.w800))]),
                  const SizedBox(height: 8),
                  ProgressBar(r.number('attendance_percent').toDouble()),
                ]),
              ),
            ),
            SectionTitle(s.t('programs.sessions')),
            for (final session in r.list('sessions'))
              Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  title: Text(session.str('title'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                  subtitle: Text('${fmt.weekdayDate(session.date('starts_at'))} · ${fmt.time(session.date('starts_at'))}'),
                  trailing: attendance[session.str('id')] != null
                      ? StatusChip(attendance[session.str('id')]!.str('status'))
                      : ((session.date('ends_at') ?? DateTime.now()).isBefore(DateTime.now()) ? StatusChip(_inferredAttendance(r.number('attendance_percent'))) : null),
                ),
              ),
            if (active) ...[
              SectionTitle(s.t('training.materials')),
              _Materials(registrationId: id),
              const SizedBox(height: 20),
              if (r['evaluation_submitted'] != true)
                FilledButton.icon(
                  onPressed: () => showModalBottomSheet(context: context, isScrollControlled: true, builder: (_) => _EvaluationSheet(registrationId: id)),
                  icon: const Icon(Icons.star_outline),
                  label: Text(s.t('training.evaluate')),
                ),
            ],
            if (['pending', 'approved', 'waitlisted'].contains(r.str('status'))) ...[
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: () async {
                  try {
                    await ref.read(apiProvider).post('/me/registrations/$id/cancel');
                    ref.invalidate(getProvider(path));
                    ref.invalidate(getProvider('/me/registrations'));
                  } catch (e) {
                    if (context.mounted) showSnack(context, ApiException.from(e).message, error: true);
                  }
                },
                style: OutlinedButton.styleFrom(foregroundColor: AppColors.danger),
                child: Text(s.t('training.cancel')),
              ),
            ],
          ]);
        },
      ),
    );
  }
}

class _Materials extends ConsumerWidget {
  const _Materials({required this.registrationId});

  final String registrationId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(getProvider('/me/registrations/$registrationId/materials'));
    return value.when(
      loading: () => const LoadingView(),
      error: (e, _) => Text(ApiException.from(e).message),
      data: (raw) {
        final items = Map<String, dynamic>.from(raw as Map).list('data');
        if (items.isEmpty) return const EmptyView(icon: Icons.folder_open);
        return Column(children: [
          for (final m in items)
            Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                leading: Icon(m.str('type') == 'video' ? Icons.play_circle_outline : Icons.description_outlined, color: AppColors.gold700),
                title: Text(m.str('title')),
                trailing: const Icon(Icons.open_in_new, size: 18),
                onTap: () async {
                  var url = m.str('url');
                  if (url.isEmpty) {
                    // File access control: the API issues a short-lived signed URL after checking enrollment.
                    final res = await ref.read(apiProvider).get('/me/materials/${m.str('id')}/download');
                    url = res['data']['url'].toString();
                  }
                  await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
                },
              ),
            ),
        ]);
      },
    );
  }
}

class _EvaluationSheet extends ConsumerStatefulWidget {
  const _EvaluationSheet({required this.registrationId});

  final String registrationId;

  @override
  ConsumerState<_EvaluationSheet> createState() => _EvaluationSheetState();
}

class _EvaluationSheetState extends ConsumerState<_EvaluationSheet> {
  final _ratings = {'content': 5, 'trainer': 5, 'organization': 5, 'relevance': 5};
  final _comments = TextEditingController();
  bool _testimonial = false;
  bool _saving = false;

  Future<void> _submit() async {
    setState(() => _saving = true);
    try {
      await ref.read(apiProvider).post('/me/registrations/${widget.registrationId}/evaluation', {
        'ratings': _ratings,
        'comments': _comments.text,
        'allow_testimonial': _testimonial,
      });
      ref.invalidate(getProvider('/me/registrations/${widget.registrationId}'));
      ref.invalidate(getProvider('/me/registrations'));
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.viewInsetsOf(context).bottom + 20),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(s.t('eval.title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
        const SizedBox(height: 12),
        for (final key in _ratings.keys)
          Row(children: [
            Expanded(child: Text(s.t('eval.$key'))),
            for (var n = 1; n <= 5; n++)
              IconButton(
                visualDensity: VisualDensity.compact,
                onPressed: () => setState(() => _ratings[key] = n),
                icon: Icon(n <= _ratings[key]! ? Icons.star : Icons.star_border, color: AppColors.gold500),
              ),
          ]),
        TextField(controller: _comments, maxLines: 3, decoration: InputDecoration(labelText: s.t('eval.comments'))),
        CheckboxListTile(
          value: _testimonial,
          onChanged: (v) => setState(() => _testimonial = v ?? false),
          title: Text(s.t('eval.testimonial'), style: const TextStyle(fontSize: 13)),
          contentPadding: EdgeInsets.zero,
        ),
        FilledButton(onPressed: _saving ? null : _submit, child: Text(s.t('common.submit'))),
      ]),
    );
  }
}

/// A past session without an attendance record: the registration's overall attendance tells whether the
/// employee attended all sessions (100%), none (0%), or it is unknown for this session.
String _inferredAttendance(num percent) => percent >= 100 ? 'present' : (percent <= 0 ? 'absent' : 'not_recorded');
