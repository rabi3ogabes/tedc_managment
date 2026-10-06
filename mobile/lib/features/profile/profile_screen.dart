import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/biometric.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/config.dart';
import '../../core/providers.dart';
import '../../core/push/push_service.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';
import '../../core/widgets/widgets.dart';
import '../security/biometric_offer.dart';

/// Profile with the Training Passport: hours, completed programs, certificates, skills and growth path.
class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});

  static const path = '/me/passport';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final me = ref.watch(authProvider).value;
    final employee = me?.employee;
    final passport = ref.watch(getProvider(path));

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(getProvider(path).future),
        child: ListView(padding: EdgeInsets.zero, children: [
          Container(
            decoration: const BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.vertical(bottom: Radius.circular(32))),
            child: Stack(children: [
              const Positioned.fill(child: DotPattern(opacity: .12)),
              SafeArea(
                bottom: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
                  child: Column(children: [
                    CircleAvatar(
                      radius: 38,
                      backgroundColor: AppColors.gold500,
                      child: Text(me?.name.characters.first ?? '', style: const TextStyle(fontSize: 30, color: AppColors.navy950, fontWeight: FontWeight.w800)),
                    ),
                    const SizedBox(height: 12),
                    Text(me?.name ?? '', style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
                    if (employee != null)
                      Text(
                        '${employee.obj('job_title')?.str('name') ?? ''} · ${employee.obj('school')?.str('name') ?? ''}',
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: AppColors.gold300, fontSize: 13),
                      ),
                    Text(me?.email ?? '', textDirection: TextDirection.ltr, style: const TextStyle(color: Colors.white60, fontSize: 12)),
                  ]),
                ),
              ),
            ]),
          ),
          Padding(
            padding: const EdgeInsets.all(20),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const _AccountEntry(),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.qr_code_2, color: AppColors.gold500),
                  title: Text(context.tr('myqr.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/my-qr'),
                ),
              ),
              const _AssignmentsEntry(),
              const _ApprovalsEntry(),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.trending_up, color: AppColors.gold500),
                  title: Text(context.tr('myneeds.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/my-needs'),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.quiz_outlined, color: AppColors.gold500),
                  title: Text(context.tr('assess.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/my-assessments'),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.rate_review_outlined, color: AppColors.gold500),
                  title: Text(context.tr('evalc.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/my-evaluations'),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.workspace_premium_outlined, color: AppColors.gold500),
                  title: Text(context.tr('growth.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/my-growth'),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.local_library_outlined, color: AppColors.gold500),
                  title: Text(context.tr('library.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/library'),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.event_outlined, color: AppColors.gold500),
                  title: Text(context.tr('events.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/events'),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.assessment_outlined, color: AppColors.gold500),
                  title: Text(context.tr('reports.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/my-reports'),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.support_agent_outlined, color: AppColors.gold500),
                  title: Text(context.tr('ticket.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/report-problem'),
                ),
              ),
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: ListTile(
                  tileColor: Colors.white,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                  leading: const Icon(Icons.notifications_active_outlined, color: AppColors.gold500),
                  title: Text(context.tr('prefs.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  onTap: () => context.push('/notification-preferences'),
                ),
              ),
              const SizedBox(height: 16),
              if (employee != null)
                passport.when(
                  loading: () => const LoadingView(),
                  error: (_, _) => const SizedBox.shrink(),
                  data: (raw) => _Passport(data: Map<String, dynamic>.from(raw['data'] as Map), fmt: fmt),
                ),
              const SizedBox(height: 20),
              Card(
                child: Column(children: [
                  ListTile(
                    leading: const Icon(Icons.language, color: AppColors.gold700),
                    title: Text(s.t('profile.language')),
                    trailing: SegmentedButton<String>(
                      showSelectedIcon: false,
                      segments: const [ButtonSegment(value: 'ar', label: Text('عربي')), ButtonSegment(value: 'en', label: Text('EN'))],
                      selected: {s.languageCode},
                      onSelectionChanged: (v) => ref.read(localeProvider.notifier).set(v.first),
                    ),
                  ),
                  if (me?.isSchoolAdmin ?? false) ...[
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.apartment, color: AppColors.gold700),
                      title: Text(s.t('profile.school')),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => context.push('/school'),
                    ),
                  ],
                  const Divider(height: 1),
                  if ((me?.roleGrants.length ?? 0) > 1) ...[
                    const _RoleTile(),
                    const Divider(height: 1),
                  ],
                  const _PushTile(),
                  const Divider(height: 1),
                  const _BiometricTile(),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.info_outline, color: AppColors.gold700),
                    title: Text(s.t('profile.about')),
                    subtitle: Text('${s.t('profile.version')} ${AppConfig.appVersion} · ${Uri.tryParse(AppConfig.apiUrl)?.host ?? ''}', textDirection: TextDirection.ltr, textAlign: s.isArabic ? TextAlign.right : TextAlign.left),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.logout, color: AppColors.danger),
                    title: Text(s.t('auth.logout'), style: const TextStyle(color: AppColors.danger)),
                    onTap: () => ref.read(authProvider.notifier).logout(),
                  ),
                ]),
              ),
            ]),
          ),
        ]),
      ),
    );
  }
}

/// Shown to a direct manager who has registrations waiting for their approval.
class _ApprovalsEntry extends ConsumerWidget {
  const _ApprovalsEntry();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final raw = ref.watch(getProvider('/admin/approvals/manager')).value;
    final n = raw is Map ? Map<String, dynamic>.from(raw).list('data').length : 0;
    if (n == 0) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: ListTile(
        tileColor: Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        leading: const Icon(Icons.how_to_reg_outlined, color: AppColors.gold500),
        title: Text(context.tr('approvals.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
        trailing: CircleAvatar(radius: 12, backgroundColor: AppColors.gold500, child: Text('$n', style: const TextStyle(fontSize: 12, color: AppColors.navy950))),
        onTap: () => context.push('/approvals'),
      ),
    );
  }
}

/// Shown to a trainer who has been proposed for a group: opens the assignment form.
class _AssignmentsEntry extends ConsumerWidget {
  const _AssignmentsEntry();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final raw = ref.watch(getProvider('/me/assignments')).value;
    final open = raw is Map ? Map<String, dynamic>.from(raw).list('data').where((a) => a.str('status') == 'proposed').length : 0;
    if (open == 0) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: ListTile(
        tileColor: Colors.white,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        leading: const Icon(Icons.assignment_ind_outlined, color: AppColors.gold500),
        title: Text(context.tr('assignments.title'), style: const TextStyle(fontWeight: FontWeight.w700)),
        trailing: CircleAvatar(radius: 12, backgroundColor: AppColors.gold500, child: Text('$open', style: const TextStyle(fontSize: 12, color: AppColors.navy950))),
        onTap: () => context.push('/assignments'),
      ),
    );
  }
}

/// Opens "My account": all the user's details, read-only, with requests for corrections.
class _AccountEntry extends ConsumerWidget {
  const _AccountEntry();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final stats = ref.watch(getProvider('/me/account')).value;
    final info = stats is Map ? Map<String, dynamic>.from(stats['data'] as Map).obj('stats') : null;
    final missing = info?.number('missing').toInt() ?? 0;
    final pending = info?.number('pending').toInt() ?? 0;

    return InkWell(
      borderRadius: BorderRadius.circular(22),
      onTap: () => context.push('/account'),
      child: Ink(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(gradient: AppColors.goldGradient, borderRadius: BorderRadius.circular(22)),
        child: Row(children: [
          Container(padding: const EdgeInsets.all(12), decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(16)), child: const Icon(Icons.badge_outlined, color: AppColors.gold300, size: 26)),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(s.t('account.title'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 17, color: AppColors.navy950)),
              const SizedBox(height: 2),
              Text(
                missing > 0 ? '$missing ${s.t('account.missingCount')}' : pending > 0 ? '$pending ${s.t('account.pendingCount')}' : s.t('account.subtitle'),
                style: const TextStyle(color: AppColors.navy900, fontSize: 12.5),
              ),
            ]),
          ),
          if (missing > 0) Badge(label: Text('$missing'), backgroundColor: AppColors.navy900, textColor: AppColors.gold300),
          const SizedBox(width: 6),
          const Icon(Icons.chevron_right, color: AppColors.navy950),
        ]),
      ),
    );
  }
}

class _Passport extends StatelessWidget {
  const _Passport({required this.data, required this.fmt});

  final Json data;
  final Fmt fmt;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final summary = data.obj('summary') ?? {};
    final skills = data.list('skills');
    final growth = data.list('growth_path');

    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
      SectionTitle(s.t('profile.passport')),
      Row(children: [
        Expanded(child: StatTile(label: s.t('profile.totalHours'), value: fmt.number(summary.number('total_hours')), icon: Icons.schedule, dark: true)),
        const SizedBox(width: 12),
        Expanded(child: StatTile(label: s.t('home.completed'), value: fmt.number(summary.number('completed_programs')), icon: Icons.task_alt)),
        const SizedBox(width: 12),
        Expanded(child: StatTile(label: s.t('home.certificates'), value: fmt.number(summary.number('certificates')), icon: Icons.workspace_premium_outlined)),
      ]),
      if (skills.isNotEmpty) ...[
        SectionTitle(s.t('profile.skills')),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(children: [
              for (final k in skills)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Row(children: [
                    Expanded(child: Text(k.str('name'), style: const TextStyle(fontSize: 13))),
                    for (var n = 1; n <= 5; n++)
                      Container(
                        width: 18,
                        height: 7,
                        margin: const EdgeInsetsDirectional.only(start: 3),
                        decoration: BoxDecoration(color: n <= k.number('level') ? AppColors.gold500 : AppColors.navy100, borderRadius: BorderRadius.circular(4)),
                      ),
                    if (k.str('source') == 'training') const Padding(padding: EdgeInsetsDirectional.only(start: 6), child: Icon(Icons.verified, size: 16, color: AppColors.success)),
                  ]),
                ),
            ]),
          ),
        ),
      ],
      if (growth.isNotEmpty) ...[
        SectionTitle(s.t('profile.growth')),
        for (final g in growth)
          IntrinsicHeight(
            child: Row(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Column(children: [
                Container(width: 14, height: 14, decoration: BoxDecoration(color: AppColors.gold500, shape: BoxShape.circle, border: Border.all(color: AppColors.gold100, width: 3))),
                Expanded(child: Container(width: 2, color: AppColors.gold300)),
              ]),
              const SizedBox(width: 12),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.only(bottom: 16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(fmt.date(g.date('date')), style: const TextStyle(fontSize: 11, color: AppColors.muted)),
                    Text(g.str('program'), style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
                    Text('${g.str('category')} · ${fmt.number(g.number('hours'))} ${s.t('common.hours')}', style: const TextStyle(fontSize: 12, color: AppColors.muted)),
                  ]),
                ),
              ),
            ]),
          ),
      ],
    ]);
  }
}

/// Push notification status on this device, with a retry / permission prompt.
class _PushTile extends ConsumerWidget {
  const _PushTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final push = ref.watch(pushServiceProvider);
    return ValueListenableBuilder<PushStatus>(
      valueListenable: push.status,
      builder: (context, status, _) {
        final (icon, color, key) = switch (status) {
          PushStatus.enabled => (Icons.notifications_active, AppColors.success, 'push.enabled'),
          PushStatus.denied => (Icons.notifications_off_outlined, AppColors.warning, 'push.denied'),
          PushStatus.notConfigured => (Icons.notifications_paused_outlined, AppColors.muted, 'push.notConfigured'),
          PushStatus.unsupported => (Icons.notifications_off_outlined, AppColors.muted, 'push.unsupported'),
          PushStatus.error => (Icons.error_outline, AppColors.danger, 'push.error'),
        };
        return ListTile(
          leading: Icon(icon, color: color),
          title: Text(s.t('push.title')),
          subtitle: Text(status == PushStatus.error && push.detail.value != null ? '${s.t(key)}\n${push.detail.value}' : s.t(key)),
          isThreeLine: status == PushStatus.error && push.detail.value != null,
          trailing: status == PushStatus.enabled ? const Icon(Icons.check_circle, color: AppColors.success) : const Icon(Icons.refresh),
          onTap: status == PushStatus.enabled || status == PushStatus.unsupported ? null : push.start,
        );
      },
    );
  }
}

/// Fingerprint sign-in switch (only offered when the phone has a fingerprint, face or lock set up).
class _BiometricTile extends ConsumerStatefulWidget {
  const _BiometricTile();

  @override
  ConsumerState<_BiometricTile> createState() => _BiometricTileState();
}

class _BiometricTileState extends ConsumerState<_BiometricTile> {
  bool? _available;

  @override
  void initState() {
    super.initState();
    ref.read(biometricServiceProvider).available().then((v) {
      if (mounted) setState(() => _available = v);
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_available != true) return const SizedBox.shrink();
    final s = context.s;
    final on = ref.read(appLockProvider.notifier).enabled;
    return Column(mainAxisSize: MainAxisSize.min, children: [
      SwitchListTile(
        secondary: const Icon(Icons.fingerprint, color: AppColors.gold700),
        title: Text(s.t('bio.title')),
        subtitle: Text(s.t('bio.subtitle')),
        value: on,
        onChanged: (v) async {
          if (v) {
            await enableBiometricLogin(context, ref);
          } else {
            await ref.read(appLockProvider.notifier).setEnabled(false);
          }
          if (mounted) setState(() {});
        },
      ),
      const Divider(height: 1),
    ]);
  }
}

/// Works as another of the person's roles (trainer, trainee, manager…); each is shown with its scope.
class _RoleTile extends ConsumerWidget {
  const _RoleTile();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final me = ref.watch(authProvider).value;
    if (me == null) return const SizedBox.shrink();
    String name(Json g) => g.str('name');
    String scope(Json g) => s.isArabic ? g.str('scope_label_ar') : g.str('scope_label_en');
    final active = me.activeRole;

    return ListTile(
      leading: const Icon(Icons.swap_horiz, color: AppColors.gold700),
      title: Text(s.t('role.switch')),
      subtitle: Text(active == null ? '' : '${name(active)} — ${scope(active)}'),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => showModalBottomSheet<void>(
        context: context,
        showDragHandle: true,
        builder: (sheet) => SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(8, 0, 8, 16),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Padding(padding: const EdgeInsets.fromLTRB(16, 0, 16, 8), child: Text(s.t('role.switch'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900))),
              for (final g in me.roleGrants)
                ListTile(
                  leading: Icon(g.flag('active') ? Icons.radio_button_checked : Icons.radio_button_off, color: g.flag('active') ? AppColors.gold700 : AppColors.muted),
                  title: Text(name(g), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(scope(g)),
                  onTap: g.flag('active')
                      ? null
                      : () async {
                          Navigator.pop(sheet);
                          try {
                            await ref.read(authProvider.notifier).switchRole(g.str('id'));
                            if (context.mounted) {
                              ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(s.t('role.switched').replaceFirst('{role}', name(g)))));
                              context.go('/home');
                            }
                          } catch (e) {
                            if (context.mounted) ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(ApiException.from(e).message)));
                          }
                        },
                ),
            ]),
          ),
        ),
      ),
    );
  }
}
