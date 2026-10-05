import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
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
            if (r['has_course'] == true && active)
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: InkWell(
                  borderRadius: BorderRadius.circular(22),
                  onTap: () => context.push('/courses/$id'),
                  child: Ink(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(22)),
                    child: Row(children: [
                      const Icon(Icons.play_circle_fill, color: AppColors.gold300, size: 40),
                      const SizedBox(width: 14),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(s.t('course.title'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16)),
                          const SizedBox(height: 6),
                          ProgressBar(r.number('course_percent').toDouble(), color: AppColors.gold300),
                          const SizedBox(height: 4),
                          Text('${r.number('course_percent').round()}%', style: const TextStyle(color: AppColors.gold300, fontSize: 12)),
                        ]),
                      ),
                      const Icon(Icons.chevron_right, color: Colors.white),
                    ]),
                  ),
                ),
              ),
            SectionTitle(s.t('programs.sessions')),
            for (final session in r.list('sessions'))
              Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  onTap: () => context.push('/sessions/${session.str('id')}'),
                  leading: Icon(session.str('mode') == 'online' ? Icons.videocam_outlined : Icons.place_outlined, color: AppColors.gold700),
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
              if (r['evaluation_submitted'] != true && r['survey_open'] != false)
                FilledButton.icon(
                  onPressed: () => showModalBottomSheet(context: context, isScrollControlled: true, builder: (_) => _EvaluationSheet(registrationId: id)),
                  icon: const Icon(Icons.star_outline),
                  label: Text(s.t('training.evaluate')),
                ),
              // The program survey is opened by the administrators (by hand, or automatically some hours after the program).
              if (r['evaluation_submitted'] != true && r['survey_open'] == false)
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(color: AppColors.gold100.withValues(alpha: .5), borderRadius: BorderRadius.circular(16)),
                  child: Row(children: [
                    const Icon(Icons.lock_clock_outlined, color: AppColors.gold700),
                    const SizedBox(width: 12),
                    Expanded(child: Text(r.str('survey_opens_at').isNotEmpty ? '${s.t('training.surveyOpensAt')} ${Fmt(s.languageCode).dateTime(r.date('survey_opens_at'))}' : s.t('training.surveyClosed'), style: const TextStyle(fontWeight: FontWeight.w600))),
                  ]),
                ),
            ],
            if (r.str('status') == 'approved') ...[
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: () => showModalBottomSheet(context: context, isScrollControlled: true, builder: (_) => _ExcuseSheet(registrationId: id)),
                child: Text(s.t('excuse.title')),
              ),
            ],
            if (['pending_manager', 'pending', 'approved', 'waitlisted'].contains(r.str('status'))) ...[
              const SizedBox(height: 12),
              OutlinedButton(
                onPressed: () => showModalBottomSheet(context: context, isScrollControlled: true, builder: (_) => _WithdrawSheet(registrationId: id, free: ['pending_manager', 'waitlisted'].contains(r.str('status')))),
                style: OutlinedButton.styleFrom(foregroundColor: AppColors.danger),
                child: Text(s.t('withdraw.button')),
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

/// Withdraw: free before the manager approved, otherwise a request with a reason that goes to the manager (and the supervisor).
class _WithdrawSheet extends ConsumerStatefulWidget {
  const _WithdrawSheet({required this.registrationId, required this.free});

  final String registrationId;
  final bool free;

  @override
  ConsumerState<_WithdrawSheet> createState() => _WithdrawSheetState();
}

class _WithdrawSheetState extends ConsumerState<_WithdrawSheet> {
  String? _code;
  final _text = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => _busy = true);
    try {
      final res = await ref.read(apiProvider).post('/me/registrations/${widget.registrationId}/withdraw', {
        if (_code != null) 'reason_code': _code,
        if (_text.text.trim().isNotEmpty) 'reason_text': _text.text.trim(),
      });
      ref.invalidate(getProvider('/me/registrations/${widget.registrationId}'));
      ref.invalidate(getProvider('/me/registrations'));
      if (mounted) {
        final direct = res is Map && (res['data'] as Map?)?['mode'] == 'direct';
        Navigator.of(context).pop();
        showSnack(context, context.tr(direct ? 'withdraw.done' : 'withdraw.requested'));
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
    final ar = s.languageCode == 'ar';
    final reasons = widget.free ? null : ref.watch(getProvider('/me/withdrawal-reasons')).value;
    final list = reasons is Map ? Map<String, dynamic>.from(reasons).list('data') : <Json>[];

    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.of(context).viewInsets.bottom + 20),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(s.t('withdraw.title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 8),
        Text(s.t(widget.free ? 'withdraw.free' : 'withdraw.needs'), style: const TextStyle(color: Colors.black54)),
        if (!widget.free) ...[
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            initialValue: _code,
            decoration: InputDecoration(labelText: s.t('withdraw.reason')),
            items: [for (final r in list) DropdownMenuItem(value: r.str('code'), child: Text(r.str(ar ? 'label_ar' : 'label_en')))],
            onChanged: (v) => setState(() => _code = v),
          ),
          const SizedBox(height: 8),
          TextField(controller: _text, minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: s.t('withdraw.details'))),
        ],
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _busy || (!widget.free && _code == null) ? null : _send,
          style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
          child: _busy ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2)) : Text(s.t(widget.free ? 'withdraw.button' : 'withdraw.send')),
        ),
      ]),
    );
  }
}

/// An absence excuse for the direct manager to decide.
class _ExcuseSheet extends ConsumerStatefulWidget {
  const _ExcuseSheet({required this.registrationId});

  final String registrationId;

  @override
  ConsumerState<_ExcuseSheet> createState() => _ExcuseSheetState();
}

class _ExcuseSheetState extends ConsumerState<_ExcuseSheet> {
  String _reason = 'sick_leave';
  DateTime? _from;
  DateTime? _to;
  final _text = TextEditingController();
  bool _busy = false;

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  String _fmt(DateTime d) => '${d.year}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  Future<void> _pick(bool from) async {
    final now = DateTime.now();
    final d = await showDatePicker(context: context, initialDate: now, firstDate: now.subtract(const Duration(days: 60)), lastDate: now.add(const Duration(days: 60)));
    if (d != null && mounted) setState(() => from ? _from = d : _to = d);
  }

  Future<void> _send() async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/me/registrations/${widget.registrationId}/excuses', {
        'reason_code': _reason,
        'from_date': _fmt(_from!),
        'to_date': _fmt(_to!),
        if (_text.text.trim().isNotEmpty) 'reason_text': _text.text.trim(),
      });
      if (mounted) {
        Navigator.of(context).pop();
        showSnack(context, context.tr('excuse.sent'));
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
      padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.of(context).viewInsets.bottom + 20),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(s.t('excuse.title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 12),
        DropdownButtonFormField<String>(
          initialValue: _reason,
          decoration: InputDecoration(labelText: s.t('excuse.reason')),
          items: [for (final r in ['sick_leave', 'bereavement', 'work_assignment', 'other']) DropdownMenuItem(value: r, child: Text(s.t('excuse.reason.$r')))],
          onChanged: (v) => setState(() => _reason = v ?? 'other'),
        ),
        const SizedBox(height: 8),
        Row(children: [
          Expanded(child: OutlinedButton(onPressed: () => _pick(true), child: Text(_from == null ? s.t('excuse.from') : _fmt(_from!)))),
          const SizedBox(width: 8),
          Expanded(child: OutlinedButton(onPressed: () => _pick(false), child: Text(_to == null ? s.t('excuse.to') : _fmt(_to!)))),
        ]),
        const SizedBox(height: 8),
        TextField(controller: _text, minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: s.t('withdraw.details'))),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _busy || _from == null || _to == null ? null : _send,
          style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
          child: _busy ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2)) : Text(s.t('excuse.send')),
        ),
      ]),
    );
  }
}
