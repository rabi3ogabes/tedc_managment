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
import '../workspace/parts.dart';
import '../workspace/workspace.dart';

/// The attendance sheet of one session, for the people who run it (its trainer, the supervisor of its group): every
/// approved trainee with the four marks (present, late, absent, excused). A tap records the mark at once; «everyone
/// present» is for the usual day. Somebody who came can be recorded as having **left early**, at the time it happened and
/// with the reason; anybody can be given a **comment**; and each person who runs the session writes their own **remark**
/// about it, read by the others. A long sheet is searched by name and narrowed to one mark. For a session of today the
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

  String get _path => Workspace.attendancePath(widget.sessionId);

  /// What other screens show from this sheet (the numbers of a session) follows a change made here.
  void _changed() {
    ref.invalidate(getProvider(_path));
    ref.invalidate(getProvider(Duties.path));
  }

  /// One change to one trainee: the row waits, the server's answer (or its refusal, in its own words) is shown.
  Future<bool> _send(String registrationId, Future<dynamic> Function(ApiClient api) call) async {
    if (_busy.contains(registrationId)) return false;
    setState(() => _busy.add(registrationId));
    try {
      await call(ref.read(apiProvider));
      _changed();
      return true;
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
      return false;
    } finally {
      if (mounted) setState(() => _busy.remove(registrationId));
    }
  }

  Future<void> _mark(String registrationId, String status) => _send(registrationId, (api) => api.post(_path, {'registration_id': registrationId, 'status': status}));

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

  /// «خروج مبكر»: when the trainee left and, if they said, why.
  Future<void> _leaveEarly(Json row, DateTime starts, DateTime ends) async {
    final s = context.s;
    final result = await showModalBottomSheet<({DateTime at, String reason})>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      backgroundColor: Colors.white,
      builder: (_) => EarlyLeaveSheet(name: row.str('name'), starts: starts, ends: ends),
    );
    if (result == null || !mounted) return;
    final id = row.str('registration_id');
    final ok = await _send(id, (api) => api.post(Workspace.earlyLeavePath(widget.sessionId), {'registration_id': id, 'at': result.at.toUtc().toIso8601String(), if (result.reason.isNotEmpty) 'reason': result.reason}));
    if (ok && mounted) showSnack(context, s.t('roll.early.done').replaceFirst('{name}', row.str('name')));
  }

  Future<void> _stayed(Json row) => _send(row.str('registration_id'), (api) => api.delete('${Workspace.earlyLeavePath(widget.sessionId)}/${row.str('registration_id')}'));

  /// A comment about one trainee in this session.
  Future<void> _comment(Json row) async {
    final s = context.s;
    final text = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      backgroundColor: Colors.white,
      builder: (_) => TextSheet(title: s.t('roll.note.title'), subtitle: row.str('name'), hint: s.t('roll.note.hint'), initial: row.str('note'), max: 1000),
    );
    if (text == null || !mounted) return;
    final id = row.str('registration_id');
    await _send(id, (api) => api.put(Workspace.notePath(widget.sessionId, id), {'note': text}));
  }

  /// The person's own remark about the session.
  Future<void> _remark(String mine) async {
    final s = context.s;
    final text = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      backgroundColor: Colors.white,
      builder: (_) => TextSheet(title: s.t('roll.remark.title'), hint: s.t('roll.remark.hint'), initial: mine, max: 2000),
    );
    if (text == null || !mounted) return;
    await _send('remark', (api) => api.put(Workspace.remarkPath(widget.sessionId), {'body': text}));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final value = ref.watch(getProvider(_path));
    final me = ref.watch(authProvider).value;
    // The session itself, when it is one of the person's own groups: its name heads the sheet, and its times bound an early leave.
    final slot = Workspace.slot(Duties.groups(ref.watch(getProvider(Duties.path)).value), widget.sessionId);
    // The two codes are for a session of today; a sheet opened another way (no group known) keeps them.
    final codes = slot == null || Duties.takesAttendance(slot.session);
    final starts = slot?.session.date('starts_at');
    final ends = slot?.session.date('ends_at');

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
            final early = Workspace.marked(rows, 'early').length;
            final filters = ['all', for (final m in _marks) if (rows.any((r) => r.str('status') == m)) m, if (early > 0) 'early'];
            final filter = filters.contains(_filter) ? _filter : 'all';
            final shown = Workspace.search(Workspace.marked(rows, filter), _query);
            final head = slot == null ? null : _SessionHead(slot: slot);
            final remarks = SessionRemarks(remarks: data.list('remarks'), busy: _busy.contains('remark'), onWrite: _remark);
            if (rows.isEmpty) {
              return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
                ?head,
                const SizedBox(height: 40),
                Center(child: Text(s.t('roll.empty'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted))),
                const SizedBox(height: 28),
                remarks,
              ]);
            }
            return ListView(padding: const EdgeInsets.fromLTRB(16, 12, 16, 32), children: [
              if (head != null) ...[head, const SizedBox(height: 12)],
              _Summary(expected: data.number('expected').toInt(), present: data.number('present').toInt(), late: data.number('late').toInt(), absent: data.number('absent').toInt(), early: early),
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
                  Expanded(child: _Way(key: const ValueKey('roll-code'), icon: Icons.qr_code_2, label: s.t('work.act.code'), onTap: () => context.push('/session-qr/${widget.sessionId}?from=sheet'))),
                  const SizedBox(width: 8),
                  Expanded(child: _Way(key: const ValueKey('roll-scan'), icon: Icons.qr_code_scanner, label: s.t('work.act.scan'), onTap: () => context.push('/staff-scan/${widget.sessionId}?from=sheet'))),
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
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                padding: const EdgeInsets.only(bottom: 10),
                child: Row(children: [
                  for (final f in filters)
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 8),
                      child: ChoiceChip(
                        key: ValueKey('roll-filter-$f'),
                        label: Text('${s.t(f == 'all' ? 'duties.phase.all' : (f == 'early' ? 'roll.early' : 'roll.$f'))} (${Workspace.marked(rows, f).length})'),
                        selected: filter == f,
                        onSelected: (_) => setState(() => _filter = f),
                        selectedColor: AppColors.navy900,
                        labelStyle: TextStyle(color: filter == f ? Colors.white : AppColors.navy900, fontWeight: FontWeight.w700),
                        showCheckmark: false,
                      ),
                    ),
                ]),
              ),
              if (shown.isEmpty) Padding(padding: const EdgeInsets.symmetric(vertical: 28), child: Center(child: Text(s.t('work.prog.noMatch'), style: const TextStyle(color: AppColors.muted)))),
              for (final r in shown)
                Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: _TraineeCard(
                    row: r,
                    fmt: fmt,
                    locked: _busy.contains(r.str('registration_id')) || _all,
                    onMark: (m) => _mark(r.str('registration_id'), m),
                    // Without the session's own times (a sheet of somebody else's group) the phone cannot bound the leave: the day and the server do.
                    onLeaveEarly: () => _leaveEarly(r, starts ?? DateTime.now().subtract(const Duration(hours: 12)), ends ?? DateTime.now().add(const Duration(hours: 12))),
                    onStayed: () => _stayed(r),
                    onComment: () => _comment(r),
                  ),
                ),
              const SizedBox(height: 14),
              remarks,
            ]);
          },
        ),
      ),
    );
  }
}

/// One trainee on the sheet: who, when they came, their mark, whether they left early, and the comment about them.
class _TraineeCard extends StatelessWidget {
  const _TraineeCard({required this.row, required this.fmt, required this.locked, required this.onMark, required this.onLeaveEarly, required this.onStayed, required this.onComment});

  static const _marks = ['present', 'late', 'absent', 'excused'];

  final Json row;
  final Fmt fmt;
  final bool locked;
  final ValueChanged<String> onMark;
  final VoidCallback onLeaveEarly;
  final VoidCallback onStayed;
  final VoidCallback onComment;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final id = row.str('registration_id');
    final left = row.date('left_early_at');
    final note = row.str('note');
    final quiet = TextButton.styleFrom(foregroundColor: AppColors.navy900, visualDensity: VisualDensity.compact, padding: const EdgeInsets.symmetric(horizontal: 8));
    const words = TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 14, 14, 8),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(row.str('name'), style: const TextStyle(fontWeight: FontWeight.w800)),
                if (row.str('school').isNotEmpty) Text(row.str('school'), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
              ]),
            ),
            if (row.date('check_in_at') != null && Workspace.came(row))
              Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(Icons.login, size: 14, color: AppColors.gold700),
                const SizedBox(width: 4),
                Text(fmt.time(row.date('check_in_at')), style: TextStyle(color: AppColors.gold700, fontSize: 12, fontWeight: FontWeight.w700)),
              ]),
          ]),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 6, children: [
            for (final m in _marks)
              ChoiceChip(
                key: ValueKey('mark-$id-$m'),
                label: Text(s.t('roll.$m')),
                selected: row.str('status') == m,
                showCheckmark: false,
                selectedColor: AppColors.gold300,
                onSelected: locked ? null : (_) => onMark(m),
              ),
          ]),
          if (left != null)
            Container(
              key: ValueKey('left-$id'),
              margin: const EdgeInsets.only(top: 10),
              padding: const EdgeInsetsDirectional.fromSTEB(12, 4, 4, 4),
              decoration: BoxDecoration(color: const Color(0xFFFDF3E1), borderRadius: BorderRadius.circular(12)),
              child: Row(children: [
                const Icon(Icons.logout, size: 16, color: AppColors.warning),
                const SizedBox(width: 8),
                Expanded(child: Text('${s.t('roll.early.left')} ${fmt.time(left)}', maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.warning, fontWeight: FontWeight.w800, fontSize: 12.5))),
                TextButton(key: ValueKey('stayed-$id'), onPressed: locked ? null : onStayed, style: quiet, child: Text(s.t('roll.early.undo'), style: words)),
              ]),
            ),
          if (note.isNotEmpty)
            InkWell(
              onTap: locked ? null : onComment,
              child: Padding(
                padding: const EdgeInsets.only(top: 10),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Padding(padding: const EdgeInsets.only(top: 2), child: Icon(Icons.sticky_note_2_outlined, size: 15, color: AppColors.gold700)),
                  const SizedBox(width: 7),
                  Expanded(child: Text(note, key: ValueKey('note-$id'), style: TextStyle(color: AppColors.navy900, fontSize: 12.5, height: 1.5))),
                ]),
              ),
            ),
          const SizedBox(height: 4),
          Row(children: [
            if (Workspace.came(row) && left == null)
              TextButton.icon(key: ValueKey('early-$id'), onPressed: locked ? null : onLeaveEarly, style: quiet, icon: const Icon(Icons.logout, size: 16), label: Text(s.t('roll.early'), style: words)),
            TextButton.icon(key: ValueKey('comment-$id'), onPressed: locked ? null : onComment, style: quiet, icon: const Icon(Icons.add_comment_outlined, size: 16), label: Text(s.t(note.isEmpty ? 'roll.note.add' : 'roll.note.edit'), style: words)),
          ]),
        ]),
      ),
    );
  }
}

/// «ملاحظات الجلسة»: what each person who runs the session wrote about it, and the way to write (or rewrite) one's own.
class SessionRemarks extends StatelessWidget {
  const SessionRemarks({super.key, required this.remarks, required this.onWrite, this.busy = false});

  final List<Json> remarks;
  final bool busy;

  /// Opens the editor with the person's own remark (empty when they have none).
  final ValueChanged<String> onWrite;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final mine = remarks.where((r) => r.flag('mine')).map((r) => r.str('body')).firstOrNull ?? '';
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Heading(s.t('roll.remark.heading'), count: remarks.isEmpty ? null : remarks.length),
      Surface(
        padding: EdgeInsets.zero,
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (remarks.isEmpty) Padding(padding: const EdgeInsets.fromLTRB(14, 14, 14, 4), child: Text(s.t('roll.remark.none'), style: const TextStyle(color: AppColors.muted, fontSize: 12.5, height: 1.5))),
          for (final (i, r) in remarks.indexed) ...[
            if (i > 0) Divider(height: 1, color: AppColors.navy100),
            Padding(
              padding: const EdgeInsets.fromLTRB(14, 12, 14, 10),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(child: Text(r.flag('mine') ? s.t('roll.remark.mine') : r.str('by'), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w800, fontSize: 12))),
                  Text(fmt.dateTime(r.date('at')), style: const TextStyle(color: AppColors.muted, fontSize: 11)),
                ]),
                const SizedBox(height: 4),
                Text(r.str('body'), style: TextStyle(color: AppColors.navy900, height: 1.6, fontSize: 13.5)),
              ]),
            ),
          ],
          Padding(
            padding: const EdgeInsets.fromLTRB(8, 0, 8, 6),
            child: Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                key: const ValueKey('remark-write'),
                onPressed: busy ? null : () => onWrite(mine),
                icon: Icon(mine.isEmpty ? Icons.rate_review_outlined : Icons.edit_outlined, size: 17),
                label: Text(s.t(mine.isEmpty ? 'roll.remark.add' : 'roll.remark.edit'), style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ),
          ),
        ]),
      ),
    ]);
  }
}

/// «خروج مبكر»: the time the trainee left (now while the session runs; chosen for a session already held) and the reason.
class EarlyLeaveSheet extends StatefulWidget {
  const EarlyLeaveSheet({super.key, required this.name, required this.starts, required this.ends});

  final String name;
  final DateTime starts;
  final DateTime ends;

  @override
  State<EarlyLeaveSheet> createState() => _EarlyLeaveSheetState();
}

class _EarlyLeaveSheetState extends State<EarlyLeaveSheet> {
  late DateTime _at = Workspace.leaveDefault(widget.starts, widget.ends, DateTime.now());
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  Future<void> _pick() async {
    final picked = await showTimePicker(context: context, initialTime: TimeOfDay.fromDateTime(_at));
    if (picked != null && mounted) setState(() => _at = Workspace.onDayOf(_at, picked.hour, picked.minute));
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final valid = Workspace.leaveValid(_at, widget.starts, widget.ends);
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, 16 + MediaQuery.viewInsetsOf(context).bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(s.t('roll.early'), textAlign: TextAlign.center, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900)),
          Text(widget.name, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, fontSize: 13)),
          const SizedBox(height: 16),
          Material(
            color: AppColors.ivory,
            borderRadius: BorderRadius.circular(16),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              key: const ValueKey('early-time'),
              onTap: _pick,
              child: Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 14),
                child: Row(children: [
                  Icon(Icons.schedule, color: AppColors.gold700),
                  const SizedBox(width: 12),
                  Expanded(child: Text(s.t('roll.early.at'), style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w700))),
                  Text(fmt.time(_at), style: TextStyle(color: AppColors.navy900, fontSize: 18, fontWeight: FontWeight.w800)),
                  const SizedBox(width: 6),
                  Icon(Icons.edit_outlined, size: 16, color: AppColors.gold700),
                ]),
              ),
            ),
          ),
          if (!valid) Padding(padding: const EdgeInsets.only(top: 6), child: Text(s.t('roll.early.badTime'), style: const TextStyle(color: AppColors.danger, fontSize: 12.5, fontWeight: FontWeight.w700))),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('early-reason'),
            controller: _reason,
            maxLength: 500,
            minLines: 1,
            maxLines: 3,
            textInputAction: TextInputAction.done,
            decoration: InputDecoration(hintText: s.t('roll.early.reason'), counterText: '', filled: true, fillColor: AppColors.ivory, border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none)),
          ),
          const SizedBox(height: 14),
          FilledButton.icon(
            key: const ValueKey('early-save'),
            style: FilledButton.styleFrom(backgroundColor: AppColors.navy900, foregroundColor: Colors.white, minimumSize: const Size(0, 48)),
            onPressed: valid ? () => Navigator.pop(context, (at: _at, reason: _reason.text.trim())) : null,
            icon: const Icon(Icons.logout, size: 18),
            label: Text(s.t('roll.early.save'), style: const TextStyle(fontWeight: FontWeight.w800)),
          ),
        ]),
      ),
    );
  }
}

/// A sheet to write a short text in (a comment about a trainee, a remark about a session). It answers the text to keep;
/// an empty one removes what was there.
class TextSheet extends StatefulWidget {
  const TextSheet({super.key, required this.title, required this.hint, this.subtitle, this.initial = '', this.max = 1000});

  final String title;
  final String? subtitle;
  final String hint;
  final String initial;
  final int max;

  @override
  State<TextSheet> createState() => _TextSheetState();
}

class _TextSheetState extends State<TextSheet> {
  late final _text = TextEditingController(text: widget.initial);

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(20, 0, 20, 16 + MediaQuery.viewInsetsOf(context).bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(widget.title, textAlign: TextAlign.center, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900)),
          if (widget.subtitle != null) Text(widget.subtitle!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, fontSize: 13)),
          const SizedBox(height: 14),
          TextField(
            key: const ValueKey('text-sheet-field'),
            controller: _text,
            autofocus: true,
            maxLength: widget.max,
            minLines: 3,
            maxLines: 6,
            decoration: InputDecoration(hintText: widget.hint, filled: true, fillColor: AppColors.ivory, border: OutlineInputBorder(borderRadius: BorderRadius.circular(14), borderSide: BorderSide.none)),
          ),
          const SizedBox(height: 8),
          Row(children: [
            if (widget.initial.isNotEmpty) ...[
              OutlinedButton(key: const ValueKey('text-sheet-remove'), onPressed: () => Navigator.pop(context, ''), style: OutlinedButton.styleFrom(foregroundColor: AppColors.danger, minimumSize: const Size(0, 48)), child: Text(s.t('common.delete'))),
              const SizedBox(width: 8),
            ],
            Expanded(
              child: FilledButton(
                key: const ValueKey('text-sheet-save'),
                style: FilledButton.styleFrom(backgroundColor: AppColors.navy900, foregroundColor: Colors.white, minimumSize: const Size(0, 48)),
                onPressed: () => Navigator.pop(context, _text.text.trim()),
                child: Text(s.t('common.save'), style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ),
          ]),
        ]),
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
  const _Summary({required this.expected, required this.present, required this.late, required this.absent, this.early = 0});

  final int expected;
  final int present;
  final int late;
  final int absent;
  final int early;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    Widget cell(String label, int n, [Color? tone]) => Expanded(
          child: Column(children: [
            Text('$n', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: tone ?? AppColors.navy900)),
            Text(label, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          ]),
        );
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Row(children: [
          cell(s.t('roll.expected'), expected),
          cell(s.t('roll.present'), present, AppColors.success),
          cell(s.t('roll.late'), late, AppColors.warning),
          cell(s.t('roll.absent'), absent, AppColors.danger),
          if (early > 0) cell(s.t('roll.early.short'), early, AppColors.warning),
        ]),
      ),
    );
  }
}
