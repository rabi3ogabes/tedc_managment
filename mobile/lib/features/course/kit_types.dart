import 'package:flutter/material.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/theme/app_theme.dart';

/// The kinds of lesson a kit (حقيبة) holds, in the order the overview lists them: videos, PowerPoint presentations, quizzes, surveys,
/// articles, interactive units, learning tools and external courses.
const kitTypes = ['video', 'presentation', 'quiz', 'survey', 'article', 'package', 'lti', 'external'];

/// The icon of a kind of lesson.
IconData kitIcon(String type) => switch (type) {
      'video' => Icons.play_circle_outline,
      'presentation' => Icons.slideshow_outlined,
      'quiz' => Icons.quiz_outlined,
      'survey' => Icons.rate_review_outlined,
      'package' => Icons.extension_outlined,
      'lti' => Icons.apps_outlined,
      'external' => Icons.open_in_new_rounded,
      _ => Icons.article_outlined,
    };

/// Whether a kind of lesson runs in the browser rather than in the app's own player.
bool kitRunsOnWeb(String type) => type == 'package' || type == 'lti' || type == 'external';

/// How many lessons of each kind, in kit order; a kind the app does not know yet counts as an article.
Map<String, int> kitCounts(Iterable<Json> lessons) {
  final raw = <String, int>{};
  for (final l in lessons) {
    final type = kitTypes.contains(l.str('type')) ? l.str('type') : 'article';
    raw[type] = (raw[type] ?? 0) + 1;
  }
  return {
    for (final t in kitTypes)
      if (raw[t] != null) t: raw[t]!,
  };
}

/// The kit at a glance: a chip for each kind of lesson with how many, and the total minutes.
class KitOverview extends StatelessWidget {
  const KitOverview({super.key, required this.counts, this.minutes});

  final Map<String, int> counts;
  final int? minutes;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final total = minutes;
    return Wrap(spacing: 8, runSpacing: 8, children: [
      for (final e in counts.entries) KitPill(icon: kitIcon(e.key), text: '${s.t('kit.type.${e.key}')} · ${e.value}'),
      if (total != null && total > 0) KitPill(icon: Icons.schedule_rounded, text: s.t('kit.minutes').replaceAll('{n}', '$total')),
    ]);
  }
}

class KitPill extends StatelessWidget {
  const KitPill({super.key, required this.icon, required this.text});

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.navy100)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 16, color: AppColors.gold700),
          const SizedBox(width: 6),
          Text(text, style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w700, color: AppColors.navy900)),
        ]),
      );
}
