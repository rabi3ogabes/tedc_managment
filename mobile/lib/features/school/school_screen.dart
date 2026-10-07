import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// School administrator tools: nominate own staff and submit training needs.
class SchoolScreen extends StatelessWidget {
  const SchoolScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          title: Text(s.t('school.title')),
          bottom: TabBar(
            indicatorColor: AppColors.gold500,
            labelColor: AppColors.navy900,
            tabs: [Tab(text: s.t('school.employees')), Tab(text: s.t('school.needs'))],
          ),
        ),
        body: const TabBarView(children: [_Employees(), _Needs()]),
      ),
    );
  }
}

class _Employees extends ConsumerWidget {
  const _Employees();

  static const path = '/admin/employees?per_page=100';

  Future<void> _nominate(BuildContext context, WidgetRef ref, Json employee) async {
    final s = context.s;
    final programs = Map<String, dynamic>.from(await ref.read(apiProvider).get('/public/programs?open=1&per_page=50') as Map).list('data');
    if (!context.mounted) return;
    final program = await showModalBottomSheet<Json>(
      context: context,
      builder: (_) => ListView(children: [
        ListTile(title: Text(s.t('school.selectProgram'), style: const TextStyle(fontWeight: FontWeight.w800))),
        for (final p in programs) ListTile(title: Text(p.str('title')), subtitle: Text(p.str('code')), onTap: () => Navigator.pop(context, p)),
      ]),
    );
    if (program == null || !context.mounted) return;
    try {
      final res = await ref.read(apiProvider).post('/admin/programs/${program.str('id')}/nominations', {'employee_ids': [employee.str('id')]});
      final result = Map<String, dynamic>.from((res['data'] as List).first as Map);
      if (!context.mounted) return;
      if (result['ok'] == true) {
        showSnack(context, s.status(result.str('status')));
      } else if (result.obj('details')?['checks'] != null) {
        showModalBottomSheet(context: context, builder: (_) => Padding(padding: const EdgeInsets.all(20), child: EligibilityPanel(result.obj('details')!)));
      } else {
        showSnack(context, result.str('message'), error: true);
      }
    } catch (e) {
      if (context.mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    return AsyncView(
      value: ref.watch(getProvider(path)),
      onRetry: () => ref.invalidate(getProvider(path)),
      builder: (raw) {
        final items = Map<String, dynamic>.from(raw as Map).list('data');
        if (items.isEmpty) return const EmptyView();
        return ListView.separated(
          itemCount: items.length,
          separatorBuilder: (_, _) => Divider(height: 1, color: AppColors.navy100),
          itemBuilder: (_, i) {
            final e = items[i];
            return ListTile(
              leading: CircleAvatar(backgroundColor: AppColors.navy900, child: Text(e.str('name').characters.first, style: TextStyle(color: AppColors.gold300))),
              title: Text(e.str('name'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text('${e.obj('job_title')?.str('name') ?? ''} · ${e.str('employee_no')}'),
              trailing: TextButton(onPressed: () => _nominate(context, ref, e), child: Text(s.t('school.nominate'))),
            );
          },
        );
      },
    );
  }
}

class _Needs extends ConsumerWidget {
  const _Needs();

  static const path = '/admin/training-needs?per_page=50';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    return Scaffold(
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: AppColors.gold500,
        foregroundColor: AppColors.navy950,
        onPressed: () => showModalBottomSheet(context: context, isScrollControlled: true, builder: (_) => const _NeedForm()),
        icon: const Icon(Icons.add),
        label: Text(s.t('school.submitNeed')),
      ),
      body: AsyncView(
        value: ref.watch(getProvider(path)),
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final items = Map<String, dynamic>.from(raw as Map).list('data');
          if (items.isEmpty) return const EmptyView();
          return ListView.builder(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
            itemCount: items.length,
            itemBuilder: (_, i) {
              final n = items[i];
              return Card(
                margin: const EdgeInsets.only(bottom: 10),
                child: ListTile(
                  title: Text(n.str('skill_name'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('${n.number('employees_count')} · ${s.t('priority.${n.str('priority')}')}\n${n.str('reason')}', maxLines: 3),
                  isThreeLine: true,
                  trailing: StatusChip(n.str('status')),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

class _NeedForm extends ConsumerStatefulWidget {
  const _NeedForm();

  @override
  ConsumerState<_NeedForm> createState() => _NeedFormState();
}

class _NeedFormState extends ConsumerState<_NeedForm> {
  final _skill = TextEditingController();
  final _reason = TextEditingController();
  int _count = 5;
  String _priority = 'high';

  Future<void> _submit() async {
    try {
      await ref.read(apiProvider).post('/admin/training-needs', {
        'skill_name': _skill.text,
        'employees_count': _count,
        'priority': _priority,
        'reason': _reason.text,
      });
      ref.invalidate(getProvider(_Needs.path));
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Padding(
      padding: EdgeInsets.fromLTRB(20, 20, 20, MediaQuery.viewInsetsOf(context).bottom + 20),
      child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        Text(s.t('school.submitNeed'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
        const SizedBox(height: 16),
        TextField(controller: _skill, decoration: InputDecoration(labelText: s.t('school.skill'))),
        const SizedBox(height: 12),
        Row(children: [
          Text(s.t('school.count')),
          const Spacer(),
          IconButton(onPressed: _count > 1 ? () => setState(() => _count--) : null, icon: const Icon(Icons.remove_circle_outline)),
          Text('$_count', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          IconButton(onPressed: () => setState(() => _count++), icon: const Icon(Icons.add_circle_outline)),
        ]),
        SegmentedButton<String>(
          showSelectedIcon: false,
          segments: [for (final p in ['low', 'medium', 'high', 'critical']) ButtonSegment(value: p, label: Text(s.t('priority.$p'), style: const TextStyle(fontSize: 12)))],
          selected: {_priority},
          onSelectionChanged: (v) => setState(() => _priority = v.first),
        ),
        const SizedBox(height: 12),
        TextField(controller: _reason, maxLines: 3, decoration: InputDecoration(labelText: s.t('school.reason'))),
        const SizedBox(height: 16),
        FilledButton(onPressed: _submit, child: Text(s.t('common.submit'))),
      ]),
    );
  }
}
