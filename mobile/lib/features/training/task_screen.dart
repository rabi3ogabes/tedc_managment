import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// Task submission: PDF, Word, images or a text response depending on the task.
class TaskScreen extends ConsumerStatefulWidget {
  const TaskScreen({super.key, required this.taskId});

  final String taskId;

  @override
  ConsumerState<TaskScreen> createState() => _TaskScreenState();
}

class _TaskScreenState extends ConsumerState<TaskScreen> {
  final _text = TextEditingController();
  PlatformFile? _file;
  bool _sending = false;

  static const _extensions = {'pdf': ['pdf'], 'word': ['doc', 'docx'], 'image': ['jpg', 'jpeg', 'png', 'heic', 'webp']};

  Future<void> _pick(List<String> types) async {
    final allowed = types.expand((t) => _extensions[t] ?? const <String>[]).toList();
    final files = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: allowed);
    if (files.isNotEmpty) setState(() => _file = files.first);
  }

  Future<void> _submit() async {
    setState(() => _sending = true);
    try {
      final form = FormData.fromMap({
        if (_text.text.trim().isNotEmpty) 'text_response': _text.text.trim(),
        if (_file?.path != null) 'file': await MultipartFile.fromFile(_file!.path!, filename: _file!.name),
      });
      await ref.read(apiProvider).post('/me/tasks/${widget.taskId}/submit', form);
      ref.invalidate(getProvider('/me/tasks'));
      if (mounted) {
        showSnack(context, context.tr('status.submitted'));
        Navigator.pop(context);
      }
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final value = ref.watch(getProvider('/me/tasks'));

    return Scaffold(
      appBar: AppBar(title: Text(s.t('tasks.submit'))),
      body: AsyncView(
        value: value,
        builder: (raw) {
          final task = Map<String, dynamic>.from(raw as Map).list('data').firstWhere((t) => t.str('id') == widget.taskId, orElse: () => {});
          if (task.isEmpty) return const EmptyView();
          final submission = task.obj('submission');
          final types = (task['submission_types'] as List? ?? const []).map((e) => e.toString()).toList();
          final locked = submission?.str('status') == 'approved';
          if (_text.text.isEmpty && submission != null) _text.text = submission.str('text_response');

          return ListView(padding: const EdgeInsets.all(20), children: [
            Text(task.str('program'), style: const TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w700)),
            Text(task.str('title'), style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800, color: AppColors.navy900)),
            const SizedBox(height: 6),
            Text('${s.t('tasks.due')}: ${fmt.dateTime(task.date('due_at'))}', style: const TextStyle(color: AppColors.muted)),
            if (submission != null) ...[const SizedBox(height: 10), Align(alignment: AlignmentDirectional.centerStart, child: StatusChip(submission.str('status')))],
            const SizedBox(height: 16),
            Text(task.str('instructions'), style: const TextStyle(height: 1.6)),
            if (submission?.str('feedback').isNotEmpty ?? false) ...[
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(color: const Color(0xFFFDF3E1), borderRadius: BorderRadius.circular(14)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(s.t('tasks.feedback'), style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.warning)),
                  const SizedBox(height: 4),
                  Text(submission!.str('feedback')),
                ]),
              ),
            ],
            if (!locked) ...[
              const SizedBox(height: 20),
              if (types.contains('text')) TextField(controller: _text, maxLines: 6, decoration: InputDecoration(labelText: s.t('tasks.text'), alignLabelWithHint: true)),
              if (types.any((t) => t != 'text')) ...[
                const SizedBox(height: 14),
                OutlinedButton.icon(
                  onPressed: () => _pick(types),
                  icon: const Icon(Icons.attach_file),
                  label: Text(_file?.name ?? (submission?.str('file_name').isNotEmpty == true ? submission!.str('file_name') : s.t('tasks.attach'))),
                ),
              ],
              const SizedBox(height: 20),
              FilledButton(onPressed: _sending ? null : _submit, child: _sending ? const CircularProgressIndicator(color: Colors.white) : Text(s.t('tasks.submit'))),
            ],
          ]);
        },
      ),
    );
  }
}
