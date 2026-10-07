import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The trainee's assessments: what is open, and the way into the exam.
class AssessmentsScreen extends ConsumerWidget {
  const AssessmentsScreen({super.key});

  static const path = '/me/assessments';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final list = ref.watch(getProvider(path));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('assess.title'))),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(getProvider(path));
          await ref.read(getProvider(path).future);
        },
        child: AsyncView(
          value: list,
          onRetry: () => ref.invalidate(getProvider(path)),
          builder: (raw) {
            final rows = Map<String, dynamic>.from(raw as Map).list('data');
            if (rows.isEmpty) return ListView(children: [Padding(padding: const EdgeInsets.all(40), child: Center(child: Text(s.t('assess.empty'))))]);
            return ListView(padding: const EdgeInsets.all(20), children: [
              for (final a in rows)
                Card(
                  child: ListTile(
                    title: Text(a.str(ar ? 'title_ar' : 'title_en'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${a.str('program')}\n${s.t('assess.attempts')} ${a.str('attempts_used')}/${a.str('max_attempts')}'
                        '${a['best_score'] != null ? '  •  ${s.t('assess.best')} ${a.str('best_score')}%' : ''}'),
                    isThreeLine: true,
                    trailing: a.flag('open')
                        ? FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 48)), 
                            onPressed: () => context.push('/assessments/${a.str('id')}/take', extra: a.flag('requires_code')),
                            child: Text(s.t(a.str('open_attempt_id').isNotEmpty ? 'assess.resume' : 'assess.start')),
                          )
                        : Text(s.t('assess.closed')),
                  ),
                ),
            ]);
          },
        ),
      ),
    );
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
    final q = qs[_index];
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(
        title: Text(s.t('assess.question').replaceAll('{n}', '${_index + 1}').replaceAll('{total}', '${qs.length}')),
        actions: [if (_left != null) Padding(padding: const EdgeInsetsDirectional.only(end: 16), child: Center(child: Text(_clock(_left!), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 18))))],
      ),
      body: Column(children: [
        Expanded(child: ListView(padding: const EdgeInsets.all(20), children: [_question(q)])),
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

  Widget _question(Json q) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final id = q.str('id');
    final p = q.obj('payload') ?? {};
    final stem = q.str(ar ? 'stem_ar' : 'stem_en').isNotEmpty ? q.str(ar ? 'stem_ar' : 'stem_en') : q.str('stem_ar');
    final head = Padding(padding: const EdgeInsets.only(bottom: 16), child: Text(stem, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, height: 1.6)));
    switch (q.str('type')) {
      case 'single_choice':
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          head,
          RadioGroup<String>(
            groupValue: _answers[id] as String?,
            onChanged: (v) => _set(id, v),
            child: Column(children: [
              for (final o in p.list('options')) RadioListTile<String>(value: o.str('id'), title: Text(o.str(ar ? 'text_ar' : 'text_en').isNotEmpty ? o.str(ar ? 'text_ar' : 'text_en') : o.str('text_ar'))),
            ]),
          ),
        ]);
      case 'multiple_select':
        final cur = List<String>.from((_answers[id] as List?) ?? const []);
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          head,
          for (final o in p.list('options'))
            CheckboxListTile(
              value: cur.contains(o.str('id')),
              title: Text(o.str(ar ? 'text_ar' : 'text_en').isNotEmpty ? o.str(ar ? 'text_ar' : 'text_en') : o.str('text_ar')),
              onChanged: (v) => _set(id, v == true ? [...cur, o.str('id')] : cur.where((x) => x != o.str('id')).toList()),
            ),
        ]);
      case 'true_false':
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          head,
          Row(children: [
            for (final v in [true, false])
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.all(4),
                  child: _answers[id] == v
                      ? FilledButton(onPressed: () => _set(id, v), child: Text(s.t(v ? 'assess.true' : 'assess.false')))
                      : OutlinedButton(onPressed: () => _set(id, v), child: Text(s.t(v ? 'assess.true' : 'assess.false'))),
                ),
              ),
          ]),
        ]);
      case 'short_answer':
      case 'numeric':
      case 'essay':
        final multi = q.str('type') == 'essay';
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          head,
          TextFormField(
            key: ValueKey(id),
            initialValue: _answers[id]?.toString() ?? '',
            minLines: multi ? 6 : 1,
            maxLines: multi ? 12 : 1,
            keyboardType: q.str('type') == 'numeric' ? const TextInputType.numberWithOptions(decimal: true, signed: true) : TextInputType.multiline,
            decoration: const InputDecoration(border: OutlineInputBorder()),
            onChanged: (v) => _set(id, v),
          ),
        ]);
      default:
        // Drag, matrix and hotspot interactions are completed on the web; the trainee is told clearly instead of seeing a broken question.
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [head, Card(child: Padding(padding: const EdgeInsets.all(16), child: Text(s.t('assess.webOnly'))))]);
    }
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
