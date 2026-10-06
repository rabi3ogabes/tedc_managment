import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

String _plain(String html) => html.replaceAll(RegExp(r'<br\s*/?>'), '\n').replaceAll(RegExp(r'</p>'), '\n').replaceAll(RegExp(r'<[^>]+>'), '').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>').trim();

/// The person's questions to their trainers, with the answers, and a form to ask a new one.
class QuestionsScreen extends ConsumerWidget {
  const QuestionsScreen({super.key});

  Future<void> _ask(BuildContext context, WidgetRef ref) async {
    final registrations = await ref.read(getProvider('/me/registrations').future);
    final programs = Map<String, dynamic>.from(registrations as Map).list('data');
    if (!context.mounted) return;
    if (programs.isEmpty) {
      showSnack(context, context.tr('soc.noPrograms'), error: true);
      return;
    }
    var programId = programs.first.str('program_id');
    var visibility = 'private';
    final subject = TextEditingController();
    final body = TextEditingController();
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setState) => Padding(
          padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(ctx).viewInsets.bottom + 16),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            DropdownButtonFormField<String>(
              initialValue: programId,
              isExpanded: true,
              decoration: InputDecoration(labelText: ctx.tr('soc.program')),
              items: [for (final p in programs) DropdownMenuItem(value: p.str('program_id'), child: Text(p.obj('program')?.str('title') ?? p.str('program_id'), overflow: TextOverflow.ellipsis))],
              onChanged: (v) => setState(() => programId = v ?? programId),
            ),
            const SizedBox(height: 8),
            TextField(controller: subject, decoration: InputDecoration(labelText: ctx.tr('soc.subject'))),
            const SizedBox(height: 8),
            TextField(controller: body, minLines: 3, maxLines: 8, decoration: InputDecoration(labelText: ctx.tr('soc.question'))),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: [ButtonSegment(value: 'private', label: Text(ctx.tr('soc.visPrivate'))), ButtonSegment(value: 'group', label: Text(ctx.tr('soc.visGroup')))],
              selected: {visibility},
              onSelectionChanged: (s) => setState(() => visibility = s.first),
            ),
            const SizedBox(height: 12),
            Align(alignment: AlignmentDirectional.centerEnd, child: FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(ctx.tr('soc.send')))),
          ]),
        ),
      ),
    );
    if (ok != true || subject.text.trim().isEmpty || body.text.trim().isEmpty) return;
    try {
      final esc = body.text.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('\n', '<br>');
      await ref.read(apiProvider).post('/social/questions', {'program_id': programId, 'subject': subject.text.trim(), 'body': '<p>$esc</p>', 'visibility': visibility});
      if (context.mounted) showSnack(context, context.tr('soc.sent'));
      ref.invalidate(getProvider('/social/questions'));
    } catch (e) {
      if (context.mounted) showSnack(context, e.toString(), error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final res = ref.watch(getProvider('/social/questions'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('soc.questionsTitle'))),
      floatingActionButton: FloatingActionButton.extended(onPressed: () => _ask(context, ref), icon: const Icon(Icons.help_outline), label: Text(context.tr('soc.ask'))),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(getProvider('/social/questions')),
        child: AsyncView(
          value: res,
          onRetry: () => ref.invalidate(getProvider('/social/questions')),
          builder: (raw) {
            final rows = Map<String, dynamic>.from(raw as Map).list('data');
            if (rows.isEmpty) return ListView(children: [EmptyView(text: context.tr('soc.noQuestions'))]);
            return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
              for (final q in rows)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        Expanded(child: Text(q.str('subject'), style: const TextStyle(fontWeight: FontWeight.w800))),
                        Chip(label: Text(context.tr('soc.status.${q.str('status')}')), visualDensity: VisualDensity.compact),
                      ]),
                      Text(_plain(q.str('body')), maxLines: 3, overflow: TextOverflow.ellipsis),
                      const SizedBox(height: 8),
                      if (q.str('answer').isNotEmpty)
                        Container(
                          width: double.infinity,
                          padding: const EdgeInsets.all(10),
                          decoration: BoxDecoration(color: Colors.green.shade50, borderRadius: BorderRadius.circular(12)),
                          child: Text(_plain(q.str('answer'))),
                        )
                      else
                        Text(context.tr('soc.waiting'), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
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
