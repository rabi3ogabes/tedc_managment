import 'dart:async';
import 'dart:io';
import 'dart:math';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdfx/pdfx.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/offline/offline_providers.dart';
import '../../core/offline/offline_sync.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// Reports a slide once it has been on screen for the lesson's minimum time (the server credits nothing sooner), and
/// each slide once.
class SlideReporter {
  SlideReporter({required this.minSeconds, required this.report});

  final int minSeconds;
  final void Function(int slide, int total) report;
  final _reported = <int>{};
  Timer? _timer;

  void show(int slide, int total) {
    _timer?.cancel();
    if (_reported.contains(slide)) return;
    _timer = Timer(Duration(seconds: max(minSeconds, 1)), () {
      _reported.add(slide);
      report(slide, total);
    });
  }

  /// Slides counted before (from the saved progress) are not reported again.
  void seen(Iterable<int> slides) => _reported.addAll(slides);

  void dispose() => _timer?.cancel();
}

/// Where the PDF comes from: its signed address, or the private plain copy of an offline download.
class SlideSource {
  const SlideSource.url(this.url) : path = null;
  const SlideSource.file(this.path) : url = null;

  final String? url;
  final String? path;
}

/// Turns the pages of the viewer.
abstract class SlidePager {
  Future<void> next();

  Future<void> previous();
}

typedef PdfViewerBuilder = Widget Function({
  required SlideSource source,
  required void Function(int total, SlidePager pager) onLoaded,
  required void Function(int page) onPage,
  required void Function(Object error) onError,
});

/// The PDF viewer (pdfx); tests replace it.
final pdfViewerProvider = Provider<PdfViewerBuilder>((ref) => _pdfxViewer);

Widget _pdfxViewer({required SlideSource source, required void Function(int, SlidePager) onLoaded, required void Function(int) onPage, required void Function(Object) onError}) =>
    _PdfxViewer(source: source, onLoaded: onLoaded, onPage: onPage, onError: onError);

class _PdfxViewer extends StatefulWidget {
  const _PdfxViewer({required this.source, required this.onLoaded, required this.onPage, required this.onError});

  final SlideSource source;
  final void Function(int, SlidePager) onLoaded;
  final void Function(int) onPage;
  final void Function(Object) onError;

  @override
  State<_PdfxViewer> createState() => _PdfxViewerState();
}

class _PdfxPager implements SlidePager {
  _PdfxPager(this.c);

  final PdfController c;
  static const _d = Duration(milliseconds: 220);

  @override
  Future<void> next() => c.nextPage(duration: _d, curve: const Cubic(0.23, 1, 0.32, 1));

  @override
  Future<void> previous() => c.previousPage(duration: _d, curve: const Cubic(0.23, 1, 0.32, 1));
}

class _PdfxViewerState extends State<_PdfxViewer> {
  late final PdfController _c = PdfController(document: _open());

  Future<PdfDocument> _open() async {
    final path = widget.source.path;
    if (path != null) return PdfDocument.openFile(path);
    final res = await Dio().get<List<int>>(widget.source.url!, options: Options(responseType: ResponseType.bytes));
    return PdfDocument.openData(Uint8List.fromList(res.data ?? const []));
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => PdfView(
        controller: _c,
        onDocumentLoaded: (doc) => widget.onLoaded(doc.pagesCount, _PdfxPager(_c)),
        onPageChanged: widget.onPage,
        onDocumentError: widget.onError,
        builders: PdfViewBuilders<DefaultBuilderOptions>(
          options: const DefaultBuilderOptions(loaderSwitchDuration: Duration(milliseconds: 200)),
          documentLoaderBuilder: (_) => const Center(child: CircularProgressIndicator()),
          pageLoaderBuilder: (_) => const Center(child: CircularProgressIndicator(strokeWidth: 2)),
          errorBuilder: (_, _) => const SizedBox.shrink(),
        ),
      );
}

/// The file's type decides; without one, its name.
bool isPdf(Json lesson) {
  final media = lesson.obj('media');
  final mime = media?.str('mime') ?? '';
  if (mime.isNotEmpty) return mime == 'application/pdf';
  return Uri.tryParse(media?.str('url') ?? '')?.path.toLowerCase().endsWith('.pdf') ?? false;
}

/// A presentation. PDFs are read in the app page by page, each slide reported after the author's minimum time
/// (online, or into the offline queue); completion follows from the slides seen. Other files open outside the app and
/// are confirmed.
class SlidesLesson extends ConsumerStatefulWidget {
  const SlidesLesson({super.key, required this.lesson, required this.onConfirm, required this.onProgress, this.offlineFile});

  final Json lesson;
  final Future<void> Function() onConfirm;
  final void Function(bool completed) onProgress;

  /// The private plain copy of the offline download (deleted when the lesson closes).
  final Future<File> Function()? offlineFile;

  @override
  ConsumerState<SlidesLesson> createState() => _SlidesLessonState();
}

class _SlidesLessonState extends ConsumerState<SlidesLesson> {
  late final SlideReporter _reporter;
  late final OfflineSync _sync; // kept: a report may finish after the lesson closed
  final _seen = <int>{};
  SlidePager? _pager;
  SlideSource? _source;
  File? _plain;
  int _page = 1;
  int _total = 0;
  double _percent = 0;
  bool _failed = false;

  String get _id => widget.lesson.str('id');
  bool get _offline => widget.lesson.flag('offline');
  Json get _rules => widget.lesson.obj('rules') ?? {};

  @override
  void initState() {
    super.initState();
    final progress = widget.lesson.obj('progress') ?? {};
    _percent = progress.number('percent').toDouble();
    for (final s in (progress['segments'] as List?) ?? const []) {
      if (s is List && s.length == 2) {
        for (var i = (s[0] as num).toInt(); i <= (s[1] as num).toInt(); i++) {
          _seen.add(i);
        }
      }
    }
    _sync = ref.read(offlineSyncProvider);
    _reporter = SlideReporter(minSeconds: (_rules['min_seconds_per_slide'] as num?)?.toInt() ?? 0, report: _report)..seen(_seen);
    if (isPdf(widget.lesson)) _prepare();
  }

  Future<void> _prepare() async {
    final open = widget.offlineFile;
    if (open == null) {
      _source = SlideSource.url(widget.lesson.obj('media')!.str('url'));
      return;
    }
    try {
      final f = await open();
      if (!mounted) {
        if (await f.exists()) await f.delete();
        return;
      }
      setState(() {
        _plain = f;
        _source = SlideSource.file(f.path);
      });
    } catch (_) {
      if (mounted) setState(() => _failed = true);
    }
  }

  @override
  void dispose() {
    _reporter.dispose();
    final plain = _plain;
    if (plain != null) unawaited(plain.exists().then((e) => e ? plain.delete() : null));
    super.dispose();
  }

  Future<void> _report(int slide, int total) async {
    _seen.add(slide);
    if (!_offline) {
      try {
        final res = await ref.read(apiProvider).post('/me/lessons/$_id/slide', {'slide': slide, 'total': total});
        final r = Map<String, dynamic>.from((res as Map)['data'] as Map);
        if (!mounted) return;
        setState(() => _percent = r.number('percent').toDouble());
        widget.onProgress(r['completed'] == true);
        return;
      } on ApiException catch (e) {
        // Only a lost connection is kept for later; a refusal is the server's answer.
        if (e.status != null) return;
      } catch (_) {}
    }
    final at = DateTime.now();
    await _sync.record((q) => q.slideView(_id, slide, total, at: at));
    if (mounted) setState(() => _percent = max(_percent, total == 0 ? 0 : _seen.length / total * 100));
  }

  void _loaded(int total, SlidePager pager) {
    if (!mounted) return;
    setState(() {
      _total = total;
      _pager = pager;
    });
    _reporter.show(_page, total);
  }

  void _turned(int page) {
    if (!mounted) return;
    setState(() => _page = page);
    _reporter.show(page, _total);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final media = widget.lesson.obj('media');
    if (media == null) return _note(Icons.slideshow_outlined, s.t('course.mediaMissing'));
    final done = widget.lesson.obj('progress')?.str('status') == 'completed';
    if (!isPdf(widget.lesson)) {
      if (_offline) return _note(Icons.cloud_off_rounded, s.t('offline.notDownloaded'));
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        _note(Icons.info_outline, s.t('course.slidesNote')),
        const SizedBox(height: 12),
        FilledButton.icon(onPressed: () => launchUrl(Uri.parse(media.str('url')), mode: LaunchMode.externalApplication), icon: const Icon(Icons.open_in_new), label: Text(s.t('course.openFile'))),
        const SizedBox(height: 10),
        if (!done) OutlinedButton.icon(key: const ValueKey('slides-confirm'), onPressed: widget.onConfirm, icon: const Icon(Icons.check), label: Text(s.t('course.confirmReviewed'))),
      ]);
    }
    final minSeconds = (_rules['min_seconds_per_slide'] as num?)?.toInt() ?? 0;
    final need = (_rules['min_view_percent'] as num?)?.toInt() ?? 80;
    final source = _source;
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      ClipRRect(
        borderRadius: BorderRadius.circular(18),
        child: Container(
          height: 260,
          decoration: BoxDecoration(color: Colors.white, border: Border.all(color: AppColors.navy100), borderRadius: BorderRadius.circular(18)),
          child: _failed
              ? Center(child: Padding(padding: const EdgeInsets.all(20), child: Text(s.t('course.slidesFailed'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.danger))))
              : source == null
                  ? const Center(child: CircularProgressIndicator())
                  : ref.watch(pdfViewerProvider)(source: source, onLoaded: _loaded, onPage: _turned, onError: (_) => setState(() => _failed = true)),
        ),
      ),
      const SizedBox(height: 8),
      Row(children: [
        IconButton(key: const ValueKey('slides-prev'), tooltip: s.t('course.prevSlide'), onPressed: _pager != null && _page > 1 ? () => _pager!.previous() : null, icon: const Icon(Icons.chevron_left_rounded)),
        Expanded(
          child: Text(_total == 0 ? '' : s.t('course.slideOf').replaceAll('{n}', '$_page').replaceAll('{total}', '$_total'), textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700, fontFeatures: [FontFeature.tabularFigures()])),
        ),
        IconButton(key: const ValueKey('slides-next'), tooltip: s.t('course.nextSlide'), onPressed: _pager != null && _page < _total ? () => _pager!.next() : null, icon: const Icon(Icons.chevron_right_rounded)),
      ]),
      Row(children: [
        Expanded(child: Text(s.t('course.slidesProgress').replaceAll('{seen}', '${_seen.where((x) => _total == 0 || x <= _total).length}').replaceAll('{total}', '${_total == 0 ? widget.lesson.number('slide_count') : _total}'), style: const TextStyle(fontWeight: FontWeight.w700))),
        Text(s.t('course.slidesNeed').replaceAll('{n}', '$need'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
      ]),
      const SizedBox(height: 6),
      ProgressBar(_percent),
      if (minSeconds > 0) Padding(padding: const EdgeInsets.only(top: 8), child: Text(s.t('course.slideMin').replaceAll('{n}', '$minSeconds'), style: const TextStyle(fontSize: 12, color: AppColors.muted))),
    ]);
  }

  Widget _note(IconData icon, String text) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .5), borderRadius: BorderRadius.circular(16)),
        child: Row(children: [Icon(icon, color: AppColors.navy800), const SizedBox(width: 10), Expanded(child: Text(text, style: const TextStyle(height: 1.5)))]),
      );
}
