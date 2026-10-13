import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/offline/offline_providers.dart';
import '../../core/offline/offline_store.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'downloads_screen.dart';

/// Progress of the items of one course being downloaded (lesson id → 0–1), while the course screen is open.
final downloadProgressProvider = StreamProvider.autoDispose.family<Map<String, double>, String>((ref, registrationId) async* {
  final map = <String, double>{};
  yield map;
  await for (final p in ref.watch(offlineStoreProvider).progress) {
    if (p.registrationId != registrationId) continue;
    map[p.lessonId] = p.fraction;
    yield Map.of(map);
  }
});

/// "Download for offline" on a course: progress per item while it runs, then what is kept and until when.
class OfflineCourseCard extends ConsumerStatefulWidget {
  const OfflineCourseCard({super.key, required this.registrationId, required this.title});

  final String registrationId;
  final String title;

  @override
  ConsumerState<OfflineCourseCard> createState() => _OfflineCourseCardState();
}

class _OfflineCourseCardState extends ConsumerState<OfflineCourseCard> {
  bool _running = false;

  @override
  void initState() {
    super.initState();
    _running = ref.read(offlineStoreProvider).downloading.contains(widget.registrationId);
  }

  Future<void> _download() async {
    final s = context.s;
    setState(() => _running = true);
    try {
      final r = await ref.read(offlineStoreProvider).downloadCourse(widget.registrationId, widget.title);
      if (!mounted) return;
      if (r.failed.isEmpty) {
        showSnack(context, s.t('offline.done'));
      } else {
        final reason = r.failed.first.reason;
        showSnack(context, '${s.t('offline.partial').replaceAll('{ok}', '${r.downloaded}').replaceAll('{failed}', '${r.failed.length}')}\n${s.t('offline.err.$reason')}', error: true);
      }
    } on OfflineError catch (e) {
      if (mounted) showSnack(context, s.t('offline.err.${e.code}'), error: true);
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) {
        setState(() => _running = false);
        ref.invalidate(offlineCoursesProvider);
        ref.invalidate(offlineUsageProvider);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final course = ref.watch(offlineCourseProvider(widget.registrationId)).value;
    final progress = ref.watch(downloadProgressProvider(widget.registrationId)).value ?? const {};
    final overall = progress.isEmpty ? null : progress.values.fold<double>(0, (a, b) => a + b) / progress.length;

    return Container(
      margin: const EdgeInsets.only(top: 12),
      padding: const EdgeInsets.fromLTRB(16, 14, 12, 14),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.navy100)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Row(children: [
          Icon(course != null ? Icons.offline_pin_rounded : Icons.download_for_offline_outlined, color: AppColors.gold700),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              _running
                  ? s.t('offline.downloading').replaceAll('{p}', '${((overall ?? 0) * 100).round()}')
                  : course != null
                      ? s.t('offline.ready').replaceAll('{lessons}', s.count('count.lessons', course.items.length)).replaceAll('{size}', sizeLabel(s, course.bytes))
                      : s.t('offline.cardTitle'),
              style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900),
            ),
          ),
          if (!_running && course != null) TextButton(onPressed: () => context.push('/downloads'), child: Text(s.t('offline.manage'))),
        ]),
        const SizedBox(height: 6),
        if (_running)
          ClipRRect(borderRadius: BorderRadius.circular(8), child: LinearProgressIndicator(value: overall, minHeight: 6, color: AppColors.gold500, backgroundColor: AppColors.navy100))
        else ...[
          Text(course != null ? expiryLabel(s, course.expiresAt, DateTime.now()) : s.t('offline.downloadHint'), style: const TextStyle(fontSize: 12.5, color: AppColors.muted, height: 1.5)),
          const SizedBox(height: 8),
          Align(
            alignment: AlignmentDirectional.centerStart,
            child: course == null
                ? FilledButton.icon(onPressed: _download, icon: const Icon(Icons.download_rounded), label: Text(s.t('offline.download')))
                : OutlinedButton.icon(onPressed: _download, icon: const Icon(Icons.sync_rounded), label: Text(s.t('offline.update'))),
          ),
        ],
      ]),
    );
  }
}

/// A small mark on a lesson: kept offline, or the share downloaded while it runs.
class OfflineLessonMark extends StatelessWidget {
  const OfflineLessonMark({super.key, required this.kept, this.progress});

  final bool kept;
  final double? progress;

  @override
  Widget build(BuildContext context) {
    final p = progress;
    if (p != null && p < 1) {
      return SizedBox.square(dimension: 18, child: CircularProgressIndicator(value: p, strokeWidth: 2.4, color: AppColors.gold500, backgroundColor: AppColors.navy100));
    }
    if (!kept && (p ?? 0) < 1) return const SizedBox.shrink();
    return Tooltip(message: context.s.t('offline.badge'), child: Icon(Icons.offline_pin_rounded, size: 18, color: AppColors.success));
  }
}

/// Starts keeping the offline content in step (send, hand in, delete) once the person is signed in.
void startOfflineSync(WidgetRef ref) => unawaited(Future<void>.microtask(() => ref.read(offlineCoordinatorProvider).start()));
