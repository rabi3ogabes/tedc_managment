import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/config.dart';
import '../../core/providers.dart';
import '../../core/push/push_service.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';
import '../../core/widgets/widgets.dart';

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
                  const _PushTile(),
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
          subtitle: Text(s.t(key)),
          trailing: status == PushStatus.enabled ? const Icon(Icons.check_circle, color: AppColors.success) : const Icon(Icons.refresh),
          onTap: status == PushStatus.enabled || status == PushStatus.unsupported ? null : push.start,
        );
      },
    );
  }
}
