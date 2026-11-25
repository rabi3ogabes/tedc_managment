import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import '../duties/duties_model.dart';
import 'parts.dart';
import 'supervision.dart';

/// «الحضور» in the supervisor's app: the sessions of the groups given to them, by day. Today's come with the three ways
/// to take attendance; a session already held opens its sheet to look at it again; the days ahead are only listed.
class SupervisorAttendance extends ConsumerStatefulWidget {
  const SupervisorAttendance({super.key});

  @override
  ConsumerState<SupervisorAttendance> createState() => _SupervisorAttendanceState();
}

class _SupervisorAttendanceState extends ConsumerState<SupervisorAttendance> {
  static const _views = ['today', 'held', 'ahead'];
  String _view = 'today';

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final value = ref.watch(getProvider(Duties.path));
    final mayAttend = ref.watch(authProvider).value?.can('attendance.manage') ?? false;
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('sup.nav.attendance'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(Duties.path).future),
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(Duties.path)),
          builder: (raw) {
            final groups = Duties.groups(raw);
            final lists = {'today': Supervision.today(groups), 'held': Supervision.held(groups), 'ahead': Supervision.ahead(groups)};
            final shown = lists[_view]!;
            return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 28), children: [
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.only(bottom: 12),
                child: Row(children: [
                  for (final v in _views)
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 8),
                      child: ChoiceChip(
                        key: ValueKey('view-$v'),
                        label: Text('${s.t('sup.att.$v')} (${lists[v]!.length})'),
                        selected: _view == v,
                        onSelected: (_) => setState(() => _view = v),
                        selectedColor: AppColors.navy900,
                        labelStyle: TextStyle(color: _view == v ? Colors.white : AppColors.navy900, fontWeight: FontWeight.w700),
                        showCheckmark: false,
                      ),
                    ),
                ]),
              ),
              if (!mayAttend)
                Container(
                  margin: const EdgeInsets.only(bottom: 12),
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(color: const Color(0xFFFDF3E1), borderRadius: BorderRadius.circular(14)),
                  child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    const Icon(Icons.lock_outline, size: 17, color: AppColors.warning),
                    const SizedBox(width: 8),
                    Expanded(child: Text(s.t('sup.att.noRight'), style: const TextStyle(color: AppColors.warning, fontSize: 12.5, fontWeight: FontWeight.w700, height: 1.5))),
                  ]),
                ),
              if (shown.isEmpty)
                Quiet(icon: _view == 'today' ? Icons.event_available_outlined : Icons.calendar_month_outlined, text: s.t('sup.att.none.$_view'))
              else
                for (final x in shown) Padding(padding: const EdgeInsets.only(bottom: 10), child: SlotCard(slot: x, mayAttend: mayAttend, showDate: _view != 'today')),
            ]);
          },
        ),
      ),
    );
  }
}

/// «الاستبانات» in the supervisor's app: for every programme given to them, the link of the trainees' survey and the link
/// of the trainer's own, each as a code, a copy, or opened.
class SupervisorSurveys extends ConsumerWidget {
  const SupervisorSurveys({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final value = ref.watch(getProvider(Duties.path));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('sup.nav.surveys'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(Duties.path).future),
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(Duties.path)),
          builder: (raw) {
            final programmes = [for (final g in Supervision.programmes(Duties.groups(raw))) if (Supervision.surveys(g).isNotEmpty) g];
            return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 28), children: [
              Padding(padding: const EdgeInsets.only(bottom: 14), child: Text(s.t('sup.survey.intro'), style: const TextStyle(color: AppColors.muted, fontSize: 13, height: 1.6))),
              if (programmes.isEmpty)
                Quiet(icon: Icons.rate_review_outlined, text: s.t('sup.survey.none'))
              else
                for (final g in programmes) Padding(padding: const EdgeInsets.only(bottom: 12), child: ProgrammeSurveys(group: g)),
            ]);
          },
        ),
      ),
    );
  }
}

/// The two surveys of one programme under its name.
class ProgrammeSurveys extends StatelessWidget {
  const ProgrammeSurveys({super.key, required this.group});

  final Json group;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final title = Duties.title(group, lang);
    final code = group.obj('program')?.str('code') ?? '';
    final phase = group.str('phase');
    final (bg, fg) = stateColors(phase);
    final surveys = Supervision.surveys(group);
    return Surface(
      padding: EdgeInsets.zero,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        InkWell(
          onTap: () => context.push('/my-programs/${group.str('id')}?part=surveys'),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 14, 14, 12),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Wrap(spacing: 6, runSpacing: 6, children: [
                Tag(s.t('duties.phase.$phase'), background: bg, foreground: fg),
                if (code.isNotEmpty) Tag(code, background: AppColors.gold100, foreground: AppColors.gold700, latin: true),
              ]),
              const SizedBox(height: 8),
              Text(title, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.3)),
            ]),
          ),
        ),
        for (final (kind, form) in surveys) ...[
          Divider(height: 1, color: AppColors.navy100),
          Padding(padding: const EdgeInsets.all(14), child: SurveyLink(kind: kind, form: form, programme: title)),
        ],
      ]),
    );
  }
}
