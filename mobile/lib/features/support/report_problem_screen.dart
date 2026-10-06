import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// "Report a problem": the report goes to the training centre's ticketing (Saaed) with the app version and platform captured.
class ReportProblemScreen extends ConsumerStatefulWidget {
  const ReportProblemScreen({super.key});

  @override
  ConsumerState<ReportProblemScreen> createState() => _ReportProblemScreenState();
}

class _ReportProblemScreenState extends ConsumerState<ReportProblemScreen> {
  static const _categories = ['bug', 'access', 'data', 'request', 'other'];
  final _subject = TextEditingController();
  final _description = TextEditingController();
  String _category = 'bug';
  bool _sending = false;

  @override
  void dispose() {
    _subject.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => _sending = true);
    try {
      final res = await ref.read(apiProvider).post('/me/tickets', {
        'category': _category,
        'subject': _subject.text.trim(),
        'description': _description.text.trim(),
        'context': {'platform': Theme.of(context).platform.name},
      }) as Map<String, dynamic>;
      final no = (res['data'] as Map?)?['ticket_no']?.toString();
      if (mounted) {
        showSnack(context, no != null && no.isNotEmpty ? '${context.s.t('ticket.sent')} $no' : context.s.t('ticket.queued'));
        Navigator.of(context).pop();
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
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('ticket.title'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        DropdownButtonFormField<String>(
          initialValue: _category,
          decoration: InputDecoration(labelText: s.t('ticket.category')),
          items: [for (final c in _categories) DropdownMenuItem(value: c, child: Text(s.t('ticket.cat.$c')))],
          onChanged: (v) => setState(() => _category = v ?? 'bug'),
        ),
        const SizedBox(height: 12),
        TextField(controller: _subject, maxLength: 200, decoration: InputDecoration(labelText: s.t('ticket.subject')), onChanged: (_) => setState(() {})),
        TextField(controller: _description, maxLines: 6, maxLength: 5000, decoration: InputDecoration(labelText: s.t('ticket.description')), onChanged: (_) => setState(() {})),
        const SizedBox(height: 12),
        FilledButton(onPressed: _sending || _subject.text.trim().isEmpty || _description.text.trim().isEmpty ? null : _send, child: Text(s.t('ticket.send'))),
      ]),
    );
  }
}
