import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// My professional growth: career and licence paths with next steps, licences, the yearly hours and PD activities.
class GrowthScreen extends ConsumerWidget {
  const GrowthScreen({super.key});

  static const _paths = '/me/paths';
  static const _licences = '/me/licences';
  static const _hours = '/me/pd-hours';
  static const _activities = '/me/pd-activities';

  void _refresh(WidgetRef ref) {
    for (final p in [_paths, _licences, _hours, _activities]) {
      ref.invalidate(getProvider(p));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('growth.title'))),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => showModalBottomSheet(context: context, isScrollControlled: true, builder: (_) => const _LogSheet()).then((_) => _refresh(ref)),
        icon: const Icon(Icons.add),
        label: Text(s.t('growth.log')),
      ),
      body: RefreshIndicator(
        onRefresh: () async {
          _refresh(ref);
          await ref.read(getProvider(_hours).future);
        },
        child: ListView(padding: const EdgeInsets.all(20), children: [
          AsyncView(
            value: ref.watch(getProvider(_hours)),
            onRetry: () => ref.invalidate(getProvider(_hours)),
            builder: (raw) {
              final d = Map<String, dynamic>.from((raw as Map)['data'] as Map);
              final target = d['target'];
              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text('${d.number('total')}${target != null ? ' / $target' : ''}', style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w800)),
                    Text('${s.t('growth.hoursYear')} ${d.str('year')}', style: const TextStyle(color: Colors.black54)),
                    if (d['percent'] != null) Padding(padding: const EdgeInsets.only(top: 8), child: ProgressBar(d.number('percent').toDouble(), color: AppColors.gold500)),
                  ]),
                ),
              );
            },
          ),
          SectionTitle(s.t('growth.paths')),
          AsyncView(
            value: ref.watch(getProvider(_paths)),
            onRetry: () => ref.invalidate(getProvider(_paths)),
            builder: (raw) {
              final rows = Map<String, dynamic>.from(raw as Map).list('data');
              if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(16), child: Text(s.t('growth.noPaths')));
              return Column(children: [
                for (final p in rows)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text(p.str(ar ? 'title_ar' : 'title_en'), style: const TextStyle(fontWeight: FontWeight.w800))),
                          StatusChip(p.str('status'), label: s.t('growth.status.${p.str('status')}')),
                        ]),
                        const SizedBox(height: 8),
                        for (final c in p.list('explanation'))
                          Row(children: [
                            Icon(c.flag('met') ? Icons.check_circle : Icons.radio_button_unchecked, size: 18, color: c.flag('met') ? Colors.green : Colors.black38),
                            const SizedBox(width: 8),
                            Expanded(child: Text(c.str('label'))),
                          ]),
                      ]),
                    ),
                  ),
              ]);
            },
          ),
          SectionTitle(s.t('growth.licences')),
          AsyncView(
            value: ref.watch(getProvider(_licences)),
            onRetry: () => ref.invalidate(getProvider(_licences)),
            builder: (raw) {
              final rows = Map<String, dynamic>.from(raw as Map).list('data');
              if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(16), child: Text(s.t('growth.noLicences')));
              return Column(children: [
                for (final l in rows)
                  Card(child: ListTile(title: Text('${s.t('growth.level')} ${l.str('level_no')}'), subtitle: Text('${l.str('licence_no')}\n${s.t('growth.expires')}: ${l.str('expires_at')}'), isThreeLine: true, trailing: StatusChip(l.str('status'), label: s.t('growth.licStatus.${l.str('status')}')))),
              ]);
            },
          ),
          SectionTitle(s.t('growth.activities')),
          AsyncView(
            value: ref.watch(getProvider(_activities)),
            onRetry: () => ref.invalidate(getProvider(_activities)),
            builder: (raw) {
              final rows = Map<String, dynamic>.from(raw as Map).list('data');
              if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(16), child: Text(s.t('growth.noActivities')));
              return Column(children: [
                for (final a in rows) _Activity(a),
              ]);
            },
          ),
          const SizedBox(height: 80),
        ]),
      ),
    );
  }
}

class _Activity extends ConsumerWidget {
  const _Activity(this.a);

  final Json a;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final editable = ['draft', 'returned'].contains(a.str('status'));
    return Card(
      child: ListTile(
        title: Text(a.str('title'), style: const TextStyle(fontWeight: FontWeight.w700)),
        subtitle: Text('${a.number('computed_hours')} h${a.str('manager_note').isNotEmpty ? '\n${a.str('manager_note')}' : ''}'),
        trailing: editable
            ? FilledButton(
                onPressed: () async {
                  try {
                    await ref.read(apiProvider).post('/me/pd-activities/${a.str('id')}/submit');
                    ref.invalidate(getProvider(GrowthScreen._activities));
                    if (context.mounted) showSnack(context, s.t('growth.submitted'));
                  } catch (e) {
                    if (context.mounted) showSnack(context, e.toString(), error: true);
                  }
                },
                child: Text(s.t('growth.submit')),
              )
            : StatusChip(a.str('status'), label: s.t('growth.pdStatus.${a.str('status')}')),
      ),
    );
  }
}

/// Logs an external activity; the evidence is added as a link here (files and camera photos are attached on the web for now).
class _LogSheet extends ConsumerStatefulWidget {
  const _LogSheet();

  @override
  ConsumerState<_LogSheet> createState() => _LogSheetState();
}

class _LogSheetState extends ConsumerState<_LogSheet> {
  final _title = TextEditingController();
  final _hours = TextEditingController(text: '4');
  final _link = TextEditingController();
  String _level = 'attendee';
  String? _type;
  bool _busy = false;

  Future<void> _save(List<Json> types) async {
    final type = _type ?? (types.isNotEmpty ? types.first.str('id') : null);
    if (type == null || _title.text.trim().isEmpty) return;
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/me/pd-activities', {
        'type_id': type,
        'title': _title.text.trim(),
        'starts_on': DateTime.now().toIso8601String().substring(0, 10),
        'duration_hours': double.tryParse(_hours.text) ?? 1,
        'participation_level': _level,
        if (_link.text.trim().startsWith('http')) 'evidence': [_link.text.trim()],
      });
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final raw = ref.watch(getProvider('/me/pd-activity-types')).value;
    final types = raw is Map ? Map<String, dynamic>.from(raw).list('data') : <Json>[];
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.of(context).viewInsets.bottom + 20),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(s.t('growth.log'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            initialValue: _type ?? (types.isNotEmpty ? types.first.str('id') : null),
            items: [for (final t in types) DropdownMenuItem(value: t.str('id'), child: Text(t.str(ar ? 'name_ar' : 'name_en')))],
            onChanged: (v) => setState(() => _type = v),
          ),
          TextField(controller: _title, decoration: InputDecoration(labelText: s.t('growth.activityTitle'))),
          TextField(controller: _hours, keyboardType: TextInputType.number, decoration: InputDecoration(labelText: s.t('growth.hours'))),
          DropdownButtonFormField<String>(
            initialValue: _level,
            items: [for (final l in ['attendee', 'presenter', 'organiser', 'author']) DropdownMenuItem(value: l, child: Text(s.t('growth.level.$l')))],
            onChanged: (v) => setState(() => _level = v ?? 'attendee'),
          ),
          TextField(controller: _link, textDirection: TextDirection.ltr, decoration: InputDecoration(labelText: s.t('growth.evidenceLink'))),
          const SizedBox(height: 16),
          FilledButton(onPressed: _busy ? null : () => _save(types), child: Text(s.t('growth.save'))),
        ]),
      ),
    );
  }
}
