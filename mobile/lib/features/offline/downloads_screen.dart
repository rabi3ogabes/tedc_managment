import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/offline/offline_exams.dart';
import '../../core/offline/offline_providers.dart';
import '../../core/offline/offline_store.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import '../assessment/assessments_screen.dart' show handInOfflineExam;

/// "245 MB" — sizes with Western digits.
String sizeLabel(S s, int bytes) {
  final fmt = Fmt(s.languageCode);
  // One decimal only while it says something: 4.8 MB, but 248 MB and 5 MB.
  String n(double v) => fmt.number(v, digits: v >= 10 || v == v.roundToDouble() ? 0 : 1);
  if (bytes >= 1 << 30) return '${n(bytes / (1 << 30))} ${s.t('offline.gb')}';
  if (bytes >= 1 << 20) return '${n(bytes / (1 << 20))} ${s.t('offline.mb')}';
  return '${fmt.number((bytes / 1024).ceil())} ${s.t('offline.kb')}';
}

/// "Removed in 9 days", "Removed today", or expired.
String expiryLabel(S s, DateTime? expires, DateTime now) {
  if (expires == null) return '';
  final left = expires.difference(now);
  if (left.isNegative || left == Duration.zero) return s.t('offline.expired');
  if (left.inHours < 24) return s.t('offline.expiresToday');
  return s.t('offline.expiresIn').replaceAll('{days}', s.count('count.days', (left.inHours / 24).ceil()));
}

/// Everything kept on this phone for learning without a connection: courses and exams, the space they take, when
/// they are removed, and the Wi-Fi only switch.
class DownloadsScreen extends ConsumerWidget {
  const DownloadsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final courses = ref.watch(offlineCoursesProvider);
    final exams = ref.watch(offlineExamListProvider).value ?? const <OfflineExam>[];
    final used = ref.watch(offlineUsageProvider).value;
    final wifiOnly = ref.watch(offlineWifiOnlyProvider).value ?? false;
    final offlineNow = ref.watch(offlineNowProvider);
    final now = DateTime.now();
    final shown = exams.where((e) => e.status != 'submitted' || e.attemptId != null).toList().reversed.toList();

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('offline.title'))),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(offlineCoursesProvider);
          ref.invalidate(offlineExamListProvider);
          ref.invalidate(offlineUsageProvider);
          await ref.read(offlineCoursesProvider.future);
        },
        child: ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(26)),
            child: Row(children: [
              Container(
                width: 56,
                height: 56,
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(18)),
                child: Icon(Icons.sd_storage_rounded, color: AppColors.gold300, size: 28),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(used == null ? '—' : sizeLabel(s, used), key: const ValueKey('offline-used'), style: const TextStyle(color: Colors.white, fontSize: 26, fontWeight: FontWeight.w800)),
                  Text(s.t('offline.used'), style: TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w600)),
                ]),
              ),
            ]),
          ),
          const SizedBox(height: 12),
          Card(
            child: SwitchListTile(
              value: wifiOnly,
              onChanged: (v) async {
                await ref.read(offlineStoreProvider).setWifiOnly(v);
                if (context.mounted) ref.invalidate(offlineWifiOnlyProvider);
              },
              secondary: Icon(Icons.wifi_rounded, color: AppColors.gold700),
              title: Text(s.t('offline.wifiOnly'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(s.t('offline.wifiOnlyHint')),
            ),
          ),
          if (offlineNow)
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Row(children: [
                const Icon(Icons.cloud_off_rounded, size: 18, color: AppColors.muted),
                const SizedBox(width: 8),
                Expanded(child: Text(s.t('offline.youAreOffline'), style: const TextStyle(color: AppColors.muted, fontWeight: FontWeight.w600))),
              ]),
            ),
          courses.when(
            loading: () => const Padding(padding: EdgeInsets.all(32), child: Center(child: CircularProgressIndicator())),
            error: (e, _) => ErrorView(message: e.toString(), onRetry: () => ref.invalidate(offlineCoursesProvider)),
            data: (list) {
              if (list.isEmpty && shown.isEmpty) return EmptyView(icon: Icons.download_for_offline_outlined, text: s.t('offline.empty'));
              return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                if (list.isNotEmpty) SectionTitle(s.t('offline.courses')),
                for (final c in list) _CourseCard(course: c, now: now),
                if (shown.isNotEmpty) SectionTitle(s.t('offline.exams')),
                for (final e in shown) _ExamCard(exam: e),
              ]);
            },
          ),
        ]),
      ),
    );
  }
}

class _CourseCard extends ConsumerWidget {
  const _CourseCard({required this.course, required this.now});

  final OfflineCourse course;
  final DateTime now;

  Future<void> _remove(BuildContext context, WidgetRef ref) async {
    final s = context.s;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(s.t('offline.remove')),
        content: Text(s.t('offline.removeConfirm').replaceAll('{title}', course.title)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(s.t('common.cancel'))),
          FilledButton(style: FilledButton.styleFrom(backgroundColor: AppColors.danger), onPressed: () => Navigator.pop(ctx, true), child: Text(s.t('offline.remove'))),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    await ref.read(offlineStoreProvider).removeCourse(course.registrationId);
    if (!context.mounted) return;
    ref.invalidate(offlineCoursesProvider);
    ref.invalidate(offlineUsageProvider);
    showSnack(context, s.t('offline.removed'));
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final expired = course.expired(now);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 14, 8, 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(course.title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5)),
          const SizedBox(height: 4),
          Text('${s.count('count.lessons', course.items.length)} · ${sizeLabel(s, course.bytes)}', style: const TextStyle(color: AppColors.muted, fontSize: 13)),
          const SizedBox(height: 8),
          Row(children: [
            Icon(expired ? Icons.error_outline_rounded : Icons.schedule_rounded, size: 16, color: expired ? AppColors.danger : AppColors.gold700),
            const SizedBox(width: 6),
            Expanded(child: Text(expiryLabel(s, course.expiresAt, now), style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: expired ? AppColors.danger : AppColors.gold700))),
            TextButton(onPressed: () => context.push('/courses/${course.registrationId}'), child: Text(s.t('offline.open'))),
            TextButton(onPressed: () => _remove(context, ref), style: TextButton.styleFrom(foregroundColor: AppColors.danger), child: Text(s.t('offline.remove'))),
          ]),
        ]),
      ),
    );
  }
}

class _ExamCard extends ConsumerWidget {
  const _ExamCard({required this.exam});

  final OfflineExam exam;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final (IconData icon, String status) = switch (exam.status) {
      'finished' => (Icons.cloud_upload_outlined, s.t('oexam.waiting')),
      'submitted' => (Icons.verified_rounded, s.t('oexam.handedIn')),
      'refused' => (Icons.block_rounded, s.t('oexam.refused').replaceAll('{reason}', s.t('oexam.reason.${exam.reason ?? 'refused'}'))),
      _ => (Icons.offline_pin_rounded, s.t('oexam.until').replaceAll('{date}', fmt.dateTime(exam.expiresAt?.toLocal()))),
    };
    return Card(
      child: ListTile(
        leading: Icon(icon, color: exam.status == 'refused' ? AppColors.danger : AppColors.gold700),
        title: Text(exam.title(s.isArabic), style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text(status),
        trailing: switch (exam.status) {
          'finished' => TextButton(onPressed: () => handInOfflineExam(context, ref, exam), child: Text(s.t('oexam.handIn'))),
          'submitted' => TextButton(onPressed: () => context.push('/attempts/${exam.attemptId}'), child: Text(s.t('oexam.viewResult'))),
          'refused' => IconButton(
              tooltip: s.t('offline.remove'),
              icon: const Icon(Icons.delete_outline),
              onPressed: () async {
                await ref.read(offlineExamsProvider).remove(exam.tokenId);
                if (context.mounted) ref.invalidate(offlineExamListProvider);
              }),
          _ => TextButton(onPressed: () => context.push('/offline-exams/${exam.tokenId}'), child: Text(s.t(exam.status == 'started' ? 'oexam.continue' : 'oexam.take'))),
        },
      ),
    );
  }
}
