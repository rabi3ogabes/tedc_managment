import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import '../duties/duties_model.dart';
import 'parts.dart';
import 'supervision.dart';

/// «برامجي» in the supervisor's app: every programme group given to them, by where it stands. A group opens its own page.
class SupervisorProgrammes extends ConsumerStatefulWidget {
  const SupervisorProgrammes({super.key});

  @override
  ConsumerState<SupervisorProgrammes> createState() => _SupervisorProgrammesState();
}

class _SupervisorProgrammesState extends ConsumerState<SupervisorProgrammes> {
  String _phase = 'all';

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final value = ref.watch(getProvider(Duties.path));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('duties.title'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(Duties.path).future),
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(Duties.path)),
          builder: (raw) {
            final groups = Duties.groups(raw);
            if (groups.isEmpty) {
              return ListView(padding: const EdgeInsets.fromLTRB(16, 48, 16, 28), children: [Quiet(icon: Icons.assignment_turned_in_outlined, text: s.t('duties.none'), hint: s.t('duties.noneHint'))]);
            }
            final phases = Duties.phases(groups);
            final phase = phases.contains(_phase) ? _phase : 'all';
            final shown = Duties.inPhase(groups, phase);
            return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 28), children: [
              if (phases.length > 1)
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Row(children: [
                    for (final p in ['all', ...phases])
                      Padding(
                        padding: const EdgeInsetsDirectional.only(end: 8),
                        child: ChoiceChip(
                          key: ValueKey('phase-$p'),
                          label: Text('${s.t('duties.phase.$p')} (${Duties.inPhase(groups, p).length})'),
                          selected: phase == p,
                          onSelected: (_) => setState(() => _phase = p),
                          selectedColor: AppColors.navy900,
                          labelStyle: TextStyle(color: phase == p ? Colors.white : AppColors.navy900, fontWeight: FontWeight.w700),
                          showCheckmark: false,
                        ),
                      ),
                  ]),
                ),
              for (final g in shown) Padding(padding: const EdgeInsets.only(bottom: 10), child: GroupTile(group: g)),
            ]);
          },
        ),
      ),
    );
  }
}

/// One group in a list: what it is, where it stands, when and where it runs, and how many are in it. It opens the group.
class GroupTile extends StatelessWidget {
  const GroupTile({super.key, required this.group});

  final Json group;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final program = group.obj('program') ?? const {};
    final phase = group.str('phase');
    final sub = Duties.groupTitle(group, lang);
    final from = group.date('from');
    final to = group.date('to');
    final rooms = group.list('rooms').map((r) => Duties.name(r, lang)).where((r) => r.isNotEmpty).join('، ');
    final place = program.str('delivery_mode') == 'online' ? s.t('mode.online') : (rooms.isEmpty ? s.t('duties.placeTbd') : rooms);
    final sessions = group.list('sessions');
    final done = sessions.where((x) => x.str('when') == 'past').length;
    final (bg, fg) = stateColors(phase);
    final dates = from == null ? '—' : (to == null || _sameDay(from, to) ? fmt.date(from) : '${fmt.dayMonth(from)} – ${fmt.date(to)}');

    return Surface(
      key: ValueKey('group-${group.str('id')}'),
      onTap: () => context.push('/my-programs/${group.str('id')}'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Wrap(spacing: 6, runSpacing: 6, children: [
              Tag(s.t('duties.phase.$phase'), background: bg, foreground: fg, icon: phase == 'running' ? Icons.sensors : null),
              if (program.str('code').isNotEmpty) Tag(program.str('code'), background: AppColors.gold100, foreground: AppColors.gold700, latin: true),
              if (Duties.teaches(group)) Tag(s.t('duties.capacity.trainer'), background: AppColors.navy100, foreground: AppColors.navy900),
            ]),
          ),
          Icon(Icons.arrow_forward_ios, size: 13, color: AppColors.gold700),
        ]),
        const SizedBox(height: 10),
        Text(Duties.title(group, lang), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.3)),
        if (sub != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(sub, style: TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w700, fontSize: 12.5))),
        const SizedBox(height: 10),
        _Line(Icons.event_outlined, dates),
        _Line(Icons.meeting_room_outlined, place),
        const SizedBox(height: 4),
        Row(children: [
          _Count(Icons.groups_2_outlined, fmt.number(group.number('trainees')), s.t('sup.count.trainees')),
          const SizedBox(width: 14),
          _Count(Icons.co_present_outlined, fmt.number(group.list('trainers').length), s.t('sup.count.trainers')),
          const SizedBox(width: 14),
          _Count(Icons.calendar_month_outlined, '${fmt.number(done)}/${fmt.number(sessions.length)}', s.t('sup.count.sessions')),
        ]),
      ]),
    );
  }

  static bool _sameDay(DateTime a, DateTime b) => a.year == b.year && a.month == b.month && a.day == b.day;
}

class _Line extends StatelessWidget {
  const _Line(this.icon, this.text);

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(children: [
          Icon(icon, size: 16, color: AppColors.gold700),
          const SizedBox(width: 8),
          Expanded(child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w600, fontSize: 13))),
        ]),
      );
}

class _Count extends StatelessWidget {
  const _Count(this.icon, this.value, this.label);

  final IconData icon;
  final String value;
  final String label;

  @override
  Widget build(BuildContext context) => Flexible(
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 15, color: AppColors.muted),
          const SizedBox(width: 5),
          Text(value, textDirection: TextDirection.ltr, style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800, fontSize: 12.5)),
          const SizedBox(width: 4),
          Flexible(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.muted, fontSize: 12))),
        ]),
      );
}

/// The parts of a group's page.
const programmeParts = ['sessions', 'trainees', 'trainers', 'surveys'];

/// One group given to the person: what it is, then its sessions, its trainees with what each attended, its trainers, and
/// the two surveys of its programme with their links.
class ProgrammeScreen extends ConsumerStatefulWidget {
  const ProgrammeScreen({super.key, required this.groupId, this.part});

  final String groupId;

  /// The part to open on (one of [programmeParts]).
  final String? part;

  @override
  ConsumerState<ProgrammeScreen> createState() => _ProgrammeScreenState();
}

class _ProgrammeScreenState extends ConsumerState<ProgrammeScreen> {
  late String _part = programmeParts.contains(widget.part) ? widget.part! : 'sessions';
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final value = ref.watch(getProvider(Duties.path));
    final mayAttend = ref.watch(authProvider).value?.can('attendance.manage') ?? false;
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('sup.prog.title'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () {
          ref.invalidate(getProvider(Supervision.traineesPath(widget.groupId)));
          return ref.refresh(getProvider(Duties.path).future);
        },
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(Duties.path)),
          builder: (raw) {
            final group = Supervision.group(Duties.groups(raw), widget.groupId);
            if (group == null) {
              return ListView(padding: const EdgeInsets.fromLTRB(16, 48, 16, 28), children: [Quiet(icon: Icons.search_off, text: s.t('sup.prog.missing'))]);
            }
            return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 32), children: [
              ProgrammeHead(group: group),
              const SizedBox(height: 14),
              _Parts(part: _part, group: group, onChanged: (p) => setState(() => _part = p)),
              const SizedBox(height: 14),
              ...switch (_part) {
                'trainees' => _trainees(context, group),
                'trainers' => _trainers(context, group),
                'surveys' => _surveys(context, group),
                _ => _sessions(context, group, mayAttend),
              },
            ]);
          },
        ),
      ),
    );
  }

  List<Widget> _sessions(BuildContext context, Json group, bool mayAttend) {
    final sessions = group.list('sessions');
    if (sessions.isEmpty) return [Quiet(icon: Icons.calendar_month_outlined, text: context.s.t('sup.prog.noSessions'))];
    return [for (final x in sessions) Padding(padding: const EdgeInsets.only(bottom: 10), child: SlotCard(slot: (group: group, session: x), mayAttend: mayAttend, showDate: true, inGroup: true))];
  }

  List<Widget> _trainees(BuildContext context, Json group) {
    final s = context.s;
    final value = ref.watch(getProvider(Supervision.traineesPath(widget.groupId)));
    return [
      AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(Supervision.traineesPath(widget.groupId))),
        builder: (raw) {
          final data = raw is Map ? (Map<String, dynamic>.from(raw).obj('data') ?? const <String, dynamic>{}) : const <String, dynamic>{};
          final all = data.list('trainees');
          if (all.isEmpty) return Quiet(icon: Icons.groups_2_outlined, text: s.t('sup.prog.noTrainees'));
          final shown = Supervision.search(all, _query);
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (all.length > 6)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: TextField(
                  key: const ValueKey('trainee-search'),
                  onChanged: (v) => setState(() => _query = v),
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    hintText: s.t('sup.prog.search'),
                    prefixIcon: const Icon(Icons.search, size: 20),
                    isDense: true,
                    filled: true,
                    fillColor: Colors.white,
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: AppColors.navy100)),
                    enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide(color: AppColors.navy100)),
                  ),
                ),
              ),
            if (shown.isEmpty)
              Quiet(icon: Icons.search_off, text: s.t('sup.prog.noMatch'))
            else
              Surface(
                padding: EdgeInsets.zero,
                child: Column(children: [
                  for (var i = 0; i < shown.length; i++) ...[
                    if (i > 0) Divider(height: 1, color: AppColors.navy100),
                    TraineeRow(row: shown[i], held: data.number('held').toInt()),
                  ],
                ]),
              ),
          ]);
        },
      ),
    ];
  }

  List<Widget> _trainers(BuildContext context, Json group) {
    final s = context.s;
    final lang = s.languageCode;
    final trainers = Duties.people(group.list('trainers'), lang);
    final supervisor = group.obj('supervisor');
    return [
      if (trainers.isEmpty)
        Quiet(icon: Icons.co_present_outlined, text: s.t('duties.noTrainers'))
      else
        Surface(
          padding: EdgeInsets.zero,
          child: Column(children: [
            for (var i = 0; i < trainers.length; i++) ...[
              if (i > 0) Divider(height: 1, color: AppColors.navy100),
              _Person(name: trainers[i].$1, role: s.t('duties.capacity.trainer'), me: trainers[i].$2, icon: Icons.co_present_outlined),
            ],
          ]),
        ),
      if (supervisor != null) ...[
        const SizedBox(height: 18),
        Heading(s.t('duties.supervisor')),
        Surface(padding: EdgeInsets.zero, child: _Person(name: Duties.name(supervisor, lang), role: s.t('duties.capacity.supervisor'), me: supervisor.flag('me'), icon: Icons.supervisor_account_outlined)),
      ],
    ];
  }

  List<Widget> _surveys(BuildContext context, Json group) {
    final s = context.s;
    final surveys = Supervision.surveys(group);
    if (surveys.isEmpty) return [Quiet(icon: Icons.rate_review_outlined, text: s.t('sup.survey.none'))];
    final title = Duties.title(group, s.languageCode);
    return [
      for (final (kind, form) in surveys) Padding(padding: const EdgeInsets.only(bottom: 10), child: Surface(child: SurveyLink(kind: kind, form: form, programme: title))),
    ];
  }
}

/// The top of a group's page: what it is and where it stands, when and where it runs.
class ProgrammeHead extends StatelessWidget {
  const ProgrammeHead({super.key, required this.group});

  final Json group;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final program = group.obj('program') ?? const {};
    final phase = group.str('phase');
    final sub = Duties.groupTitle(group, lang);
    final from = group.date('from');
    final to = group.date('to');
    final rooms = group.list('rooms').map((r) => Duties.name(r, lang)).where((r) => r.isNotEmpty).join('، ');
    final place = program.str('delivery_mode') == 'online' ? s.t('mode.online') : (rooms.isEmpty ? s.t('duties.placeTbd') : rooms);
    final dates = from == null ? '—' : (to == null || GroupTile._sameDay(from, to) ? fmt.date(from) : '${fmt.dayMonth(from)} – ${fmt.date(to)}');
    Widget fact(IconData icon, String text) => Padding(
          padding: const EdgeInsets.only(top: 7),
          child: Row(children: [
            Icon(icon, size: 16, color: AppColors.gold300),
            const SizedBox(width: 8),
            Expanded(child: Text(text, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600))),
          ]),
        );

    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(24)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Wrap(spacing: 6, runSpacing: 6, children: [
          Tag(s.t('duties.phase.$phase'), background: AppColors.gold500, foreground: AppColors.navy950, icon: phase == 'running' ? Icons.sensors : null),
          if (program.str('code').isNotEmpty) Tag(program.str('code'), background: Colors.white.withValues(alpha: .14), foreground: Colors.white, latin: true),
          for (final c in const ['supervisor', 'trainer'])
            if ((c == 'supervisor' && Duties.supervises(group)) || (c == 'trainer' && Duties.teaches(group))) Tag(s.t('duties.capacity.$c'), background: Colors.white.withValues(alpha: .14), foreground: Colors.white),
        ]),
        const SizedBox(height: 12),
        Text(Duties.title(group, lang), style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800, height: 1.3)),
        if (sub != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(sub, style: TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w700, fontSize: 13))),
        const SizedBox(height: 6),
        fact(Icons.event_outlined, dates),
        fact(Icons.meeting_room_outlined, place),
      ]),
    );
  }
}

/// The four parts of a group's page, each with how many it holds.
class _Parts extends StatelessWidget {
  const _Parts({required this.part, required this.group, required this.onChanged});

  final String part;
  final Json group;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final counts = {
      'sessions': group.list('sessions').length,
      'trainees': group.number('trainees').toInt(),
      'trainers': group.list('trainers').length,
      'surveys': Supervision.surveys(group).length,
    };
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.navy100)),
      child: Row(children: [
        for (final p in programmeParts)
          Expanded(
            child: Semantics(
              button: true,
              selected: part == p,
              label: '${s.t('sup.prog.$p')}: ${counts[p]}',
              excludeSemantics: true,
              child: Material(
                color: part == p ? AppColors.navy900 : Colors.transparent,
                borderRadius: BorderRadius.circular(14),
                clipBehavior: Clip.antiAlias,
                child: InkWell(
                  key: ValueKey('part-$p'),
                  onTap: () => onChanged(p),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8, horizontal: 2),
                    child: Column(children: [
                      Text(Fmt('en').number(counts[p]), style: TextStyle(color: part == p ? AppColors.gold300 : AppColors.navy900, fontSize: 16, fontWeight: FontWeight.w800, height: 1.2)),
                      Text(s.t('sup.prog.$p'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: part == p ? Colors.white : AppColors.muted, fontSize: 12, fontWeight: FontWeight.w700)),
                    ]),
                  ),
                ),
              ),
            ),
          ),
      ]),
    );
  }
}

/// One trainee of a group: who, from where, and how many of the sessions held so far they attended.
class TraineeRow extends StatelessWidget {
  const TraineeRow({super.key, required this.row, required this.held});

  final Json row;
  final int held;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final attended = row.number('attended').toInt();
    final under = [row.str('employee_no'), row.str('school')].where((e) => e.isNotEmpty).join(' · ');
    final share = Duties.percent(attended, held);
    final tone = held == 0 ? AppColors.muted : (share >= 75 ? AppColors.success : (share >= 50 ? AppColors.warning : AppColors.danger));
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
      child: Row(children: [
        CircleAvatar(radius: 18, backgroundColor: AppColors.navy100, child: Text(Supervision.initial(row.str('name')), style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800))),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(row.str('name'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900, fontSize: 14)),
            if (under.isNotEmpty) Text(under, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
          ]),
        ),
        const SizedBox(width: 10),
        Column(crossAxisAlignment: CrossAxisAlignment.end, children: [
          Text(held == 0 ? '—' : '${fmt.number(attended)} / ${fmt.number(held)}', textDirection: TextDirection.ltr, style: TextStyle(color: tone, fontWeight: FontWeight.w800, fontSize: 13.5)),
          Text(s.t(held == 0 ? 'sup.prog.notHeld' : 'sup.prog.attended'), style: const TextStyle(color: AppColors.muted, fontSize: 10.5)),
        ]),
      ]),
    );
  }
}

class _Person extends StatelessWidget {
  const _Person({required this.name, required this.role, required this.me, required this.icon});

  final String name;
  final String role;
  final bool me;
  final IconData icon;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
        child: Row(children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(color: AppColors.ivory, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.navy100)),
            child: Icon(icon, size: 19, color: AppColors.gold700),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(name, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900, fontSize: 14)),
              Text(role, style: const TextStyle(color: AppColors.muted, fontSize: 12)),
            ]),
          ),
          if (me) Tag(context.s.t('duties.you'), background: AppColors.gold100, foreground: AppColors.gold700),
        ]),
      );
}
