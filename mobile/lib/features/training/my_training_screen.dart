import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

class MyTrainingScreen extends StatelessWidget {
  const MyTrainingScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return DefaultTabController(
      length: 4,
      child: Scaffold(
        appBar: AppBar(
          title: Text(s.t('training.title')),
          bottom: TabBar(
            isScrollable: true,
            tabAlignment: TabAlignment.start,
            indicatorColor: AppColors.gold500,
            labelColor: AppColors.navy900,
            unselectedLabelColor: AppColors.muted,
            labelStyle: const TextStyle(fontWeight: FontWeight.w800),
            tabs: [
              Tab(text: s.t('training.programs')),
              Tab(text: s.t('training.calendar')),
              Tab(text: s.t('training.tasks')),
              Tab(text: s.t('training.surveys')),
            ],
          ),
        ),
        body: const TabBarView(children: [_Programs(), _Calendar(), _Tasks(), _Surveys()]),
      ),
    );
  }
}

class _Refreshable extends ConsumerWidget {
  const _Refreshable({required this.path, required this.builder});

  final String path;
  final Widget Function(List<Json> items) builder;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return RefreshIndicator(
      onRefresh: () => ref.refresh(getProvider(path).future),
      child: AsyncView(
        value: ref.watch(getProvider(path)),
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final items = Map<String, dynamic>.from(raw as Map).list('data');
          return items.isEmpty ? ListView(children: const [EmptyView()]) : builder(items);
        },
      ),
    );
  }
}

class _Programs extends StatelessWidget {
  const _Programs();

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    return _Refreshable(
      path: '/me/registrations',
      builder: (items) => ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(height: 12),
        itemBuilder: (_, i) {
          final r = items[i];
          final p = r.obj('program') ?? {};
          final attendance = r.number('attendance_percent').toDouble();
          return Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(20),
              onTap: () => context.push('/registrations/${r.str('id')}'),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(child: Text(p.str('title'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: AppColors.navy900))),
                    StatusChip(r.str('status')),
                  ]),
                  const SizedBox(height: 4),
                  Text('${fmt.date(p.date('start_date'))} – ${fmt.date(p.date('end_date'))}', style: const TextStyle(color: AppColors.muted, fontSize: 12)),
                  const SizedBox(height: 12),
                  Row(children: [
                    Text(s.t('training.attendance'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
                    const Spacer(),
                    Text(fmt.percent(attendance), style: const TextStyle(fontWeight: FontWeight.w800)),
                  ]),
                  const SizedBox(height: 6),
                  ProgressBar(attendance, color: attendance >= p.number('min_attendance_percent') ? AppColors.success : AppColors.gold500),
                  const SizedBox(height: 10),
                  Row(children: [
                    Text(s.t('training.certificate'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
                    const Spacer(),
                    StatusChip(r.str('certificate_status')),
                  ]),
                ]),
              ),
            ),
          );
        },
      ),
    );
  }
}

class _Calendar extends StatelessWidget {
  const _Calendar();

  @override
  Widget build(BuildContext context) {
    final fmt = Fmt(context.s.languageCode);
    return _Refreshable(
      path: '/me/calendar',
      builder: (items) {
        final upcoming = items.where((e) => (e.date('ends_at') ?? DateTime(2000)).isAfter(DateTime.now())).toList();
        final list = upcoming.isEmpty ? items : upcoming;
        return ListView.builder(
          padding: const EdgeInsets.all(16),
          itemCount: list.length,
          itemBuilder: (_, i) {
            final session = list[i];
            final start = session.date('starts_at');
            return Padding(
              padding: const EdgeInsets.only(bottom: 12),
              child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Container(
                  width: 56,
                  padding: const EdgeInsets.symmetric(vertical: 10),
                  decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(16)),
                  child: Column(children: [
                    Text('${start?.day ?? ''}', style: const TextStyle(color: AppColors.gold300, fontSize: 20, fontWeight: FontWeight.w800)),
                    Text(fmt.time(start), style: const TextStyle(color: Colors.white70, fontSize: 10)),
                  ]),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(session.obj('program')?.str('title') ?? '', style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
                        Text(session.str('title'), style: const TextStyle(fontSize: 13, color: AppColors.muted)),
                        const SizedBox(height: 4),
                        Text('${fmt.weekdayDate(start)} · ${session.str('location')}', style: const TextStyle(fontSize: 12, color: AppColors.gold700)),
                      ]),
                    ),
                  ),
                ),
              ]),
            );
          },
        );
      },
    );
  }
}

class _Tasks extends StatelessWidget {
  const _Tasks();

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    return _Refreshable(
      path: '/me/tasks',
      builder: (items) => ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (_, i) {
          final task = items[i];
          final submission = task.obj('submission');
          return Card(
            child: ListTile(
              contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
              leading: const CircleAvatar(backgroundColor: AppColors.gold100, child: Icon(Icons.assignment_outlined, color: AppColors.gold700)),
              title: Text(task.str('title'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text('${task.str('program')}\n${s.t('tasks.due')}: ${fmt.dateTime(task.date('due_at'))}'),
              isThreeLine: true,
              trailing: submission != null ? StatusChip(submission.str('status')) : (task.flag('is_required') ? StatusChip('pending', label: s.t('common.required')) : null),
              onTap: () => context.push('/tasks/${task.str('id')}'),
            ),
          );
        },
      ),
    );
  }
}

class _Surveys extends StatelessWidget {
  const _Surveys();

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    return _Refreshable(
      path: '/me/surveys',
      builder: (items) => ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: items.length,
        separatorBuilder: (_, _) => const SizedBox(height: 10),
        itemBuilder: (_, i) {
          final survey = items[i];
          return Card(
            child: ListTile(
              leading: CircleAvatar(
                backgroundColor: AppColors.navy900,
                child: Text('${survey.number('stage_days')}', style: const TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w800, fontSize: 13)),
              ),
              title: Text(survey.str('program'), style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text('${s.t('survey.title')} · ${fmt.date(survey.date('scheduled_for'))}'),
              trailing: StatusChip(survey.str('status')),
              onTap: survey.str('status') == 'sent' ? () => context.push('/surveys/${survey.str('id')}') : null,
            ),
          );
        },
      ),
    );
  }
}
