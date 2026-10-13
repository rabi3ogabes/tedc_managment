import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/offline/exam_clock.dart';
import '../../core/offline/offline_exams.dart';
import '../../core/offline/offline_providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'question_view.dart';

/// An exam taken without a connection: the package downloaded earlier, a timer on the phone's monotonic clock,
/// answers saved encrypted every few seconds, and handed in when the phone is back online.
class OfflineExamScreen extends ConsumerStatefulWidget {
  const OfflineExamScreen({super.key, required this.tokenId});

  final String tokenId;

  @override
  ConsumerState<OfflineExamScreen> createState() => _OfflineExamScreenState();
}

class _OfflineExamScreenState extends ConsumerState<OfflineExamScreen> with WidgetsBindingObserver {
  Json? _pkg;
  String? _error;
  ExamClock? _clock;
  DateTime? _startedAt;
  final Map<String, dynamic> _answers = {};
  int _index = 0;
  bool _dirty = false;
  bool _finished = false;
  bool _handingIn = false;
  bool _timeUp = false;
  Timer? _tick;
  Timer? _save;

  late final OfflineExams _exams;
  int? get _limit => _pkg == null || (_pkg!['time_limit_minutes'] as num?) == null ? null : (_pkg!['time_limit_minutes'] as num).toInt() * 60;
  int get _elapsed {
    final e = _clock?.elapsedSeconds ?? 0;
    return _limit == null ? e : e.clamp(0, _limit!);
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _exams = ref.read(offlineExamsProvider);
    _load();
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _tick?.cancel();
    _save?.cancel();
    if (_clock != null && !_finished) unawaited(_persist(force: true));
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (_clock == null || _finished) return;
    if (state == AppLifecycleState.paused || state == AppLifecycleState.inactive) {
      _clock!.pause();
      unawaited(_persist(force: true));
    } else if (state == AppLifecycleState.resumed) {
      _clock!.resume();
      _check();
    }
  }

  Future<void> _load() async {
    try {
      final exams = _exams;
      final pkg = await exams.package(widget.tokenId);
      final p = await exams.progress(widget.tokenId);
      final entry = (await exams.list()).where((e) => e.tokenId == widget.tokenId).firstOrNull;
      if (!mounted) return;
      setState(() {
        _pkg = pkg;
        _answers
          ..clear()
          ..addAll(p.answers);
        _startedAt = p.startedAt;
        _finished = entry?.waiting ?? false;
      });
      if (p.startedAt != null && !_finished) _run(ExamClock(spent: p.elapsedSeconds, savedAt: p.savedAt));
    } catch (e) {
      if (mounted) setState(() => _error = e is StateError ? context.s.t('oexam.gone') : e.toString());
    }
  }

  void _start() {
    _startedAt = DateTime.now();
    _run(ExamClock());
    unawaited(_persist(force: true));
  }

  void _run(ExamClock clock) {
    setState(() => _clock = clock);
    _tick = Timer.periodic(const Duration(seconds: 1), (_) => _check());
    _save = Timer.periodic(const Duration(seconds: 5), (_) => _persist());
    _check();
  }

  /// The time is up: the answers so far are kept and wait to be handed in.
  void _check() {
    if (!mounted || _finished) return;
    if (_limit != null && (_clock?.elapsedSeconds ?? 0) >= _limit!) {
      _timeUp = true;
      unawaited(_finish());
      return;
    }
    setState(() {});
  }

  Future<void> _persist({bool force = false}) async {
    if ((!_dirty && !force) || _finished || _clock == null) return;
    _dirty = false;
    try {
      await _exams.save(widget.tokenId, ExamProgress(answers: Map.of(_answers), elapsedSeconds: _elapsed, startedAt: _startedAt));
    } catch (_) {
      _dirty = true;
    }
  }

  void _set(String id, dynamic v) {
    setState(() => _answers[id] = v);
    _dirty = true;
  }

  Future<void> _finish() async {
    if (_finished) return;
    _tick?.cancel();
    _save?.cancel();
    setState(() => _finished = true);
    await _exams.finish(widget.tokenId, ExamProgress(answers: Map.of(_answers), elapsedSeconds: _elapsed, startedAt: _startedAt, finishedAt: DateTime.now()));
    if (!mounted) return;
    ref.invalidate(offlineExamListProvider);
    await _handIn(quiet: true);
  }

  Future<void> _handIn({bool quiet = false}) async {
    if (_handingIn) return;
    setState(() => _handingIn = true);
    final s = context.s;
    try {
      final r = await _exams.submit(widget.tokenId);
      if (!mounted) return;
      ref.invalidate(offlineExamListProvider);
      context.pushReplacement('/attempts/${r['id']}');
      return;
    } on ApiException catch (e) {
      if (!mounted) return;
      ref.invalidate(offlineExamListProvider);
      if (e.code != 'network' && e.status != null) {
        setState(() => _error = s.t('oexam.refused').replaceAll('{reason}', s.t('oexam.reason.${e.code ?? 'refused'}')));
      } else if (!quiet) {
        showSnack(context, s.t('oexam.stillOffline'));
      }
    } catch (_) {
      if (mounted && !quiet) showSnack(context, s.t('oexam.stillOffline'));
    } finally {
      if (mounted) setState(() => _handingIn = false);
    }
  }

  Future<void> _confirm(List<Json> qs) async {
    final pending = qs.where((q) => !answered(_answers[q.str('id')])).length;
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
    if (ok == true) await _finish();
  }

  String _mmss(int s) => '${(s ~/ 60).toString().padLeft(2, '0')}:${(s % 60).toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final pkg = _pkg;
    if (_error != null) {
      return Scaffold(appBar: AppBar(), body: Center(child: Padding(padding: const EdgeInsets.all(28), child: Text(_error!, textAlign: TextAlign.center, style: const TextStyle(fontSize: 16, height: 1.6)))));
    }
    if (pkg == null) return const Scaffold(body: Center(child: CircularProgressIndicator()));
    if (_finished) return _savedView(s);
    if (_clock == null) return _intro(s, pkg);

    final qs = pkg.list('questions');
    final q = qs[_index.clamp(0, qs.length - 1)];
    final left = _limit == null ? null : (_limit! - (_clock?.elapsedSeconds ?? 0)).clamp(0, _limit!);
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(
        title: Text(s.t('assess.question').replaceAll('{n}', '${_index + 1}').replaceAll('{total}', '${qs.length}')),
        actions: [if (left != null) Padding(padding: const EdgeInsetsDirectional.only(end: 16), child: Center(child: Text(_mmss(left), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18, color: left < 60 ? AppColors.danger : null))))],
      ),
      body: Column(children: [
        _Banner(text: s.t('oexam.banner')),
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

  Widget _intro(S s, Json pkg) {
    final ar = s.isArabic;
    final fmt = Fmt(s.languageCode);
    final title = pkg.str(ar ? 'title_ar' : 'title_en').isNotEmpty ? pkg.str(ar ? 'title_ar' : 'title_en') : pkg.str('title_ar');
    final limit = (pkg['time_limit_minutes'] as num?)?.toInt();
    final expires = pkg.date('expires_at');
    final expired = expires != null && expires.isBefore(DateTime.now());
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('oexam.introTitle'))),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        Container(
          padding: const EdgeInsets.all(22),
          decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(26)),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(Icons.offline_pin_rounded, color: AppColors.gold300, size: 34),
            const SizedBox(height: 12),
            Text(title, style: const TextStyle(color: Colors.white, fontSize: 21, fontWeight: FontWeight.w800, height: 1.4)),
            const SizedBox(height: 14),
            Wrap(spacing: 8, runSpacing: 8, children: [
              _Pill(text: s.count('count.questions', pkg.list('questions').length)),
              _Pill(text: limit == null ? s.t('oexam.noLimit') : s.t('oexam.timeLimit').replaceAll('{minutes}', s.count('count.minutes', limit))),
              if (expires != null) _Pill(text: s.t('oexam.until').replaceAll('{date}', fmt.dateTime(expires))),
            ]),
          ]),
        ),
        const SizedBox(height: 18),
        Text(s.t('oexam.introText'), style: const TextStyle(fontSize: 15, height: 1.75, color: AppColors.ink)),
        const SizedBox(height: 22),
        if (expired)
          Text(s.t('oexam.expiredBeforeStart'), style: const TextStyle(color: AppColors.danger, fontWeight: FontWeight.w700))
        else
          FilledButton.icon(onPressed: _start, style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)), icon: const Icon(Icons.play_arrow_rounded), label: Text(s.t('oexam.start'))),
      ]),
    );
  }

  Widget _savedView(S s) => Scaffold(
        backgroundColor: AppColors.ivory,
        appBar: AppBar(automaticallyImplyLeading: false, title: Text(s.t('oexam.introTitle'))),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(28),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Container(
                width: 76,
                height: 76,
                decoration: BoxDecoration(color: AppColors.gold100, shape: BoxShape.circle),
                child: Icon(Icons.lock_clock_rounded, size: 38, color: AppColors.gold700),
              ),
              const SizedBox(height: 18),
              if (_timeUp) Padding(padding: const EdgeInsets.only(bottom: 8), child: Text(s.t('oexam.timeUp'), style: const TextStyle(color: AppColors.warning, fontWeight: FontWeight.w700))),
              Text(s.t('oexam.savedTitle'), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppColors.navy900)),
              const SizedBox(height: 8),
              Text(s.t('oexam.savedText'), textAlign: TextAlign.center, style: const TextStyle(height: 1.7, color: AppColors.muted)),
              const SizedBox(height: 24),
              FilledButton.icon(
                onPressed: _handingIn ? null : () => _handIn(),
                style: FilledButton.styleFrom(minimumSize: const Size(220, 50)),
                icon: _handingIn ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2.2, color: Colors.white)) : const Icon(Icons.cloud_upload_outlined),
                label: Text(s.t(_handingIn ? 'oexam.handingIn' : 'oexam.handIn')),
              ),
              const SizedBox(height: 8),
              TextButton(onPressed: () => context.canPop() ? context.pop() : context.go('/home'), child: Text(s.t('oexam.back'))),
            ]),
          ),
        ),
      );
}

class _Banner extends StatelessWidget {
  const _Banner({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        color: AppColors.navy900,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        child: Row(children: [
          Icon(Icons.cloud_off_rounded, size: 16, color: AppColors.gold300),
          const SizedBox(width: 8),
          Expanded(child: Text(text, style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600))),
        ]),
      );
}

class _Pill extends StatelessWidget {
  const _Pill({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(color: Colors.white.withValues(alpha: .12), borderRadius: BorderRadius.circular(99), border: Border.all(color: Colors.white24)),
        child: Text(text, style: TextStyle(color: AppColors.gold300, fontSize: 12.5, fontWeight: FontWeight.w700)),
      );
}
