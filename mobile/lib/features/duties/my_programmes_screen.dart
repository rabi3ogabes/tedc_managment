import 'package:material_ui/material_ui.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_style.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'duties_model.dart';

/// «برامجي»: every programme group the person supervises or teaches, whichever screen of the dashboard assigned it. Each
/// group shows when and where it runs and with whom, its session to act on with who attended, the way to take attendance
/// for a session of today, and the trainee survey as a code the room can scan from this phone.
class MyProgrammesScreen extends ConsumerStatefulWidget {
  const MyProgrammesScreen({super.key});

  @override
  ConsumerState<MyProgrammesScreen> createState() => _MyProgrammesScreenState();
}

class _MyProgrammesScreenState extends ConsumerState<MyProgrammesScreen> {
  String _phase = 'all';

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final value = ref.watch(getProvider(Duties.path));
    final mayAttend = ref.watch(authProvider).value?.can('attendance.manage') ?? false;

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
              return ListView(padding: const EdgeInsets.all(24), children: [
                const SizedBox(height: 80),
                Icon(Icons.assignment_turned_in_outlined, size: 56, color: AppColors.gold500),
                const SizedBox(height: 16),
                Text(s.t('duties.none'), textAlign: TextAlign.center, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900)),
                const SizedBox(height: 8),
                Text(s.t('duties.noneHint'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, height: 1.6)),
              ]);
            }
            final phases = Duties.phases(groups);
            final phase = phases.contains(_phase) ? _phase : 'all';
            final shown = Duties.inPhase(groups, phase);
            return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 28), children: [
              if (phases.length > 1)
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Row(children: [
                    for (final p in ['all', ...phases])
                      Padding(
                        padding: const EdgeInsetsDirectional.only(end: 8),
                        child: ChoiceChip(
                          label: Text(s.t('duties.phase.$p')),
                          selected: phase == p,
                          onSelected: (_) => setState(() => _phase = p),
                          selectedColor: AppColors.navy900,
                          labelStyle: TextStyle(color: phase == p ? Colors.white : AppColors.navy900, fontWeight: FontWeight.w700),
                          showCheckmark: false,
                        ),
                      ),
                  ]),
                ),
              for (final g in shown) Padding(padding: const EdgeInsets.only(bottom: 16), child: DutyCard(group: g, mayAttend: mayAttend)),
            ]);
          },
        ),
      ),
    );
  }
}

/// One group: what it is and where it stands, the facts of it, its session to act on, the survey, and its sessions.
class DutyCard extends StatelessWidget {
  const DutyCard({super.key, required this.group, required this.mayAttend});

  final Json group;

  /// Whether the person's role works with attendance at all (the server decides which sessions are theirs).
  final bool mayAttend;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final program = group.obj('program') ?? const {};
    final sub = Duties.groupTitle(group, lang);
    final from = group.date('from');
    final to = group.date('to');
    final rooms = group.list('rooms').map((r) => Duties.name(r, lang)).where((r) => r.isNotEmpty).join('، ');
    final trainers = Duties.people(group.list('trainers'), lang);
    final supervisor = group.obj('supervisor');
    final focus = Duties.focus(group);
    final sessions = group.list('sessions');
    final survey = group.obj('forms')?.obj('trainee');
    final radius = BorderRadius.circular(AppStyle.radius(5));

    return Container(
      decoration: BoxDecoration(color: Colors.white, borderRadius: radius, border: Border.all(color: AppColors.navy100), boxShadow: [BoxShadow(color: AppColors.navy900.withValues(alpha: .06), blurRadius: 18, offset: const Offset(0, 8))]),
      clipBehavior: Clip.antiAlias,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 6, runSpacing: 6, children: [
              _Tag(program.str('code'), background: AppColors.gold100, foreground: AppColors.gold700, mono: true),
              _Tag(s.t('duties.phase.${group.str('phase')}'), background: _phaseColor(group.str('phase')).$1, foreground: _phaseColor(group.str('phase')).$2),
              if (Duties.supervises(group)) _Tag(s.t('duties.capacity.supervisor'), background: AppColors.navy900, foreground: Colors.white, icon: Icons.supervisor_account),
              if (Duties.teaches(group)) _Tag(s.t('duties.capacity.trainer'), background: AppColors.navy900, foreground: Colors.white, icon: Icons.co_present),
            ]),
            const SizedBox(height: 10),
            Text(Duties.title(group, lang), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.3)),
            if (sub != null) Padding(padding: const EdgeInsets.only(top: 2), child: Text(sub, style: TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w700, fontSize: 13))),
            const SizedBox(height: 12),
            _Fact(Icons.event_outlined, s.t('duties.when'), from == null ? '—' : (to == null || _sameDay(from, to) ? fmt.dateTime(from) : '${fmt.date(from)}  ←  ${fmt.date(to)}')),
            _Fact(Icons.meeting_room_outlined, s.t('duties.place'), program.str('delivery_mode') == 'online' ? s.t('mode.online') : (rooms.isEmpty ? s.t('duties.placeTbd') : rooms)),
            _Fact(Icons.groups_2_outlined, s.t('duties.trainees'), fmt.number(group.number('trainees'))),
            _PeopleFact(Icons.co_present_outlined, s.t('duties.trainers'), trainers, empty: s.t('duties.noTrainers'), you: s.t('duties.you')),
            _PeopleFact(Icons.supervisor_account_outlined, s.t('duties.supervisor'), supervisor == null ? const [] : Duties.people([supervisor], lang), empty: s.t('duties.noSupervisor'), you: s.t('duties.you')),
          ]),
        ),
        if (focus != null) Padding(padding: const EdgeInsets.fromLTRB(12, 8, 12, 12), child: _FocusSession(session: focus, mayAttend: mayAttend)),
        if (survey != null) _SurveyRow(title: Duties.title(group, lang), survey: survey),
        if (sessions.length > 1)
          Theme(
            data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
            child: ExpansionTile(
              tilePadding: const EdgeInsets.symmetric(horizontal: 16),
              backgroundColor: AppColors.ivory,
              collapsedBackgroundColor: AppColors.ivory,
              leading: Icon(Icons.calendar_month_outlined, color: AppColors.gold700, size: 20),
              title: Text('${s.t('duties.sessions')} · ${fmt.number(sessions.where((x) => x.str('when') == 'past').length)} / ${fmt.number(sessions.length)}', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5, color: AppColors.navy900)),
              children: [for (final x in sessions) _SessionRow(session: x, mayAttend: mayAttend)],
            ),
          ),
      ]),
    );
  }

  static bool _sameDay(DateTime a, DateTime b) => a.year == b.year && a.month == b.month && a.day == b.day;

  static (Color, Color) _phaseColor(String phase) => switch (phase) {
        'running' => (const Color(0xFFE7F6EF), AppColors.success),
        'upcoming' => (const Color(0xFFE6F1FB), const Color(0xFF1D5C96)),
        _ => (AppColors.navy100, AppColors.muted),
      };
}

class _Tag extends StatelessWidget {
  const _Tag(this.text, {required this.background, required this.foreground, this.icon, this.mono = false});

  final String text;
  final Color background;
  final Color foreground;
  final IconData? icon;
  final bool mono;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(99)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 13, color: AppColors.gold300), const SizedBox(width: 5)],
          Text(text, style: TextStyle(color: foreground, fontSize: 11, fontWeight: FontWeight.w800, fontFamily: mono ? 'monospace' : null)),
        ]),
      );
}

class _Fact extends StatelessWidget {
  const _Fact(this.icon, this.label, this.value);

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => _FactRow(icon: icon, label: label, child: Text(value, style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w600, fontSize: 13.5, height: 1.35)));
}

/// A list of names, the reader's own marked «أنت».
class _PeopleFact extends StatelessWidget {
  const _PeopleFact(this.icon, this.label, this.people, {required this.empty, required this.you});

  final IconData icon;
  final String label;
  final List<(String, bool)> people;
  final String empty;
  final String you;

  @override
  Widget build(BuildContext context) {
    final style = TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w600, fontSize: 13.5, height: 1.35);
    if (people.isEmpty) return _FactRow(icon: icon, label: label, child: Text(empty, style: style.copyWith(color: AppColors.muted)));
    return _FactRow(
      icon: icon,
      label: label,
      child: Wrap(spacing: 8, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
        for (final (name, me) in people)
          Row(mainAxisSize: MainAxisSize.min, children: [
            Flexible(child: Text(name, style: style)),
            if (me) ...[
              const SizedBox(width: 5),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 1),
                decoration: BoxDecoration(color: AppColors.gold100, borderRadius: BorderRadius.circular(99)),
                child: Text(you, style: TextStyle(color: AppColors.gold700, fontSize: 10, fontWeight: FontWeight.w800)),
              ),
            ],
          ]),
      ]),
    );
  }
}

class _FactRow extends StatelessWidget {
  const _FactRow({required this.icon, required this.label, required this.child});

  final IconData icon;
  final String label;
  final Widget child;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 9),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(padding: const EdgeInsets.only(top: 1), child: Icon(icon, size: 17, color: AppColors.gold700)),
          const SizedBox(width: 10),
          SizedBox(width: 78, child: Text(label, style: const TextStyle(color: AppColors.muted, fontSize: 12, fontWeight: FontWeight.w600, height: 1.5))),
          Expanded(child: child),
        ]),
      );
}

/// The session to act on: what it is, when and where, who came, and taking attendance when it is a session of today.
class _FocusSession extends StatelessWidget {
  const _FocusSession({required this.session, required this.mayAttend});

  final Json session;
  final bool mayAttend;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final when = session.str('when');
    final now = when == 'now';
    final title = session.str(lang == 'en' ? 'title_en' : 'title_ar');
    final place = Duties.name(session.obj('place'), lang);
    final present = session.number('present');
    final expected = session.number('expected');
    final today = Duties.takesAttendance(session);

    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [AppColors.navy900, now ? AppColors.navy700 : AppColors.navy800]),
        borderRadius: BorderRadius.circular(AppStyle.radius(4)),
        border: now ? Border.all(color: AppColors.gold300, width: 1.5) : null,
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          if (now) ...[Icon(Icons.sensors, size: 15, color: AppColors.gold300), const SizedBox(width: 6)],
          Text(s.t('duties.session.${Duties.sessionLabel(session)}'), style: TextStyle(color: AppColors.gold300, fontSize: 12, fontWeight: FontWeight.w800)),
        ]),
        const SizedBox(height: 4),
        if (title.isNotEmpty) Text(title, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 15)),
        Text([fmt.dateTime(session.date('starts_at')), if (place.isNotEmpty) place].join(' · '), style: const TextStyle(color: Colors.white70, fontSize: 12.5, height: 1.5)),
        if (when != 'later') ...[
          const SizedBox(height: 12),
          Row(children: [
            Text(s.t('duties.attendance'), style: const TextStyle(color: Colors.white70, fontSize: 12, fontWeight: FontWeight.w600)),
            const Spacer(),
            Text(expected > 0 ? '${fmt.number(present)} / ${fmt.number(expected)}' : '—', style: TextStyle(color: AppColors.gold300, fontSize: 17, fontWeight: FontWeight.w800)),
          ]),
          const SizedBox(height: 6),
          ClipRRect(
            borderRadius: BorderRadius.circular(99),
            child: LinearProgressIndicator(value: Duties.percent(present, expected) / 100, minHeight: 7, backgroundColor: Colors.white.withValues(alpha: .16), valueColor: AlwaysStoppedAnimation(AppColors.gold300)),
          ),
        ],
        if (mayAttend && today) ...[
          const SizedBox(height: 14),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(
              key: ValueKey('attend-${session.str('id')}'),
              style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, padding: const EdgeInsets.symmetric(vertical: 12)),
              onPressed: () => context.push('/roll-call/${session.str('id')}'),
              icon: const Icon(Icons.fact_check_outlined, size: 19),
              label: Text(s.t('role.rollCall'), style: const TextStyle(fontWeight: FontWeight.w800)),
            ),
          ),
        ],
      ]),
    );
  }
}

/// The trainee survey of the programme: where it stands, how many answered, and its code for the room.
class _SurveyRow extends StatelessWidget {
  const _SurveyRow({required this.title, required this.survey});

  final String title;
  final Json survey;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final status = survey.str('status');
    final url = survey.str('url');
    return Container(
      decoration: BoxDecoration(border: Border(top: BorderSide(color: AppColors.navy100))),
      padding: const EdgeInsetsDirectional.fromSTEB(16, 10, 8, 10),
      child: Row(children: [
        Container(
          width: 36,
          height: 36,
          decoration: BoxDecoration(color: AppColors.ivory, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.navy100)),
          child: Icon(Icons.rate_review_outlined, size: 18, color: AppColors.gold700),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(s.t('duties.survey'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5, color: AppColors.navy900)),
            Text('${s.t('duties.survey.$status')} · ${fmt.number(survey.number('answered'))} / ${fmt.number(survey.number('expected'))}', style: const TextStyle(color: AppColors.muted, fontSize: 12)),
          ]),
        ),
        if (url.isNotEmpty)
          TextButton.icon(
            key: const ValueKey('survey-code'),
            onPressed: () => _show(context, url),
            icon: const Icon(Icons.qr_code_2, size: 19),
            label: Text(s.t('duties.survey.show')),
          ),
      ]),
    );
  }

  void _show(BuildContext context, String url) {
    final s = context.s;
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      backgroundColor: Colors.white,
      builder: (sheet) => SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(s.t('duties.survey'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900)),
            const SizedBox(height: 2),
            Text(title, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, fontSize: 13)),
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(14),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24), border: Border.all(color: AppColors.navy100)),
              child: QrImageView(data: url, size: 250, eyeStyle: QrEyeStyle(eyeShape: QrEyeShape.square, color: AppColors.navy950), dataModuleStyle: QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: AppColors.navy950)),
            ),
            const SizedBox(height: 14),
            Text(s.t('duties.survey.hint'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, height: 1.6, fontSize: 13)),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: () async {
                await Clipboard.setData(ClipboardData(text: url));
                if (sheet.mounted) ScaffoldMessenger.maybeOf(sheet)?.showSnackBar(SnackBar(content: Text(s.t('duties.survey.copied'))));
              },
              icon: const Icon(Icons.copy_outlined, size: 18),
              label: Text(s.t('duties.survey.copy')),
            ),
          ]),
        ),
      ),
    );
  }
}

/// One session in the list of a group: when, where it stands, who came, and attendance for a session of today.
class _SessionRow extends StatelessWidget {
  const _SessionRow({required this.session, required this.mayAttend});

  final Json session;
  final bool mayAttend;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final when = session.str('when');
    final title = session.str(lang == 'en' ? 'title_en' : 'title_ar');
    final expected = session.number('expected');
    final (bg, fg) = switch (when) {
      'now' => (const Color(0xFFE7F6EF), AppColors.success),
      'today' => (AppColors.gold100, AppColors.gold700),
      'later' => (const Color(0xFFE6F1FB), const Color(0xFF1D5C96)),
      _ => (AppColors.navy100, AppColors.muted),
    };
    final attend = mayAttend && Duties.takesAttendance(session);
    return ListTile(
      dense: true,
      tileColor: Colors.white,
      contentPadding: const EdgeInsetsDirectional.fromSTEB(16, 2, 8, 2),
      onTap: attend ? () => context.push('/roll-call/${session.str('id')}') : null,
      title: Row(children: [
        Flexible(child: Text(title.isEmpty ? fmt.date(session.date('starts_at')) : title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.navy900, fontSize: 13.5))),
        const SizedBox(width: 8),
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
          decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(99)),
          child: Text(s.t('duties.when.$when'), style: TextStyle(color: fg, fontSize: 10, fontWeight: FontWeight.w800)),
        ),
      ]),
      subtitle: Text(fmt.dateTime(session.date('starts_at')), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
      trailing: when == 'later' || expected <= 0
          ? (attend ? Icon(Icons.fact_check_outlined, color: AppColors.gold700, size: 20) : null)
          : Text('${fmt.number(session.number('present'))} / ${fmt.number(expected)}', style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800, fontSize: 13)),
    );
  }
}
