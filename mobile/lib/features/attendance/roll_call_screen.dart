import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import '../duties/duties_model.dart';
import '../supervisor/supervision.dart';

/// The attendance sheet of one session, for the people who run it (its trainer, the supervisor of its group): every
/// approved trainee with the four marks (present, late, absent, excused). A tap records the mark at once; «everyone
/// present» is for the usual day. A long sheet is searched by name and narrowed to one mark. For a session of today the
/// sheet also leads to the two codes: the one the trainees scan, and scanning a trainee's own. The centre's rule that a
/// trainer can enter marks by hand only for a short while after the session starts is the server's, and its message is shown.
class RollCallScreen extends ConsumerStatefulWidget {
  const RollCallScreen({super.key, required this.sessionId});

  final String sessionId;

  @override
  ConsumerState<RollCallScreen> createState() => _RollCallScreenState();
}

class _RollCallScreenState extends ConsumerState<RollCallScreen> {
  static const _marks = ['present', 'late', 'absent', 'excused'];

  final Set<String> _busy = {};
  bool _all = false;
  String _query = '';
  String _filter = 'all';

  String get _path => Supervision.attendancePath(widget.sessionId);

  /// What other screens show from this sheet (the numbers of a session) follows a change made here.
  void _changed() {
    ref.invalidate(getProvider(_path));
    ref.invalidate(getProvider(Duties.path));
  }

  Future<void> _mark(String registrationId, String status) async {
    if (_busy.contains(registrationId)) return;
    setState(() => _busy.add(registrationId));
    try {
      await ref.read(apiProvider).post(_path, {'registration_id': registrationId, 'status': status});
      _changed();
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy.remove(registrationId));
    }
  }

  Future<void> _everyonePresent(List<Json> rows) async {
    setState(() => _all = true);
    var done = 0;
    try {
      for (final r in rows.where((r) => r.str('status') != 'present')) {
        await ref.read(apiProvider).post(_path, {'registration_id': r.str('registration_id'), 'status': 'present'});
        done++;
      }
      _changed();
      if (mounted) showSnack(context, context.s.t('roll.allDone').replaceFirst('{n}', '$done'));
    } catch (e) {
      _changed();
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _all = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final value = ref.watch(getProvider(_path));
    final me = ref.watch(authProvider).value;
    // The session itself, when it is one of the person's own groups: its name heads the sheet.
    final slot = Supervision.slot(Duties.groups(ref.watch(getProvider(Duties.path)).value), widget.sessionId);
    // The two codes are for a session of today; a sheet opened another way (no group known) keeps them.
    final codes = slot == null || Duties.takesAttendance(slot.session);

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('roll.title'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(_path).future),
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(_path)),
          builder: (raw) {
            final data = Map<String, dynamic>.from(raw as Map).obj('data') ?? <String, dynamic>{};
            final rows = data.list('rows');
            final filter = _filter == 'all' || rows.any((r) => r.str('status') == _filter) ? _filter : 'all';
            final shown = Supervision.search(Supervision.marked(rows, filter), _query);
            final head = slot == null ? null : _SessionHead(slot: slot);
            if (rows.isEmpty) {
              return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
                ?head,
                const SizedBox(height: 40),
                Center(child: Text(s.t('roll.empty'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted))),
              ]);
            }
            return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
              if (head != null) ...[head, const SizedBox(height: 12)],
              _Summary(expected: data.number('expected').toInt(), present: data.number('present').toInt(), late: data.number('late').toInt(), absent: data.number('absent').toInt()),
              const SizedBox(height: 12),
              SizedBox(
                width: double.infinity,
                child: FilledButton.icon(
                  onPressed: _all ? null : () => _everyonePresent(rows),
                  style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, minimumSize: const Size(0, 48)),
                  icon: const Icon(Icons.done_all),
                  label: Text(s.t('roll.everyone'), style: const TextStyle(fontWeight: FontWeight.w800)),
                ),
              ),
              if (codes) ...[
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(child: _Way(key: const ValueKey('roll-code'), icon: Icons.qr_code_2, label: s.t('sup.act.code'), onTap: () => context.push('/session-qr/${widget.sessionId}?from=sheet'))),
                  const SizedBox(width: 8),
                  Expanded(child: _Way(key: const ValueKey('roll-scan'), icon: Icons.qr_code_scanner, label: s.t('sup.act.scan'), onTap: () => context.push('/staff-scan/${widget.sessionId}?from=sheet'))),
                ]),
              ],
              if (me?.isTrainer ?? false)
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: TextButton.icon(onPressed: () => context.push('/scan?trainer=1'), icon: const Icon(Icons.how_to_reg_outlined, size: 18), label: Text(s.t('roll.myCheckIn'))),
                ),
              const SizedBox(height: 12),
              if (rows.length > 6)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: TextField(
                    key: const ValueKey('roll-search'),
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
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(children: [
                  for (final f in ['all', ..._marks])
                    if (f == 'all' || rows.any((r) => r.str('status') == f))
                      Padding(
                        padding: const EdgeInsetsDirectional.only(end: 8),
                        child: ChoiceChip(
                          key: ValueKey('roll-filter-$f'),
                          label: Text('${s.t(f == 'all' ? 'duties.phase.all' : 'roll.$f')} (${Supervision.marked(rows, f).length})'),
                          selected: filter == f,
                          onSelected: (_) => setState(() => _filter = f),
                          selectedColor: AppColors.navy900,
                          labelStyle: TextStyle(color: filter == f ? Colors.white : AppColors.navy900, fontWeight: FontWeight.w700),
                          showCheckmark: false,
                        ),
                      ),
                ]),
              ),
              if (shown.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 28), child: Center(child: Text(s.t('sup.prog.noMatch'), style: const TextStyle(color: AppColors.muted)))),
              for (final r in shown)
                Card(
                  margin: const EdgeInsets.only(bottom: 10),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(r.str('name'), style: const TextStyle(fontWeight: FontWeight.w800)),
                            if (r.str('school').isNotEmpty) Text(r.str('school'), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                          ]),
                        ),
                        if (r.date('check_in_at') != null)
                          Row(mainAxisSize: MainAxisSize.min, children: [
                            Icon(Icons.login, size: 14, color: AppColors.gold700),
                            const SizedBox(width: 4),
                            Text(fmt.time(r.date('check_in_at')), style: TextStyle(color: AppColors.gold700, fontSize: 12, fontWeight: FontWeight.w700)),
                          ]),
                      ]),
                      const SizedBox(height: 10),
                      Wrap(spacing: 8, runSpacing: 6, children: [
                        for (final m in _marks)
                          ChoiceChip(
                            label: Text(s.t('roll.$m')),
                            selected: r.str('status') == m,
                            showCheckmark: false,
                            selectedColor: AppColors.gold300,
                            onSelected: _busy.contains(r.str('registration_id')) || _all ? null : (_) => _mark(r.str('registration_id'), m),
                          ),
                      ]),
                    ]),
                  ),
                ),
            ]);
          },
        ),
      ),
    );
  }
}

/// Which session the sheet is of: the programme, the session, and when and where it is held.
class _SessionHead extends StatelessWidget {
  const _SessionHead({required this.slot});

  final Slot slot;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final session = slot.session;
    final title = session.str(lang == 'en' ? 'title_en' : 'title_ar');
    final place = Duties.name(session.obj('place'), lang);
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(Duties.title(slot.group, lang), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.3)),
      const SizedBox(height: 2),
      Text([if (title.isNotEmpty) title, fmt.dateTime(session.date('starts_at')), if (place.isNotEmpty) place].join(' · '), style: const TextStyle(color: AppColors.muted, fontSize: 12.5, height: 1.5)),
    ]);
  }
}

/// One of the two codes, as a quiet button under «everyone present».
class _Way extends StatelessWidget {
  const _Way({super.key, required this.icon, required this.label, required this.onTap});

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => OutlinedButton(
        onPressed: onTap,
        style: OutlinedButton.styleFrom(minimumSize: const Size(0, 46), padding: const EdgeInsets.symmetric(horizontal: 8), backgroundColor: Colors.white, foregroundColor: AppColors.navy900, side: BorderSide(color: AppColors.navy100)),
        child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          Icon(icon, size: 18),
          const SizedBox(width: 6),
          Flexible(child: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13))),
        ]),
      );
}

class _Summary extends StatelessWidget {
  const _Summary({required this.expected, required this.present, required this.late, required this.absent});

  final int expected;
  final int present;
  final int late;
  final int absent;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    Widget cell(String label, int n, [Color? tone]) => Expanded(
          child: Column(children: [
            Text('$n', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: tone ?? AppColors.navy900)),
            Text(label, style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          ]),
        );
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Row(children: [cell(s.t('roll.expected'), expected), cell(s.t('roll.present'), present, AppColors.success), cell(s.t('roll.late'), late, AppColors.warning), cell(s.t('roll.absent'), absent, AppColors.danger)]),
      ),
    );
  }
}
