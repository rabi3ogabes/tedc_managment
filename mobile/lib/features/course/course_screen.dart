import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The online course of a program: progress, the modules and lessons with locks, and "continue where you left off".
class CourseScreen extends ConsumerWidget {
  const CourseScreen({super.key, required this.registrationId});

  final String registrationId;

  String get _path => '/me/registrations/$registrationId/course';

  static IconData iconFor(String type) => switch (type) {
        'video' => Icons.play_circle_outline,
        'presentation' => Icons.slideshow_outlined,
        'quiz' => Icons.quiz_outlined,
        'survey' => Icons.rate_review_outlined,
        _ => Icons.article_outlined,
      };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);

    return Scaffold(
      appBar: AppBar(title: Text(s.t('course.title'))),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(getProvider(_path).future),
        child: AsyncView(
          value: ref.watch(getProvider(_path)),
          onRetry: () => ref.invalidate(getProvider(_path)),
          builder: (raw) {
            final d = Map<String, dynamic>.from((raw as Map)['data'] as Map);
            final summary = d.obj('summary') ?? {};
            final percent = summary.number('percent').toDouble();
            final modules = d.list('modules');
            final go = summary.str('resume_lesson_id').isNotEmpty ? summary.str('resume_lesson_id') : summary.str('next_lesson_id');

            return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(26)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(d.obj('program')?.str('title') ?? '', style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 14),
                  Row(children: [
                    SizedBox(
                      width: 64,
                      height: 64,
                      child: Stack(alignment: Alignment.center, children: [
                        CircularProgressIndicator(value: percent / 100, strokeWidth: 6, backgroundColor: Colors.white24, color: AppColors.gold300),
                        Text('${percent.round()}%', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                      ]),
                    ),
                    const SizedBox(width: 16),
                    Expanded(child: Text('${fmt.number(summary.number('completed'))} / ${fmt.number(summary.number('required'))} ${s.t('course.lessonsDone')}', style: TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w700))),
                  ]),
                  if (go.isNotEmpty && summary['completed_course'] != true) ...[
                    const SizedBox(height: 16),
                    FilledButton.icon(
                      onPressed: () => context.push('/lessons/$go?registration=$registrationId'),
                      style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, minimumSize: const Size.fromHeight(50)),
                      icon: const Icon(Icons.play_arrow),
                      label: Text(summary.str('resume_lesson_id').isNotEmpty ? s.t('course.resume') : s.t('course.start')),
                    ),
                  ],
                  if ((summary.obj('certificate')?['issued'] == true)) ...[
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: () => context.go('/certificates'),
                      style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Colors.white54)),
                      icon: const Icon(Icons.workspace_premium_outlined),
                      label: Text(s.t('course.certIssued')),
                    ),
                  ] else if (summary['completed_course'] == true && summary.obj('certificate') != null && (summary.obj('certificate')!['missing'] as List).isNotEmpty)
                    Padding(padding: const EdgeInsets.only(top: 10), child: Text('${s.t('course.certPending')}: ${(summary.obj('certificate')!['missing'] as List).join(' · ')}', style: TextStyle(color: AppColors.gold300, fontSize: 12.5))),
                  if (summary['completed_course'] == true) Padding(padding: const EdgeInsets.only(top: 12), child: Text('🎉 ${s.t('course.allDone')}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700))),
                ]),
              ),
              for (final m in modules) ...[
                SectionTitle(m.str('title')),
                Card(
                  clipBehavior: Clip.antiAlias,
                  child: Column(children: [
                    for (final l in m.list('lessons')) ...[
                      _LessonTile(lesson: l, onTap: () => context.push('/lessons/${l.str('id')}?registration=$registrationId'), fmt: fmt),
                      if (l != m.list('lessons').last) Divider(height: 1, color: AppColors.navy100),
                    ],
                  ]),
                ),
              ],
            ]);
          },
        ),
      ),
    );
  }
}

class _LessonTile extends StatelessWidget {
  const _LessonTile({required this.lesson, required this.onTap, required this.fmt});

  final Json lesson;
  final VoidCallback onTap;
  final Fmt fmt;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final locked = lesson.flag('locked');
    final status = lesson.str('status');
    final done = status == 'completed';
    final minutes = (lesson.number('duration_seconds') / 60).ceil();

    return ListTile(
      enabled: !locked,
      onTap: locked ? () => showSnack(context, s.t('course.lockedHint')) : onTap,
      leading: CircleAvatar(
        backgroundColor: done ? AppColors.success : AppColors.navy100,
        child: Icon(done ? Icons.check : locked ? Icons.lock_outline : CourseScreen.iconFor(lesson.str('type')), color: done ? Colors.white : AppColors.navy800, size: 20),
      ),
      title: Text(lesson.str('title'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
      subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text([s.t('course.type.${lesson.str('type')}'), if (minutes > 0) '$minutes ${s.t('course.min')}', if (!lesson.flag('is_required')) s.t('course.optional')].join(' · '), style: const TextStyle(fontSize: 12)),
        if (status == 'in_progress' && lesson.number('percent') > 0) Padding(padding: const EdgeInsets.only(top: 6), child: ProgressBar(lesson.number('percent').toDouble())),
      ]),
      trailing: locked ? null : const Icon(Icons.chevron_right),
    );
  }
}
