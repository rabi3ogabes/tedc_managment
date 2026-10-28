import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The trainee form (docs/saber/08-centre-forms.md): the centre's questions — the trainer, the content, the environment, the expected
/// impact, an overall rating and the trainee's words — with what the old form asked first (programme, group, trainer) already filled in.
/// Same form, rules and payload as the web portal (web/src/pages/portal/TraineeEvaluation.tsx).
class TraineeEvaluationScreen extends ConsumerStatefulWidget {
  const TraineeEvaluationScreen({super.key, required this.registrationId});

  final String registrationId;

  @override
  ConsumerState<TraineeEvaluationScreen> createState() => _TraineeEvaluationScreenState();
}

class _TraineeEvaluationScreenState extends ConsumerState<TraineeEvaluationScreen> {
  final Map<String, dynamic> _answers = {};
  final Map<String, TextEditingController> _texts = {};
  final Map<String, GlobalKey> _keys = {};
  Set<String> _flagged = {};
  bool _introOpen = false;
  bool _allow = false;
  bool _busy = false;

  String get _path => '/me/registrations/${widget.registrationId}/evaluation';

  @override
  void dispose() {
    for (final c in _texts.values) {
      c.dispose();
    }
    super.dispose();
  }

  TextEditingController _text(String id) => _texts.putIfAbsent(id, () => TextEditingController());

  dynamic _answer(Json q) => _answers[q.str('id')];

  /// Conditional logic, as the server and the web form apply it.
  bool _visible(Json q) {
    final rule = q.obj('show_if');
    if (rule == null || rule.str('question').isEmpty) return true;
    final a = _answers[rule.str('question')];
    final value = rule['value']?.toString() ?? '';
    final matches = a is List ? a.map((e) => '$e').contains(value) : '${a ?? ''}' == value;
    return switch (rule.str('op')) {
      'answered' => _filled(a),
      'not_equals' => _filled(a) && !matches,
      'gte' => a is num && a >= (num.tryParse(value) ?? 0),
      'lte' => a is num && a <= (num.tryParse(value) ?? 0),
      _ => matches,
    };
  }

  bool _missing(Json q) {
    if (!q.flag('required') || !_answerable(q)) return false;
    final a = _answer(q);
    if (q.str('type') == 'matrix') return a is! Map || a.length < q.list('rows').length;
    return !_filled(a);
  }

  List<Json> _shown(List<Json> questions) => questions.where(_visible).toList();

  Future<void> _submit(List<Json> questions) async {
    final shown = _shown(questions);
    final gaps = shown.where(_missing).map((q) => q.str('id')).toList();
    if (gaps.isNotEmpty) {
      HapticFeedback.mediumImpact();
      setState(() => _flagged = gaps.toSet());
      final target = _keys[gaps.first]?.currentContext;
      if (target != null) Scrollable.ensureVisible(target, duration: const Duration(milliseconds: 450), curve: Curves.easeOutCubic, alignment: .15);
      showSnack(context, context.tr('eval.missing').replaceAll('{n}', '${gaps.length}'), error: true);
      return;
    }

    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post(_path, {
        'answers': {
          for (final q in shown)
            if (_answerable(q) && _filled(_answer(q))) q.str('id'): _answer(q),
        },
        'allow_testimonial': _allow,
      });
      ref.invalidate(getProvider(_path));
      ref.invalidate(getProvider('/me/registrations/${widget.registrationId}'));
      ref.invalidate(getProvider('/me/registrations'));
      ref.invalidate(getProvider('/me/home'));
      if (mounted) {
        HapticFeedback.lightImpact();
        showSnack(context, context.tr('eval.thanks'));
        context.pop();
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
    final value = ref.watch(getProvider(_path));
    final title = value.value is Map ? Map<String, dynamic>.from(value.value as Map).obj('data')?.str('title') : null;

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(title?.isNotEmpty == true ? title! : s.t('eval.title'), maxLines: 2, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(_path)),
        builder: (raw) {
          final f = Map<String, dynamic>.from((raw as Map)['data'] as Map);
          if (f.flag('submitted') || !f.flag('open')) return _Done(submitted: f.flag('submitted'));

          final questions = f.list('questions');
          final shown = _shown(questions);
          final required = shown.where((q) => q.flag('required') && _answerable(q)).toList();
          final done = required.where((q) => !_missing(q)).length;
          var n = 0;

          return Column(children: [
            _Progress(done: done, total: required.length),
            Expanded(
              // Not a lazy list: every card stays built, so a submit with gaps can scroll to the first one.
              child: SingleChildScrollView(
                padding: EdgeInsets.fromLTRB(16, 4, 16, 24 + MediaQuery.paddingOf(context).bottom),
                keyboardDismissBehavior: ScrollViewKeyboardDismissBehavior.onDrag,
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  _ContextChips(info: f.obj('context') ?? const {}),
                  if (f.str('intro').isNotEmpty) ...[
                    const SizedBox(height: 14),
                    _Intro(text: f.str('intro'), open: _introOpen, onToggle: () => setState(() => _introOpen = !_introOpen)),
                  ],
                  const SizedBox(height: 6),
                  for (final q in shown)
                    if (q.str('type') == 'section')
                      Padding(
                        padding: const EdgeInsets.fromLTRB(4, 18, 4, 0),
                        child: Text(_title(context, q), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy900)),
                      )
                    else if (_answerable(q))
                      _QuestionCard(
                        key: _keys.putIfAbsent(q.str('id'), GlobalKey.new),
                        number: ++n,
                        question: q,
                        flagged: _flagged.contains(q.str('id')) && _missing(q),
                        answer: _answer(q),
                        controller: _isText(q) ? _text(q.str('id')) : null,
                        onChanged: (v) => setState(() => _answers[q.str('id')] = v),
                      ),
                  const SizedBox(height: 14),
                  Material(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(18),
                    child: CheckboxListTile(
                      value: _allow,
                      onChanged: (v) => setState(() => _allow = v ?? false),
                      title: Text(s.t('eval.testimonial'), style: const TextStyle(fontSize: 13, height: 1.5)),
                      activeColor: AppColors.navy900,
                      controlAffinity: ListTileControlAffinity.leading,
                      contentPadding: const EdgeInsetsDirectional.only(start: 6, end: 14),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18), side: BorderSide(color: AppColors.navy100)),
                    ),
                  ),
                  const SizedBox(height: 18),
                  FilledButton(
                    onPressed: _busy ? null : () => _submit(questions),
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(56), backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18))),
                    child: _busy
                        ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2))
                        : Text(s.t('common.submit'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                  ),
                ]),
              ),
            ),
          ]);
        },
      ),
    );
  }
}

bool _filled(dynamic v) => !(v == null || v == '' || (v is List && v.isEmpty) || (v is Map && v.isEmpty));

bool _isText(Json q) => const {'short_text', 'long_text'}.contains(q.str('type'));

/// The question types this screen can answer; anything else is neither shown nor counted.
bool _answerable(Json q) => const {'matrix', 'single', 'dropdown', 'multiple', 'yes_no', 'rating', 'scale', 'short_text', 'long_text'}.contains(q.str('type'));

String _title(BuildContext context, Json q) => !context.s.isArabic && q.str('title_en').isNotEmpty ? q.str('title_en') : q.str('title');

String _label(BuildContext context, Json item) => !context.s.isArabic && item.str('label_en').isNotEmpty ? item.str('label_en') : item.str('label');

/// The scale's points, each with its name (`labels` run from the lowest value up), highest first when `order` is `desc`.
List<(int, String)> _points(Json q) {
  final scale = q.obj('scale') ?? const {};
  final min = scale['min'] is num ? scale.number('min').toInt() : 1;
  final max = scale['max'] is num ? scale.number('max').toInt() : 5;
  final labels = (scale['labels'] as List? ?? const []).map((e) => '$e').toList();
  final points = [for (var v = min; v <= max; v++) (v, v - min < labels.length && labels[v - min].isNotEmpty ? labels[v - min] : '$v')];
  return scale.str('order') == 'desc' ? points.reversed.toList() : points;
}

/// Answered / required, pinned above the form.
class _Progress extends StatelessWidget {
  const _Progress({required this.done, required this.total});

  final int done;
  final int total;

  @override
  Widget build(BuildContext context) {
    final ratio = total == 0 ? 0.0 : done / total;
    final complete = total > 0 && done == total;
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 10, 20, 12),
      decoration: BoxDecoration(color: AppColors.ivory, border: Border(bottom: BorderSide(color: AppColors.navy100.withValues(alpha: .7)))),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          if (complete) ...[const Icon(Icons.check_circle, size: 16, color: AppColors.success), const SizedBox(width: 6)],
          Text(context.tr('eval.progress').replaceAll('{done}', '$done').replaceAll('{total}', '$total'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.muted)),
          const Spacer(),
          Text('${(ratio * 100).round()}%', style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: complete ? AppColors.success : AppColors.navy900, fontFeatures: const [FontFeature.tabularFigures()])),
        ]),
        const SizedBox(height: 8),
        TweenAnimationBuilder<double>(
          tween: Tween(end: ratio * 100),
          duration: const Duration(milliseconds: 350),
          curve: Curves.easeOutCubic,
          builder: (_, v, _) => ProgressBar(v, color: complete ? AppColors.success : AppColors.gold500),
        ),
      ]),
    );
  }
}

/// What the Microsoft form asked first — programme, group, trainer, dates — already known.
class _ContextChips extends StatelessWidget {
  const _ContextChips({required this.info});

  final Json info;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final trainers = (info['trainers'] as List? ?? const []).map((e) => '$e').where((e) => e.isNotEmpty).toList();
    final from = info.date('from');
    final to = info.date('to');
    final dates = from == null ? '' : (to != null && to != from ? '${fmt.date(from)} – ${fmt.date(to)}' : fmt.date(from));

    return Wrap(spacing: 8, runSpacing: 8, children: [
      if (info.str('program').isNotEmpty) _Chip(icon: Icons.school_outlined, text: info.str('program')),
      if (info.str('group').isNotEmpty) _Chip(icon: Icons.groups_2_outlined, text: info.str('group')),
      if (trainers.isNotEmpty) _Chip(icon: Icons.co_present_outlined, text: '${s.t('eval.trainer')}: ${trainers.join(s.isArabic ? '، ' : ', ')}'),
      if (dates.isNotEmpty) _Chip(icon: Icons.event_outlined, text: dates),
    ]);
  }
}

class _Chip extends StatelessWidget {
  const _Chip({required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(99), border: Border.all(color: AppColors.navy100)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 15, color: AppColors.gold700),
        const SizedBox(width: 6),
        Flexible(child: Text(text, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.navy800))),
      ]),
    );
  }
}

/// The centre's welcome message: three lines, the rest on demand.
class _Intro extends StatelessWidget {
  const _Intro({required this.text, required this.open, required this.onToggle});

  final String text;
  final bool open;
  final VoidCallback onToggle;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onToggle,
        borderRadius: BorderRadius.circular(22),
        child: Ink(
          padding: const EdgeInsets.fromLTRB(18, 16, 18, 10),
          decoration: BoxDecoration(
            gradient: LinearGradient(begin: AlignmentDirectional.topStart, end: AlignmentDirectional.bottomEnd, colors: [AppColors.gold100, AppColors.ivory]),
            borderRadius: BorderRadius.circular(22),
            border: Border.all(color: AppColors.gold300.withValues(alpha: .6)),
          ),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            AnimatedSize(
              duration: const Duration(milliseconds: 280),
              curve: Curves.easeOutCubic,
              alignment: AlignmentDirectional.topStart,
              child: Text(text, maxLines: open ? null : 3, overflow: open ? TextOverflow.visible : TextOverflow.ellipsis, style: TextStyle(fontSize: 14, height: 1.85, color: AppColors.navy800)),
            ),
            const SizedBox(height: 4),
            Row(children: [
              Text(context.tr(open ? 'eval.less' : 'eval.more'), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: AppColors.gold700)),
              const SizedBox(width: 2),
              AnimatedRotation(turns: open ? .5 : 0, duration: const Duration(milliseconds: 250), child: Icon(Icons.expand_more, size: 18, color: AppColors.gold700)),
            ]),
          ]),
        ),
      ),
    );
  }
}

/// One question: a numbered card, outlined in red while a required answer is missing after a submit.
class _QuestionCard extends StatelessWidget {
  const _QuestionCard({super.key, required this.number, required this.question, required this.flagged, required this.answer, required this.controller, required this.onChanged});

  final int number;
  final Json question;
  final bool flagged;
  final dynamic answer;
  final TextEditingController? controller;
  final ValueChanged<dynamic> onChanged;

  @override
  Widget build(BuildContext context) {
    final q = question;
    final rows = q.list('rows');
    final rowsDone = answer is Map ? (answer as Map).length : 0;

    return AnimatedContainer(
      duration: const Duration(milliseconds: 220),
      margin: const EdgeInsets.only(top: 14),
      padding: const EdgeInsets.fromLTRB(16, 16, 16, 18),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        border: Border.all(color: flagged ? AppColors.danger : AppColors.navy100, width: flagged ? 1.6 : 1),
        boxShadow: [BoxShadow(color: (flagged ? AppColors.danger : AppColors.navy900).withValues(alpha: flagged ? .10 : .04), blurRadius: 18, offset: const Offset(0, 6))],
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            width: 26,
            height: 26,
            alignment: Alignment.center,
            decoration: BoxDecoration(color: flagged ? AppColors.danger : AppColors.navy900, shape: BoxShape.circle),
            child: Text('$number', style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w800)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text.rich(
              TextSpan(text: _title(context, q), children: [if (q.flag('required')) const TextSpan(text: ' *', style: TextStyle(color: AppColors.danger))]),
              style: TextStyle(fontSize: 15, height: 1.5, fontWeight: FontWeight.w800, color: AppColors.navy900),
            ),
          ),
          if (q.str('type') == 'matrix' && rows.isNotEmpty) ...[
            const SizedBox(width: 8),
            _RowsCounter(done: rowsDone, total: rows.length),
          ],
        ]),
        if (q.str('description').isNotEmpty) ...[
          const SizedBox(height: 6),
          Text(q.str('description'), style: const TextStyle(fontSize: 12.5, height: 1.6, color: AppColors.muted)),
        ],
        const SizedBox(height: 14),
        _input(context),
      ]),
    );
  }

  Widget _input(BuildContext context) {
    final q = question;
    switch (q.str('type')) {
      case 'matrix':
        final points = _points(q);
        final picked = answer is Map ? Map<String, dynamic>.from(answer as Map) : <String, dynamic>{};
        final rows = q.list('rows');
        return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          for (var i = 0; i < rows.length; i++) ...[
            if (i > 0) Divider(height: 26, color: AppColors.navy100.withValues(alpha: .8)),
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Padding(
                padding: const EdgeInsets.only(top: 7),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  width: 7,
                  height: 7,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    color: picked.containsKey(rows[i].str('id')) ? AppColors.success : (flagged ? AppColors.danger : AppColors.gold300),
                  ),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  _label(context, rows[i]),
                  style: TextStyle(fontSize: 14, height: 1.55, fontWeight: FontWeight.w600, color: flagged && !picked.containsKey(rows[i].str('id')) ? AppColors.danger : AppColors.ink),
                ),
              ),
            ]),
            const SizedBox(height: 10),
            _Choices(
              items: [for (final p in points) (p.$1, p.$2)],
              selected: (v) => picked[rows[i].str('id')] == v,
              alert: flagged && !picked.containsKey(rows[i].str('id')),
              onTap: (v) => onChanged({...picked, rows[i].str('id'): v}),
            ),
          ],
        ]);
      case 'rating':
      case 'scale':
        return _Choices(items: [for (final p in _points(q)) (p.$1, p.$2)], selected: (v) => answer == v, alert: flagged, onTap: onChanged);
      case 'yes_no':
        return _Choices(items: [('yes', context.tr('evalc.yes')), ('no', context.tr('evalc.no'))], selected: (v) => answer == v, alert: flagged, onTap: onChanged);
      case 'multiple':
        final list = answer is List ? List<String>.from((answer as List).map((e) => '$e')) : <String>[];
        return _Choices(
          items: [for (final o in q.list('options')) (o.str('id'), _label(context, o))],
          selected: (v) => list.contains(v),
          alert: flagged,
          wrap: true,
          onTap: (v) => onChanged(list.contains(v) ? (list..remove(v)) : [...list, v as String]),
        );
      case 'short_text':
      case 'long_text':
        final long = q.str('type') == 'long_text';
        return TextField(
          controller: controller,
          minLines: long ? 4 : 1,
          maxLines: long ? 10 : 1,
          maxLength: long ? 5000 : 500,
          textInputAction: long ? TextInputAction.newline : TextInputAction.done,
          onChanged: (v) => onChanged(v.trim()),
          style: const TextStyle(fontSize: 14.5, height: 1.7),
          decoration: InputDecoration(
            hintText: context.tr('eval.answer'),
            counterText: '',
            filled: true,
            fillColor: AppColors.ivory,
            contentPadding: const EdgeInsets.all(14),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: AppColors.navy100)),
            enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: flagged ? AppColors.danger : AppColors.navy100)),
            focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(16), borderSide: BorderSide(color: AppColors.navy900, width: 1.4)),
          ),
        );
      default: // single, dropdown
        final options = q.list('options');
        return _Choices(
          items: [for (final o in options) (o.str('id'), _label(context, o))],
          selected: (v) => answer == v,
          alert: flagged,
          wrap: options.length > 5,
          onTap: onChanged,
        );
    }
  }
}

class _RowsCounter extends StatelessWidget {
  const _RowsCounter({required this.done, required this.total});

  final int done;
  final int total;

  @override
  Widget build(BuildContext context) {
    final complete = done >= total;
    return AnimatedContainer(
      duration: const Duration(milliseconds: 220),
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 4),
      decoration: BoxDecoration(color: complete ? const Color(0xFFE7F6EF) : AppColors.gold100, borderRadius: BorderRadius.circular(99)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        if (complete) ...[const Icon(Icons.check, size: 13, color: AppColors.success), const SizedBox(width: 3)],
        Text(
          context.tr('eval.rows').replaceAll('{done}', '$done').replaceAll('{total}', '$total'),
          style: TextStyle(fontSize: 11, fontWeight: FontWeight.w800, color: complete ? AppColors.success : AppColors.gold700),
        ),
      ]),
    );
  }
}

/// Named choices as large tap targets: one even row for a short scale (أوافق بشدة … غير موافق بشدة), wrapping pills otherwise.
class _Choices extends StatelessWidget {
  const _Choices({required this.items, required this.selected, required this.onTap, this.alert = false, this.wrap = false});

  final List<(Object, String)> items;
  final bool Function(Object value) selected;
  final ValueChanged<Object> onTap;
  final bool alert;
  final bool wrap;

  Widget _tile(BuildContext context, (Object, String) item, {required bool expand}) {
    final on = selected(item.$1);
    return Semantics(
      button: true,
      selected: on,
      label: item.$2,
      excludeSemantics: true,
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: () {
            HapticFeedback.selectionClick();
            onTap(item.$1);
          },
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 180),
            curve: Curves.easeOut,
            constraints: const BoxConstraints(minHeight: 52),
            padding: EdgeInsets.symmetric(horizontal: expand ? 4 : 16, vertical: 8),
            alignment: Alignment.center,
            decoration: BoxDecoration(
              color: on ? AppColors.navy900 : Colors.white,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: on ? AppColors.navy900 : (alert ? AppColors.danger.withValues(alpha: .45) : AppColors.navy100), width: on ? 1.4 : 1),
              boxShadow: on ? [BoxShadow(color: AppColors.navy900.withValues(alpha: .22), blurRadius: 10, offset: const Offset(0, 4))] : null,
            ),
            child: Text(
              item.$2,
              textAlign: TextAlign.center,
              maxLines: expand ? 2 : null,
              overflow: expand ? TextOverflow.ellipsis : null,
              style: TextStyle(fontSize: expand ? 11.5 : 13, height: 1.3, fontWeight: on ? FontWeight.w800 : FontWeight.w600, color: on ? Colors.white : AppColors.ink),
            ),
          ),
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (wrap || items.length > 5) {
      return Wrap(spacing: 8, runSpacing: 8, children: [for (final item in items) _tile(context, item, expand: false)]);
    }
    return IntrinsicHeight(
      child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        for (var i = 0; i < items.length; i++) ...[
          if (i > 0) const SizedBox(width: 6),
          Expanded(child: _tile(context, items[i], expand: true)),
        ],
      ]),
    );
  }
}

/// Already answered, or not open: nothing to fill in.
class _Done extends StatelessWidget {
  const _Done({required this.submitted});

  final bool submitted;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Container(
            width: 84,
            height: 84,
            decoration: BoxDecoration(shape: BoxShape.circle, color: submitted ? const Color(0xFFE7F6EF) : AppColors.gold100),
            child: Icon(submitted ? Icons.verified_rounded : Icons.lock_clock_outlined, size: 42, color: submitted ? AppColors.success : AppColors.gold700),
          ),
          const SizedBox(height: 18),
          Text(context.tr(submitted ? 'eval.already' : 'eval.closed'), textAlign: TextAlign.center, style: TextStyle(fontSize: 16, height: 1.7, fontWeight: FontWeight.w700, color: AppColors.navy900)),
          const SizedBox(height: 22),
          OutlinedButton(onPressed: () => context.pop(), style: OutlinedButton.styleFrom(minimumSize: const Size(200, 50)), child: Text(context.tr('eval.back'))),
        ]),
      ),
    );
  }
}
