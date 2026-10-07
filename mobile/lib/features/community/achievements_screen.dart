import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// Points and level, badges, the leaderboard, open challenges and the rewards shop.
class AchievementsScreen extends ConsumerWidget {
  const AchievementsScreen({super.key});

  String _name(BuildContext context, Json j) => context.s.isArabic ? j.str('name_ar') : j.str('name_en');

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final me = ref.watch(getProvider('/gamification/me'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('gam.title'))),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(getProvider('/gamification/me'));
          ref.invalidate(getProvider('/gamification/leaderboard'));
          ref.invalidate(getProvider('/gamification/challenges'));
          ref.invalidate(getProvider('/gamification/rewards'));
        },
        child: AsyncView(
          value: me,
          onRetry: () => ref.invalidate(getProvider('/gamification/me')),
          builder: (raw) {
            final p = Map<String, dynamic>.from((raw as Map)['data'] as Map);
            final level = p.obj('level') ?? {};
            final next = p.obj('next_level');
            final earned = p.number('earned').toDouble();
            final base = level.number('min_points').toDouble();
            final span = next == null ? 1.0 : (next.number('min_points').toDouble() - base).clamp(1, double.infinity);
            final progress = next == null ? 1.0 : ((earned - base) / span).clamp(0.0, 1.0);
            final badges = p.list('badges');
            return ListView(padding: const EdgeInsets.all(16), children: [
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Icon(Icons.emoji_events_outlined, size: 40, color: AppColors.gold500),
                      const SizedBox(width: 12),
                      Expanded(child: Text('${context.tr('gam.level')} ${level.str('no')} · ${_name(context, level)}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
                      Text(p.str('points'), style: TextStyle(fontSize: 28, fontWeight: FontWeight.w900, color: AppColors.navy900)),
                    ]),
                    const SizedBox(height: 12),
                    LinearProgressIndicator(value: progress, minHeight: 8, borderRadius: BorderRadius.circular(8), color: AppColors.gold500),
                    const SizedBox(height: 6),
                    Text(next == null ? context.tr('gam.maxLevel') : '${next.str('points_needed')} ${context.tr('gam.toNext')} ${_name(context, next)}', style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                  ]),
                ),
              ),
              const SizedBox(height: 16),
              Text(context.tr('gam.badges'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              if (badges.isEmpty) Padding(padding: const EdgeInsets.all(12), child: Text(context.tr('gam.noBadges'))),
              Wrap(spacing: 10, runSpacing: 10, children: [
                for (final b in badges)
                  Chip(avatar: Icon(Icons.workspace_premium, color: AppColors.gold500), label: Text(_name(context, b))),
              ]),
              const SizedBox(height: 16),
              _Section(path: '/gamification/challenges', title: context.tr('gam.challenges'), builder: (rows) => [
                    if (rows.isEmpty) Padding(padding: const EdgeInsets.all(12), child: Text(context.tr('gam.noChallenges'))),
                    for (final c in rows) _Challenge(c: c),
                  ]),
              const SizedBox(height: 16),
              _Section(path: '/gamification/rewards', title: context.tr('gam.rewards'), builder: (rows) => [
                    if (rows.isEmpty) Padding(padding: const EdgeInsets.all(12), child: Text(context.tr('gam.noRewards'))),
                    for (final r in rows) _Reward(r: r),
                  ]),
              const SizedBox(height: 16),
              const _Board(),
            ]);
          },
        ),
      ),
    );
  }
}

class _Section extends ConsumerWidget {
  const _Section({required this.path, required this.title, required this.builder});

  final String path;
  final String title;
  final List<Widget> Function(List<Json> rows) builder;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final res = ref.watch(getProvider(path));
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      const SizedBox(height: 8),
      AsyncView(value: res, onRetry: () => ref.invalidate(getProvider(path)), builder: (raw) => Column(children: builder(Map<String, dynamic>.from(raw as Map).list('data')))),
    ]);
  }
}

class _Challenge extends ConsumerWidget {
  const _Challenge({required this.c});

  final Json c;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final goal = c.obj('goal')?.number('count').toInt() ?? 1;
    final progress = c.number('progress').toInt();
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(context.s.isArabic ? c.str('title_ar') : c.str('title_en'), style: const TextStyle(fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(context.s.isArabic ? c.str('description_ar') : c.str('description_en'), style: const TextStyle(color: AppColors.muted)),
          const SizedBox(height: 8),
          if (c.flag('joined')) ...[
            LinearProgressIndicator(value: (progress / goal).clamp(0.0, 1.0), minHeight: 6, borderRadius: BorderRadius.circular(6), color: AppColors.gold500),
            const SizedBox(height: 4),
            Text(c.flag('completed') ? context.tr('gam.done') : '$progress / $goal', style: const TextStyle(fontSize: 12)),
          ] else if (!c.flag('closed'))
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: FilledButton(
                onPressed: () async {
                  try {
                    await ref.read(apiProvider).post('/gamification/challenges/${c.str('id')}/join');
                    ref.invalidate(getProvider('/gamification/challenges'));
                  } catch (e) {
                    if (context.mounted) showSnack(context, e.toString(), error: true);
                  }
                },
                child: Text(context.tr('gam.join')),
              ),
            ),
        ]),
      ),
    );
  }
}

class _Reward extends ConsumerWidget {
  const _Reward({required this.r});

  final Json r;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Card(
      child: ListTile(
        leading: Icon(Icons.card_giftcard, color: AppColors.gold500),
        title: Text(context.s.isArabic ? r.str('title_ar') : r.str('title_en'), style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text('${r.str('cost_points')} ${context.tr('gam.pts')}'),
        trailing: FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 48)), 
          onPressed: r.flag('can_redeem')
              ? () async {
                  try {
                    final res = await ref.read(apiProvider).post('/gamification/rewards/${r.str('id')}/redeem');
                    final code = res is Map && res['data'] is Map ? (res['data'] as Map)['code'] : '';
                    if (context.mounted) showSnack(context, '${context.tr('gam.redeemed')} $code');
                    ref.invalidate(getProvider('/gamification/rewards'));
                    ref.invalidate(getProvider('/gamification/me'));
                  } catch (e) {
                    if (context.mounted) showSnack(context, e.toString(), error: true);
                  }
                }
              : null,
          child: Text(context.tr('gam.redeem')),
        ),
      ),
    );
  }
}

class _Board extends ConsumerWidget {
  const _Board();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final res = ref.watch(getProvider('/gamification/leaderboard?period=month&scope=ministry'));
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(context.tr('gam.board'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      const SizedBox(height: 8),
      AsyncView(
        value: res,
        onRetry: () => ref.invalidate(getProvider('/gamification/leaderboard?period=month&scope=ministry')),
        builder: (raw) {
          final rows = (Map<String, dynamic>.from(raw as Map).obj('data') ?? {}).list('rows');
          if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(12), child: Text(context.tr('gam.noBoard')));
          return Card(
            child: Column(children: [
              for (final r in rows)
                ListTile(
                  dense: true,
                  tileColor: r.flag('me') ? AppColors.gold500.withValues(alpha: .12) : null,
                  leading: CircleAvatar(radius: 14, child: Text(r.str('rank'), style: const TextStyle(fontSize: 12))),
                  title: Text(r.str('name', '—')),
                  trailing: Text(r.str('points'), style: const TextStyle(fontWeight: FontWeight.w800)),
                ),
            ]),
          );
        },
      ),
    ]);
  }
}
