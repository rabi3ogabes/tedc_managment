import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// 30 / 60 / 90-day impact follow-up survey.
class SurveyScreen extends ConsumerStatefulWidget {
  const SurveyScreen({super.key, required this.surveyId});

  final String surveyId;

  @override
  ConsumerState<SurveyScreen> createState() => _SurveyScreenState();
}

class _SurveyScreenState extends ConsumerState<SurveyScreen> {
  String _applied = 'yes';
  double _score = 80;
  bool _needsSupport = false;
  final _changes = TextEditingController();
  final _skills = TextEditingController();
  final _support = TextEditingController();
  bool _sending = false;

  Future<void> _submit() async {
    setState(() => _sending = true);
    try {
      await ref.read(apiProvider).post('/me/surveys/${widget.surveyId}', {
        'applied_learning': _applied,
        'application_score': _score.round(),
        'changes_observed': _changes.text,
        'skills_improved': _skills.text.split(RegExp('[,،]')).map((e) => e.trim()).where((e) => e.isNotEmpty).toList(),
        'needs_support': _needsSupport,
        'support_details': _needsSupport ? _support.text : null,
      });
      ref.invalidate(getProvider('/me/surveys'));
      ref.invalidate(getProvider('/me/home'));
      if (mounted) Navigator.pop(context);
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final survey = ref.watch(getProvider('/me/surveys')).value;
    final item = survey == null ? null : Map<String, dynamic>.from(survey as Map).list('data').where((e) => e.str('id') == widget.surveyId).firstOrNull;

    return Scaffold(
      appBar: AppBar(title: Text(s.t('survey.title'))),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        if (item != null) ...[
          Text(item.str('program'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppColors.navy900)),
          Text('${item.number('stage_days')} ${s.t('survey.days')}', style: const TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w700)),
          const SizedBox(height: 20),
        ],
        Text(s.t('survey.applied'), style: const TextStyle(fontWeight: FontWeight.w700)),
        const SizedBox(height: 8),
        SegmentedButton<String>(
          segments: [for (final v in ['yes', 'partially', 'no']) ButtonSegment(value: v, label: Text(s.t('survey.$v')))],
          selected: {_applied},
          onSelectionChanged: (v) => setState(() {
            _applied = v.first;
            _score = {'yes': 85.0, 'partially': 60.0, 'no': 20.0}[_applied]!;
          }),
        ),
        const SizedBox(height: 12),
        Slider(value: _score, min: 0, max: 100, divisions: 20, label: '${_score.round()}%', activeColor: AppColors.gold500, onChanged: (v) => setState(() => _score = v)),
        TextField(controller: _changes, maxLines: 3, decoration: InputDecoration(labelText: s.t('survey.changes'), alignLabelWithHint: true)),
        const SizedBox(height: 12),
        TextField(controller: _skills, decoration: InputDecoration(labelText: s.t('survey.skills'))),
        SwitchListTile(
          value: _needsSupport,
          onChanged: (v) => setState(() => _needsSupport = v),
          title: Text(s.t('survey.support')),
          activeThumbColor: AppColors.gold500,
          contentPadding: EdgeInsets.zero,
        ),
        if (_needsSupport) TextField(controller: _support, maxLines: 2, decoration: InputDecoration(labelText: s.t('survey.supportDetails'))),
        const SizedBox(height: 20),
        FilledButton(onPressed: _sending ? null : _submit, child: Text(s.t('common.submit'))),
      ]),
    );
  }
}
