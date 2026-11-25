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
import 'workspace.dart';

/// «برامجي» in the app of a supervisor or a trainer: every programme group given to them, by where it stands. A group
/// opens its own page.
class WorkspaceProgrammes extends ConsumerStatefulWidget {
  const WorkspaceProgrammes({super.key});

  @override
  ConsumerState<WorkspaceProgrammes> createState() => _WorkspaceProgrammesState();
}

class _WorkspaceProgrammesState extends ConsumerState<WorkspaceProgrammes> {
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

bool _sameDay(DateTime a, DateTime b) => a.year == b.year && a.month == b.month && a.day == b.day;

/// The days a group runs: one day, or the first and the last.
String groupDates(Json group, Fmt fmt) {
  final from = group.date('from');
  final to = group.date('to');
  return from == null ? '—' : (to == null || _sameDay(from, to) ? fmt.date(from) : '${fmt.dayMonth(from)} – ${fmt.date(to)}');
}

/// Where a group is held: online, its rooms, or not set yet.
String groupPlace(Json group, S s) {
  final rooms = group.list('rooms').map((r) => Duties.name(r, s.languageCode)).where((r) => r.isNotEmpty).join('، ');
  return group.obj('program')?.str('delivery_mode') == 'online' ? s.t('mode.online') : (rooms.isEmpty ? s.t('duties.placeTbd') : rooms);
}

/// One group in a list: what it is, where it stands, the days and the hour it starts, its room, its hours, and how many
/// are in it; its kits for somebody who teaches it. It opens the group.
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
    final sessions = group.list('sessions');
    final done = sessions.where((x) => x.str('when') == 'past').length;
    final (bg, fg) = stateColors(phase);
    final hours = Workspace.groupHours(group);
    final kits = Workspace.kitCount(group);
    final both = Duties.supervises(group) && Duties.teaches(group);
    final starts = sessions.isEmpty ? null : Workspace.firstStart(group);

    return Surface(
      key: ValueKey('group-${group.str('id')}'),
      onTap: () => context.push('/my-programs/${group.str('id')}'),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Wrap(spacing: 6, runSpacing: 6, children: [
              Tag(s.t('duties.phase.$phase'), background: bg, foreground: fg, icon: phase == 'running' ? Icons.sensors : null),
              if (program.str('code').isNotEmpty) Tag(program.str('code'), background: AppColors.gold100, foreground: AppColors.gold700, latin: true),
              // Somebody who both supervises and teaches a group is told so; one capacity alone is the app they are in.
              if (both) Tag('${s.t('duties.capacity.supervisor')} · ${s.t('duties.capacity.trainer')}', background: AppColors.navy100, foreground: AppColors.navy900),
              if (kits > 0) Tag(s.count('work.kit.count', kits), background: AppColors.navy100, foreground: AppColors.navy900, icon: Icons.inventory_2_outlined),
            ]),
          ),
          Icon(Icons.arrow_forward_ios, size: 13, color: AppColors.gold700),
        ]),
        const SizedBox(height: 10),
        Text(Duties.title(group, lang), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.3)),
        if (sub != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(sub, style: TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w700, fontSize: 12.5))),
        const SizedBox(height: 10),
        _Line(Icons.event_outlined, [groupDates(group, fmt), if (starts != null) fmt.time(starts)].join(' · ')),
        _Line(Icons.meeting_room_outlined, groupPlace(group, s)),
        const SizedBox(height: 4),
        Row(children: [
          _Count(Icons.groups_2_outlined, fmt.number(group.number('trainees')), s.t('work.count.trainees')),
          const SizedBox(width: 14),
          if (hours > 0) ...[_Count(Icons.schedule, Workspace.hoursText(hours), s.t('work.count.hours')), const SizedBox(width: 14)],
          _Count(Icons.calendar_month_outlined, '${fmt.number(done)}/${fmt.number(sessions.length)}', s.t('work.count.sessions')),
        ]),
      ]),
    );
  }
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

/// One group given to the person: what it is, then its sessions, its trainees with what each attended, its team (the
/// supervisor with the way to reach them, and the trainers), its training kits for somebody who teaches it, and the two
/// surveys of its programme with their links.
class ProgrammeScreen extends ConsumerStatefulWidget {
  const ProgrammeScreen({super.key, required this.groupId, this.part});

  final String groupId;

  /// The part to open on (one of [Workspace.parts]).
  final String? part;

  @override
  ConsumerState<ProgrammeScreen> createState() => _ProgrammeScreenState();
}

class _ProgrammeScreenState extends ConsumerState<ProgrammeScreen> {
  late String _part = widget.part ?? 'sessions';
  String _query = '';

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final value = ref.watch(getProvider(Duties.path));
    final mayAttend = ref.watch(authProvider).value?.can('attendance.manage') ?? false;
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('work.prog.title'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () {
          ref.invalidate(getProvider(Workspace.traineesPath(widget.groupId)));
          return ref.refresh(getProvider(Duties.path).future);
        },
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(Duties.path)),
          builder: (raw) {
            final group = Workspace.group(Duties.groups(raw), widget.groupId);
            if (group == null) {
              return ListView(padding: const EdgeInsets.fromLTRB(16, 48, 16, 28), children: [Quiet(icon: Icons.search_off, text: s.t('work.prog.missing'))]);
            }
            final parts = Workspace.parts(group);
            final part = parts.contains(_part) ? _part : 'sessions';
            return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 32), children: [
              ProgrammeHead(group: group),
              const SizedBox(height: 14),
              _Parts(part: part, parts: parts, group: group, onChanged: (p) => setState(() => _part = p)),
              const SizedBox(height: 14),
              ...switch (part) {
                'trainees' => _trainees(context, group),
                'team' => _team(context, group),
                'kits' => _kits(context, group),
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
    if (sessions.isEmpty) return [Quiet(icon: Icons.calendar_month_outlined, text: context.s.t('work.prog.noSessions'))];
    return [for (final x in sessions) Padding(padding: const EdgeInsets.only(bottom: 10), child: SlotCard(slot: (group: group, session: x), mayAttend: mayAttend, showDate: true, inGroup: true))];
  }

  List<Widget> _trainees(BuildContext context, Json group) {
    final s = context.s;
    final value = ref.watch(getProvider(Workspace.traineesPath(widget.groupId)));
    return [
      AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(Workspace.traineesPath(widget.groupId))),
        builder: (raw) {
          final data = raw is Map ? (Map<String, dynamic>.from(raw).obj('data') ?? const <String, dynamic>{}) : const <String, dynamic>{};
          final all = data.list('trainees');
          if (all.isEmpty) return Quiet(icon: Icons.groups_2_outlined, text: s.t('work.prog.noTrainees'));
          final shown = Workspace.search(all, _query);
          return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            if (all.length > 6)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: TextField(
                  key: const ValueKey('trainee-search'),
                  onChanged: (v) => setState(() => _query = v),
                  textInputAction: TextInputAction.search,
                  decoration: InputDecoration(
                    hintText: s.t('work.prog.search'),
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
              Quiet(icon: Icons.search_off, text: s.t('work.prog.noMatch'))
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

  /// The supervisor of the group first, with the way to reach them; then the trainers.
  List<Widget> _team(BuildContext context, Json group) {
    final s = context.s;
    final lang = s.languageCode;
    final trainers = Duties.people(group.list('trainers'), lang);
    final supervisor = group.obj('supervisor');
    return [
      Heading(s.t('duties.supervisor')),
      if (supervisor == null) Quiet(icon: Icons.supervisor_account_outlined, text: s.t('duties.noSupervisor')) else ContactCard(person: supervisor, role: s.t('duties.capacity.supervisor')),
      const SizedBox(height: 18),
      Heading(s.t('duties.trainers'), count: trainers.isEmpty ? null : trainers.length),
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
    ];
  }

  List<Widget> _kits(BuildContext context, Json group) {
    final programId = group.obj('program')?.str('id') ?? '';
    return [
      for (final kit in Workspace.kits(group)) Padding(padding: const EdgeInsets.only(bottom: 10), child: KitTile(kit: kit, programId: programId)),
      if (Workspace.hasCourse(group)) CourseKitTile(programId: programId),
      const SizedBox(height: 12),
      const KitOwnershipNote(),
    ];
  }

  List<Widget> _surveys(BuildContext context, Json group) {
    final s = context.s;
    final surveys = Workspace.surveys(group);
    if (surveys.isEmpty) return [Quiet(icon: Icons.rate_review_outlined, text: s.t('work.survey.none'))];
    final title = Duties.title(group, s.languageCode);
    return [
      for (final (kind, form) in surveys) Padding(padding: const EdgeInsets.only(bottom: 10), child: Surface(child: SurveyLink(kind: kind, form: form, programme: title))),
    ];
  }
}

/// The training kits belong to the centre: they are read in the protected reader, never downloaded.
class KitOwnershipNote extends StatelessWidget {
  const KitOwnershipNote({super.key});

  @override
  Widget build(BuildContext context) => Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        const Padding(padding: EdgeInsets.only(top: 2), child: Icon(Icons.verified_user_outlined, size: 15, color: AppColors.muted)),
        const SizedBox(width: 8),
        Expanded(child: Text(context.s.t('work.kit.ownership'), style: const TextStyle(color: AppColors.muted, fontSize: 12, height: 1.6))),
      ]);
}

/// The top of a group's page: what it is and where it stands, when and where it runs, how many hours it is, and the way
/// to its room.
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
    final sessions = group.list('sessions');
    final hours = Workspace.groupHours(group);
    final starts = sessions.isEmpty ? null : Workspace.firstStart(group);
    // The room to walk to: the session to act on, when it still has to be held.
    final next = Duties.focus(group);
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
        fact(Icons.event_outlined, [groupDates(group, fmt), if (starts != null) fmt.time(starts)].join(' · ')),
        fact(Icons.meeting_room_outlined, groupPlace(group, s)),
        if (hours > 0) fact(Icons.schedule, '${hoursLabel(s, hours)} · ${s.count('work.sessionsCount', sessions.length)}'),
        if (next != null) RoomWay(slot: (group: group, session: next), onDark: true, padding: const EdgeInsets.only(top: 12)),
      ]),
    );
  }
}

/// The parts of a group's page as one strip, each with how many it holds.
class _Parts extends StatelessWidget {
  const _Parts({required this.part, required this.parts, required this.group, required this.onChanged});

  final String part;
  final List<String> parts;
  final Json group;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final counts = {
      'sessions': group.list('sessions').length,
      'trainees': group.number('trainees').toInt(),
      'team': group.list('trainers').length + (group.obj('supervisor') == null ? 0 : 1),
      'kits': Workspace.kitCount(group),
      'surveys': Workspace.surveys(group).length,
    };
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(18), border: Border.all(color: AppColors.navy100)),
      child: Row(children: [
        for (final p in parts)
          Expanded(
            child: Semantics(
              button: true,
              selected: part == p,
              label: '${s.t('work.prog.$p')}: ${counts[p]}',
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
                      Text(s.t('work.prog.$p'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: part == p ? Colors.white : AppColors.muted, fontSize: parts.length > 4 ? 11 : 12, fontWeight: FontWeight.w700)),
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
        CircleAvatar(radius: 18, backgroundColor: AppColors.navy100, child: Text(Workspace.initial(row.str('name')), style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800))),
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
          Text(s.t(held == 0 ? 'work.prog.notHeld' : 'work.prog.attended'), style: const TextStyle(color: AppColors.muted, fontSize: 10.5)),
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
