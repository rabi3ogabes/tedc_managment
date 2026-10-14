import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/offline/offline_exams.dart';
import '../../core/offline/offline_providers.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'adaptive_practice.dart';
import 'question_view.dart';

/// The trainee's assessments: what is open, the way into the exam, and (when the trainer allows it) taking it offline.
class AssessmentsScreen extends ConsumerWidget {
  const AssessmentsScreen({super.key});

  static const path = '/me/assessments';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final list = ref.watch(getProvider(path));
    final offline = featureOn(ref, 'offline_mobile');
    final packages = offline ? (ref.watch(offlineExamListProvider).value ?? const <OfflineExam>[]) : const <OfflineExam>[];
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('assess.title'))),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(getProvider(path));
          ref.invalidate(offlineExamListProvider);
          await ref.read(getProvider(path).future);
        },
        child: AsyncView(
          value: list,
          onRetry: () => ref.invalidate(getProvider(path)),
          builder: (raw) {
            final rows = Map<String, dynamic>.from(raw as Map).list('data');
            final waiting = packages.where((p) => p.waiting).toList();
            if (rows.isEmpty && waiting.isEmpty) return ListView(children: [Padding(padding: const EdgeInsets.all(40), child: Center(child: Text(s.t('assess.empty'))))]);
            return ListView(padding: const EdgeInsets.all(20), children: [
              for (final p in waiting) _WaitingCard(exam: p),
              for (final a in rows)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 14, 12, 12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(a.str(ar ? 'title_ar' : 'title_en'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15.5)),
                            const SizedBox(height: 4),
                            Text('${a.str('program')}\n${s.t('assess.attempts')} ${a.str('attempts_used')}/${a.str('max_attempts')}'
                                '${a['best_score'] != null ? '  •  ${s.t('assess.best')} ${a.str('best_score')}%' : ''}', style: const TextStyle(color: AppColors.muted, height: 1.5, fontSize: 13)),
                          ]),
                        ),
                        const SizedBox(width: 8),
                        a.flag('open') && a.flag('face_check')
                            // The camera presence check runs in a browser: the app sends the learner to a computer.
                            ? Padding(padding: const EdgeInsets.only(top: 12), child: Icon(Icons.desktop_windows_outlined, color: AppColors.gold700))
                            : a.flag('open')
                            ? FilledButton(
                                style: FilledButton.styleFrom(minimumSize: const Size(0, 48)),
                                onPressed: () => context.push('/assessments/${a.str('id')}/take', extra: a.flag('requires_code')),
                                child: Text(s.t(a.str('open_attempt_id').isNotEmpty ? 'assess.resume' : 'assess.start')),
                              )
                            : Padding(padding: const EdgeInsets.only(top: 12), child: Text(s.t('assess.closed'))),
                      ]),
                      if (a.flag('open') && a.flag('face_check'))
                        Padding(
                          padding: const EdgeInsets.only(top: 10),
                          child: Container(
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .5), borderRadius: BorderRadius.circular(14)),
                            child: Text(s.t(a.str('face_fallback') == 'flagged' ? 'assess.faceComputerFlagged' : 'assess.faceComputer'), style: const TextStyle(fontSize: 12.5, height: 1.5)),
                          ),
                        ),
                      if (a.flag('adaptive'))
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Row(children: [Icon(Icons.auto_graph_rounded, size: 16, color: AppColors.gold700), const SizedBox(width: 6), Text(s.t('adaptive.title'), style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: AppColors.gold700))]),
                        ),
                      if (offline && a.flag('allow_offline')) _OfflineAction(assessment: a, package: packages.where((p) => p.assessmentId == a.str('id') && (p.open || p.waiting)).lastOrNull),
                    ]),
                  ),
                ),
            ]);
          },
        ),
      ),
    );
  }
}

/// Taking the assessment without a connection: download its package, take it, or hand it in.
class _OfflineAction extends ConsumerStatefulWidget {
  const _OfflineAction({required this.assessment, required this.package});

  final Json assessment;
  final OfflineExam? package;

  @override
  ConsumerState<_OfflineAction> createState() => _OfflineActionState();
}

class _OfflineActionState extends ConsumerState<_OfflineAction> {
  bool _busy = false;

  Future<void> _download() async {
    setState(() => _busy = true);
    try {
      final e = await ref.read(offlineExamsProvider).download(widget.assessment.str('id'));
      if (!mounted) return;
      ref.invalidate(offlineExamListProvider);
      showSnack(context, context.s.t('oexam.downloaded').replaceAll('{date}', Fmt(context.s.languageCode).dateTime(e.expiresAt?.toLocal())));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final p = widget.package;
    final fmt = Fmt(s.languageCode);
    return Padding(
      padding: const EdgeInsets.only(top: 10),
      child: Container(
        padding: const EdgeInsetsDirectional.fromSTEB(12, 6, 6, 6),
        decoration: BoxDecoration(color: AppColors.gold100.withValues(alpha: .55), borderRadius: BorderRadius.circular(14)),
        child: Row(children: [
          Icon(p == null ? Icons.download_for_offline_outlined : p.waiting ? Icons.cloud_upload_outlined : Icons.offline_pin_rounded, size: 20, color: AppColors.gold700),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              p == null
                  ? s.t('oexam.offlineHint').replaceAll('{hours}', s.count('count.hours', widget.assessment.number('offline_window_hours').toInt()))
                  : p.waiting
                      ? s.t('oexam.waiting')
                      : s.t('oexam.until').replaceAll('{date}', fmt.dateTime(p.expiresAt?.toLocal())),
              style: const TextStyle(fontSize: 12.5, height: 1.4),
            ),
          ),
          if (_busy)
            const Padding(padding: EdgeInsets.all(12), child: SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2.2)))
          else if (p == null)
            TextButton(onPressed: _download, child: Text(s.t('oexam.download')))
          else if (p.waiting)
            TextButton(onPressed: () => handInOfflineExam(context, ref, p), child: Text(s.t('oexam.handIn')))
          else
            TextButton(onPressed: () => context.push('/offline-exams/${p.tokenId}'), child: Text(s.t(p.status == 'started' ? 'oexam.continue' : 'oexam.take'))),
        ]),
      ),
    );
  }
}

/// An exam answered offline that waits for a connection to be handed in.
class _WaitingCard extends ConsumerWidget {
  const _WaitingCard({required this.exam});

  final OfflineExam exam;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    return Card(
      color: AppColors.navy100.withValues(alpha: .45),
      child: ListTile(
        leading: Icon(Icons.cloud_upload_outlined, color: AppColors.navy800),
        title: Text(exam.title(s.isArabic), style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(s.t('oexam.waiting')),
        trailing: TextButton(onPressed: () => handInOfflineExam(context, ref, exam), child: Text(s.t('oexam.handIn'))),
      ),
    );
  }
}

/// Hands in an exam answered offline and opens its result; says plainly what happened otherwise.
Future<void> handInOfflineExam(BuildContext context, WidgetRef ref, OfflineExam exam) async {
  final s = context.s;
  final exams = ref.read(offlineExamsProvider);
  try {
    final r = await exams.submit(exam.tokenId);
    if (!context.mounted) return;
    ref.invalidate(offlineExamListProvider);
    ref.invalidate(getProvider(AssessmentsScreen.path));
    context.push('/attempts/${r['id']}');
  } on ApiException catch (e) {
    if (!context.mounted) return;
    ref.invalidate(offlineExamListProvider);
    showSnack(context, e.code == 'network' ? s.t('oexam.stillOffline') : s.t('oexam.refused').replaceAll('{reason}', s.t('oexam.reason.${e.code ?? 'refused'}')), error: true);
  } catch (e) {
    if (context.mounted) showSnack(context, e.toString(), error: true);
  }
}

/// The exam: server-owned timer, autosave every few seconds, question navigator, review before submitting.
class ExamScreen extends ConsumerStatefulWidget {
  const ExamScreen({super.key, required this.id, this.needsCode = false});

  final String id;
  final bool needsCode;

  @override
  ConsumerState<ExamScreen> createState() => _ExamScreenState();
}

class _ExamScreenState extends ConsumerState<ExamScreen> with WidgetsBindingObserver {
  Json? _attempt;
  String? _error;
  final Map<String, dynamic> _answers = {};
  int _index = 0;
  int? _left;
  bool _dirty = false;
  bool _finished = false;
  Timer? _tick;
  Timer? _save;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) => _begin());
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _tick?.cancel();
    _save?.cancel();
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.paused) {
      _flush();
      _event('blur');
    }
  }

  Future<void> _begin({String? code}) async {
    String? accessCode = code;
    if (widget.needsCode && accessCode == null) {
      accessCode = await _askCode();
      if (accessCode == null) {
        if (mounted) context.pop();
        return;
      }
    }
    try {
      final res = await ref.read(apiProvider).post('/me/assessments/${widget.id}/start', {'access_code': ?accessCode});
      final a = Map<String, dynamic>.from((res as Map)['data'] as Map);
      final saved = a.obj('answers') ?? {};
      if (!mounted) return;
      setState(() {
        _attempt = a;
        _answers
          ..clear()
          ..addAll(saved);
        _left = (a['remaining_seconds'] as num?)?.toInt();
      });
      _tick = Timer.periodic(const Duration(seconds: 1), (_) {
        if (_left == null || _finished) return;
        setState(() => _left = (_left! - 1).clamp(0, 1 << 30));
        if (_left == 0) _submit();
      });
      _save = Timer.periodic(const Duration(seconds: 6), (_) => _flush());
    } catch (e) {
      if (mounted) setState(() => _error = e.toString());
    }
  }

  Future<String?> _askCode() {
    final c = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.tr('assess.code')),
        content: TextField(controller: c, autofocus: true, textDirection: TextDirection.ltr),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: Text(ctx.tr('common.cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, c.text.trim()), child: Text(ctx.tr('assess.start'))),
        ],
      ),
    );
  }

  Future<void> _flush() async {
    if (!_dirty || _finished || _attempt == null) return;
    _dirty = false;
    try {
      await ref.read(apiProvider).put('/me/attempts/${_attempt!.str('id')}/answers', {'answers': _answers});
    } catch (_) {
      _dirty = true;
    }
  }

  Future<void> _event(String type) async {
    if (_attempt == null || _finished) return;
    try {
      await ref.read(apiProvider).post('/me/attempts/${_attempt!.str('id')}/events', {'type': type});
    } catch (_) {}
  }

  Future<void> _submit() async {
    if (_finished || _attempt == null) return;
    _finished = true;
    try {
      _dirty = true;
      await ref.read(apiProvider).put('/me/attempts/${_attempt!.str('id')}/answers', {'answers': _answers}).catchError((_) => null);
      await ref.read(apiProvider).post('/me/attempts/${_attempt!.str('id')}/submit');
      if (mounted) context.pushReplacement('/attempts/${_attempt!.str('id')}');
    } catch (e) {
      _finished = false;
      if (mounted) showSnack(context, e.toString(), error: true);
    }
  }

  void _set(String id, dynamic v) {
    setState(() => _answers[id] = v);
    _dirty = true;
  }

  String _clock(int s) => '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    if (_error != null) return Scaffold(appBar: AppBar(), body: Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!))));
    if (_attempt == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    final qs = _attempt!.list('questions');
    if (qs.isEmpty) return Scaffold(appBar: AppBar(), body: Center(child: Text(s.t('assess.empty'))));
    final timer = _left == null ? null : Padding(padding: const EdgeInsetsDirectional.only(end: 16), child: Center(child: Text(_clock(_left!), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18))));
    if (_attempt!.obj('adaptive') != null) {
      return Scaffold(
        backgroundColor: AppColors.ivory,
        appBar: AppBar(title: Text(s.t('adaptive.title')), actions: [?timer]),
        body: AdaptivePractice(attempt: _attempt!, onDraft: _set, onFinish: _submit),
      );
    }
    final q = qs[_index];
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(
        title: Text(s.t('assess.question').replaceAll('{n}', '${_index + 1}').replaceAll('{total}', '${qs.length}')),
        actions: [?timer],
      ),
      body: Column(children: [
        Expanded(child: ListView(padding: const EdgeInsets.all(20), children: [QuestionView(q: q, answer: _answers[q.str('id')], onChanged: (v) => _set(q.str('id'), v))])),
        SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
            child: Row(children: [
              OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: _index > 0 ? () => setState(() => _index--) : null, child: Text(s.t('assess.prev'))),
              const SizedBox(width: 8),
              OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: _index < qs.length - 1 ? () => setState(() => _index++) : null, child: Text(s.t('assess.next'))),
              const Spacer(),
              FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: () => _confirm(qs), child: Text(s.t('assess.submit'))),
            ]),
          ),
        ),
      ]),
    );
  }

  Future<void> _confirm(List<Json> qs) async {
    final pending = qs.where((q) => _answers[q.str('id')] == null || _answers[q.str('id')] == '').length;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.tr('assess.submit')),
        content: Text('${ctx.tr('assess.confirm')}${pending > 0 ? '\n\n${ctx.tr('assess.unanswered').replaceAll('{n}', '$pending')}' : ''}'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(ctx.tr('common.cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(ctx.tr('assess.submit'))),
        ],
      ),
    );
    if (ok == true) _submit();
  }
}

/// The result of an attempt, as far as the feedback rules allow.
class AttemptResultScreen extends ConsumerWidget {
  const AttemptResultScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final res = ref.watch(getProvider('/me/attempts/$id/result'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('assess.result'))),
      body: AsyncView(
        value: res,
        onRetry: () => ref.invalidate(getProvider('/me/attempts/$id/result')),
        builder: (raw) {
          final r = Map<String, dynamic>.from((raw as Map)['data'] as Map);
          final score = r['score_percent'];
          final passed = r['passed'];
          return ListView(padding: const EdgeInsets.all(24), children: [
            if (r.str('status') == 'grading')
              Center(child: Text(s.t('assess.waiting'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)))
            else if (score != null)
              Center(child: Text('$score%', style: const TextStyle(fontSize: 48, fontWeight: FontWeight.w800)))
            else
              Center(child: Text(s.t('assess.hidden'))),
            if (passed != null) Center(child: Padding(padding: const EdgeInsets.all(8), child: Text(s.t(passed == true ? 'assess.passed' : 'assess.failed'), style: TextStyle(fontWeight: FontWeight.w700, color: passed == true ? Colors.green : Colors.red)))),
            if (r.str('feedback').isNotEmpty) Card(child: Padding(padding: const EdgeInsets.all(12), child: Text(r.str('feedback')))),
          ]);
        },
      ),
    );
  }
}
