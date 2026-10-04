import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

const needsListPath = '/me/needs-surveys';

Color _hex(String? value, [Color fallback = AppColors.navy900]) {
  final v = (value ?? '').replaceAll('#', '');
  return v.length == 6 ? Color(int.parse('FF$v', radix: 16)) : fallback;
}

// ---------------------------------------------------------------------------
// Logic shared with the web form and the server (show_if, required, pages).

bool _filled(Object? v) => !(v == null || (v is String && v.isEmpty) || (v is List && v.isEmpty) || (v is Map && v.isEmpty));

bool isVisible(Json q, Map<String, Object?> answers) {
  final rule = q.obj('show_if');
  if (rule == null || rule.str('question').isEmpty) return true;
  final a = answers[rule.str('question')];
  final value = rule['value']?.toString() ?? '';
  final matches = a is List ? a.map((e) => e.toString()).contains(value) : (a?.toString() ?? '') == value;
  switch (rule.str('op', 'equals')) {
    case 'answered':
      return _filled(a);
    case 'not_equals':
      return _filled(a) && !matches;
    case 'gte':
      return a is num && a >= (num.tryParse(value) ?? 0);
    case 'lte':
      return a is num && a <= (num.tryParse(value) ?? 0);
    default:
      return matches;
  }
}

List<String> missingRequired(List<Json> questions, Map<String, Object?> answers) => [
      for (final q in questions)
        if (q.str('type') != 'section' && q.flag('required') && isVisible(q, answers))
          if (q.str('type') == 'matrix' ? ((answers[q.str('id')] as Map?)?.length ?? 0) < q.list('rows').length : !_filled(answers[q.str('id')])) q.str('id'),
    ];

List<List<Json>> paginate(List<Json> questions, bool onePerPage) {
  final pages = <List<Json>>[[]];
  Json? heading;
  for (final q in questions) {
    if (onePerPage) {
      if (q.str('type') == 'section') {
        heading = q;
        continue;
      }
      pages.add([?heading, q]);
      heading = null;
    } else {
      if (q.str('type') == 'section' && pages.last.any((x) => x.str('type') != 'section')) pages.add([]);
      pages.last.add(q);
    }
  }
  return pages.where((p) => p.isNotEmpty).toList();
}

// ---------------------------------------------------------------------------

/// Surveys addressed to the employee.
class NeedsSurveysScreen extends ConsumerWidget {
  const NeedsSurveysScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    return Scaffold(
      appBar: AppBar(title: Text(s.t('needs.title'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(needsListPath).future),
        child: AsyncView(
          value: ref.watch(getProvider(needsListPath)),
          onRetry: () => ref.invalidate(getProvider(needsListPath)),
          builder: (raw) {
            final items = Map<String, dynamic>.from(raw as Map).list('data');
            if (items.isEmpty) return ListView(children: const [EmptyView(icon: Icons.assignment_outlined)]);
            return ListView(padding: const EdgeInsets.all(16), children: [
              Text(s.t('needs.subtitle'), style: const TextStyle(color: AppColors.muted)),
              const SizedBox(height: 12),
              for (final item in items) Padding(padding: const EdgeInsets.only(bottom: 14), child: NeedsSurveyCard(item: item)),
            ]);
          },
        ),
      ),
    );
  }
}

class NeedsSurveyCard extends StatelessWidget {
  const NeedsSurveyCard({super.key, required this.item});

  final Json item;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final accent = _hex(item['accent'] as String?);
    final done = item['responded_at'] != null;
    final open = item.flag('open');
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(20),
      clipBehavior: Clip.antiAlias,
      elevation: 0,
      child: InkWell(
        onTap: () => context.push('/needs-surveys/${item.str('id')}'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            height: 70,
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(gradient: LinearGradient(colors: [accent, Color.lerp(accent, Colors.black, .45)!])),
            alignment: AlignmentDirectional.topStart,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(color: done ? const Color(0xFF6EE7B7) : (open ? AppColors.gold300 : Colors.white24), borderRadius: BorderRadius.circular(20)),
              child: Text(done ? s.t('needs.done') : (open ? s.t('status.sent') : s.t('needs.closed')), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: AppColors.ink)),
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(item.str('title'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: AppColors.navy900)),
              if (item.str('description').isNotEmpty) ...[
                const SizedBox(height: 6),
                Text(item.str('description'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.muted, height: 1.5)),
              ],
              const SizedBox(height: 10),
              Text(
                '${fmt.number(item.number('questions_count'))} ${s.t('needs.questions')} · ${fmt.number(item.number('estimated_minutes'))} ${s.t('needs.minutes')}'
                '${item.date('closes_at') != null ? ' · ${s.t('needs.closes')} ${fmt.date(item.date('closes_at'))}' : ''}',
                style: const TextStyle(fontSize: 12, color: AppColors.muted),
              ),
              const SizedBox(height: 12),
              FilledButton(
                style: FilledButton.styleFrom(backgroundColor: accent),
                onPressed: () => context.push('/needs-surveys/${item.str('id')}'),
                child: Text(done ? s.t('needs.view') : s.t('needs.answer')),
              ),
            ]),
          ),
        ]),
      ),
    );
  }
}

/// Home banner shown while surveys await an answer.
class PendingNeedsBanner extends ConsumerWidget {
  const PendingNeedsBanner({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final raw = ref.watch(getProvider(needsListPath)).value;
    if (raw == null) return const SizedBox.shrink();
    final pending = Map<String, dynamic>.from(raw as Map).list('data').where((e) => e.flag('open') && e['responded_at'] == null).toList();
    if (pending.isEmpty) return const SizedBox.shrink();
    final s = context.s;
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Material(
        borderRadius: BorderRadius.circular(18),
        clipBehavior: Clip.antiAlias,
        child: Ink(
          decoration: const BoxDecoration(gradient: AppColors.goldGradient),
          child: InkWell(
            onTap: () => context.push(pending.length == 1 ? '/needs-surveys/${pending.first.str('id')}' : '/needs-surveys'),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Row(children: [
                Container(width: 44, height: 44, decoration: BoxDecoration(color: Colors.white24, borderRadius: BorderRadius.circular(12)), child: const Icon(Icons.assignment_outlined, color: Colors.white)),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${pending.length} ${s.t('needs.pending')}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                    Text(pending.first.str('title'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white70, fontSize: 12)),
                  ]),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                  child: Text(s.t('needs.answer'), style: const TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w800, fontSize: 12)),
                ),
              ]),
            ),
          ),
        ),
      ),
    );
  }
}

/// Full-screen survey form.
class NeedsSurveyScreen extends ConsumerStatefulWidget {
  const NeedsSurveyScreen({super.key, required this.id});

  final String id;

  @override
  ConsumerState<NeedsSurveyScreen> createState() => _NeedsSurveyScreenState();
}

enum _Stage { welcome, form, done }

class _NeedsSurveyScreenState extends ConsumerState<NeedsSurveyScreen> {
  _Stage? _stage;
  final Map<String, Object?> _answers = {};
  int _page = 0;
  bool _showErrors = false;
  bool _sending = false;
  String? _error;
  String? _thanks;
  DateTime? _started;
  final _scroll = ScrollController();

  String get _path => '$needsListPath/${widget.id}';

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  void _init(Json survey) {
    if (_stage != null) return;
    final previous = survey.obj('answers');
    if (previous != null) _answers.addAll(previous);
    _stage = previous != null ? _Stage.done : _Stage.welcome;
  }

  Future<void> _next(List<List<Json>> pages, List<Json> all) async {
    final current = pages[_page.clamp(0, pages.length - 1)];
    final missing = missingRequired(current, _answers);
    if (missing.isNotEmpty) {
      setState(() => _showErrors = true);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(context.tr('needs.required'))));
      return;
    }
    if (_page < pages.length - 1) {
      setState(() {
        _page++;
        _showErrors = false;
      });
      _scroll.jumpTo(0);
      return;
    }
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final visible = all.where((q) => isVisible(q, _answers)).map((q) => q.str('id')).toSet();
      final payload = {for (final e in _answers.entries) if (visible.contains(e.key) && e.value != null) e.key: e.value};
      final res = await ref.read(apiProvider).post(_path, {
        'answers': payload,
        'duration_seconds': _started == null ? null : DateTime.now().difference(_started!).inSeconds,
      });
      ref.invalidate(getProvider(needsListPath));
      ref.invalidate(getProvider(_path));
      if (!mounted) return;
      setState(() {
        _thanks = (res as Map)['message']?.toString();
        _stage = _Stage.done;
      });
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: ref.watch(getProvider(_path)).hasValue ? null : AppBar(),
      body: AsyncView(
      value: ref.watch(getProvider(_path)),
      onRetry: () => ref.invalidate(getProvider(_path)),
      builder: (raw) {
        final survey = Map<String, dynamic>.from((raw as Map)['data'] as Map);
        _init(survey);
        final settings = survey.obj('settings') ?? {};
        final accent = _hex(survey['accent'] as String? ?? settings['accent'] as String?);
        final all = survey.list('questions');
        final pages = paginate(all.where((q) => isVisible(q, _answers)).toList(), settings.flag('one_per_page'));
        final current = pages.isEmpty ? <Json>[] : pages[_page.clamp(0, pages.length - 1)];
        final missing = _showErrors ? missingRequired(current, _answers) : const <String>[];
        final countable = all.where((q) => q.str('type') != 'section' && isVisible(q, _answers)).toList();
        final progress = countable.isEmpty ? 0.0 : countable.where((q) => _answers[q.str('id')] != null).length / countable.length;
        final closed = !survey.flag('open');

        return Scaffold(
          backgroundColor: AppColors.ivory,
          body: CustomScrollView(controller: _scroll, slivers: [
            SliverAppBar(
              pinned: true,
              expandedHeight: 150,
              backgroundColor: accent,
              foregroundColor: Colors.white,
              flexibleSpace: FlexibleSpaceBar(
                titlePadding: const EdgeInsetsDirectional.fromSTEB(56, 0, 16, 14),
                title: Text(survey.str('title'), maxLines: 2, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: Colors.white)),
                background: DecoratedBox(decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [accent, Color.lerp(accent, Colors.black, .45)!]))),
              ),
              bottom: _stage == _Stage.form && settings['show_progress'] != false
                  ? PreferredSize(
                      preferredSize: const Size.fromHeight(4),
                      child: TweenAnimationBuilder<double>(
                        tween: Tween(end: progress),
                        duration: const Duration(milliseconds: 400),
                        builder: (_, v, _) => LinearProgressIndicator(value: v, minHeight: 4, backgroundColor: Colors.white24, color: AppColors.gold300),
                      ),
                    )
                  : null,
            ),
            SliverPadding(
              padding: const EdgeInsets.fromLTRB(16, 20, 16, 120),
              sliver: SliverList.list(children: [
                if (_stage == _Stage.welcome) ..._welcome(survey, settings, accent, closed),
                if (_stage == _Stage.form) ...[
                  if (pages.length > 1)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: Text('${s.t('needs.page')} ${_page + 1} ${s.t('needs.of')} ${pages.length}', style: const TextStyle(color: AppColors.muted, fontWeight: FontWeight.w700, fontSize: 12)),
                    ),
                  for (final q in current) q.str('type') == 'section' ? _section(q, accent) : _questionCard(q, accent, missing.contains(q.str('id'))),
                  if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: AppColors.danger))),
                ],
                if (_stage == _Stage.done) ..._done(settings, accent, closed),
              ]),
            ),
          ]),
          bottomNavigationBar: _stage == _Stage.form
              ? SafeArea(
                  child: Padding(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
                    child: Row(children: [
                      if (_page > 0)
                        TextButton.icon(
                          onPressed: () => setState(() => _page--),
                          icon: const Icon(Icons.arrow_back),
                          label: Text(s.t('needs.prev')),
                        ),
                      const Spacer(),
                      FilledButton(
                        style: FilledButton.styleFrom(backgroundColor: accent, padding: const EdgeInsets.symmetric(horizontal: 28, vertical: 14)),
                        onPressed: _sending ? null : () => _next(pages, all),
                        child: _sending
                            ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                            : Text(_page >= pages.length - 1 ? s.t('needs.submit') : s.t('needs.next')),
                      ),
                    ]),
                  ),
                )
              : null,
        );
      },
      ),
    );
  }

  List<Widget> _welcome(Json survey, Json settings, Color accent, bool closed) {
    final s = context.s;
    final anonymous = survey.flag('anonymous');
    return [
      if (settings.str('welcome').isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 16), child: Text(settings.str('welcome'), style: const TextStyle(fontSize: 16, height: 1.7))),
      if (survey.str('description').isNotEmpty) Text(survey.str('description'), style: const TextStyle(color: AppColors.muted, height: 1.7)),
      const SizedBox(height: 18),
      Row(children: [
        Expanded(child: StatTile(label: s.t('needs.questions'), value: survey.number('questions_count').toString(), icon: Icons.checklist)),
        const SizedBox(width: 10),
        Expanded(child: StatTile(label: s.t('needs.minutes'), value: survey.number('estimated_minutes').toString(), icon: Icons.schedule)),
      ]),
      const SizedBox(height: 14),
      Row(children: [
        Icon(anonymous ? Icons.visibility_off_outlined : Icons.verified_user_outlined, color: accent, size: 20),
        const SizedBox(width: 8),
        Expanded(child: Text(anonymous ? s.t('needs.anonymous') : s.t('needs.secure'), style: const TextStyle(fontWeight: FontWeight.w700))),
      ]),
      const SizedBox(height: 8),
      Text(s.t('needs.profileNote'), style: const TextStyle(fontSize: 12, color: AppColors.muted, height: 1.6)),
      const SizedBox(height: 28),
      if (closed)
        Container(padding: const EdgeInsets.all(16), decoration: BoxDecoration(color: AppColors.navy100, borderRadius: BorderRadius.circular(16)), child: Text(s.t('needs.closed'), textAlign: TextAlign.center))
      else
        FilledButton.icon(
          style: FilledButton.styleFrom(backgroundColor: accent, padding: const EdgeInsets.symmetric(vertical: 16)),
          onPressed: () => setState(() {
            _stage = _Stage.form;
            _started = DateTime.now();
          }),
          icon: const Icon(Icons.play_arrow_rounded),
          label: Text(s.t('needs.start')),
        ),
    ];
  }

  List<Widget> _done(Json settings, Color accent, bool closed) {
    final s = context.s;
    return [
      const SizedBox(height: 24),
      Center(child: Container(width: 84, height: 84, decoration: BoxDecoration(color: accent, shape: BoxShape.circle), child: const Icon(Icons.check_rounded, color: Colors.white, size: 44))),
      const SizedBox(height: 20),
      Text(s.t('needs.thanks'), textAlign: TextAlign.center, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: AppColors.navy900)),
      const SizedBox(height: 8),
      Text(_thanks ?? (settings.str('thank_you').isNotEmpty ? settings.str('thank_you') : s.t('needs.thanksText')), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, height: 1.6)),
      const SizedBox(height: 28),
      if (!closed)
        OutlinedButton(
          onPressed: () => setState(() {
            _stage = _Stage.form;
            _page = 0;
          }),
          child: Text(s.t('needs.edit')),
        ),
      const SizedBox(height: 8),
      FilledButton(style: FilledButton.styleFrom(backgroundColor: accent), onPressed: () => context.canPop() ? context.pop() : context.go('/home'), child: Text(s.t('needs.close'))),
    ];
  }

  Widget _section(Json q, Color accent) => Padding(
        padding: const EdgeInsets.only(top: 6, bottom: 14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(q.str('title'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppColors.navy900)),
          if (q.str('description').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(q.str('description'), style: const TextStyle(color: AppColors.muted, height: 1.6))),
          const SizedBox(height: 10),
          Container(width: 56, height: 3, decoration: BoxDecoration(color: accent, borderRadius: BorderRadius.circular(2))),
        ]),
      );

  Widget _questionCard(Json q, Color accent, bool error) {
    return Container(
      margin: const EdgeInsets.only(bottom: 14),
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: error ? AppColors.danger : AppColors.navy100, width: error ? 1.5 : 1),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text.rich(TextSpan(children: [
          TextSpan(text: q.str('title'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, height: 1.6, color: AppColors.ink)),
          if (q.flag('required')) const TextSpan(text: ' *', style: TextStyle(color: AppColors.danger)),
        ])),
        if (q.str('description').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(q.str('description'), style: const TextStyle(color: AppColors.muted, fontSize: 13))),
        const SizedBox(height: 12),
        _input(q, accent),
      ]),
    );
  }

  static List<String> _swap(List<String> list, int a, int b) {
    final next = [...list];
    final tmp = next[a];
    next[a] = next[b];
    next[b] = tmp;
    return next;
  }

  void _set(String id, Object? value) => setState(() => value == null ? _answers.remove(id) : _answers[id] = value);

  Widget _choiceTile({required bool selected, required String label, required VoidCallback onTap, required Color accent, bool square = false, bool enabled = true}) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Opacity(
        opacity: enabled || selected ? 1 : .4,
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: enabled || selected ? onTap : null,
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 160),
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(
              color: selected ? accent.withValues(alpha: .07) : Colors.white,
              border: Border.all(color: selected ? accent : AppColors.navy100, width: selected ? 1.5 : 1),
              borderRadius: BorderRadius.circular(14),
            ),
            child: Row(children: [
              Icon(
                square ? (selected ? Icons.check_box_rounded : Icons.check_box_outline_blank_rounded) : (selected ? Icons.radio_button_checked : Icons.radio_button_unchecked),
                color: selected ? accent : AppColors.muted,
                size: 22,
              ),
              const SizedBox(width: 10),
              Expanded(child: Text(label, style: TextStyle(fontWeight: selected ? FontWeight.w700 : FontWeight.w500))),
            ]),
          ),
        ),
      ),
    );
  }

  Widget _pointRow(List<int> points, int? value, Color accent, ValueChanged<int> onTap) => Row(children: [
        for (final p in points)
          Expanded(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 2),
              child: InkWell(
                borderRadius: BorderRadius.circular(10),
                onTap: () => onTap(p),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 150),
                  height: 42,
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: value == p ? accent : Colors.white,
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: value == p ? accent : AppColors.navy100),
                  ),
                  child: Text('$p', style: TextStyle(fontWeight: FontWeight.w800, color: value == p ? Colors.white : AppColors.muted)),
                ),
              ),
            ),
          ),
      ]);

  Widget _labels(String? low, String? high) => (low ?? '').isEmpty && (high ?? '').isEmpty
      ? const SizedBox.shrink()
      : Padding(
          padding: const EdgeInsets.only(top: 6),
          child: Row(mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
            Text(low ?? '', style: const TextStyle(fontSize: 11, color: AppColors.muted)),
            Text(high ?? '', style: const TextStyle(fontSize: 11, color: AppColors.muted)),
          ]),
        );

  Widget _input(Json q, Color accent) {
    final s = context.s;
    final id = q.str('id');
    final value = _answers[id];
    final scale = q.obj('scale') ?? {'min': 1, 'max': 5};
    final min = (scale['min'] as num? ?? 1).toInt();
    final max = (scale['max'] as num? ?? 5).toInt();
    final points = [for (var p = min; p <= max; p++) p];
    final options = q.list('options');

    switch (q.str('type')) {
      case 'short_text':
      case 'long_text':
      case 'number':
      case 'date':
        final multiline = q.str('type') == 'long_text';
        return TextFormField(
          key: ValueKey('t-$id'),
          initialValue: value?.toString() ?? '',
          maxLines: multiline ? 4 : 1,
          keyboardType: q.str('type') == 'number' ? const TextInputType.numberWithOptions(decimal: true) : (multiline ? TextInputType.multiline : TextInputType.text),
          readOnly: q.str('type') == 'date',
          onTap: q.str('type') == 'date'
              ? () async {
                  final picked = await showDatePicker(context: context, firstDate: DateTime(1990), lastDate: DateTime(2100), initialDate: DateTime.tryParse(value?.toString() ?? '') ?? DateTime.now());
                  if (picked != null) _set(id, picked.toIso8601String().substring(0, 10));
                }
              : null,
          decoration: InputDecoration(hintText: q.str('type') == 'date' ? (value?.toString() ?? 'YYYY-MM-DD') : null),
          onChanged: (v) => _set(id, v.isEmpty ? null : (q.str('type') == 'number' ? num.tryParse(v) : v)),
        );
      case 'dropdown':
        return DropdownButtonFormField<String>(
          initialValue: value as String?,
          isExpanded: true,
          hint: Text(s.t('needs.select')),
          items: [for (final o in options) DropdownMenuItem(value: o.str('id'), child: Text(o.str('label')))],
          onChanged: (v) => _set(id, v),
        );
      case 'single':
        return Column(children: [
          for (final o in options) _choiceTile(selected: value == o.str('id'), label: o.str('label'), accent: accent, onTap: () => _set(id, value == o.str('id') ? null : o.str('id'))),
        ]);
      case 'multiple':
        final list = List<String>.from(value as List? ?? const []);
        final limit = (q['max_select'] as num?)?.toInt();
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (limit != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text('${s.t('needs.chooseUpTo')} $limit · ${list.length}/$limit', style: const TextStyle(fontSize: 12, color: AppColors.muted))),
          for (final o in options)
            _choiceTile(
              square: true,
              selected: list.contains(o.str('id')),
              enabled: limit == null || list.length < limit,
              label: o.str('label'),
              accent: accent,
              onTap: () {
                final next = [...list];
                next.contains(o.str('id')) ? next.remove(o.str('id')) : next.add(o.str('id'));
                _set(id, next.isEmpty ? null : next);
              },
            ),
        ]);
      case 'yes_no':
        return Row(children: [
          for (final v in ['yes', 'no'])
            Expanded(
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 4),
                child: _choiceTile(selected: value == v, label: s.t('needs.$v'), accent: accent, onTap: () => _set(id, value == v ? null : v)),
              ),
            ),
        ]);
      case 'rating':
        return Wrap(children: [
          for (final p in points.where((p) => p > 0))
            IconButton(
              iconSize: 36,
              onPressed: () => _set(id, value == p ? null : p),
              icon: Icon(value is num && p <= value ? Icons.star_rounded : Icons.star_outline_rounded, color: accent),
            ),
        ]);
      case 'scale':
        return Column(children: [_pointRow(points, value as int?, accent, (p) => _set(id, value == p ? null : p)), _labels(scale['min_label'] as String?, scale['max_label'] as String?)]);
      case 'nps':
        return Column(children: [
          _pointRow(List.generate(6, (i) => i), value as int?, accent, (p) => _set(id, value == p ? null : p)),
          const SizedBox(height: 6),
          _pointRow(List.generate(5, (i) => i + 6), value, accent, (p) => _set(id, value == p ? null : p)),
          _labels(s.t('needs.notLikely'), s.t('needs.veryLikely')),
        ]);
      case 'matrix':
        final grid = Map<String, Object?>.from(value as Map? ?? const {});
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          for (final row in q.list('rows'))
            Padding(
              padding: const EdgeInsets.only(bottom: 14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(row.str('label'), style: const TextStyle(fontWeight: FontWeight.w600)),
                const SizedBox(height: 6),
                _pointRow(points, grid[row.str('id')] as int?, accent, (p) => _set(id, {...grid, row.str('id'): p})),
              ]),
            ),
          _labels(scale['min_label'] as String?, scale['max_label'] as String?),
        ]);
      case 'ranking':
        final order = List<String>.from(value as List? ?? options.map((o) => o.str('id')));
        final labels = {for (final o in options) o.str('id'): o.str('label')};
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(s.t('needs.rankHint'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          const SizedBox(height: 8),
          for (var i = 0; i < order.length; i++)
            Container(
              margin: const EdgeInsets.only(bottom: 6),
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.navy100)),
              child: Row(children: [
                CircleAvatar(radius: 13, backgroundColor: accent.withValues(alpha: 1 - i * .12), child: Text('${i + 1}', style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w800))),
                const SizedBox(width: 10),
                Expanded(child: Text(labels[order[i]] ?? '', style: const TextStyle(fontWeight: FontWeight.w600))),
                IconButton(visualDensity: VisualDensity.compact, onPressed: i == 0 ? null : () => _set(id, _swap(order, i, i - 1)), icon: const Icon(Icons.arrow_upward, size: 18)),
                IconButton(visualDensity: VisualDensity.compact, onPressed: i == order.length - 1 ? null : () => _set(id, _swap(order, i, i + 1)), icon: const Icon(Icons.arrow_downward, size: 18)),
              ]),
            ),
          if (value == null) TextButton(onPressed: () => _set(id, order), child: Text(s.t('needs.rankConfirm'))),
        ]);
      default:
        return const SizedBox.shrink();
    }
  }
}
