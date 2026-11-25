import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_style.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';
import '../../core/widgets/widgets.dart';
import '../duties/duties_model.dart';
import '../home/home_screen.dart';
import '../home/role_home.dart';
import '../home/staff_home.dart';
import 'parts.dart';
import 'supervision.dart';
import 'supervisor_programmes.dart';

/// The supervisor's home: the session to act on now with the ways to take its attendance, the day and the programmes in
/// four numbers, the other sessions of today, and the programmes that are running and the ones still to start.
class SupervisorHome extends ConsumerWidget {
  const SupervisorHome({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final me = ref.watch(authProvider).value;
    // The greeting, the identity line and what waits for the person come with the role's home; the screen does not wait for them.
    final homeRaw = ref.watch(getProvider(RoleHome.path)).value;
    final home = homeRaw is Map && homeRaw['data'] is Map ? Map<String, dynamic>.from(homeRaw['data'] as Map) : const <String, dynamic>{};
    final duties = ref.watch(getProvider(Duties.path));
    final mayAttend = me?.can('attendance.manage') ?? false;

    return Scaffold(
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () {
          ref.invalidate(getProvider(RoleHome.path));
          return ref.refresh(getProvider(Duties.path).future);
        },
        child: CustomScrollView(physics: const AlwaysScrollableScrollPhysics(), slivers: [
          SliverToBoxAdapter(child: HomeHeader(name: home.str('greeting_name').isNotEmpty ? home.str('greeting_name') : (me?.name ?? ''), identity: home.obj('identity') ?? const {}, roleLabel: me?.activeRole?.str('name'))),
          SliverPadding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
            sliver: SliverList.list(
              children: duties.when(
                loading: () => const [LoadingView(items: 3)],
                error: (e, _) => [ErrorView(message: ApiException.from(e).message, onRetry: () => ref.invalidate(getProvider(Duties.path)))],
                data: (raw) => [
                  ...supervisorHomeBody(context, Duties.groups(raw), mayAttend: mayAttend),
                  if (home.list('actions').isNotEmpty) ...[const SizedBox(height: 22), ActionTiles(tiles: home.list('actions'))],
                  const SizedBox(height: 18),
                  Center(
                    child: TextButton.icon(
                      onPressed: () => launchUrl(StaffHome.dashboardUri, mode: LaunchMode.externalApplication),
                      icon: const Icon(Icons.open_in_new, size: 16),
                      label: Text(s.t('role.openDashboard')),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

/// What the home shows from the groups given to the person. [now] is for tests.
List<Widget> supervisorHomeBody(BuildContext context, List<Json> groups, {required bool mayAttend, DateTime? now}) {
  final s = context.s;
  if (groups.isEmpty) return [Quiet(icon: Icons.assignment_turned_in_outlined, text: s.t('duties.none'), hint: s.t('duties.noneHint'))];

  final today = Supervision.today(groups, now);
  final focus = Supervision.focus(groups, now);
  final others = [for (final x in today) if (x.session.str('id') != focus?.session.str('id')) x];
  final running = Supervision.running(groups);
  final upcoming = Supervision.upcoming(groups);
  final next = Supervision.ahead(groups, now);

  return [
    if (focus != null) NowCard(slot: focus, mayAttend: mayAttend) else FreeDayCard(next: next.isEmpty ? null : next.first),
    const SizedBox(height: 12),
    Row(children: [
      _Stat(value: running.length, label: s.t('sup.stat.running'), icon: Icons.play_circle_outline, onTap: () => context.go('/programs')),
      const SizedBox(width: 8),
      _Stat(value: upcoming.length, label: s.t('sup.stat.upcoming'), icon: Icons.event_outlined, onTap: () => context.go('/programs')),
      const SizedBox(width: 8),
      _Stat(value: today.length, label: s.t('sup.stat.today'), icon: Icons.today_outlined, onTap: () => context.go('/training')),
      const SizedBox(width: 8),
      _Stat(value: Supervision.trainees(groups), label: s.t('sup.stat.trainees'), icon: Icons.groups_2_outlined),
    ]),
    if (others.isNotEmpty) ...[
      const SizedBox(height: 22),
      Heading(s.t(focus == null ? 'sup.today' : 'sup.todayRest'), count: others.length, action: s.t('sup.nav.attendance'), onAction: () => context.go('/training')),
      for (final x in others) Padding(padding: const EdgeInsets.only(bottom: 10), child: SlotCard(slot: x, mayAttend: mayAttend)),
    ],
    if (running.isNotEmpty) ...[
      const SizedBox(height: 22),
      Heading(s.t('sup.running'), count: running.length),
      for (final g in running) Padding(padding: const EdgeInsets.only(bottom: 10), child: GroupTile(group: g)),
    ],
    const SizedBox(height: 22),
    Heading(s.t('sup.upcoming'), count: upcoming.isEmpty ? null : upcoming.length, action: s.t('common.viewAll'), onAction: () => context.go('/programs')),
    if (upcoming.isEmpty)
      Quiet(icon: Icons.event_available_outlined, text: s.t('sup.noUpcoming'))
    else
      for (final g in upcoming.take(3)) Padding(padding: const EdgeInsets.only(bottom: 10), child: GroupTile(group: g)),
  ];
}

/// The session to act on: what it is, when and where, who came so far, and the three ways to take its attendance.
class NowCard extends StatelessWidget {
  const NowCard({super.key, required this.slot, required this.mayAttend});

  final Slot slot;
  final bool mayAttend;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final session = slot.session;
    final live = session.str('when') == 'now';
    final title = session.str(lang == 'en' ? 'title_en' : 'title_ar');
    final place = Duties.name(session.obj('place'), lang);
    final radius = BorderRadius.circular(AppStyle.radius(6));

    return Container(
      decoration: BoxDecoration(
        gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [AppColors.navy950, AppColors.navy900, AppColors.navy700]),
        borderRadius: radius,
        boxShadow: [BoxShadow(color: AppColors.navy900.withValues(alpha: .28), blurRadius: 24, offset: const Offset(0, 12))],
      ),
      clipBehavior: Clip.antiAlias,
      child: Stack(children: [
        const Positioned.fill(child: DotPattern(opacity: .08)),
        Padding(
          padding: const EdgeInsets.all(18),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                decoration: BoxDecoration(color: live ? AppColors.gold500 : Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(99)),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(live ? Icons.sensors : Icons.schedule, size: 14, color: live ? AppColors.navy950 : AppColors.gold300),
                  const SizedBox(width: 5),
                  Text(s.t(live ? 'sup.now' : 'sup.next'), style: TextStyle(color: live ? AppColors.navy950 : Colors.white, fontSize: 11.5, fontWeight: FontWeight.w800)),
                ]),
              ),
              const Spacer(),
              Text('${fmt.time(session.date('starts_at'))} – ${fmt.time(session.date('ends_at'))}', style: TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w800, fontSize: 13)),
            ]),
            const SizedBox(height: 14),
            InkWell(
              onTap: () => context.push('/my-programs/${slot.group.str('id')}'),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(Duties.title(slot.group, lang), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800, height: 1.3)),
                if ([title, place].any((e) => e.isNotEmpty))
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Row(children: [
                      const Icon(Icons.place_outlined, size: 15, color: Colors.white70),
                      const SizedBox(width: 5),
                      Expanded(child: Text([if (title.isNotEmpty) title, if (place.isNotEmpty) place].join(' · '), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white70, fontSize: 13))),
                    ]),
                  ),
              ]),
            ),
            if (live) ...[
              const SizedBox(height: 16),
              AttendanceLine(present: session.number('present'), expected: session.number('expected'), onDark: true, label: s.t('duties.attendance')),
            ],
            if (mayAttend) ...[const SizedBox(height: 16), SessionActions(sessionId: session.str('id'), onDark: true)],
          ]),
        ),
      ]),
    );
  }
}

/// A day with nothing left to run: said plainly, with the next session when there is one.
class FreeDayCard extends StatelessWidget {
  const FreeDayCard({super.key, this.next});

  final Slot? next;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final x = next;
    return Surface(
      padding: const EdgeInsets.all(16),
      onTap: x == null ? null : () => context.push('/my-programs/${x.group.str('id')}'),
      child: Row(children: [
        Container(
          width: 48,
          height: 48,
          decoration: BoxDecoration(color: AppColors.gold100, borderRadius: BorderRadius.circular(16)),
          child: Icon(Icons.event_available_outlined, color: AppColors.gold700),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(s.t('sup.freeDay'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5, color: AppColors.navy900)),
            const SizedBox(height: 2),
            Text(
              x == null ? s.t('sup.noNext') : '${s.t('duties.session.next')}: ${fmt.weekdayDate(x.session.date('starts_at'))} · ${fmt.time(x.session.date('starts_at'))}\n${Duties.title(x.group, lang)}',
              maxLines: 3,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(color: AppColors.muted, fontSize: 12.5, height: 1.5),
            ),
          ]),
        ),
        if (x != null) Icon(Icons.arrow_forward_ios, size: 13, color: AppColors.gold700),
      ]),
    );
  }
}

class _Stat extends StatelessWidget {
  const _Stat({required this.value, required this.label, required this.icon, this.onTap});

  final int value;
  final String label;
  final IconData icon;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Semantics(
          button: onTap != null,
          label: '$label: $value',
          excludeSemantics: true,
          child: Material(
            color: Colors.white,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppStyle.radius(-2)), side: BorderSide(color: AppColors.navy100)),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: onTap,
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 4),
                child: Column(children: [
                  Icon(icon, size: 18, color: AppColors.gold700),
                  const SizedBox(height: 5),
                  Text(Fmt('en').number(value), style: TextStyle(fontSize: 21, fontWeight: FontWeight.w800, height: 1.1, color: AppColors.navy900)),
                  const SizedBox(height: 2),
                  Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w700, color: AppColors.muted)),
                ]),
              ),
            ),
          ),
        ),
      );
}
