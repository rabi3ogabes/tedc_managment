import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The evaluation forms waiting for the signed-in person (trainer reflection, planning, supervisor and specialist feedback).
class EvaluationsScreen extends ConsumerWidget {
  const EvaluationsScreen({super.key});

  static const path = '/me/evaluations';

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final list = ref.watch(getProvider(path));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('evalc.title'))),
      body: AsyncView(
        value: list,
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final rows = Map<String, dynamic>.from(raw as Map).list('data');
          if (rows.isEmpty) return Center(child: Text(s.t('evalc.empty')));
          return ListView(padding: const EdgeInsets.all(20), children: [
            for (final r in rows)
              Card(
                child: ListTile(
                  title: Text(r.str(ar ? 'title_ar' : 'title_en'), style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(r.str('program')),
                  trailing: r.str('status') == 'pending'
                      ? FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: () => context.push('/evaluations/${r.str('id')}'), child: Text(s.t('evalc.fill')))
                      : Text(s.t('evalc.done')),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

/// Answers one evaluation form. Ratings, yes/no and text are supported; evidence is added as links (files are attached on the web).
class EvaluationFormScreen extends ConsumerStatefulWidget {
  const EvaluationFormScreen({super.key, required this.id});

  final String id;

  @override
  ConsumerState<EvaluationFormScreen> createState() => _EvaluationFormScreenState();
}

class _EvaluationFormScreenState extends ConsumerState<EvaluationFormScreen> {
  final Map<String, dynamic> _answers = {};
  final Map<String, String> _links = {};
  bool _busy = false;

  Future<void> _submit(List<Json> questions) async {
    final missing = questions.where((q) => q.flag('required') && (_answers[q.str('id')] == null || _answers[q.str('id')] == ''));
    if (missing.isNotEmpty) {
      showSnack(context, context.tr('evalc.required'), error: true);
      return;
    }
    setState(() => _busy = true);
    try {
      final evidence = {for (final e in _links.entries) if (e.value.startsWith('http')) e.key: [e.value]};
      await ref.read(apiProvider).post('/me/evaluations/${widget.id}', {'answers': _answers, if (evidence.isNotEmpty) 'evidence': evidence});
      ref.invalidate(getProvider(EvaluationsScreen.path));
      if (mounted) {
        showSnack(context, context.tr('evalc.submitted'));
        context.pop();
      }
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
    final res = ref.watch(getProvider('/me/evaluations/${widget.id}'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('evalc.title'))),
      body: AsyncView(
        value: res,
        onRetry: () => ref.invalidate(getProvider('/me/evaluations/${widget.id}')),
        builder: (raw) {
          final d = Map<String, dynamic>.from((raw as Map)['data'] as Map);
          final qs = d.list('questions').where((q) => q.str('type') != 'section').toList();
          final evidenceAllowed = d.flag('evidence_allowed');
          return ListView(padding: const EdgeInsets.all(20), children: [
            Text(d.str(ar ? 'title_ar' : 'title_en'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 16),
            for (final q in qs) ...[
              Text(q.str(!ar && q.str('title_en').isNotEmpty ? 'title_en' : 'title') + (q.flag('required') ? ' *' : ''), style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              _input(q),
              if (q.flag('evidence') && evidenceAllowed)
                TextField(
                  textDirection: TextDirection.ltr,
                  decoration: InputDecoration(hintText: s.t('evalc.link')),
                  onChanged: (v) => _links[q.str('id')] = v.trim(),
                ),
              const SizedBox(height: 18),
            ],
            FilledButton(onPressed: _busy ? null : () => _submit(qs), child: Text(s.t('evalc.submit'))),
          ]);
        },
      ),
    );
  }

  Widget _input(Json q) {
    final id = q.str('id');
    switch (q.str('type')) {
      case 'rating':
      case 'scale':
        final scale = q.obj('scale');
        final min = (scale?.number('min') ?? 1).toInt();
        final max = (scale?.number('max') ?? 5).toInt();
        return Wrap(spacing: 8, children: [
          for (var v = min; v <= max; v++) ChoiceChip(label: Text('$v'), selected: _answers[id] == v, onSelected: (_) => setState(() => _answers[id] = v)),
        ]);
      case 'yes_no':
        return Wrap(spacing: 8, children: [
          for (final v in ['yes', 'no']) ChoiceChip(label: Text(context.tr('evalc.$v')), selected: _answers[id] == v, onSelected: (_) => setState(() => _answers[id] = v)),
        ]);
      case 'nps':
        return Wrap(spacing: 6, children: [
          for (var v = 0; v <= 10; v++) ChoiceChip(label: Text('$v'), selected: _answers[id] == v, onSelected: (_) => setState(() => _answers[id] = v)),
        ]);
      default:
        return TextField(minLines: 3, maxLines: 8, decoration: const InputDecoration(border: OutlineInputBorder()), onChanged: (v) => _answers[id] = v);
    }
  }
}
