import 'dart:async';
import 'dart:ui' show FontFeature;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// One lesson of an online course: a tracked video, slides, a quiz, a survey or an article.
class LessonScreen extends ConsumerStatefulWidget {
  const LessonScreen({super.key, required this.id, this.registrationId});

  final String id;
  final String? registrationId;

  @override
  ConsumerState<LessonScreen> createState() => _LessonScreenState();
}

class _LessonScreenState extends ConsumerState<LessonScreen> {
  Json? _lesson;
  String? _error;
  bool _completed = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _lesson = null;
      _error = null;
    });
    try {
      final res = await ref.read(apiProvider).get('/me/lessons/${widget.id}');
      if (!mounted) return;
      final data = Map<String, dynamic>.from((res as Map)['data'] as Map);
      setState(() {
        _lesson = data;
        _completed = data.obj('progress')?.str('status') == 'completed';
      });
    } catch (e) {
      if (mounted) setState(() => _error = ApiException.from(e).message);
    }
  }

  /// Called by the lesson widgets whenever the server reports progress.
  void _progress(bool completed) {
    final regId = widget.registrationId ?? _lesson?.str('registration_id') ?? '';
    ref.invalidate(getProvider('/me/registrations/$regId/course'));
    ref.invalidate(getProvider('/me/registrations'));
    if (completed && !_completed && mounted) setState(() => _completed = true);
  }

  Future<void> _markDone() async {
    try {
      await ref.read(apiProvider).post('/me/lessons/${widget.id}/complete');
      _progress(true);
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final l = _lesson;
    return Scaffold(
      appBar: AppBar(title: Text(l?.str('title') ?? s.t('course.title'), overflow: TextOverflow.ellipsis)),
      body: _error != null
          ? ErrorView(message: _error, onRetry: _load)
          : l == null
              ? const LoadingView()
              : ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
                  if (l.str('description').isNotEmpty) Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(l.str('description'), style: const TextStyle(color: AppColors.muted, height: 1.5))),
                  switch (l.str('type')) {
                    'video' => _VideoLesson(lesson: l, onProgress: _progress),
                    'presentation' => _SlidesLesson(lesson: l, onConfirm: _markDone),
                    'quiz' => _QuizLesson(lesson: l, onProgress: _progress),
                    'survey' => _SurveyLesson(lesson: l, onProgress: _progress),
                    _ => _ArticleLesson(lesson: l, done: _completed, onDone: _markDone),
                  },
                  if (_completed) ...[
                    const SizedBox(height: 16),
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(color: const Color(0xFFE7F6EF), borderRadius: BorderRadius.circular(16)),
                      child: Row(children: [
                        const Icon(Icons.check_circle, color: AppColors.success),
                        const SizedBox(width: 10),
                        Expanded(child: Text(s.t('course.lessonDone'), style: const TextStyle(fontWeight: FontWeight.w700, color: AppColors.success))),
                        TextButton(onPressed: () => context.pop(), child: Text(s.t('course.backToCourse'))),
                      ]),
                    ),
                  ],
                ]),
    );
  }
}

// Video -------------------------------------------------------------------------------------------------------

class _VideoLesson extends ConsumerStatefulWidget {
  const _VideoLesson({required this.lesson, required this.onProgress});

  final Json lesson;
  final void Function(bool completed) onProgress;

  @override
  ConsumerState<_VideoLesson> createState() => _VideoLessonState();
}

class _VideoLessonState extends ConsumerState<_VideoLesson> with WidgetsBindingObserver {
  VideoPlayerController? _c;
  Timer? _timer;
  double _sent = 0;
  double _furthest = 0;
  double _lastTick = 0;
  double _percent = 0;
  String? _notice;
  bool _failed = false;

  Json get _rules => widget.lesson.obj('rules') ?? {};
  bool get _allowSeek => _rules['allow_seeking'] != false;
  Json? get _media => widget.lesson.obj('media');
  bool get _embedded => _media?.str('kind') == 'link' && _media!.str('embed').isNotEmpty;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    final progress = widget.lesson.obj('progress') ?? {};
    _percent = progress.number('percent').toDouble();
    _furthest = progress.number('furthest').toDouble();
    _sent = progress.number('position').toDouble();
    final media = _media;
    if (media != null && !_embedded) _setUp(media.str('url'), progress.number('position').toDouble(), progress.str('status') == 'completed');
    _timer = Timer.periodic(const Duration(seconds: 10), (_) => _embedded ? _wallClock() : _beat());
  }

  Future<void> _setUp(String url, double resumeAt, bool completed) async {
    final c = VideoPlayerController.networkUrl(Uri.parse(url));
    try {
      await c.initialize();
    } catch (_) {
      if (mounted) setState(() => _failed = true);
      return;
    }
    if (!mounted) return;
    if (!completed && resumeAt > 5 && resumeAt < c.value.duration.inSeconds - 5) {
      await c.seekTo(Duration(seconds: resumeAt.floor()));
      _sent = resumeAt;
    }
    c.addListener(_onTick);
    setState(() => _c = c);
  }

  void _onTick() {
    final c = _c;
    if (c == null || !mounted) return;
    final now = c.value.position.inMilliseconds / 1000;
    // Continuous playback moves the reachable point forward; a jump does not.
    if (c.value.isPlaying && now >= _lastTick && now - _lastTick < 2) _furthest = _furthest < now ? now : _furthest;
    if (!_allowSeek && c.value.isPlaying && now > _furthest + 2) c.seekTo(Duration(milliseconds: (_furthest * 1000).round()));
    _lastTick = now;
    setState(() {});
  }

  Future<void> _beat({bool force = false}) async {
    final c = _c;
    if (c == null) return;
    if (!c.value.isPlaying && !force) return;
    final from = _sent;
    final to = c.value.position.inMilliseconds / 1000;
    if (to <= from || (to - from < 0.5 && !force)) return;
    _sent = to;
    try {
      final res = await ref.read(apiProvider).post('/me/lessons/${widget.lesson.str('id')}/heartbeat', {'from': from, 'to': to, 'duration': c.value.duration.inSeconds, 'rate': c.value.playbackSpeed});
      final r = Map<String, dynamic>.from(res['data'] as Map);
      if (!mounted) return;
      _percent = r.number('percent').toDouble();
      _furthest = r.number('furthest').toDouble();
      if (!_allowSeek && c.value.position.inSeconds > r.number('position') + 8) {
        await c.seekTo(Duration(seconds: r.number('position').floor()));
        _sent = r.number('position').toDouble();
        _notice = context.s.t('course.noSeek');
      }
      setState(() {});
      widget.onProgress(r['completed'] == true);
    } catch (_) {
      _sent = from;
    }
  }

  /// YouTube / Vimeo cannot be observed: time spent on this screen, while the app is on screen, is what counts.
  double _wall = 0;
  bool _foreground = true;
  Future<void> _wallClock() async {
    if (!_foreground) return;
    final from = _wall;
    _wall += 10;
    try {
      final res = await ref.read(apiProvider).post('/me/lessons/${widget.lesson.str('id')}/heartbeat', {'from': from, 'to': _wall, 'duration': widget.lesson.number('duration_seconds')});
      final r = Map<String, dynamic>.from(res['data'] as Map);
      if (mounted) setState(() => _percent = r.number('percent').toDouble());
      widget.onProgress(r['completed'] == true);
    } catch (_) {
      _wall = from;
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    if (state != AppLifecycleState.resumed) {
      if (widget.lesson.obj('rules')?['pause_when_hidden'] != false) _c?.pause();
      _beat(force: true);
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _timer?.cancel();
    _beat(force: true);
    _c?.removeListener(_onTick);
    _c?.dispose();
    super.dispose();
  }

  String _clock(double seconds) {
    final s = seconds.floor();
    final h = s ~/ 3600;
    final m = (s % 3600) ~/ 60;
    final sec = (s % 60).toString().padLeft(2, '0');
    return h > 0 ? '$h:${m.toString().padLeft(2, '0')}:$sec' : '$m:$sec';
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final media = _media;
    if (media == null) return _Note(icon: Icons.videocam_off_outlined, text: s.t('course.mediaMissing'));
    if (_embedded) {
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        _Note(icon: Icons.info_outline, text: s.t('course.embedNote')),
        const SizedBox(height: 12),
        FilledButton.icon(onPressed: () => launchUrl(Uri.parse(media.str('url')), mode: LaunchMode.externalApplication), icon: const Icon(Icons.open_in_new), label: Text(s.t('course.openVideo'))),
        const SizedBox(height: 12),
        ProgressBar(_percent),
      ]);
    }
    final c = _c;
    if (_failed) return _Note(icon: Icons.error_outline, text: s.t('course.videoFailed'), danger: true);
    if (c == null) return const AspectRatio(aspectRatio: 16 / 9, child: Center(child: CircularProgressIndicator()));

    final duration = c.value.duration.inMilliseconds / 1000;
    final position = c.value.position.inMilliseconds / 1000;
    final maxSpeed = (_rules['max_speed'] as num?)?.toDouble() ?? 2;
    final speeds = [0.75, 1.0, 1.25, 1.5, 2.0].where((x) => x <= maxSpeed).toList();

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: Stack(alignment: Alignment.center, children: [
          AspectRatio(aspectRatio: c.value.aspectRatio == 0 ? 16 / 9 : c.value.aspectRatio, child: VideoPlayer(c)),
          if (!c.value.isPlaying) IconButton.filled(iconSize: 40, onPressed: c.play, icon: const Icon(Icons.play_arrow), style: IconButton.styleFrom(backgroundColor: Colors.white, foregroundColor: AppColors.navy900)),
        ]),
      ),
      const SizedBox(height: 8),
      // The seek bar cannot go beyond what was reached when skipping is off.
      Slider(
        value: duration > 0 ? position.clamp(0, duration) : 0,
        max: duration > 0 ? duration : 1,
        secondaryTrackValue: _allowSeek ? null : (_furthest.clamp(0, duration > 0 ? duration : 1)).toDouble(),
        onChanged: (v) {
          if (!_allowSeek && v > _furthest + 1) {
            setState(() => _notice = s.t('course.noSeek'));
            return;
          }
          c.seekTo(Duration(milliseconds: (v * 1000).round()));
        },
        onChangeEnd: (_) {
          _sent = c.value.position.inMilliseconds / 1000;
        },
      ),
      Row(children: [
        IconButton(onPressed: () => c.value.isPlaying ? c.pause() : c.play(), icon: Icon(c.value.isPlaying ? Icons.pause : Icons.play_arrow)),
        Text('${_clock(position)} / ${_clock(duration)}', textDirection: TextDirection.ltr, style: const TextStyle(fontFeatures: [FontFeature.tabularFigures()])),
        const Spacer(),
        PopupMenuButton<double>(
          tooltip: s.t('course.speed'),
          onSelected: c.setPlaybackSpeed,
          itemBuilder: (_) => [for (final x in speeds) PopupMenuItem(value: x, child: Text('$x×'))],
          child: Padding(padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8), child: Text('${c.value.playbackSpeed}×', style: const TextStyle(fontWeight: FontWeight.w700))),
        ),
      ]),
      if (_notice != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(_notice!, style: const TextStyle(color: AppColors.warning, fontWeight: FontWeight.w600))),
      Row(children: [Text('${s.t('course.watched')} ${_percent.round()}%', style: const TextStyle(fontWeight: FontWeight.w700)), const Spacer(), Text('${s.t('course.needWatch')} ${(_rules['min_watch_percent'] as num?) ?? 90}%', style: const TextStyle(fontSize: 12, color: AppColors.muted))]),
      const SizedBox(height: 6),
      ProgressBar(_percent),
    ]);
  }
}

// Slides ------------------------------------------------------------------------------------------------------

class _SlidesLesson extends StatelessWidget {
  const _SlidesLesson({required this.lesson, required this.onConfirm});

  final Json lesson;
  final Future<void> Function() onConfirm;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final media = lesson.obj('media');
    if (media == null) return _Note(icon: Icons.slideshow_outlined, text: s.t('course.mediaMissing'));
    final done = lesson.obj('progress')?.str('status') == 'completed';
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      _Note(icon: Icons.info_outline, text: s.t('course.slidesNote')),
      const SizedBox(height: 12),
      FilledButton.icon(onPressed: () => launchUrl(Uri.parse(media.str('url')), mode: LaunchMode.externalApplication), icon: const Icon(Icons.open_in_new), label: Text(s.t('course.openFile'))),
      const SizedBox(height: 10),
      if (!done) OutlinedButton.icon(onPressed: onConfirm, icon: const Icon(Icons.check), label: Text(s.t('course.confirmReviewed'))),
    ]);
  }
}

// Article -----------------------------------------------------------------------------------------------------

class _ArticleLesson extends StatelessWidget {
  const _ArticleLesson({required this.lesson, required this.done, required this.onDone});

  final Json lesson;
  final bool done;
  final Future<void> Function() onDone;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final blocks = lesson.str('body').split(RegExp(r'\n{2,}')).map((b) => b.trim()).where((b) => b.isNotEmpty);
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (final b in blocks)
        Padding(
          padding: const EdgeInsets.only(bottom: 14),
          child: b.startsWith('# ')
              ? Text(b.substring(2), style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: AppColors.navy900))
              : Text(b.split('\n').map((l) => l.trimLeft().startsWith('- ') ? '• ${l.trimLeft().substring(2)}' : l).join('\n'), style: const TextStyle(fontSize: 16, height: 1.8)),
        ),
      if (!done) FilledButton.icon(onPressed: onDone, icon: const Icon(Icons.check), label: Text(s.t('course.markDone'))),
    ]);
  }
}

// Quiz --------------------------------------------------------------------------------------------------------

class _QuizLesson extends ConsumerStatefulWidget {
  const _QuizLesson({required this.lesson, required this.onProgress});

  final Json lesson;
  final void Function(bool completed) onProgress;

  @override
  ConsumerState<_QuizLesson> createState() => _QuizLessonState();
}

class _QuizLessonState extends ConsumerState<_QuizLesson> {
  final Map<String, Set<String>> _answers = {};
  Json? _result;
  bool _started = false;
  bool _busy = false;
  String? _error;
  late DateTime _startedAt;

  Json get _quiz => widget.lesson.obj('quiz') ?? {};

  Future<void> _submit() async {
    final questions = _quiz.list('questions');
    if (questions.any((q) => (_answers[q.str('id')] ?? {}).isEmpty)) {
      setState(() => _error = context.s.t('course.answerAll'));
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final res = await ref.read(apiProvider).post('/me/lessons/${widget.lesson.str('id')}/quiz', {
        'answers': {for (final e in _answers.entries) e.key: e.value.toList()},
        'seconds': DateTime.now().difference(_startedAt).inSeconds,
      });
      final r = Map<String, dynamic>.from(res['data'] as Map);
      setState(() {
        _result = r;
        _started = false;
      });
      widget.onProgress(r['status'] == 'completed');
    } catch (e) {
      setState(() => _error = ApiException.from(e).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final questions = _quiz.list('questions');
    final maxAttempts = _quiz['max_attempts'] as num?;
    final used = (_result?.number('attempts') ?? _quiz.number('attempts')).toInt();
    final exhausted = maxAttempts != null && used >= maxAttempts;

    if (_result != null && !_started) {
      final r = _result!;
      final passed = r['passed'] == true;
      final review = r.obj('review') ?? {};
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(color: passed ? const Color(0xFFE7F6EF) : const Color(0xFFFDF3E1), borderRadius: BorderRadius.circular(20)),
          child: Column(children: [
            Text('${r.number('score_percent').round()}%', style: const TextStyle(fontSize: 44, fontWeight: FontWeight.w800, color: AppColors.navy900)),
            Text(passed ? s.t('course.passed') : s.t('course.failed'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: passed ? AppColors.success : AppColors.warning)),
            if (!passed && r['attempts_left'] != 0) Padding(padding: const EdgeInsets.only(top: 12), child: FilledButton.icon(onPressed: _begin, icon: const Icon(Icons.refresh), label: Text(s.t('course.retry')))),
          ]),
        ),
        const SizedBox(height: 14),
        for (final q in questions) _ReviewCard(question: q, info: review.obj(q.str('id'))),
      ]);
    }

    if (!_started) {
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(children: [
            const Text('📝', style: TextStyle(fontSize: 40)),
            const SizedBox(height: 8),
            Text('${questions.length} ${s.t('course.questions')} · ${s.t('course.passMark')} ${_quiz.number('pass_percent')}%', style: const TextStyle(fontWeight: FontWeight.w700)),
            Text(maxAttempts == null ? s.t('course.unlimited') : '${s.t('course.attempts')} $used / $maxAttempts', style: const TextStyle(color: AppColors.muted)),
            const SizedBox(height: 14),
            FilledButton(onPressed: exhausted || questions.isEmpty ? null : _begin, child: Text(exhausted ? s.t('course.noAttempts') : s.t('course.startQuiz'))),
          ]),
        ),
      );
    }

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (var i = 0; i < questions.length; i++)
        Card(
          margin: const EdgeInsets.only(bottom: 12),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${i + 1}. ${questions[i].str('text')}', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
              const SizedBox(height: 4),
              Text(questions[i].str('type') == 'multiple' ? s.t('course.pickMany') : s.t('course.pickOne'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
              const SizedBox(height: 8),
              for (final o in questions[i].list('options'))
                _OptionTile(
                  text: o.str('text'),
                  multiple: questions[i].str('type') == 'multiple',
                  selected: (_answers[questions[i].str('id')] ?? {}).contains(o.str('id')),
                  onTap: () => setState(() {
                    final set = _answers.putIfAbsent(questions[i].str('id'), () => <String>{});
                    if (questions[i].str('type') == 'multiple') {
                      set.contains(o.str('id')) ? set.remove(o.str('id')) : set.add(o.str('id'));
                    } else {
                      set
                        ..clear()
                        ..add(o.str('id'));
                    }
                  }),
                ),
            ]),
          ),
        ),
      if (_error != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(_error!, style: const TextStyle(color: AppColors.danger))),
      FilledButton(onPressed: _busy ? null : _submit, style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)), child: _busy ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2)) : Text(s.t('course.submit'))),
    ]);
  }

  void _begin() => setState(() {
        _answers.clear();
        _result = null;
        _error = null;
        _started = true;
        _startedAt = DateTime.now();
      });
}

class _OptionTile extends StatelessWidget {
  const _OptionTile({required this.text, required this.multiple, required this.selected, required this.onTap});

  final String text;
  final bool multiple;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: InkWell(
          borderRadius: BorderRadius.circular(14),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            decoration: BoxDecoration(border: Border.all(color: selected ? AppColors.navy900 : AppColors.navy100, width: selected ? 2 : 1), borderRadius: BorderRadius.circular(14), color: selected ? AppColors.navy100.withValues(alpha: .5) : null),
            child: Row(children: [
              Icon(multiple ? (selected ? Icons.check_box : Icons.check_box_outline_blank) : (selected ? Icons.radio_button_checked : Icons.radio_button_off), color: selected ? AppColors.navy900 : AppColors.muted),
              const SizedBox(width: 10),
              Expanded(child: Text(text)),
            ]),
          ),
        ),
      );
}

class _ReviewCard extends StatelessWidget {
  const _ReviewCard({required this.question, required this.info});

  final Json question;
  final Json? info;

  @override
  Widget build(BuildContext context) {
    final correctIds = (info?['correct_options'] as List?)?.map((e) => e.toString()).toSet();
    final chosen = (info?['chosen'] as List?)?.map((e) => e.toString()).toSet() ?? {};
    final ok = info?['correct'] == true;
    return Card(
      margin: const EdgeInsets.only(bottom: 10),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [Icon(ok ? Icons.check_circle : Icons.cancel, color: ok ? AppColors.success : AppColors.danger, size: 20), const SizedBox(width: 8), Expanded(child: Text(question.str('text'), style: const TextStyle(fontWeight: FontWeight.w700)))]),
          if (correctIds != null)
            for (final o in question.list('options'))
              Container(
                margin: const EdgeInsets.only(top: 6),
                padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(10),
                  color: correctIds.contains(o.str('id')) ? const Color(0xFFE7F6EF) : chosen.contains(o.str('id')) ? const Color(0xFFFBEAEA) : null,
                  border: Border.all(color: AppColors.navy100),
                ),
                child: Text('${o.str('text')}${correctIds.contains(o.str('id')) ? '  ✓' : chosen.contains(o.str('id')) ? '  ✗' : ''}'),
              ),
          if ((info?.str('explanation') ?? '').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 8), child: Text(info!.str('explanation'), style: const TextStyle(color: AppColors.muted))),
        ]),
      ),
    );
  }
}

// Survey ------------------------------------------------------------------------------------------------------

class _SurveyLesson extends ConsumerStatefulWidget {
  const _SurveyLesson({required this.lesson, required this.onProgress});

  final Json lesson;
  final void Function(bool completed) onProgress;

  @override
  ConsumerState<_SurveyLesson> createState() => _SurveyLessonState();
}

class _SurveyLessonState extends ConsumerState<_SurveyLesson> {
  final Map<String, dynamic> _answers = {};
  late bool _done = widget.lesson.obj('survey')?['submitted'] == true;
  bool _busy = false;
  String? _error;

  Future<void> _submit() async {
    final questions = widget.lesson.obj('survey')?.list('questions') ?? [];
    final missing = questions.any((q) => q.flag('required') && (_answers[q.str('id')] == null || _answers[q.str('id')] == '' || (_answers[q.str('id')] is List && (_answers[q.str('id')] as List).isEmpty)));
    if (missing) {
      setState(() => _error = context.s.t('course.surveyRequired'));
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref.read(apiProvider).post('/me/lessons/${widget.lesson.str('id')}/survey', {'answers': _answers});
      setState(() => _done = true);
      widget.onProgress(true);
    } catch (e) {
      setState(() => _error = ApiException.from(e).message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    if (_done) return Card(child: Padding(padding: const EdgeInsets.all(28), child: Column(children: [const Icon(Icons.check_circle, size: 56, color: AppColors.success), const SizedBox(height: 10), Text(s.t('course.thanks'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))])));
    final questions = widget.lesson.obj('survey')?.list('questions') ?? [];

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      for (var i = 0; i < questions.length; i++)
        Card(
          margin: const EdgeInsets.only(bottom: 12),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('${i + 1}. ${questions[i].str('text')}${questions[i].flag('required') ? ' *' : ''}', style: const TextStyle(fontWeight: FontWeight.w800)),
              const SizedBox(height: 10),
              _input(questions[i]),
            ]),
          ),
        ),
      if (_error != null) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(_error!, style: const TextStyle(color: AppColors.danger))),
      FilledButton(onPressed: _busy ? null : _submit, style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)), child: Text(s.t('course.send'))),
    ]);
  }

  Widget _input(Json q) {
    final id = q.str('id');
    switch (q.str('type')) {
      case 'rating':
        return Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          for (var n = 1; n <= 5; n++)
            IconButton(iconSize: 36, onPressed: () => setState(() => _answers[id] = n), icon: Icon((_answers[id] as int? ?? 0) >= n ? Icons.star : Icons.star_border, color: AppColors.gold500)),
        ]);
      case 'nps':
        return Wrap(spacing: 6, runSpacing: 6, children: [
          for (var n = 0; n <= 10; n++) ChoiceChip(label: Text('$n'), selected: _answers[id] == n, onSelected: (_) => setState(() => _answers[id] = n)),
        ]);
      case 'choice':
        return Column(children: [for (final o in q.list('options')) _OptionTile(text: o.str('text'), multiple: false, selected: _answers[id] == o.str('id'), onTap: () => setState(() => _answers[id] = o.str('id')))]);
      case 'multiple':
        final current = (_answers[id] as List?)?.cast<String>() ?? <String>[];
        return Column(children: [
          for (final o in q.list('options'))
            _OptionTile(
              text: o.str('text'),
              multiple: true,
              selected: current.contains(o.str('id')),
              onTap: () => setState(() => _answers[id] = current.contains(o.str('id')) ? current.where((x) => x != o.str('id')).toList() : [...current, o.str('id')]),
            ),
        ]);
      default:
        return TextField(maxLines: 3, onChanged: (v) => _answers[id] = v, decoration: InputDecoration(hintText: context.s.t('course.typeHere')));
    }
  }
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.text, this.danger = false});

  final IconData icon;
  final String text;
  final bool danger;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: danger ? const Color(0xFFFBEAEA) : AppColors.gold100, borderRadius: BorderRadius.circular(16)),
        child: Row(children: [Icon(icon, color: danger ? AppColors.danger : AppColors.gold700), const SizedBox(width: 10), Expanded(child: Text(text, style: const TextStyle(height: 1.4)))]),
      );
}
