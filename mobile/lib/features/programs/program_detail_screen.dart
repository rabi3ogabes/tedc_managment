import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

class ProgramDetailScreen extends ConsumerStatefulWidget {
  const ProgramDetailScreen({super.key, required this.code});

  final String code;

  @override
  ConsumerState<ProgramDetailScreen> createState() => _ProgramDetailScreenState();
}

class _ProgramDetailScreenState extends ConsumerState<ProgramDetailScreen> {
  bool _registering = false;
  String? _groupId;

  Future<void> _register(String programId) async {
    setState(() => _registering = true);
    try {
      await ref.read(apiProvider).post('/me/programs/$programId/register', _groupId == null ? null : {'group_id': _groupId});
      ref.invalidate(getProvider('/me/programs/$programId/eligibility'));
      ref.invalidate(getProvider('/me/registrations'));
      ref.invalidate(getProvider('/me/home'));
      if (mounted) showSnack(context, context.tr('programs.registeredOk'));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _registering = false);
    }
  }

  Future<void> _addToCart(String groupId) async {
    setState(() => _registering = true);
    try {
      await ref.read(apiProvider).post('/me/cart/items', {'group_id': groupId});
      ref.invalidate(getProvider('/me/cart'));
      if (mounted) {
        showSnack(context, context.tr('shop.addedToCart'));
        context.push('/cart');
      }
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _registering = false);
    }
  }

  Future<void> _addToCalendar(Json program, Json session) async {
    String f(DateTime d) => '${d.toUtc().toIso8601String().replaceAll(RegExp(r'[-:]'), '').split('.').first}Z';
    final uri = Uri.https('calendar.google.com', '/calendar/render', {
      'action': 'TEMPLATE',
      'text': '${program.str('title')} — ${session.str('title')}',
      'dates': '${f(session.date('starts_at')!)}/${f(session.date('ends_at')!)}',
      'location': session.str('location'),
    });
    await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final value = ref.watch(getProvider('/public/programs/${widget.code}'));

    return Scaffold(
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider('/public/programs/${widget.code}')),
        builder: (raw) {
          final p = Map<String, dynamic>.from(raw['data'] as Map);
          final category = p.obj('category');
          final eligibility = ref.watch(getProvider('/me/programs/${p.str('id')}/eligibility'));
          final sessions = p.list('sessions');
          final groups = p.list('groups');
          final objectives = (p['objectives'] as List? ?? const []).map((e) => e.toString()).where((e) => e.isNotEmpty).toList();

          return CustomScrollView(slivers: [
            SliverAppBar(
              pinned: true,
              expandedHeight: 220,
              backgroundColor: AppColors.navy900,
              foregroundColor: Colors.white,
              flexibleSpace: FlexibleSpaceBar(
                background: Stack(fit: StackFit.expand, children: [
                  ProgramCover(code: p.str('code'), category: category?.str('name'), categorySlug: category?.str('slug'), height: 220),
                  const DecoratedBox(decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [Colors.transparent, Color(0xCC06122A)]))),
                  PositionedDirectional(
                    start: 20,
                    end: 20,
                    bottom: 40,
                    child: Text(p.str('title'), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
                  ),
                ]),
              ),
            ),
            SliverPadding(
              padding: const EdgeInsets.all(20),
              sliver: SliverList.list(children: [
                Wrap(spacing: 8, runSpacing: 8, children: [
                  StatusChip(p.str('status')),
                  _InfoPill(Icons.event, fmt.date(p.date('start_date'))),
                  _InfoPill(Icons.schedule, '${fmt.number(p.number('total_hours'))} ${s.t('common.hours')}'),
                  _InfoPill(Icons.place_outlined, s.t('mode.${p.str('delivery_mode')}')),
                  _InfoPill(Icons.event_seat_outlined, '${fmt.number(p.number('seats_available'))} ${s.t('common.seats')}'),
                ]),
                const SizedBox(height: 16),
                Text(p.str('description'), style: const TextStyle(height: 1.7, color: AppColors.ink)),
                SectionTitle(s.t('programs.eligibility')),
                eligibility.when(
                  loading: () => const LoadingView(),
                  error: (e, _) => Text(ApiException.from(e).message),
                  data: (raw) {
                    final e = Map<String, dynamic>.from(raw['data'] as Map);
                    final registration = e.obj('registration');
                    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      EligibilityPanel(e),
                      const SizedBox(height: 10),
                      _AdmissionNotes(e.obj('admission'), registration == null),
                      const SizedBox(height: 14),
                      if (groups.length > 1 && registration == null) ...[
                        Text(s.t('groups.choose'), style: const TextStyle(fontWeight: FontWeight.w700)),
                        const SizedBox(height: 8),
                        Wrap(spacing: 8, runSpacing: 8, children: [
                          for (final g in groups)
                            ChoiceChip(
                              label: Text('${g.str('title')} · ${fmt.date(g.date('start_date'))} · ${fmt.number(g.number('seats_available'))} ${s.t('common.seats')}'),
                              selected: (_groupId ?? groups.first.str('id')) == g.str('id'),
                              onSelected: (_) => setState(() => _groupId = g.str('id')),
                            ),
                        ]),
                        const SizedBox(height: 14),
                      ],
                      if (registration != null)
                        Container(
                          padding: const EdgeInsets.all(14),
                          decoration: BoxDecoration(color: AppColors.navy100, borderRadius: BorderRadius.circular(14)),
                          child: Row(children: [
                            Expanded(child: Text(s.t('programs.registered'), style: const TextStyle(fontWeight: FontWeight.w700))),
                            StatusChip(registration.str('status')),
                          ]),
                        )
                      else if (e['registration_open'] != true)
                        OutlinedButton(onPressed: null, child: Text(s.t('programs.closed')))
                      else if (p.obj('pricing')?.flag('paid') ?? false)
                        FilledButton(
                          onPressed: e['eligible'] == true && !_registering && groups.isNotEmpty ? () => _addToCart(_groupId ?? groups.first.str('id')) : null,
                          style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
                          child: _registering ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2)) : Text('${s.t('shop.addToCart')} · ${p.obj('pricing')!.number('price').toStringAsFixed(2)} ${s.t('shop.qar')}'),
                        )
                      else
                        FilledButton(
                          onPressed: e['eligible'] == true && !_registering ? () => _register(p.str('id')) : null,
                          style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
                          child: _registering ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2)) : Text(s.t('programs.register')),
                        ),
                    ]);
                  },
                ),
                if (objectives.isNotEmpty) ...[
                  SectionTitle(s.t('programs.objectives')),
                  for (final o in objectives)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        const Icon(Icons.check_circle, color: AppColors.success, size: 18),
                        const SizedBox(width: 8),
                        Expanded(child: Text(o)),
                      ]),
                    ),
                ],
                if (p.list('skills').isNotEmpty) ...[
                  SectionTitle(s.t('programs.skills')),
                  Wrap(spacing: 8, runSpacing: 8, children: [for (final k in p.list('skills')) Chip(label: Text(k.str('name')))]),
                ],
                if (sessions.isNotEmpty) ...[
                  SectionTitle(s.t('programs.sessions')),
                  for (final session in sessions)
                    Card(
                      margin: const EdgeInsets.only(bottom: 10),
                      child: ListTile(
                        leading: CircleAvatar(backgroundColor: AppColors.navy900, child: Text('${session.number('sequence')}', style: const TextStyle(color: AppColors.gold300))),
                        title: Text(session.str('title'), style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
                        subtitle: Text('${fmt.weekdayDate(session.date('starts_at'))} · ${fmt.time(session.date('starts_at'))}\n${session.str('location')}'),
                        isThreeLine: true,
                        trailing: IconButton(
                          tooltip: s.t('programs.addToCalendar'),
                          icon: const Icon(Icons.event_available, color: AppColors.gold700),
                          onPressed: () => _addToCalendar(p, session),
                        ),
                      ),
                    ),
                ],
              ]),
            ),
          ]);
        },
      ),
    );
  }
}

class _InfoPill extends StatelessWidget {
  const _InfoPill(this.icon, this.text);

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(99), border: Border.all(color: AppColors.navy100)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 14, color: AppColors.gold700),
          const SizedBox(width: 4),
          Text(text, style: const TextStyle(fontSize: 12, color: AppColors.navy800)),
        ]),
      );
}

/// What the trainee should know before registering: clashes, repeats, seats for their entity and the approval path.
class _AdmissionNotes extends StatelessWidget {
  const _AdmissionNotes(this.a, this.show);

  final Json? a;
  final bool show;

  @override
  Widget build(BuildContext context) {
    if (a == null || !show) return const SizedBox.shrink();
    final s = context.s;
    final conflicts = a!.list('conflicts');
    final repeat = a!.obj('repeat');
    final lines = <(IconData, String, Color)>[
      for (final c in conflicts) (Icons.event_busy, '${s.t('admission.conflict')} ${c.str('program')}', AppColors.danger),
      if (repeat != null) (Icons.history, '${s.t('admission.repeat')} ${repeat.str('program')}', AppColors.warning),
      if (a!['seat_in_my_pool'] == false) (Icons.event_seat_outlined, s.t('admission.noSeat'), AppColors.warning),
      (Icons.alt_route, s.t('admission.path'), Colors.black54),
    ];

    return Column(children: [
      for (final (icon, text, color) in lines)
        Padding(
          padding: const EdgeInsets.only(bottom: 6),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(icon, size: 16, color: color),
            const SizedBox(width: 8),
            Expanded(child: Text(text, style: TextStyle(fontSize: 12.5, color: color))),
          ]),
        ),
    ]);
  }
}
