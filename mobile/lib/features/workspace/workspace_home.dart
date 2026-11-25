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
import 'workspace.dart';
import 'workspace_programmes.dart';

/// The home of a supervisor or a trainer: the session to act on now with the ways to take its attendance (and, for a
/// trainer, its room, its kit and their own check-in), the day and the programmes in four numbers, the other sessions of
/// today, and the programmes that are running and the ones still to start. Somebody who is also a trainee has the way to
/// their own training at the bottom.
class WorkspaceHome extends ConsumerWidget {
  const WorkspaceHome({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final me = ref.watch(authProvider).value;
    // The greeting, the identity line and what waits for the person come with the role's home; the screen does not wait for them.
    final homeRaw = ref.watch(getProvider(RoleHome.path)).value;
    final home = homeRaw is Map && homeRaw['data'] is Map ? Map<String, dynamic>.from(homeRaw['data'] as Map) : const <String, dynamic>{};
    final duties = ref.watch(getProvider(Duties.path));
    final mayAttend = me?.can('attendance.manage') ?? false;
    final trainer = me?.worksAsTrainer ?? false;

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
                  ...workspaceHomeBody(context, Duties.groups(raw), mayAttend: mayAttend, trainer: trainer),
                  if (home.list('actions').isNotEmpty) ...[const SizedBox(height: 22), ActionTiles(tiles: home.list('actions'))],
                  const SizedBox(height: 22),
                  const AlsoTrainee(),
                  // A trainer's own certificates (of thanks, of training hours) are a tab of the trainee's app: one press away.
                  if (trainer) Padding(padding: const EdgeInsets.only(top: 10), child: _Link(key: const ValueKey('my-certificates'), icon: Icons.workspace_premium_outlined, label: s.t('work.myCertificates'), onTap: () => context.go('/certificates?own=1'))),
                  Padding(padding: const EdgeInsets.only(top: 10), child: _Link(icon: Icons.open_in_new, label: s.t('role.openDashboard'), onTap: () => launchUrl(StaffHome.dashboardUri, mode: LaunchMode.externalApplication))),
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
List<Widget> workspaceHomeBody(BuildContext context, List<Json> groups, {required bool mayAttend, bool trainer = false, DateTime? now}) {
  final s = context.s;
  if (groups.isEmpty) return [Quiet(icon: Icons.assignment_turned_in_outlined, text: s.t('duties.none'), hint: s.t('duties.noneHint'))];

  final today = Workspace.today(groups, now);
  final focus = Workspace.focus(groups, now);
  final others = [for (final x in today) if (x.session.str('id') != focus?.session.str('id')) x];
  final running = Workspace.running(groups);
  final upcoming = Workspace.upcoming(groups);
  final next = Workspace.ahead(groups, now);

  return [
    if (focus != null) NowCard(slot: focus, mayAttend: mayAttend, trainer: trainer) else FreeDayCard(next: next.isEmpty ? null : next.first),
    const SizedBox(height: 12),
    Row(children: [
      _Stat(value: '${running.length}', label: s.t('work.stat.running'), icon: Icons.play_circle_outline, onTap: () => context.go('/programs')),
      const SizedBox(width: 8),
      _Stat(value: '${upcoming.length}', label: s.t('work.stat.upcoming'), icon: Icons.event_outlined, onTap: () => context.go('/programs')),
      const SizedBox(width: 8),
      _Stat(value: '${today.length}', label: s.t('work.stat.today'), icon: Icons.today_outlined, onTap: () => context.go('/training')),
      const SizedBox(width: 8),
      // A supervisor counts the trainees in their care; a trainer, the hours still to give.
      if (trainer)
        _Stat(key: const ValueKey('stat-hours'), value: Workspace.hoursText(Workspace.hoursAhead(groups)), label: s.t('work.stat.hours'), icon: Icons.schedule)
      else
        _Stat(value: '${Workspace.trainees(groups)}', label: s.t('work.stat.trainees'), icon: Icons.groups_2_outlined),
    ]),
    if (others.isNotEmpty) ...[
      const SizedBox(height: 22),
      Heading(s.t(focus == null ? 'work.today' : 'work.todayRest'), count: others.length, action: s.t('work.nav.attendance'), onAction: () => context.go('/training')),
      for (final x in others) Padding(padding: const EdgeInsets.only(bottom: 10), child: SlotCard(slot: x, mayAttend: mayAttend)),
    ],
    if (running.isNotEmpty) ...[
      const SizedBox(height: 22),
      Heading(s.t('work.running'), count: running.length),
      for (final g in running) Padding(padding: const EdgeInsets.only(bottom: 10), child: GroupTile(group: g)),
    ],
    const SizedBox(height: 22),
    Heading(s.t('work.upcoming'), count: upcoming.isEmpty ? null : upcoming.length, action: s.t('common.viewAll'), onAction: () => context.go('/programs')),
    if (upcoming.isEmpty)
      Quiet(icon: Icons.event_available_outlined, text: s.t('work.noUpcoming'))
    else
      for (final g in upcoming.take(3)) Padding(padding: const EdgeInsets.only(bottom: 10), child: GroupTile(group: g)),
  ];
}

/// The session to act on: what it is, when and where, who came so far, and the three ways to take its attendance. The way
/// to its room is beside it; a trainer also has its kit and their own check-in.
class NowCard extends StatelessWidget {
  const NowCard({super.key, required this.slot, required this.mayAttend, this.trainer = false});

  final Slot slot;
  final bool mayAttend;
  final bool trainer;

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
                  Text(s.t(live ? 'work.now' : 'work.next'), style: TextStyle(color: live ? AppColors.navy950 : Colors.white, fontSize: 11.5, fontWeight: FontWeight.w800)),
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
                      Expanded(child: Text([if (title.isNotEmpty) title, if (place.isNotEmpty) place, if (Workspace.sessionHours(session) > 0) hoursLabel(s, Workspace.sessionHours(session))].join(' · '), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white70, fontSize: 13))),
                    ]),
                  ),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.only(top: 12),
              child: Wrap(spacing: 8, runSpacing: 8, children: [
                RoomWay(slot: slot, onDark: true),
                if (trainer && Workspace.kitCount(slot.group) > 0)
                  ActionPill(key: const ValueKey('now-kit'), icon: Icons.inventory_2_outlined, label: s.t('work.kit.short'), onDark: true, onTap: () => context.push('/my-programs/${slot.group.str('id')}?part=kits')),
                if (trainer) ActionPill(key: const ValueKey('now-checkin'), icon: Icons.how_to_reg_outlined, label: s.t('roll.myCheckIn'), onDark: true, onTap: () => context.push('/scan?trainer=1')),
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
            Text(s.t('work.freeDay'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15.5, color: AppColors.navy900)),
            const SizedBox(height: 2),
            Text(
              x == null ? s.t('work.noNext') : '${s.t('duties.session.next')}: ${fmt.weekdayDate(x.session.date('starts_at'))} · ${fmt.time(x.session.date('starts_at'))}\n${Duties.title(x.group, lang)}',
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
  const _Stat({super.key, required this.value, required this.label, required this.icon, this.onTap});

  final String value;
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
                  Text(value, style: TextStyle(fontSize: 21, fontWeight: FontWeight.w800, height: 1.1, color: AppColors.navy900)),
                  const SizedBox(height: 2),
                  Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 10.5, fontWeight: FontWeight.w700, color: AppColors.muted)),
                ]),
              ),
            ),
          ),
        ),
      );
}

/// «أنت متدرب أيضاً»: a supervisor or a trainer who is registered in programmes as a trainee has, one press away, the app
/// of their own training (their programmes, their sessions, their certificates). It takes no space for anybody else.
class AlsoTrainee extends ConsumerWidget {
  const AlsoTrainee({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    return OtherRoleCard(key: const ValueKey('also-trainee'), grant: ref.watch(authProvider).value?.traineeGrant, icon: Icons.school_outlined, title: s.t('work.alsoTrainee'), hint: s.t('work.alsoTraineeHint'));
  }
}

/// «أنت مشرف أيضاً» / «أنت مدرب أيضاً»: the way back, on the trainee's home, for a supervisor or a trainer who went to
/// their own training. It takes no space for somebody who is only a trainee.
class BackToWork extends ConsumerWidget {
  const BackToWork({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final grant = ref.watch(authProvider).value?.workGrant;
    final supervisor = grant?.str('slug') == 'supervisor';
    return OtherRoleCard(
      key: const ValueKey('back-to-work'),
      grant: grant,
      icon: supervisor ? Icons.supervisor_account_outlined : Icons.co_present_outlined,
      title: s.t(supervisor ? 'work.alsoSupervisor' : 'work.alsoTrainer'),
      hint: s.t('work.backHint'),
      padding: const EdgeInsets.only(bottom: 14),
    );
  }
}

/// One press to work as another role the person holds: the whole app is loaded again for it. It takes no space without a grant.
class OtherRoleCard extends ConsumerStatefulWidget {
  const OtherRoleCard({super.key, required this.grant, required this.icon, required this.title, required this.hint, this.padding = EdgeInsets.zero});

  final Json? grant;
  final IconData icon;
  final String title;
  final String hint;
  final EdgeInsetsGeometry padding;

  @override
  ConsumerState<OtherRoleCard> createState() => _OtherRoleCardState();
}

class _OtherRoleCardState extends ConsumerState<OtherRoleCard> {
  bool _busy = false;

  Future<void> _switch(String grantId) async {
    setState(() => _busy = true);
    try {
      await ref.read(authProvider.notifier).switchRole(grantId);
      if (mounted) context.go('/home');
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final grant = widget.grant;
    if (grant == null) return const SizedBox.shrink();
    return Padding(
      padding: widget.padding,
      child: Surface(
        onTap: _busy ? null : () => _switch(grant.str('id')),
        child: Row(children: [
          Container(
            width: 44,
            height: 44,
            decoration: BoxDecoration(color: AppColors.gold100, borderRadius: BorderRadius.circular(14)),
            child: _busy ? Padding(padding: const EdgeInsets.all(12), child: CircularProgressIndicator(strokeWidth: 2.4, color: AppColors.navy900)) : Icon(widget.icon, color: AppColors.gold700),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(widget.title, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900, fontSize: 14.5)),
              Text(widget.hint, style: const TextStyle(color: AppColors.muted, fontSize: 12, height: 1.5)),
            ]),
          ),
          Icon(Icons.swap_horiz, color: AppColors.gold700),
        ]),
      ),
    );
  }
}

/// A quiet way out at the bottom of the home.
class _Link extends StatelessWidget {
  const _Link({super.key, required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Center(child: TextButton.icon(onPressed: onTap, icon: Icon(icon, size: 16), label: Text(label)));
}
