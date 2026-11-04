import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'kit_types.dart';

/// The kit (حقيبة) of an online programme as its trainer sees it: every module and lesson the learners get, read-only, with what each
/// holds; a lesson opens a sheet with its details and a way to look at its file or link.
class KitScreen extends ConsumerWidget {
  const KitScreen({super.key, required this.programId});

  final String programId;

  String get _path => '/me/programs/$programId/kit';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    return Scaffold(
      appBar: AppBar(title: Text(s.t('kit.title'))),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(getProvider(_path).future),
        child: AsyncView(
          value: ref.watch(getProvider(_path)),
          onRetry: () => ref.invalidate(getProvider(_path)),
          builder: (raw) {
            final d = Map<String, dynamic>.from((raw as Map)['data'] as Map);
            final totals = d.obj('totals') ?? {};
            final modules = d.list('modules');
            final lessons = [for (final m in modules) ...m.list('lessons')];
            return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(26)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Icon(Icons.inventory_2_outlined, color: AppColors.gold300),
                    const SizedBox(width: 8),
                    Text(s.t('kit.contents'), style: TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w800)),
                  ]),
                  const SizedBox(height: 10),
                  Text(d.obj('program')?.str('title') ?? '', style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800, height: 1.3)),
                  const SizedBox(height: 8),
                  Text(s.t('kit.readOnly'), style: const TextStyle(color: Colors.white70, fontSize: 12.5, height: 1.5)),
                ]),
              ),
              const SizedBox(height: 14),
              KitOverview(counts: kitCounts(lessons), minutes: totals.number('minutes').round()),
              if (modules.isEmpty) Padding(padding: const EdgeInsets.all(28), child: Center(child: Text(s.t('kit.empty'), style: const TextStyle(color: AppColors.muted)))),
              for (final m in modules) ...[
                SectionTitle(m.str('title')),
                Card(
                  clipBehavior: Clip.antiAlias,
                  child: Column(children: [
                    for (final l in m.list('lessons')) ...[
                      _KitLessonTile(lesson: l),
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

class _KitLessonTile extends StatelessWidget {
  const _KitLessonTile({required this.lesson});

  final Json lesson;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final type = kitTypes.contains(lesson.str('type')) ? lesson.str('type') : 'article';
    final minutes = (lesson.number('duration_seconds') / 60).ceil();
    return ListTile(
      onTap: () => showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        showDragHandle: true,
        builder: (_) => _KitLessonSheet(lesson: lesson),
      ),
      leading: CircleAvatar(backgroundColor: AppColors.navy100, child: Icon(kitIcon(type), color: AppColors.navy900)),
      title: Text(lesson.str('title'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
      subtitle: Text([s.t('kit.type.$type'), if (minutes > 0) s.t('kit.minutes').replaceAll('{n}', '$minutes'), if (lesson.flag('is_required')) s.t('kit.required')].join(' · ')),
      trailing: const Icon(Icons.chevron_right),
    );
  }
}

/// What a lesson holds: its description, its length or questions, and a way to look at its file or link.
class _KitLessonSheet extends StatelessWidget {
  const _KitLessonSheet({required this.lesson});

  final Json lesson;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final type = kitTypes.contains(lesson.str('type')) ? lesson.str('type') : 'article';
    final minutes = (lesson.number('duration_seconds') / 60).ceil();
    final questions = lesson.number('questions').toInt();
    final slides = lesson.number('slide_count').toInt();
    final url = lesson.str('url');
    return SafeArea(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 20),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Container(
              width: 48,
              height: 48,
              decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(16)),
              child: Icon(kitIcon(type), color: AppColors.gold300),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(s.t('kit.type.$type'), style: const TextStyle(color: AppColors.muted, fontSize: 12, fontWeight: FontWeight.w600)),
                Text(lesson.str('title'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.3)),
              ]),
            ),
          ]),
          const SizedBox(height: 12),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (minutes > 0) KitPill(icon: Icons.schedule_rounded, text: s.t('kit.minutes').replaceAll('{n}', '$minutes')),
            if (type == 'presentation' && slides > 0) KitPill(icon: Icons.slideshow_outlined, text: s.t('kit.slides').replaceAll('{n}', '$slides')),
            if (type == 'quiz') KitPill(icon: Icons.help_outline_rounded, text: s.t('kit.questions').replaceAll('{n}', '$questions').replaceAll('{p}', '${lesson.number('pass_percent').round()}')),
            if (type == 'survey') KitPill(icon: Icons.help_outline_rounded, text: s.t('kit.surveyQuestions').replaceAll('{n}', '$questions')),
            if (lesson.flag('is_required')) KitPill(icon: Icons.flag_outlined, text: s.t('kit.required')),
          ]),
          if (lesson.str('description').isNotEmpty) ...[
            const SizedBox(height: 14),
            Text(lesson.str('description'), style: const TextStyle(height: 1.6)),
          ],
          if (url.isNotEmpty) ...[
            const SizedBox(height: 18),
            FilledButton.icon(
              onPressed: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
              icon: const Icon(Icons.open_in_new),
              label: Text(s.t('kit.open')),
            ),
          ],
        ]),
      ),
    );
  }
}
