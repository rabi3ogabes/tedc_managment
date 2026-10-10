import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// «Programs for me»: only what the person can take, in three tabs — for their role, general, and the smart picks.
class ProgramsScreen extends ConsumerStatefulWidget {
  const ProgramsScreen({super.key});

  static const path = '/me/programs-for-me';

  @override
  ConsumerState<ProgramsScreen> createState() => _ProgramsScreenState();
}

class _ProgramsScreenState extends ConsumerState<ProgramsScreen> {
  String _query = '';
  String? _mode;

  static const _tabs = ['mine', 'general', 'recommended'];

  bool _matches(Json program) {
    if (_mode != null && program.str('delivery_mode') != _mode) return false;
    if (_query.isEmpty) return true;
    final q = _query.toLowerCase();
    return program.str('title').toLowerCase().contains(q) || program.str('code').toLowerCase().contains(q);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final data = ref.watch(getProvider(ProgramsScreen.path));

    return DefaultTabController(
      length: _tabs.length,
      child: Scaffold(
        appBar: AppBar(
          title: Text(s.t('programs.title')),
          bottom: TabBar(
            tabs: [for (final t in _tabs) Tab(text: s.t('forme.$t'))],
          ),
        ),
        body: AsyncView(
          value: data,
          onRetry: () => ref.invalidate(getProvider(ProgramsScreen.path)),
          builder: (raw) {
            final d = Map<String, dynamic>.from(raw as Map).obj('data') ?? const {};
            final position = d.str('position');
            return Column(children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
                child: TextField(
                  decoration: InputDecoration(hintText: s.t('common.search'), prefixIcon: const Icon(Icons.search)),
                  onChanged: (v) => setState(() => _query = v.trim()),
                ),
              ),
              SizedBox(
                height: 42,
                child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
                  for (final m in ['in_person', 'online', 'hybrid'])
                    Padding(
                      padding: const EdgeInsetsDirectional.only(end: 8),
                      child: FilterChip(label: Text(s.t('mode.$m')), selected: _mode == m, onSelected: (v) => setState(() => _mode = v ? m : null), selectedColor: AppColors.gold100),
                    ),
                ]),
              ),
              Expanded(
                child: TabBarView(children: [
                  _ProgramList(
                    rows: d.list('mine').where(_matches).toList(),
                    hint: position.isEmpty ? s.t('forme.mineHint') : '${s.t('forme.mineHint')} · $position',
                    empty: s.t('forme.emptyMine'),
                    onRefresh: () => ref.refresh(getProvider(ProgramsScreen.path).future),
                  ),
                  _ProgramList(
                    rows: d.list('general').where(_matches).toList(),
                    hint: s.t('forme.generalHint'),
                    empty: s.t('forme.emptyGeneral'),
                    onRefresh: () => ref.refresh(getProvider(ProgramsScreen.path).future),
                  ),
                  _ProgramList(
                    rows: d.list('recommended').map((r) => (r.obj('program') ?? const <String, dynamic>{}) + {'score': r['score']}).where(_matches).toList(),
                    hint: s.t('forme.recommendedHint'),
                    empty: s.t('forme.emptyRecommended'),
                    onRefresh: () => ref.refresh(getProvider(ProgramsScreen.path).future),
                  ),
                ]),
              ),
            ]);
          },
        ),
      ),
    );
  }
}

extension on Map<String, dynamic> {
  Map<String, dynamic> operator +(Map<String, dynamic> other) => {...this, ...other};
}

class _ProgramList extends StatelessWidget {
  const _ProgramList({required this.rows, required this.hint, required this.empty, required this.onRefresh});

  final List<Json> rows;
  final String hint;
  final String empty;
  final Future<dynamic> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return RefreshIndicator(
      color: AppColors.gold500,
      onRefresh: onRefresh,
      child: ListView(padding: const EdgeInsets.all(16), children: [
        Text('$hint\n${s.t('forme.onlyYours')}', style: const TextStyle(color: AppColors.muted, fontSize: 12.5, height: 1.5)),
        const SizedBox(height: 12),
        if (rows.isEmpty) EmptyView(text: empty),
        for (final p in rows) Padding(padding: const EdgeInsets.only(bottom: 14), child: ProgramCard(program: p)),
      ]),
    );
  }
}

class ProgramCard extends StatelessWidget {
  const ProgramCard({super.key, required this.program});

  final Json program;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final category = program.obj('category');
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => context.push('/programs/${program.str('code')}'),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          ProgramCover(code: program.str('code'), category: category?.str('name'), categorySlug: category?.str('slug')),
          Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                StatusChip(program.str('status')),
                if (program.str('my_registration').isNotEmpty) ...[const SizedBox(width: 8), StatusChip(program.str('my_registration'), label: s.t('forme.registered'))],
              ]),
              const SizedBox(height: 8),
              Text(program.str('title'), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy900)),
              if (program.str('summary').isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(program.str('summary'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.muted, fontSize: 13)),
              ],
              const SizedBox(height: 12),
              Wrap(spacing: 14, runSpacing: 6, children: [
                _Meta(Icons.event, fmt.date(program.date('start_date'))),
                _Meta(Icons.schedule, '${fmt.number(program.number('total_hours'))} ${s.t('common.hours')}'),
                _Meta(Icons.place_outlined, s.t('mode.${program.str('delivery_mode')}')),
                if (program['seats_available'] != null) _Meta(Icons.event_seat_outlined, '${fmt.number(program.number('seats_available'))} ${s.t('common.seats')}'),
              ]),
            ]),
          ),
        ]),
      ),
    );
  }
}

class _Meta extends StatelessWidget {
  const _Meta(this.icon, this.text);

  final IconData icon;
  final String text;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 15, color: AppColors.gold700),
        const SizedBox(width: 4),
        Text(text, style: const TextStyle(fontSize: 12, color: AppColors.muted)),
      ]);
}
