import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// A trainer's proposals: fill the assignment form so the centre can approve the assignment.
class AssignmentsScreen extends ConsumerWidget {
  const AssignmentsScreen({super.key});

  static const path = '/me/assignments';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final value = ref.watch(getProvider(path));

    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('assignments.title'))),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final rows = Map<String, dynamic>.from(raw as Map).list('data');
          if (rows.isEmpty) return Center(child: Text(s.t('assignments.empty')));
          return ListView(padding: const EdgeInsets.all(20), children: [for (final a in rows) _AssignmentCard(a)]);
        },
      ),
    );
  }
}

class _AssignmentCard extends ConsumerStatefulWidget {
  const _AssignmentCard(this.a);

  final Json a;

  @override
  ConsumerState<_AssignmentCard> createState() => _AssignmentCardState();
}

class _AssignmentCardState extends ConsumerState<_AssignmentCard> {
  late bool _available = widget.a.obj('form')?['availability_confirmed'] == true;
  late bool _cv = widget.a.obj('form')?['cv_updated'] == true;
  late final TextEditingController _notes = TextEditingController(text: widget.a.obj('form')?.str('notes') ?? '');
  bool _busy = false;

  @override
  void dispose() {
    _notes.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).put('/me/assignments/${widget.a.str('id')}/form', {
        'form': {'availability_confirmed': _available, 'cv_updated': _cv, 'notes': _notes.text},
      });
      ref.invalidate(getProvider(AssignmentsScreen.path));
      if (mounted) showSnack(context, context.tr('assignments.submitted'));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final ar = s.languageCode == 'ar';
    final group = widget.a.obj('group');
    final open = widget.a.str('status') == 'proposed';

    return Card(
      margin: const EdgeInsets.only(bottom: 16),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(group?.str(ar ? 'program_title_ar' : 'program_title_en') ?? '', style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                Text('${group?.str(ar ? 'title_ar' : 'title_en') ?? ''} · ${fmt.date(group?.date('start_date'))}', style: const TextStyle(color: Colors.black54, fontSize: 13)),
              ]),
            ),
            StatusChip(widget.a.str('status'), label: s.t('assignments.st.${widget.a.str('status')}')),
          ]),
          if (open) ...[
            const SizedBox(height: 8),
            CheckboxListTile(contentPadding: EdgeInsets.zero, value: _available, onChanged: (v) => setState(() => _available = v ?? false), title: Text(s.t('assignments.availability'))),
            CheckboxListTile(contentPadding: EdgeInsets.zero, value: _cv, onChanged: (v) => setState(() => _cv = v ?? false), title: Text(s.t('assignments.cv'))),
            TextField(controller: _notes, minLines: 2, maxLines: 4, decoration: InputDecoration(labelText: s.t('assignments.notes'))),
            const SizedBox(height: 12),
            FilledButton(
              onPressed: _available && !_busy ? _submit : null,
              style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
              child: _busy ? const SizedBox(width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2)) : Text(s.t('assignments.submit')),
            ),
          ],
        ]),
      ),
    );
  }
}
