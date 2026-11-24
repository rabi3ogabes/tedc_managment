import 'dart:async';

import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_style.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import '../wayfinding/classroom_sheet.dart';

/// «برامج اليوم»: what the screens decide from a session of today (`GET /me/today-board`, and the `today` rows of the home
/// screen). A row: `id`, `program`, `program_code`, `title`, `starts_at`, `ends_at`, `room`, `trainers`, `supervisors`,
/// `when` (past, now, later), `present`, `expected`, `attention`.
abstract final class Today {
  static const path = '/me/today-board';

  static List<String> names(Json row, String key) => (row[key] as List? ?? const []).map((e) => e.toString()).where((e) => e.isNotEmpty).toList();

  /// Where the session is held: the room the board names, else the place older answers carry.
  static String room(Json row) => row.str('room').isNotEmpty ? row.str('room') : row.str('location');

  /// Where the session stands. A row without the word (an older server) is worked out from its times.
  static String when(Json row, [DateTime? now]) {
    final given = row.str('when');
    if (given.isNotEmpty) return given;
    final at = now ?? DateTime.now();
    final start = row.date('starts_at');
    final end = row.date('ends_at');
    if (end != null && end.isBefore(at)) return 'past';
    return start != null && !start.isAfter(at) ? 'now' : 'later';
  }

  /// The sessions left after the filter of the board: all, one state, or the ones that need somebody's attention.
  static List<Json> filter(List<Json> rows, String by) => switch (by) {
        'all' => rows,
        'attention' => [for (final r in rows) if (r.flag('attention')) r],
        _ => [for (final r in rows) if (when(r) == by) r],
      };

  /// The filters worth offering: a state only when a session is in it.
  static List<String> filters(List<Json> rows) => [
        'all',
        for (final f in const ['now', 'later', 'past']) if (rows.any((r) => when(r) == f)) f,
        if (rows.any((r) => r.flag('attention'))) 'attention',
      ];

  /// How much of the room came, as a fraction for a bar; nobody expected is none.
  static double share(Json row) => row.number('expected') > 0 ? (row.number('present') / row.number('expected')).clamp(0.0, 1.0).toDouble() : 0;
}

/// One session of today: when, which programme, where, who trains it, who supervises it, and who came.
class TodaySessionCard extends StatelessWidget {
  const TodaySessionCard({super.key, required this.row, this.onAttend});

  final Json row;

  /// Opens the attendance of the session, for somebody who takes it; without it the card is read only.
  final VoidCallback? onAttend;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final when = Today.when(row);
    final trainers = Today.names(row, 'trainers');
    final supervisors = Today.names(row, 'supervisors');
    final room = Today.room(row);
    final expected = row.number('expected');
    final live = when == 'now';
    final past = when == 'past';
    final (chipBg, chipFg) = live ? (const Color(0xFFE7F6EF), AppColors.success) : (past ? (AppColors.navy100, AppColors.muted) : (AppColors.gold100, AppColors.gold700));

    return Card(
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppStyle.radius(4)), side: BorderSide(color: live ? AppColors.gold500 : AppColors.navy100, width: live ? 1.4 : 1)),
      child: InkWell(
        onTap: onAttend,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Container(
                width: 64,
                padding: const EdgeInsets.symmetric(vertical: 10),
                decoration: BoxDecoration(color: past ? AppColors.navy700 : AppColors.navy900, borderRadius: BorderRadius.circular(16)),
                child: Column(children: [
                  Text(fmt.time(row.date('starts_at')), style: TextStyle(color: AppColors.gold300, fontSize: 13, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 2),
                  Text(fmt.time(row.date('ends_at')), style: const TextStyle(color: Colors.white70, fontSize: 10.5)),
                ]),
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(row.str('program'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.25)),
                  if (row.str('title').isNotEmpty) Text(row.str('title'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.muted, fontSize: 12.5)),
                  const SizedBox(height: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 3),
                    decoration: BoxDecoration(color: chipBg, borderRadius: BorderRadius.circular(99)),
                    child: Row(mainAxisSize: MainAxisSize.min, children: [
                      if (live) ...[Icon(Icons.sensors, size: 12, color: chipFg), const SizedBox(width: 4)],
                      Text(s.t('today.state.$when'), style: TextStyle(color: chipFg, fontSize: 10.5, fontWeight: FontWeight.w800)),
                    ]),
                  ),
                ]),
              ),
              if (row.flag('has_course') && row.str('program_id').isNotEmpty)
                IconButton.filled(
                  key: const ValueKey('open-kit'),
                  tooltip: s.t('kit.title'),
                  visualDensity: VisualDensity.compact,
                  style: IconButton.styleFrom(backgroundColor: AppColors.navy900, foregroundColor: AppColors.gold300),
                  icon: const Icon(Icons.inventory_2_outlined, size: 20),
                  onPressed: () => context.push('/kit/${row.str('program_id')}'),
                ),
              ClassroomMapButton(
                mode: row.str('mode'),
                target: ClassroomTarget(roomId: row.str('room_id'), placeId: row.str('map_place'), program: row.str('program'), title: row.str('title'), startsAt: row.date('starts_at'), endsAt: row.date('ends_at')),
              ),
            ]),
            const SizedBox(height: 10),
            if (room.isNotEmpty) _Line(Icons.place_outlined, s.t('today.room'), room),
            if (trainers.isNotEmpty) _Line(Icons.co_present_outlined, s.t('today.trainer'), trainers.join('، ')),
            if (supervisors.isNotEmpty) _Line(Icons.supervisor_account_outlined, s.t('today.supervisor'), supervisors.join('، ')),
            if (row.flag('attention'))
              Container(
                margin: const EdgeInsets.only(top: 4),
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(color: const Color(0xFFFDF3E1), borderRadius: BorderRadius.circular(10)),
                child: Row(children: [
                  const Icon(Icons.error_outline, size: 15, color: AppColors.warning),
                  const SizedBox(width: 6),
                  Expanded(child: Text([if (trainers.isEmpty) s.t('today.noTrainer'), if (room.isEmpty) s.t('today.noRoom')].join(' · '), style: const TextStyle(color: AppColors.warning, fontSize: 12, fontWeight: FontWeight.w700))),
                ]),
              ),
            if (when != 'later' && expected > 0) ...[
              const SizedBox(height: 8),
              Row(children: [
                Text(s.t('today.attendance'), style: const TextStyle(color: AppColors.muted, fontSize: 12, fontWeight: FontWeight.w600)),
                const SizedBox(width: 10),
                Expanded(
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(99),
                    child: LinearProgressIndicator(value: Today.share(row), minHeight: 6, backgroundColor: AppColors.navy100, valueColor: AlwaysStoppedAnimation(live ? AppColors.success : AppColors.gold500)),
                  ),
                ),
                const SizedBox(width: 10),
                Text('${fmt.number(row.number('present'))} / ${fmt.number(expected)}', style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800, fontSize: 13)),
              ]),
            ],
          ]),
        ),
      ),
    );
  }
}

class _Line extends StatelessWidget {
  const _Line(this.icon, this.label, this.value);

  final IconData icon;
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 5),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(padding: const EdgeInsets.only(top: 1), child: Icon(icon, size: 15, color: AppColors.gold700)),
          const SizedBox(width: 7),
          SizedBox(width: 58, child: Text(label, style: const TextStyle(fontSize: 12, color: AppColors.muted, fontWeight: FontWeight.w600))),
          Expanded(child: Text(value, style: TextStyle(fontSize: 12.5, color: AppColors.navy900, fontWeight: FontWeight.w600, height: 1.35))),
        ]),
      );
}

/// The day in a few numbers: sessions, the ones on now, rooms, trainers, supervisors, and what needs attention.
class TodaySummary extends StatelessWidget {
  const TodaySummary({super.key, required this.summary});

  final Json summary;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final live = summary.number('now');
    final attention = summary.number('attention');
    Widget tile(String key, IconData icon, {bool accent = false, bool warn = false}) => Expanded(
          child: Container(
            padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
            decoration: BoxDecoration(
              gradient: accent ? AppColors.navyGradient : null,
              color: accent ? null : (warn ? const Color(0xFFFDF3E1) : Colors.white),
              borderRadius: BorderRadius.circular(AppStyle.radius(3)),
              border: accent ? null : Border.all(color: warn ? const Color(0xFFF2D9A6) : AppColors.navy100),
            ),
            child: Column(children: [
              Icon(icon, size: 18, color: accent ? AppColors.gold300 : (warn ? AppColors.warning : AppColors.gold700)),
              const SizedBox(height: 4),
              Text(fmt.number(summary.number(key)), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, height: 1.1, color: accent ? Colors.white : (warn ? AppColors.warning : AppColors.navy900))),
              const SizedBox(height: 2),
              Text(s.t('today.sum.$key'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 10.5, fontWeight: FontWeight.w700, color: accent ? Colors.white70 : AppColors.muted)),
            ]),
          ),
        );

    return Column(children: [
      Row(children: [
        tile('sessions', Icons.event_note_outlined, accent: true),
        const SizedBox(width: 8),
        tile('now', Icons.sensors),
        const SizedBox(width: 8),
        tile('rooms', Icons.meeting_room_outlined),
      ]),
      const SizedBox(height: 8),
      Row(children: [
        tile('trainers', Icons.co_present_outlined),
        const SizedBox(width: 8),
        tile('supervisors', Icons.supervisor_account_outlined),
        const SizedBox(width: 8),
        tile('attention', Icons.error_outline, warn: attention > 0),
      ]),
      if (live > 0 && summary.number('expected') > 0)
        Padding(
          padding: const EdgeInsets.only(top: 10),
          child: Row(children: [
            Icon(Icons.groups_2_outlined, size: 16, color: AppColors.gold700),
            const SizedBox(width: 6),
            Expanded(child: Text(s.t('today.inRooms'), style: const TextStyle(color: AppColors.muted, fontSize: 12.5, fontWeight: FontWeight.w600))),
            Text('${fmt.number(summary.number('present'))} / ${fmt.number(summary.number('expected'))}', style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w800)),
          ]),
        ),
    ]);
  }
}

/// «برامج اليوم»: every session of today, for somebody who runs the centre (or a trainer's own). The numbers on top, a
/// filter by where a session stands, and each session with its room, trainer, supervisor and attendance. It refreshes by
/// itself every minute while it is open, and on pulling down.
class TodayBoardScreen extends ConsumerStatefulWidget {
  const TodayBoardScreen({super.key});

  @override
  ConsumerState<TodayBoardScreen> createState() => _TodayBoardScreenState();
}

class _TodayBoardScreenState extends ConsumerState<TodayBoardScreen> {
  String _filter = 'all';
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 60), (_) {
      if (mounted) ref.invalidate(getProvider(Today.path));
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final value = ref.watch(getProvider(Today.path));
    final me = ref.watch(authProvider).value;
    final canAttend = (me?.can('attendance.manage') ?? false) || (me?.isTrainer ?? false);

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('today.title'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(Today.path).future),
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(Today.path)),
          builder: (raw) {
            final d = raw is Map ? (Map<String, dynamic>.from(raw).obj('data') ?? const <String, dynamic>{}) : const <String, dynamic>{};
            final rows = d.list('sessions');
            final filters = Today.filters(rows);
            final by = filters.contains(_filter) ? _filter : 'all';
            final shown = Today.filter(rows, by);
            return ListView(padding: const EdgeInsets.fromLTRB(16, 14, 16, 28), children: [
              Text(Fmt(s.languageCode).weekdayDate(DateTime.now()), style: const TextStyle(color: AppColors.muted, fontWeight: FontWeight.w700, fontSize: 13)),
              if (!d.flag('whole_centre') && rows.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(s.t('today.mine'), style: const TextStyle(color: AppColors.muted, fontSize: 12))),
              const SizedBox(height: 12),
              if (rows.isEmpty)
                Padding(
                  padding: const EdgeInsets.only(top: 70),
                  child: Column(children: [
                    Icon(Icons.event_available_outlined, size: 56, color: AppColors.gold500),
                    const SizedBox(height: 14),
                    Text(s.t('role.noToday'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900)),
                  ]),
                )
              else ...[
                TodaySummary(summary: d.obj('summary') ?? const {}),
                const SizedBox(height: 14),
                if (filters.length > 2)
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    padding: const EdgeInsets.only(bottom: 10),
                    child: Row(children: [
                      for (final f in filters)
                        Padding(
                          padding: const EdgeInsetsDirectional.only(end: 8),
                          child: ChoiceChip(
                            label: Text('${s.t('today.filter.$f')} (${Today.filter(rows, f).length})'),
                            selected: by == f,
                            onSelected: (_) => setState(() => _filter = f),
                            selectedColor: AppColors.navy900,
                            labelStyle: TextStyle(color: by == f ? Colors.white : AppColors.navy900, fontWeight: FontWeight.w700),
                            showCheckmark: false,
                          ),
                        ),
                    ]),
                  ),
                for (final r in shown)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 10),
                    child: TodaySessionCard(row: r, onAttend: canAttend && r.str('id').isNotEmpty ? () => context.push('/roll-call/${r.str('id')}') : null),
                  ),
              ],
            ]);
          },
        ),
      ),
    );
  }
}
