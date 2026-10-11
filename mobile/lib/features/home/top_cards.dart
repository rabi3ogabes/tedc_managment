import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_style.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';

const _sources = ['center', 'internal', 'external', 'knowledge_transfer'];

/// «My hours this year»: the first card of the home screen — a ring of the hours counted towards the annual target.
/// Tap it for where the hours came from.
class HoursHeroCard extends ConsumerWidget {
  const HoursHeroCard({super.key});

  static const path = '/me/pd-hours';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final data = ref.watch(getProvider(path));
    return data.when(
      loading: () => const _Skeleton(height: 150),
      error: (_, _) => const SizedBox.shrink(),
      data: (raw) {
        final h = Map<String, dynamic>.from((raw as Map)['data'] as Map);
        final s = context.s;
        final fmt = Fmt(s.languageCode);
        final total = h.number('total').toDouble();
        final target = h['target'] == null ? null : h.number('target').toDouble();
        final ratio = target == null || target <= 0 ? null : (total / target).clamp(0.0, 1.0);
        final reached = target != null && total >= target;
        final shortfall = target == null ? null : math.max(0.0, target - total);
        return Semantics(
          button: true,
          label: '${s.t('hours.title')}: ${fmt.number(total, digits: 1)}',
          child: Material(
            color: Colors.transparent,
            borderRadius: BorderRadius.circular(AppStyle.radius(8)),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: () => _details(context, h),
              child: Ink(
                decoration: BoxDecoration(
                  gradient: AppColors.navyGradient,
                  borderRadius: BorderRadius.circular(AppStyle.radius(8)),
                  boxShadow: [BoxShadow(color: AppColors.navy900.withValues(alpha: .28), blurRadius: 26, offset: const Offset(0, 12))],
                ),
                child: Stack(children: [
                  const Positioned.fill(child: DotPattern(opacity: .12)),
                  Padding(
                    padding: const EdgeInsets.all(20),
                    child: Row(children: [
                      _Ring(ratio: ratio, label: fmt.number(total, digits: total == total.roundToDouble() ? 0 : 1)),
                      const SizedBox(width: 18),
                      Expanded(
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(s.t('hours.title'), style: TextStyle(color: AppColors.gold300, fontSize: 13, fontWeight: FontWeight.w700)),
                          const SizedBox(height: 4),
                          Text(
                            target == null ? s.t('hours.noTarget') : '${fmt.number(total, digits: 1)} / ${fmt.number(target, digits: 0)} ${s.t('common.hours')}',
                            style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800, height: 1.3),
                          ),
                          const SizedBox(height: 6),
                          if (target != null)
                            Text(
                              reached ? s.t('hours.done') : '${s.t('hours.remaining')}: ${fmt.number(shortfall, digits: 1)} ${s.t('common.hours')}',
                              style: TextStyle(color: reached ? AppColors.gold300 : Colors.white70, fontSize: 12.5, fontWeight: FontWeight.w600),
                            ),
                          const SizedBox(height: 8),
                          Row(children: [
                            Text(s.t('hours.tapForMore'), style: const TextStyle(color: Colors.white54, fontSize: 11.5)),
                            const SizedBox(width: 4),
                            const Icon(Icons.keyboard_arrow_down, size: 16, color: Colors.white54),
                          ]),
                        ]),
                      ),
                    ]),
                  ),
                ]),
              ),
            ),
          ),
        );
      },
    );
  }

  void _details(BuildContext context, Json h) {
    showModalBottomSheet<void>(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      backgroundColor: Theme.of(context).colorScheme.surface,
      builder: (_) => _HoursSheet(hours: h),
    );
  }
}

class _Ring extends StatelessWidget {
  const _Ring({required this.ratio, required this.label});

  final double? ratio;
  final String label;

  @override
  Widget build(BuildContext context) => SizedBox(
        width: 92,
        height: 92,
        child: Stack(alignment: Alignment.center, children: [
          TweenAnimationBuilder<double>(
            tween: Tween(begin: 0, end: ratio ?? 0),
            duration: MediaQuery.disableAnimationsOf(context) ? Duration.zero : const Duration(milliseconds: 900),
            curve: Curves.easeOutCubic,
            builder: (_, v, _) => CustomPaint(size: const Size(92, 92), painter: _RingPainter(v, AppColors.gold300)),
          ),
          Text(label, style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
        ]),
      );
}

class _RingPainter extends CustomPainter {
  _RingPainter(this.value, this.color);

  final double value;
  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;
    final stroke = Paint()..style = PaintingStyle.stroke..strokeWidth = 9..strokeCap = StrokeCap.round;
    canvas.drawArc(rect.deflate(5), 0, math.pi * 2, false, stroke..color = Colors.white.withValues(alpha: .16));
    if (value > 0) canvas.drawArc(rect.deflate(5), -math.pi / 2, math.pi * 2 * value, false, stroke..color = color);
  }

  @override
  bool shouldRepaint(_RingPainter old) => old.value != value || old.color != color;
}

class _HoursSheet extends StatelessWidget {
  const _HoursSheet({required this.hours});

  final Json hours;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final raw = hours.obj('raw') ?? const {};
    final counted = hours.obj('counted') ?? const {};
    final top = _sources.map((k) => raw.number(k).toDouble()).fold<double>(1, math.max);
    return SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(s.t('hours.title'), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppColors.navy900)),
          const SizedBox(height: 4),
          Text('${s.t('hours.period')}: ${fmt.date(hours.date('from'))} — ${fmt.date(hours.date('to'))}', style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
          const SizedBox(height: 16),
          Row(children: [
            _Kpi(label: s.t('hours.counted'), value: fmt.number(hours.number('total'), digits: 1)),
            const SizedBox(width: 10),
            _Kpi(label: s.t('hours.target'), value: hours['target'] == null ? '—' : fmt.number(hours.number('target'), digits: 0)),
            const SizedBox(width: 10),
            _Kpi(label: s.t('hours.remaining'), value: hours['shortfall'] == null ? '—' : fmt.number(hours.number('shortfall'), digits: 1)),
          ]),
          const SizedBox(height: 20),
          Text(s.t('hours.sources'), style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
          const SizedBox(height: 10),
          for (final k in _sources)
            Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(s.t('hours.$k'), style: const TextStyle(fontWeight: FontWeight.w600))),
                  Text('${fmt.number(counted.number(k), digits: 1)} / ${fmt.number(raw.number(k), digits: 1)}', style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                ]),
                const SizedBox(height: 6),
                ProgressBarLine(value: raw.number(k).toDouble() / top),
              ]),
            ),
        ]),
      ),
    );
  }
}

class _Kpi extends StatelessWidget {
  const _Kpi({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Expanded(
        child: Container(
          padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 10),
          decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .6), borderRadius: BorderRadius.circular(16)),
          child: Column(children: [
            Text(value, style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppColors.navy900)),
            const SizedBox(height: 2),
            Text(label, textAlign: TextAlign.center, style: const TextStyle(fontSize: 11, color: AppColors.muted)),
          ]),
        ),
      );
}

/// A thin gold progress line (0..1).
class ProgressBarLine extends StatelessWidget {
  const ProgressBarLine({super.key, required this.value, this.height = 8});

  final double value;
  final double height;

  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(99),
        child: LinearProgressIndicator(value: value.clamp(0.0, 1.0), minHeight: height, backgroundColor: AppColors.navy100, color: AppColors.gold500),
      );
}

class _Skeleton extends StatelessWidget {
  const _Skeleton({required this.height});

  final double height;

  @override
  Widget build(BuildContext context) => Container(height: height, decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .6), borderRadius: BorderRadius.circular(AppStyle.radius(8))));
}

/// «My tasks and surveys»: what is waiting for the person, as tiles that open the right screen.
class TodoCard extends StatelessWidget {
  const TodoCard({super.key, required this.todo, required this.pendingNeeds});

  /// `/me/home` → `todo`: tasks, changes, evaluations, surveys.
  final Json todo;
  final int pendingNeeds;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final rows = <({String key, IconData icon, int count, String route})>[
      (key: 'tasks', icon: Icons.checklist_rtl, count: todo.number('tasks').toInt(), route: '/training'),
      (key: 'changes', icon: Icons.edit_note, count: todo.number('changes').toInt(), route: '/training'),
      (key: 'evaluations', icon: Icons.rate_review_outlined, count: todo.number('evaluations').toInt(), route: '/training'),
      (key: 'surveys', icon: Icons.insights_outlined, count: todo.number('surveys').toInt(), route: '/training'),
      (key: 'needs', icon: Icons.assignment_outlined, count: pendingNeeds, route: '/needs-surveys'),
    ];
    final open = rows.where((r) => r.count > 0).toList();
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(AppStyle.radius(4)), border: Border.all(color: AppColors.navy100), boxShadow: [BoxShadow(color: AppColors.navy900.withValues(alpha: .06), blurRadius: 18, offset: const Offset(0, 8))]),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Container(padding: const EdgeInsets.all(8), decoration: BoxDecoration(gradient: AppColors.goldGradient, borderRadius: BorderRadius.circular(12)), child: Icon(Icons.task_alt, size: 18, color: AppColors.navy950)),
          const SizedBox(width: 10),
          Expanded(child: Text(s.t('todo.title'), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy900))),
          if (open.isNotEmpty) Text(fmt.number(open.fold<int>(0, (a, r) => a + r.count)), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppColors.gold700)),
        ]),
        const SizedBox(height: 12),
        if (open.isEmpty)
          Row(children: [
            const Icon(Icons.check_circle, color: AppColors.success, size: 20),
            const SizedBox(width: 8),
            Expanded(child: Text(s.t('todo.allDone'), style: const TextStyle(color: AppColors.muted, fontWeight: FontWeight.w600))),
          ])
        else
          Wrap(spacing: 10, runSpacing: 10, children: [
            for (final r in open)
              Semantics(
                button: true,
                label: '${s.t('todo.${r.key}')}: ${r.count}',
                child: InkWell(
                  onTap: () => context.push(r.route),
                  borderRadius: BorderRadius.circular(16),
                  child: Container(
                    constraints: const BoxConstraints(minHeight: 48),
                    padding: const EdgeInsetsDirectional.fromSTEB(12, 8, 14, 8),
                    decoration: BoxDecoration(color: AppColors.gold100, borderRadius: BorderRadius.circular(16)),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      Icon(r.icon, size: 18, color: AppColors.gold700),
                      const SizedBox(width: 8),
                      Text(fmt.number(r.count), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900)),
                      const SizedBox(width: 6),
                      Text(s.t('todo.${r.key}'), style: TextStyle(fontSize: 12.5, fontWeight: FontWeight.w600, color: AppColors.navy800)),
                    ]),
                  ),
                ),
              ),
          ]),
      ]),
    );
  }
}

/// «My course progress»: the courses being taken, each with its progress; at the top of the Programs page.
class CourseProgressStrip extends ConsumerWidget {
  const CourseProgressStrip({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final raw = ref.watch(getProvider('/me/registrations')).value;
    if (raw == null) return const SizedBox.shrink();
    final rows = Map<String, dynamic>.from(raw as Map).list('data').where((r) => r.str('status') == 'approved' && r.flag('has_course')).toList();
    if (rows.isEmpty) return const SizedBox.shrink();
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
        child: Text(s.t('progress.title'), style: TextStyle(fontSize: 15, fontWeight: FontWeight.w800, color: AppColors.navy900)),
      ),
      SizedBox(
        height: 92,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16),
          itemCount: rows.length,
          separatorBuilder: (_, _) => const SizedBox(width: 10),
          itemBuilder: (_, i) {
            final r = rows[i];
            final pct = r.number('course_percent').toDouble();
            return InkWell(
              onTap: () => context.push('/registrations/${r.str('id')}'),
              borderRadius: BorderRadius.circular(18),
              child: Container(
                width: 230,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.navy100)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisAlignment: MainAxisAlignment.spaceBetween, children: [
                  Text(r.obj('program')?.str('title') ?? '', maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.navy900, height: 1.3)),
                  Row(children: [
                    Expanded(child: ProgressBarLine(value: pct / 100, height: 7)),
                    const SizedBox(width: 8),
                    Text('${fmt.number(pct)}%', style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.gold700, fontSize: 12.5)),
                  ]),
                ]),
              ),
            );
          },
        ),
      ),
    ]);
  }
}

/// «My certificates» at the top of the Certificates page: how many, this year, and the hours they certify.
class CertificatesSummary extends StatelessWidget {
  const CertificatesSummary({super.key, required this.items});

  /// Rows of the certificate wallet.
  final List<Json> items;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final issued = items.where((c) => c.date('issued_at') != null).toList();
    final year = DateTime.now().year;
    final thisYear = issued.where((c) => c.date('issued_at')!.year == year).length;
    final hours = issued.fold<num>(0, (a, c) => a + c.number('hours'));
    Widget cell(String value, String label, IconData icon) => Expanded(
          child: Column(children: [
            Icon(icon, size: 20, color: AppColors.gold300),
            const SizedBox(height: 6),
            Text(value, style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800)),
            const SizedBox(height: 2),
            Text(label, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white70, fontSize: 11.5)),
          ]),
        );
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 18, horizontal: 12),
      decoration: BoxDecoration(
        gradient: AppColors.navyGradient,
        borderRadius: BorderRadius.circular(AppStyle.radius(6)),
        boxShadow: [BoxShadow(color: AppColors.navy900.withValues(alpha: .25), blurRadius: 22, offset: const Offset(0, 10))],
      ),
      child: Column(children: [
        Text(s.t('cs.total'), style: TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w700)),
        const SizedBox(height: 12),
        Row(children: [
          cell(fmt.number(issued.length), s.t('cs.total'), Icons.workspace_premium),
          cell(fmt.number(thisYear), s.t('cs.thisYear'), Icons.event_available),
          cell(fmt.number(hours, digits: hours == hours.roundToDouble() ? 0 : 1), s.t('cs.hours'), Icons.schedule),
        ]),
      ]),
    );
  }
}
