import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// Help centre: articles for the person's roles, the manuals as PDF, support channels and a way to report a problem.
class HelpScreen extends ConsumerStatefulWidget {
  const HelpScreen({super.key});

  @override
  ConsumerState<HelpScreen> createState() => _HelpScreenState();
}

class _HelpScreenState extends ConsumerState<HelpScreen> {
  final _search = TextEditingController();
  String _q = '';
  String? _busy;

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _manual(String role, String lang) async {
    setState(() => _busy = '$role-$lang');
    try {
      final bytes = await ref.read(apiProvider).bytes('/me/help/manuals/$role/pdf?lang=$lang');
      final dir = await getTemporaryDirectory();
      final file = File('${dir.path}/manual-$role-$lang.pdf');
      await file.writeAsBytes(bytes, flush: true);
      final result = await OpenFilex.open(file.path, type: 'application/pdf');
      if (result.type != ResultType.done && mounted) showSnack(context, context.tr('help.opened'), error: true);
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = null);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = context.s.isArabic;
    final path = _q.trim().length > 1 ? '/me/help/articles?q=${Uri.encodeQueryComponent(_q.trim())}' : '/me/help/articles';
    final articles = ref.watch(getProvider(path));
    final manuals = ref.watch(getProvider('/me/help/manuals'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('help.title'))),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        TextField(
          controller: _search,
          decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: context.tr('help.search')),
          onChanged: (v) => setState(() => _q = v),
        ),
        const SizedBox(height: 12),
        AsyncView(
          value: articles,
          onRetry: () => ref.invalidate(getProvider(path)),
          builder: (raw) {
            final map = Map<String, dynamic>.from(raw as Map);
            final rows = map.list('data');
            return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              if (rows.isEmpty) EmptyView(text: context.tr('help.none')),
              for (final a in rows)
                Card(
                  child: ListTile(
                    leading: Icon(a.flag('has_video') ? Icons.play_circle_outline : Icons.article_outlined, color: AppColors.gold500),
                    title: Text(ar ? a.str('title_ar') : a.str('title_en'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text(ar ? a.str('excerpt_ar') : a.str('excerpt_en'), maxLines: 2, overflow: TextOverflow.ellipsis),
                    onTap: () => context.push('/help/${a.str('slug')}'),
                  ),
                ),
              const SizedBox(height: 16),
              _Support(support: map.obj('support')),
            ]);
          },
        ),
        const SizedBox(height: 16),
        Text(context.tr('help.manuals'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
        AsyncView(
          value: manuals,
          onRetry: () => ref.invalidate(getProvider('/me/help/manuals')),
          builder: (raw) {
            final rows = Map<String, dynamic>.from(raw as Map).list('data');
            return Column(children: [
              for (final m in rows)
                Card(
                  child: ListTile(
                    title: Text(ar ? m.str('name_ar') : m.str('name_en'), style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${m.number('articles').toInt()} ${context.tr('help.articles')}'),
                    trailing: Row(mainAxisSize: MainAxisSize.min, children: [
                      for (final l in const ['ar', 'en'])
                        TextButton(
                          onPressed: _busy == null ? () => _manual(m.str('role'), l) : null,
                          child: _busy == '${m.str('role')}-$l' ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)) : Text(context.tr(l == 'ar' ? 'help.pdfAr' : 'help.pdfEn')),
                        ),
                    ]),
                  ),
                ),
            ]);
          },
        ),
        const SizedBox(height: 16),
        FilledButton.icon(onPressed: () => context.push('/report-problem'), icon: const Icon(Icons.support_agent_outlined), label: Text(context.tr('help.report'))),
      ]),
    );
  }
}

class _Support extends StatelessWidget {
  const _Support({required this.support});

  final Json? support;

  @override
  Widget build(BuildContext context) {
    final ar = context.s.isArabic;
    final channels = support?.list('channels') ?? const <Json>[];
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text(context.tr('help.support'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      for (final c in channels)
        ListTile(
          dense: true,
          leading: Icon(c.str('key') == 'phone' ? Icons.phone_outlined : c.str('key') == 'email' ? Icons.mail_outline : Icons.support_agent_outlined, color: AppColors.gold500),
          title: Text(context.tr('help.channel.${c.str('key')}')),
          subtitle: Text(c.str('value').isEmpty ? context.tr('help.notSet') : c.str('value'), textDirection: TextDirection.ltr),
          trailing: Text(ar ? c.str('hours_ar') : c.str('hours_en'), style: const TextStyle(fontSize: 11), textAlign: TextAlign.end),
        ),
    ]);
  }
}

/// One article: text blocks, screenshots, the video link and the «was it helpful?» question.
class HelpArticleScreen extends ConsumerStatefulWidget {
  const HelpArticleScreen({super.key, required this.slug});

  final String slug;

  @override
  ConsumerState<HelpArticleScreen> createState() => _HelpArticleScreenState();
}

class _HelpArticleScreenState extends ConsumerState<HelpArticleScreen> {
  bool _sent = false;

  Future<void> _vote(bool helpful) async {
    try {
      await ref.read(apiProvider).post('/me/help/articles/${widget.slug}/feedback', {'helpful': helpful});
      if (mounted) setState(() => _sent = true);
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  /// Plain blocks out of the article HTML: headings, paragraphs, list items and quotes.
  static List<(String, String)> blocks(String html) {
    final out = <(String, String)>[];
    for (final m in RegExp(r'<(h[1-6]|p|li|blockquote)[^>]*>(.*?)</\1>', dotAll: true).allMatches(html)) {
      final text = (m.group(2) ?? '').replaceAll(RegExp(r'<[^>]+>'), '').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&quot;', '"').replaceAll('&#039;', "'").trim();
      if (text.isNotEmpty) out.add((m.group(1)!, text));
    }
    return out;
  }

  @override
  Widget build(BuildContext context) {
    final ar = context.s.isArabic;
    final res = ref.watch(getProvider('/me/help/articles/${widget.slug}'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('help.title'))),
      body: AsyncView(
        value: res,
        onRetry: () => ref.invalidate(getProvider('/me/help/articles/${widget.slug}')),
        builder: (raw) {
          final a = Map<String, dynamic>.from((raw as Map)['data'] as Map);
          final shots = a.list('screenshots');
          final video = a.str('video_asset_url').isNotEmpty ? a.str('video_asset_url') : a.str('video_url');
          return ListView(padding: const EdgeInsets.all(16), children: [
            Text(ar ? a.str('title_ar') : a.str('title_en'), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
            const SizedBox(height: 12),
            for (final (tag, text) in blocks(ar ? a.str('body_ar') : a.str('body_en')))
              Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: tag == 'li'
                    ? Row(crossAxisAlignment: CrossAxisAlignment.start, children: [const Text('•  '), Expanded(child: Text(text, style: const TextStyle(height: 1.7)))])
                    : Text(text, style: TextStyle(height: 1.7, fontWeight: tag.startsWith('h') ? FontWeight.w800 : FontWeight.w400, fontSize: tag.startsWith('h') ? 16 : 14)),
              ),
            if (shots.isNotEmpty) ...[
              Text(context.tr('help.shots'), style: const TextStyle(fontWeight: FontWeight.w800)),
              for (final s in shots)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 6),
                  child: Column(children: [
                    ClipRRect(borderRadius: BorderRadius.circular(12), child: Image.network(s.str('url'), errorBuilder: (_, _, _) => const SizedBox.shrink())),
                    if ((ar ? s.str('caption_ar') : s.str('caption_en')).isNotEmpty) Text(ar ? s.str('caption_ar') : s.str('caption_en'), style: const TextStyle(fontSize: 12)),
                  ]),
                ),
            ],
            if (video.isNotEmpty)
              TextButton.icon(
                onPressed: () {
                  final uri = Uri.tryParse(video);
                  if (uri != null && (uri.scheme == 'https' || uri.scheme == 'http')) launchUrl(uri, mode: LaunchMode.externalApplication);
                },
                icon: const Icon(Icons.play_circle_outline),
                label: Text(context.tr('help.video')),
              ),
            const SizedBox(height: 12),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: _sent
                    ? Text(context.tr('help.thanks'), style: const TextStyle(fontWeight: FontWeight.w700))
                    : Row(children: [
                        Expanded(child: Text(context.tr('help.helpful'), style: const TextStyle(fontWeight: FontWeight.w700))),
                        TextButton(onPressed: () => _vote(true), child: Text(context.tr('help.yes'))),
                        TextButton(onPressed: () => _vote(false), child: Text(context.tr('help.no'))),
                      ]),
              ),
            ),
          ]);
        },
      ),
    );
  }
}

/// The guided tour: a short sheet shown once per person (and again after a release); skipping is remembered.
Future<void> showTourIfPending(BuildContext context, WidgetRef ref) async {
  try {
    final res = await ref.read(apiProvider).get('/me/tours') as Map;
    final tour = res['data'] is Map ? Map<String, dynamic>.from(res['data'] as Map) : null;
    if (tour == null || !context.mounted) return;
    final steps = tour.list('steps');
    if (steps.isEmpty) return;
    final key = tour.str('key');
    var i = 0;
    final ar = context.s.isArabic;
    await showModalBottomSheet<void>(
      context: context,
      isDismissible: true,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setState) {
          final step = steps[i];
          final last = i == steps.length - 1;
          return Padding(
            padding: const EdgeInsets.all(20),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(ar ? step.str('title_ar') : step.str('title_en'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              Text(ar ? step.str('body_ar') : step.str('body_en'), style: const TextStyle(height: 1.7)),
              const SizedBox(height: 16),
              Row(mainAxisAlignment: MainAxisAlignment.end, children: [
                if (!last) TextButton(onPressed: () => Navigator.of(ctx).pop(), child: Text(context.tr('help.tour.skip'))),
                FilledButton(onPressed: () => last ? Navigator.of(ctx).pop() : setState(() => i++), child: Text(context.tr(last ? 'help.tour.done' : 'help.tour.next'))),
              ]),
            ]),
          );
        },
      ),
    );
    await ref.read(apiProvider).post('/me/tours', {'key': key, 'state': i == steps.length - 1 ? 'done' : 'dismissed'});
  } catch (_) {
    // A tour is a courtesy: if it cannot load or save, the app carries on.
  }
}
