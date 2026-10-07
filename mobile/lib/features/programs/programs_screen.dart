import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

class ProgramsScreen extends ConsumerStatefulWidget {
  const ProgramsScreen({super.key});

  @override
  ConsumerState<ProgramsScreen> createState() => _ProgramsScreenState();
}

class _ProgramsScreenState extends ConsumerState<ProgramsScreen> {
  String _query = '';
  String? _mode;
  bool _openOnly = true;

  String get _path {
    final params = <String>['per_page=50', if (_openOnly) 'open=1', if (_mode != null) 'mode=$_mode', if (_query.isNotEmpty) 'q=${Uri.encodeQueryComponent(_query)}'];
    return '/public/programs?${params.join('&')}';
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final programs = ref.watch(getProvider(_path));

    return Scaffold(
      appBar: AppBar(title: Text(s.t('programs.title'))),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 8),
          child: TextField(
            decoration: InputDecoration(hintText: s.t('common.search'), prefixIcon: const Icon(Icons.search)),
            onSubmitted: (v) => setState(() => _query = v.trim()),
          ),
        ),
        SizedBox(
          height: 42,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 16), children: [
            FilterChip(label: Text(s.status('registration_open')), selected: _openOnly, onSelected: (v) => setState(() => _openOnly = v), selectedColor: AppColors.gold100),
            for (final m in ['in_person', 'online', 'hybrid'])
              Padding(
                padding: const EdgeInsetsDirectional.only(start: 8),
                child: FilterChip(label: Text(s.t('mode.$m')), selected: _mode == m, onSelected: (v) => setState(() => _mode = v ? m : null), selectedColor: AppColors.gold100),
              ),
          ]),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: () => ref.refresh(getProvider(_path).future),
            child: AsyncView(
              value: programs,
              onRetry: () => ref.invalidate(getProvider(_path)),
              builder: (raw) {
                final list = Map<String, dynamic>.from(raw as Map).list('data');
                if (list.isEmpty) return ListView(children: const [EmptyView()]);
                return ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: list.length,
                  separatorBuilder: (_, _) => const SizedBox(height: 14),
                  itemBuilder: (_, i) => ProgramCard(program: list[i]),
                );
              },
            ),
          ),
        ),
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
              StatusChip(program.str('status')),
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
