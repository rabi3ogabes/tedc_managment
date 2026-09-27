import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';
import '../../core/widgets/widgets.dart';

class HomeScreen extends ConsumerWidget {
  const HomeScreen({super.key});

  static const path = '/me/home';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final home = ref.watch(getProvider(path));

    return Scaffold(
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(path).future),
        child: AsyncView(
          value: home,
          onRetry: () => ref.invalidate(getProvider(path)),
          builder: (raw) {
            final d = Map<String, dynamic>.from(raw['data'] as Map);
            final stats = d.obj('stats') ?? {};
            final next = d.obj('next_session');
            final recommended = d.list('recommended');

            return CustomScrollView(slivers: [
              SliverToBoxAdapter(child: _Header(name: d.str('greeting_name'), hours: fmt.number(stats.number('training_hours')))),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
                sliver: SliverList.list(children: [
                  Row(children: [
                    Expanded(child: StatTile(label: s.t('home.active'), value: fmt.number(stats.number('active_programs')), icon: Icons.play_circle_outline)),
                    const SizedBox(width: 12),
                    Expanded(child: StatTile(label: s.t('home.completed'), value: fmt.number(stats.number('completed_programs')), icon: Icons.task_alt)),
                    const SizedBox(width: 12),
                    Expanded(child: StatTile(label: s.t('home.certificates'), value: fmt.number(stats.number('certificates')), icon: Icons.workspace_premium_outlined)),
                  ]),
                  const SizedBox(height: 16),
                  _ScanCard(onTap: () => context.push('/scan')),
                  if (stats.number('pending_surveys') > 0) ...[
                    const SizedBox(height: 12),
                    Card(
                      child: ListTile(
                        leading: const Icon(Icons.insights, color: AppColors.gold700),
                        title: Text('${fmt.number(stats.number('pending_surveys'))} ${s.t('home.pendingSurveys')}', style: const TextStyle(fontWeight: FontWeight.w700)),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => context.go('/training'),
                      ),
                    ),
                  ],
                  if (next != null) ...[
                    SectionTitle(s.t('home.nextSession')),
                    _NextSession(session: next, fmt: fmt),
                  ],
                  SectionTitle(s.t('home.recommended'), action: TextButton(onPressed: () => context.go('/programs'), child: Text(s.t('common.viewAll')))),
                  if (recommended.isEmpty) const EmptyView(),
                  for (final r in recommended) Padding(padding: const EdgeInsets.only(bottom: 14), child: RecommendationCard(item: r)),
                ]),
              ),
            ]);
          },
        ),
      ),
    );
  }
}

class _Header extends StatelessWidget {
  const _Header({required this.name, required this.hours});

  final String name;
  final String hours;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Container(
      decoration: const BoxDecoration(
        gradient: AppColors.navyGradient,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(32)),
      ),
      child: Stack(children: [
        const Positioned.fill(child: DotPattern(opacity: .12)),
        SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                const OfficialEmblem(size: 46),
                const SizedBox(width: 12),
                Expanded(child: Text(s.t('app.name'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 13))),
              ]),
              const SizedBox(height: 24),
              Text(s.t('home.welcome'), style: const TextStyle(color: Colors.white70)),
              Text(name, style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
              const SizedBox(height: 16),
              Row(children: [
                Text(hours, style: const TextStyle(color: AppColors.gold300, fontSize: 36, fontWeight: FontWeight.w800)),
                const SizedBox(width: 8),
                Text(s.t('home.hours'), style: const TextStyle(color: Colors.white70)),
              ]),
            ]),
          ),
        ),
      ]),
    );
  }
}

class _ScanCard extends StatelessWidget {
  const _ScanCard({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(22),
      child: Ink(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(gradient: AppColors.goldGradient, borderRadius: BorderRadius.circular(22)),
        child: Row(children: [
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(16)),
            child: const Icon(Icons.qr_code_scanner, color: AppColors.gold300, size: 28),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(s.t('home.scan'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17, color: AppColors.navy950)),
              Text(s.t('home.scanHint'), style: const TextStyle(color: AppColors.navy800, fontSize: 12.5)),
            ]),
          ),
          const Icon(Icons.arrow_forward_ios, size: 16, color: AppColors.navy900),
        ]),
      ),
    );
  }
}

class _NextSession extends StatelessWidget {
  const _NextSession({required this.session, required this.fmt});

  final Json session;
  final Fmt fmt;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(children: [
          Container(
            width: 58,
            padding: const EdgeInsets.symmetric(vertical: 10),
            decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(16)),
            child: Column(children: [
              Text('${session.date('starts_at')?.day ?? ''}', style: const TextStyle(color: AppColors.gold300, fontSize: 22, fontWeight: FontWeight.w800)),
              Text(fmt.time(session.date('starts_at')), style: const TextStyle(color: Colors.white70, fontSize: 10)),
            ]),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(session.str('program'), style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
              Text(session.str('title'), style: const TextStyle(color: AppColors.muted, fontSize: 13)),
              if (session.str('location').isNotEmpty)
                Row(children: [
                  const Icon(Icons.place_outlined, size: 14, color: AppColors.gold700),
                  const SizedBox(width: 4),
                  Expanded(child: Text(session.str('location'), style: const TextStyle(fontSize: 12, color: AppColors.muted))),
                ]),
            ]),
          ),
        ]),
      ),
    );
  }
}

class RecommendationCard extends StatelessWidget {
  const RecommendationCard({super.key, required this.item});

  final Json item;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final program = item.obj('program') ?? {};
    final category = program.obj('category');
    final reasons = (item['reasons'] as List? ?? const []).map((e) => e.toString()).toList();
    final score = item.number('score').toDouble();

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push('/programs/${program.str('code')}'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          ProgramCover(code: program.str('code'), category: category?.str('name'), categorySlug: category?.str('slug'), height: 90),
          Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Expanded(child: Text(program.str('title'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy900))),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(color: AppColors.gold100, borderRadius: BorderRadius.circular(99)),
                  child: Text('${score.round()}%', style: const TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w800)),
                ),
              ]),
              const SizedBox(height: 10),
              ProgressBar(score),
              const SizedBox(height: 10),
              Text(s.t('home.why'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: AppColors.navy800)),
              for (final r in reasons.take(3))
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Padding(padding: EdgeInsets.only(top: 6), child: CircleAvatar(radius: 2.5, backgroundColor: AppColors.gold500)),
                    const SizedBox(width: 8),
                    Expanded(child: Text(r, style: const TextStyle(fontSize: 12, color: AppColors.muted))),
                  ]),
                ),
            ]),
          ),
        ]),
      ),
    );
  }
}
