import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_style.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import '../reports/dashboard_section.dart';
import 'home_screen.dart';
import 'staff_home.dart';

/// The home screen of everyone who is not working as a trainee (trainer, school administrator, supervisor, coordinator,
/// centre and system administrators, executive): only what needs their action, with the cards the administrator chose
/// for their role (Settings → Brand Identity Studio → App home screen).
class RoleHome extends ConsumerWidget {
  const RoleHome({super.key});

  static const path = '/me/app-home';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(getProvider(path));
    final roleName = (ref.watch(authProvider).value?.activeRole)?.str('name');
    return Scaffold(
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(path).future),
        child: AsyncView(
          value: data,
          onRetry: () => ref.invalidate(getProvider(path)),
          builder: (raw) {
            final d = Map<String, dynamic>.from(raw['data'] as Map);
            final sections = (d['sections'] as List? ?? const []).map((e) => e.toString()).toList();
            return CustomScrollView(slivers: [
              SliverToBoxAdapter(child: HomeHeader(name: d.str('greeting_name'), identity: d.obj('identity') ?? const {}, roleLabel: roleName)),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 18, 20, 32),
                sliver: SliverList.list(children: [
                  for (final key in sections) ..._section(key, context, d),
                  const SizedBox(height: 4),
                  OutlinedButton.icon(
                    onPressed: () => launchUrl(StaffHome.dashboardUri, mode: LaunchMode.externalApplication),
                    icon: const Icon(Icons.open_in_new, size: 18),
                    label: Text(context.s.t('role.openDashboard')),
                  ),
                ]),
              ),
            ]);
          },
        ),
      ),
    );
  }

  List<Widget> _section(String key, BuildContext context, Json d) {
    const gap = SizedBox(height: 16);
    switch (key) {
      case 'actions':
        return [ActionTiles(tiles: d.list('actions')), gap];
      case 'today':
        return [TodaySessions(sessions: d.list('today'), trainer: d.str('role') == 'trainer'), gap];
      case 'dashboard':
        return const [DashboardSection()];
      default:
        return const [];   // the trainee's cards only exist in the trainee's view
    }
  }
}

IconData _icon(String name) => switch (name) {
      'approval' => Icons.approval,
      'how_to_reg' => Icons.how_to_reg,
      'edit_note' => Icons.edit_note,
      'assignment_ind' => Icons.assignment_ind_outlined,
      'bug_report' => Icons.bug_report_outlined,
      _ => Icons.task_alt,
    };

/// «Waiting for you»: one tile per thing that needs the person, with its number. Nothing waiting is said calmly.
class ActionTiles extends StatelessWidget {
  const ActionTiles({super.key, required this.tiles});

  final List<Json> tiles;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final lang = s.languageCode;
    if (tiles.isEmpty) {
      return Container(
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(AppStyle.radius(4)), border: Border.all(color: AppColors.navy100)),
        child: Row(children: [
          const Icon(Icons.check_circle, color: AppColors.success),
          const SizedBox(width: 10),
          Expanded(child: Text(s.t('role.allClear'), style: const TextStyle(color: AppColors.muted, fontWeight: FontWeight.w600))),
        ]),
      );
    }
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(s.t('role.waiting'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900))),
      GridView(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(crossAxisCount: 2, mainAxisSpacing: 12, crossAxisSpacing: 12, mainAxisExtent: 128),
        children: [for (final t in tiles) _Tile(tile: t, lang: lang, count: fmt.number(t.number('count')))],
      ),
    ]);
  }
}

class _Tile extends StatelessWidget {
  const _Tile({required this.tile, required this.lang, required this.count});

  final Json tile;
  final String lang;
  final String count;

  @override
  Widget build(BuildContext context) {
    final tone = tile.str('tone');
    final label = tile.obj('label')?.str(lang) ?? '';
    final gold = tone == 'gold';
    final danger = tone == 'danger';
    final fg = gold ? AppColors.navy950 : (danger ? AppColors.danger : Colors.white);
    final route = tile.str('route');
    final web = tile.str('web');
    void open() {
      if (route.isNotEmpty) {
        context.push(route);
      } else if (web.isNotEmpty) {
        launchUrl(StaffHome.dashboardUri.replace(path: '${StaffHome.dashboardUri.path.replaceFirst(RegExp(r'/admin/?$'), '')}$web'), mode: LaunchMode.externalApplication);
      }
    }

    return Semantics(
      button: true,
      label: '$label: $count',
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(AppStyle.radius(4)),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: open,
          child: Ink(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              gradient: gold ? AppColors.goldGradient : (danger ? null : AppColors.navyGradient),
              color: danger ? const Color(0xFFFBEAEA) : null,
              borderRadius: BorderRadius.circular(AppStyle.radius(4)),
              boxShadow: [BoxShadow(color: AppColors.navy900.withValues(alpha: .14), blurRadius: 16, offset: const Offset(0, 8))],
            ),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                Icon(_icon(tile.str('icon')), color: fg.withValues(alpha: .9), size: 22),
                const Spacer(),
                Icon(route.isEmpty ? Icons.open_in_new : Icons.arrow_forward_ios, size: 13, color: fg.withValues(alpha: .6)),
              ]),
              const Spacer(),
              Text(count, style: TextStyle(color: fg, fontSize: 28, fontWeight: FontWeight.w800, height: 1)),
              const SizedBox(height: 4),
              Text(label, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: fg.withValues(alpha: .85), fontSize: 12, fontWeight: FontWeight.w600, height: 1.25)),
            ]),
          ),
        ),
      ),
    );
  }
}

/// Today's sessions: a trainer's own, or the centre's for people who manage programs.
class TodaySessions extends StatelessWidget {
  const TodaySessions({super.key, required this.sessions, required this.trainer});

  final List<Json> sessions;
  final bool trainer;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(padding: const EdgeInsets.only(bottom: 12), child: Text(s.t('role.today'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900))),
      if (sessions.isEmpty)
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(18),
          decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .5), borderRadius: BorderRadius.circular(AppStyle.radius(0))),
          child: Text(s.t('role.noToday'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted)),
        )
      else
        for (final x in sessions)
          Padding(
            padding: const EdgeInsets.only(bottom: 10),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Row(children: [
                  Container(
                    width: 64,
                    padding: const EdgeInsets.symmetric(vertical: 10),
                    decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(16)),
                    child: Column(children: [
                      Text(fmt.time(x.date('starts_at')), style: TextStyle(color: AppColors.gold300, fontSize: 13, fontWeight: FontWeight.w800)),
                      const SizedBox(height: 2),
                      Text(fmt.time(x.date('ends_at')), style: const TextStyle(color: Colors.white70, fontSize: 10.5)),
                    ]),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(x.str('program'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.25)),
                      if (x.str('title').isNotEmpty) Text(x.str('title'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                      if (x.str('location').isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 4),
                          child: Row(children: [
                            Icon(Icons.place_outlined, size: 14, color: AppColors.gold700),
                            const SizedBox(width: 4),
                            Expanded(child: Text(x.str('location'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, color: AppColors.muted))),
                          ]),
                        ),
                    ]),
                  ),
                ]),
              ),
            ),
          ),
      if (trainer && sessions.isNotEmpty)
        FilledButton.icon(
          onPressed: () => context.push('/scan?trainer=1'),
          icon: const Icon(Icons.qr_code_scanner),
          label: Text(s.t('role.rollCall')),
        ),
    ]);
  }
}
