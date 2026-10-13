import 'package:flutter/material.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';

/// One question of an assessment and the answer given so far (online, offline and adaptive practice).
class QuestionView extends StatelessWidget {
  const QuestionView({super.key, required this.q, required this.answer, required this.onChanged, this.enabled = true});

  final Json q;
  final dynamic answer;
  final ValueChanged<dynamic> onChanged;
  final bool enabled;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final id = q.str('id');
    final p = q.obj('payload') ?? {};
    String text(Json o, String key) => o.str('${key}_${ar ? 'ar' : 'en'}').isNotEmpty ? o.str('${key}_${ar ? 'ar' : 'en'}') : o.str('${key}_ar');
    final head = Padding(padding: const EdgeInsets.only(bottom: 16), child: Text(text(q, 'stem'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700, height: 1.6)));
    void set(dynamic v) {
      if (enabled) onChanged(v);
    }

    switch (q.str('type')) {
      case 'single_choice':
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          head,
          RadioGroup<String>(
            groupValue: answer as String?,
            onChanged: set,
            child: Column(children: [for (final o in p.list('options')) RadioListTile<String>(value: o.str('id'), enabled: enabled, title: Text(text(o, 'text')))]),
          ),
        ]);
      case 'multiple_select':
        final cur = List<String>.from((answer as List?) ?? const []);
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          head,
          for (final o in p.list('options'))
            CheckboxListTile(
              value: cur.contains(o.str('id')),
              enabled: enabled,
              title: Text(text(o, 'text')),
              onChanged: (v) => set(v == true ? [...cur, o.str('id')] : cur.where((x) => x != o.str('id')).toList()),
            ),
        ]);
      case 'true_false':
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          head,
          Row(children: [
            for (final v in [true, false])
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.all(4),
                  child: answer == v
                      ? FilledButton(onPressed: enabled ? () => set(v) : null, child: Text(s.t(v ? 'assess.true' : 'assess.false')))
                      : OutlinedButton(onPressed: enabled ? () => set(v) : null, child: Text(s.t(v ? 'assess.true' : 'assess.false'))),
                ),
              ),
          ]),
        ]);
      case 'short_answer':
      case 'numeric':
      case 'essay':
        final multi = q.str('type') == 'essay';
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          head,
          TextFormField(
            key: ValueKey(id),
            initialValue: answer?.toString() ?? '',
            enabled: enabled,
            minLines: multi ? 6 : 1,
            maxLines: multi ? 12 : 1,
            keyboardType: q.str('type') == 'numeric' ? const TextInputType.numberWithOptions(decimal: true, signed: true) : TextInputType.multiline,
            decoration: const InputDecoration(border: OutlineInputBorder()),
            onChanged: set,
          ),
        ]);
      default:
        // Drag, matrix and hotspot interactions are completed on the web; the trainee is told clearly instead of seeing a broken question.
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [head, Card(child: Padding(padding: const EdgeInsets.all(16), child: Text(s.t('assess.webOnly'))))]);
    }
  }
}

/// Whether an answer was given.
bool answered(dynamic v) => v != null && v != '' && !(v is List && v.isEmpty);
