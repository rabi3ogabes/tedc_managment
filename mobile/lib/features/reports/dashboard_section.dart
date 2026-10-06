import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';

/// The dashboard of the active role (principal, deputy, trainer, trainee…): the widgets its preset offers, in the person's own order.
class DashboardSection extends ConsumerWidget {
  const DashboardSection({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final layout = ref.watch(getProvider('/dashboard'));
    return layout.when(
      loading: () => const SizedBox.shrink(),
      error: (_, _) => const SizedBox.shrink(),
      data: (raw) {
        final widgets = Map<String, dynamic>.from((raw as Map)['data'] as Map).list('widgets').where((w) => !w.flag('hidden'));
        if (widgets.isEmpty) return const SizedBox.shrink();
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          const SizedBox(height: 12),
          for (final w in widgets) Padding(padding: const EdgeInsets.only(bottom: 10), child: _WidgetCard(meta: w)),
        ]);
      },
    );
  }
}

class _WidgetCard extends ConsumerWidget {
  const _WidgetCard({required this.meta});

  final Json meta;

  String _t(Object? v, String lang) {
    if (v is Map) return (v[lang] ?? v['ar'] ?? v['en'] ?? '').toString();
    return v?.toString() ?? '';
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final lang = s.languageCode;
    final data = ref.watch(getProvider('/dashboard/widgets/${meta.str('key')}'));
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(_t(meta['title'], lang), style: const TextStyle(fontWeight: FontWeight.w800)),
          const SizedBox(height: 10),
          data.when(
            loading: () => const LinearProgressIndicator(),
            error: (_, _) => Text(s.t('dash.noData')),
            data: (raw) => _body(Map<String, dynamic>.from((raw as Map)['data'] as Map), lang, s),
          ),
        ]),
      ),
    );
  }

  Widget _body(Json w, String lang, S s) {
    switch (w.str('type')) {
      case 'kpis':
        return Wrap(spacing: 10, runSpacing: 10, children: [
          for (final i in w.list('items'))
            SizedBox(
              width: 96,
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('${i['value']}${i['unit'] ?? ''}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
                Text(_t(i['label'], lang), style: const TextStyle(fontSize: 11, color: Colors.black54)),
              ]),
            ),
        ]);
      case 'gauge':
        final v = (w['value'] as num?)?.toDouble() ?? 0;
        final target = (w['target'] as num?)?.toDouble();
        final pct = w.str('unit') == '%' ? v / 100 : (target != null && target > 0 ? v / target : 0.0);
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${v.toStringAsFixed(v == v.roundToDouble() ? 0 : 1)}${w.str('unit')}${target != null ? ' / ${target.toStringAsFixed(0)}${w.str('unit')}' : ''}', style: const TextStyle(fontSize: 22, fontWeight: FontWeight.w800)),
          const SizedBox(height: 6),
          LinearProgressIndicator(value: pct.clamp(0, 1).toDouble(), minHeight: 8, borderRadius: BorderRadius.circular(8), color: AppColors.gold500),
        ]);
      case 'bar':
      case 'donut':
        final pts = w.list('points');
        if (pts.isEmpty) return Text(s.t('dash.noData'));
        final max = pts.map((p) => (p['value'] as num).toDouble()).fold<double>(1, (a, b) => b > a ? b : a);
        return Column(children: [
          for (final p in pts)
            Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Row(children: [
                SizedBox(width: 90, child: Text(p.str('label'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12))),
                Expanded(child: LinearProgressIndicator(value: ((p['value'] as num).toDouble() / max).clamp(0, 1).toDouble(), minHeight: 8, borderRadius: BorderRadius.circular(8), color: AppColors.gold500)),
                const SizedBox(width: 8),
                Text('${p['value']}', style: const TextStyle(fontWeight: FontWeight.w700)),
              ]),
            ),
        ]);
      case 'list':
        final items = w.list('items');
        if (items.isEmpty) return Text(s.t('dash.noData'));
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          for (final i in items)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(_t(i['title'], lang), style: const TextStyle(fontWeight: FontWeight.w600)),
                if (i.str('subtitle').isNotEmpty) Text(i.str('subtitle'), style: const TextStyle(fontSize: 12, color: Colors.black54)),
                if (i['percent'] is num) LinearProgressIndicator(value: ((i['percent'] as num).toDouble() / 100).clamp(0, 1).toDouble(), minHeight: 5, color: AppColors.gold500),
              ]),
            ),
        ]);
      case 'table':
        final rows = w.list('rows');
        if (rows.isEmpty) return Text(s.t('dash.noData'));
        final cols = w.list('columns');
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          for (final r in rows)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: cols.isEmpty
                  ? Text('${_t(r['title'], lang)} — ${r['score']}')
                  : Text(cols.map((c) => '${r[c.str('key')] ?? ''}').join(' • ')),
            ),
        ]);
      default:
        return const SizedBox.shrink();
    }
  }
}
