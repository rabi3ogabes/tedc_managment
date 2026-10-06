import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The trainee's own reports: calendar, hours by year, completed courses, attendance and a statement of courses (as a PDF to keep or print).
class MyReportsScreen extends ConsumerWidget {
  const MyReportsScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final list = ref.watch(getProvider('/me/reports'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('reports.title'))),
      body: AsyncView(
        value: list,
        onRetry: () => ref.invalidate(getProvider('/me/reports')),
        builder: (raw) {
          final rows = Map<String, dynamic>.from(raw as Map).list('data');
          return ListView(padding: const EdgeInsets.all(16), children: [
            for (final r in rows)
              Card(
                child: ListTile(
                  title: Text(((r['title'] as Map?)?[ar ? 'ar' : 'en'] ?? r.str('key')).toString(), style: const TextStyle(fontWeight: FontWeight.w700)),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => _ReportScreen(report: r))),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

class _ReportScreen extends ConsumerWidget {
  const _ReportScreen({required this.report});

  final Json report;

  Future<void> _pdf(BuildContext context, WidgetRef ref, String lang) async {
    try {
      final bytes = await ref.read(apiProvider).bytes('/me/reports/${report.str('key')}/export?format=pdf&lang=$lang');
      final dir = await getTemporaryDirectory();
      final file = File('${dir.path}/${report.str('key')}.pdf');
      await file.writeAsBytes(bytes, flush: true);
      await OpenFilex.open(file.path, type: 'application/pdf');
    } catch (e) {
      if (context.mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final lang = s.languageCode;
    final path = '/me/reports/${report.str('key')}';
    final res = ref.watch(getProvider(path));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(
        title: Text(((report['title'] as Map?)?[lang] ?? '').toString()),
        actions: [IconButton(icon: const Icon(Icons.picture_as_pdf_outlined), tooltip: 'PDF', onPressed: () => _pdf(context, ref, lang))],
      ),
      body: AsyncView(
        value: res,
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final d = Map<String, dynamic>.from((raw as Map)['data'] as Map);
          final cols = d.list('columns');
          final rows = d.list('rows');
          if (rows.isEmpty) return Center(child: Text(s.t('reports.empty')));
          return ListView(padding: const EdgeInsets.all(16), children: [
            for (final r in rows)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    for (final c in cols)
                      if ((r[c.str('key')]?.toString() ?? '').isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 4),
                          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            SizedBox(width: 110, child: Text(((c['label'] as Map?)?[lang] ?? c.str('key')).toString(), style: const TextStyle(fontSize: 12, color: Colors.black54))),
                            Expanded(child: Text(r[c.str('key')].toString(), style: const TextStyle(fontWeight: FontWeight.w600))),
                          ]),
                        ),
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }
}
