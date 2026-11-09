import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The trainer's roll call for one session: every approved trainee with the four marks (present, late, absent,
/// excused). A tap records the mark at once; «everyone present» is for the usual day. The centre's rule that a trainer
/// can enter marks by hand only for a short while after the session starts is the server's, and its message is shown.
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

  String get _path => '/admin/sessions/${widget.sessionId}/attendance';

  Future<void> _mark(String registrationId, String status) async {
    if (_busy.contains(registrationId)) return;
    setState(() => _busy.add(registrationId));
    try {
      await ref.read(apiProvider).post(_path, {'registration_id': registrationId, 'status': status});
      ref.invalidate(getProvider(_path));
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
      ref.invalidate(getProvider(_path));
      if (mounted) showSnack(context, context.s.t('roll.allDone').replaceFirst('{n}', '$done'));
    } catch (e) {
      ref.invalidate(getProvider(_path));
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _all = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final value = ref.watch(getProvider(_path));

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('roll.title'))),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(_path)),
        builder: (raw) {
          final data = Map<String, dynamic>.from(raw as Map).obj('data') ?? <String, dynamic>{};
          final rows = data.list('rows');
          if (rows.isEmpty) return Center(child: Text(s.t('roll.empty')));
          return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 32), children: [
            _Summary(expected: data.number('expected').toInt(), present: data.number('present').toInt(), late: data.number('late').toInt(), absent: data.number('absent').toInt()),
            const SizedBox(height: 12),
            Row(children: [
              Expanded(
                child: FilledButton.icon(
                  onPressed: _all ? null : () => _everyonePresent(rows),
                  style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950, minimumSize: const Size(0, 48)),
                  icon: const Icon(Icons.done_all),
                  label: Text(s.t('roll.everyone')),
                ),
              ),
              const SizedBox(width: 8),
              OutlinedButton.icon(
                onPressed: () => context.push('/scan?trainer=1'),
                style: OutlinedButton.styleFrom(minimumSize: const Size(0, 48)),
                icon: const Icon(Icons.qr_code_scanner),
                label: Text(s.t('roll.myCheckIn')),
              ),
            ]),
            const SizedBox(height: 12),
            for (final r in rows)
              Card(
                margin: const EdgeInsets.only(bottom: 10),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(r.str('name'), style: const TextStyle(fontWeight: FontWeight.w800)),
                    if (r.str('school').isNotEmpty) Text(r.str('school'), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
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
    );
  }
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
    Widget cell(String label, int n) => Expanded(
          child: Column(children: [
            Text('$n', style: TextStyle(fontSize: 24, fontWeight: FontWeight.w800, color: AppColors.navy900)),
            Text(label, style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          ]),
        );
    return Card(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 14),
        child: Row(children: [cell(s.t('roll.expected'), expected), cell(s.t('roll.present'), present), cell(s.t('roll.late'), late), cell(s.t('roll.absent'), absent)]),
      ),
    );
  }
}
