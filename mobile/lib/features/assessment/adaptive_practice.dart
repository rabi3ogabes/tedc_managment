import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'question_view.dart';

/// Adaptive practice: one question at a time, chosen for the learner's level in each competency, with the reason it
/// was chosen. In practice (or with immediate feedback) the learner hears right or wrong after each answer.
class AdaptivePractice extends ConsumerStatefulWidget {
  const AdaptivePractice({super.key, required this.attempt, required this.onDraft, required this.onFinish});

  /// The attempt as `start` returned it (with `adaptive`).
  final Json attempt;
  final void Function(String questionId, dynamic answer) onDraft;
  final Future<void> Function() onFinish;

  @override
  ConsumerState<AdaptivePractice> createState() => _AdaptivePracticeState();
}

class _AdaptivePracticeState extends ConsumerState<AdaptivePractice> {
  Json? _q;
  dynamic _answer;
  String? _level;
  Json? _skill;
  Json? _why;
  late int _answered;
  late int _total;
  late bool _reveal;
  late List<Json> _steps;
  Json? _feedback;
  bool _busy = false;
  bool _done = false;

  @override
  void initState() {
    super.initState();
    final a = widget.attempt.obj('adaptive') ?? {};
    _answered = a.number('answered').toInt();
    _total = a.number('total').toInt();
    _reveal = a.flag('reveal');
    _steps = a.list('steps');
    final current = a.str('current_id');
    _done = current.isEmpty;
    _q = widget.attempt.list('questions').where((q) => q.str('id') == current).firstOrNull;
    _answer = (widget.attempt.obj('answers') ?? {})[current];
    _level = a['level'] as String?;
    _skill = a.obj('skill');
    _why = a.obj('why');
  }

  String _text(Json? v, bool ar) => v == null ? '' : (ar ? v.str('ar') : v.str('en')).isNotEmpty ? (ar ? v.str('ar') : v.str('en')) : v.str('ar');

  Future<void> _check() async {
    final q = _q;
    if (q == null || _busy) return;
    if (!answered(_answer)) {
      showSnack(context, context.s.t('adaptive.answerFirst'));
      return;
    }
    setState(() => _busy = true);
    try {
      final res = await ref.read(apiProvider).post('/me/attempts/${widget.attempt.str('id')}/adaptive-answer', {'question_id': q.str('id'), 'answer': _answer});
      final r = Map<String, dynamic>.from((res as Map)['data'] as Map);
      if (!mounted) return;
      setState(() {
        _busy = false;
        _answered = r.number('answered').toInt();
        _total = r.number('total').toInt();
        if (_steps.isNotEmpty) _steps[_steps.length - 1] = {..._steps.last, 'answered': true, 'correct': r['correct']};
        if (_reveal) {
          _feedback = r;
        } else {
          _move(r);
        }
      });
      if (!_reveal) showSnack(context, context.s.t('adaptive.saved'));
    } catch (e) {
      if (!mounted) return;
      setState(() => _busy = false);
      showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  void _move(Json r) {
    final next = r.obj('next');
    _feedback = null;
    _answer = null;
    if (next == null || r.flag('done')) {
      _done = true;
      _q = null;
      return;
    }
    _q = next;
    _level = r['level'] as String?;
    _skill = r.obj('skill');
    _why = r.obj('why');
    _steps = [..._steps, {'level': _level, 'answered': false, 'correct': null}];
  }

  Future<void> _finish() async {
    if (!_done) {
      final s = context.s;
      final ok = await showDialog<bool>(
        context: context,
        builder: (ctx) => AlertDialog(
          title: Text(s.t('adaptive.finish')),
          content: Text(s.t('adaptive.finishConfirm')),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(s.t('adaptive.keepGoing'))),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(s.t('adaptive.finish'))),
          ],
        ),
      );
      if (ok != true) return;
    }
    await widget.onFinish();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final ar = s.isArabic;
    final q = _q;
    final f = _feedback;
    return Column(children: [
      Expanded(
        child: ListView(padding: const EdgeInsets.fromLTRB(20, 12, 20, 20), children: [
          _PathStrip(steps: _steps, total: _total),
          Padding(
            padding: const EdgeInsets.only(top: 6, bottom: 14),
            child: Text(s.t('adaptive.answered').replaceAll('{n}', '$_answered').replaceAll('{total}', '$_total'), style: const TextStyle(fontSize: 12.5, color: AppColors.muted, fontWeight: FontWeight.w600)),
          ),
          if (q == null)
            _DoneCard(text: s.t('adaptive.done'))
          else ...[
            Wrap(spacing: 8, runSpacing: 8, children: [
              if (_level != null) _Chip(icon: Icons.signal_cellular_alt_rounded, text: s.t('adaptive.level').replaceAll('{level}', s.t('adaptive.levels.$_level')), gold: true),
              if (_text(_skill, ar).isNotEmpty) _Chip(icon: Icons.track_changes_rounded, text: _text(_skill, ar)),
            ]),
            if (_text(_why, ar).isNotEmpty)
              Theme(
                data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
                child: ExpansionTile(
                  tilePadding: EdgeInsets.zero,
                  childrenPadding: const EdgeInsetsDirectional.only(bottom: 8),
                  expandedCrossAxisAlignment: CrossAxisAlignment.start,
                  leading: Icon(Icons.lightbulb_outline_rounded, color: AppColors.gold700),
                  title: Text(s.t('adaptive.why'), style: TextStyle(fontWeight: FontWeight.w700, fontSize: 14, color: AppColors.navy900)),
                  children: [Text(_text(_why, ar), style: const TextStyle(height: 1.6, color: AppColors.ink))],
                ),
              ),
            const SizedBox(height: 8),
            QuestionView(
              key: ValueKey(q.str('id')),
              q: q,
              answer: _answer,
              enabled: f == null && !_busy,
              onChanged: (v) {
                setState(() => _answer = v);
                widget.onDraft(q.str('id'), v);
              },
            ),
            if (f != null) _Feedback(result: f, ar: ar),
          ],
        ]),
      ),
      SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
          child: Row(children: [
            TextButton(onPressed: _busy ? null : _finish, child: Text(s.t(_done ? 'adaptive.seeResult' : 'adaptive.finish'))),
            const Spacer(),
            if (q != null)
              FilledButton(
                style: FilledButton.styleFrom(minimumSize: const Size(0, 48)),
                onPressed: _busy ? null : (f != null ? () => setState(() => _move(f)) : _check),
                child: _busy
                    ? const SizedBox.square(dimension: 20, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
                    : Text(s.t(f != null ? (f.flag('done') ? 'adaptive.seeResult' : 'adaptive.next') : (_reveal ? 'adaptive.check' : 'adaptive.next'))),
              )
            else
              FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: _busy ? null : _finish, child: Text(s.t('adaptive.seeResult'))),
          ]),
        ),
      ),
    ]);
  }
}

class _Chip extends StatelessWidget {
  const _Chip({required this.icon, required this.text, this.gold = false});

  final IconData icon;
  final String text;
  final bool gold;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(color: gold ? AppColors.gold100 : AppColors.navy100.withValues(alpha: .5), borderRadius: BorderRadius.circular(99), border: Border.all(color: gold ? AppColors.gold300 : AppColors.navy100)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 16, color: gold ? AppColors.gold700 : AppColors.navy800),
          const SizedBox(width: 6),
          Flexible(child: Text(text, style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: gold ? AppColors.gold700 : AppColors.navy900))),
        ]),
      );
}

/// One dot per question: its height is the level, its colour the result (when it may be shown).
class _PathStrip extends StatelessWidget {
  const _PathStrip({required this.steps, required this.total});

  final List<Json> steps;
  final int total;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    const lift = {'easy': 14.0, 'medium': 7.0, 'hard': 0.0};
    return Semantics(
      label: s.t('adaptive.path'),
      child: SizedBox(
        height: 30,
        child: Row(children: [
          for (var i = 0; i < total; i++)
            Expanded(
              child: Align(
                alignment: Alignment.topCenter,
                child: Padding(
                  padding: EdgeInsets.only(top: i < steps.length ? lift[steps[i].str('level')] ?? 7 : 7),
                  child: Container(
                    width: 12,
                    height: 12,
                    decoration: BoxDecoration(
                      shape: BoxShape.circle,
                      color: i >= steps.length
                          ? Colors.transparent
                          : steps[i]['correct'] == true
                              ? AppColors.success
                              : steps[i]['correct'] == false
                                  ? AppColors.danger
                                  : steps[i].flag('answered')
                                      ? AppColors.gold500
                                      : Colors.white,
                      border: Border.all(color: i >= steps.length ? AppColors.navy100 : AppColors.navy800.withValues(alpha: .45), width: 2),
                    ),
                  ),
                ),
              ),
            ),
        ]),
      ),
    );
  }
}

class _Feedback extends StatelessWidget {
  const _Feedback({required this.result, required this.ar});

  final Json result;
  final bool ar;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final correct = result['correct'];
    final explanation = result.obj('explanation');
    final text = explanation == null ? '' : (ar ? explanation.str('ar') : explanation.str('en')).isNotEmpty ? (ar ? explanation.str('ar') : explanation.str('en')) : explanation.str('ar');
    final good = correct == true;
    final graded = result.flag('graded');
    return Container(
      margin: const EdgeInsets.only(top: 16),
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: !graded ? AppColors.gold100 : good ? const Color(0xFFE7F6EF) : const Color(0xFFFDECEC),
        borderRadius: BorderRadius.circular(16),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(!graded ? Icons.schedule_rounded : good ? Icons.check_circle_rounded : Icons.cancel_rounded, color: !graded ? AppColors.gold700 : good ? AppColors.success : AppColors.danger),
          const SizedBox(width: 8),
          Text(s.t(!graded ? 'adaptive.pending' : good ? 'adaptive.correct' : 'adaptive.notQuite'), style: TextStyle(fontWeight: FontWeight.w800, color: !graded ? AppColors.gold700 : good ? AppColors.success : AppColors.danger)),
        ]),
        if (text.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(text, style: const TextStyle(height: 1.6, color: AppColors.ink))),
      ]),
    );
  }
}

class _DoneCard extends StatelessWidget {
  const _DoneCard({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(20),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.navy100)),
        child: Row(children: [
          Icon(Icons.flag_circle_rounded, color: AppColors.gold500, size: 32),
          const SizedBox(width: 12),
          Expanded(child: Text(text, style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15, color: AppColors.navy900))),
        ]),
      );
}
