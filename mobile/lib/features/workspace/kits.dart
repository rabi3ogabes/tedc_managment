import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/config.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import '../duties/duties_model.dart';
import 'parts.dart';
import 'workspace.dart';
import 'workspace_programmes.dart';

/// «الحقائب» in a trainer's app: the training kits of every programme they teach, and the content of the online ones.
class WorkspaceKits extends ConsumerWidget {
  const WorkspaceKits({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final lang = s.languageCode;
    final value = ref.watch(getProvider(Duties.path));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('work.nav.kits'))),
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () => ref.refresh(getProvider(Duties.path).future),
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(Duties.path)),
          builder: (raw) {
            final programmes = [for (final g in Workspace.programmes(Duties.groups(raw))) if (Workspace.kitCount(g) > 0) g];
            if (programmes.isEmpty) {
              return ListView(padding: const EdgeInsets.fromLTRB(16, 48, 16, 28), children: [Quiet(icon: Icons.inventory_2_outlined, text: s.t('work.kit.none'), hint: s.t('work.kit.noneHint'))]);
            }
            return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 28), children: [
              for (final g in programmes) ...[
                Padding(
                  padding: const EdgeInsets.only(top: 12, bottom: 10),
                  child: InkWell(
                    onTap: () => context.push('/my-programs/${g.str('id')}?part=kits'),
                    child: Row(children: [
                      Expanded(child: Text(Duties.title(g, lang), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.3))),
                      const SizedBox(width: 8),
                      Tag(s.t('duties.phase.${g.str('phase')}'), background: stateColors(g.str('phase')).$1, foreground: stateColors(g.str('phase')).$2),
                    ]),
                  ),
                ),
                for (final kit in Workspace.kits(g)) Padding(padding: const EdgeInsets.only(bottom: 10), child: KitTile(kit: kit, programId: g.obj('program')?.str('id') ?? '')),
                if (Workspace.hasCourse(g)) Padding(padding: const EdgeInsets.only(bottom: 10), child: CourseKitTile(programId: g.obj('program')?.str('id') ?? '')),
              ],
              const SizedBox(height: 8),
              const KitOwnershipNote(),
            ]);
          },
        ),
      ),
    );
  }
}

/// What a training kit is for and what it holds, as its trainer reads about it before a session. The files themselves
/// open in the centre's protected reader (they are never downloaded), which this page leads to.
class KitInfoScreen extends ConsumerWidget {
  const KitInfoScreen({super.key, required this.kitId, this.programId = ''});

  final String kitId;
  final String programId;

  /// The protected reader of the kit, on the dashboard.
  static Uri readerUri(String kitId) => Uri.parse('${AppConfig.apiUrl.replaceFirst(RegExp(r'/api/v1/?$'), '')}/admin/teaching/kits/$kitId');

  /// The lines of a kit's objectives, however the kit keeps them (a list of lines, of texts in two languages, or one text).
  static List<String> objectives(Object? raw, String lang) {
    final lines = switch (raw) {
      final List list => [for (final e in list) e is Map ? (e[lang] ?? e['text'] ?? e['ar'] ?? e['en'] ?? '').toString() : e.toString()],
      final String text => text.split('\n'),
      _ => const <String>[],
    };
    return [for (final l in lines) if (l.trim().isNotEmpty) l.trim()];
  }

  /// A file's size to read: KB under a megabyte, else MB with one decimal.
  static String size(num bytes) => bytes <= 0 ? '' : (bytes < 1024 * 1024 ? '${(bytes / 1024).ceil()} KB' : '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB');

  static IconData icon(String kind) => switch (kind) {
        'pptx' || 'ppt' || 'deck' || 'slides' => Icons.slideshow_outlined,
        'pdf' => Icons.picture_as_pdf_outlined,
        'video' || 'mp4' => Icons.play_circle_outline,
        'audio' || 'mp3' => Icons.headphones_outlined,
        'image' || 'png' || 'jpg' => Icons.image_outlined,
        'xlsx' || 'xls' || 'sheet' => Icons.table_chart_outlined,
        _ => Icons.description_outlined,
      };

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final lang = s.languageCode;
    final path = Workspace.kitPath(kitId, programId);
    final value = ref.watch(getProvider(path));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('work.kit.title'))),
      body: AsyncView(
        value: value,
        onRetry: () => ref.invalidate(getProvider(path)),
        builder: (raw) {
          final kit = raw is Map ? (Map<String, dynamic>.from(raw).obj('data') ?? const <String, dynamic>{}) : const <String, dynamic>{};
          final files = kit.list('files');
          final goals = objectives(kit['objectives'], lang);
          final about = kit.str(lang == 'en' ? 'description_en' : 'description_ar').isNotEmpty ? kit.str(lang == 'en' ? 'description_en' : 'description_ar') : kit.str('description_ar');
          final hours = kit.number('hours');
          return ListView(padding: const EdgeInsets.fromLTRB(16, 4, 16, 32), children: [
            Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(24)),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Wrap(spacing: 6, runSpacing: 6, children: [
                  if (kit.str('code').isNotEmpty) Tag(kit.str('code'), background: Colors.white.withValues(alpha: .14), foreground: Colors.white, latin: true),
                  if (kit.number('version') > 0) Tag('${s.t('work.kit.version')} ${kit.number('version')}', background: Colors.white.withValues(alpha: .14), foreground: Colors.white),
                  if (kit.flag('acknowledged')) Tag(s.t('work.kit.acknowledged'), background: AppColors.gold500, foreground: AppColors.navy950, icon: Icons.verified_outlined),
                ]),
                const SizedBox(height: 12),
                Text(kit.str(lang == 'en' ? 'title_en' : 'title_ar'), style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800, height: 1.3)),
                const SizedBox(height: 8),
                Text([if (hours > 0) hoursLabel(s, hours), s.count('work.kit.files', files.length)].join(' · '), style: TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w700, fontSize: 13)),
              ]),
            ),
            const SizedBox(height: 14),
            SizedBox(
              width: double.infinity,
              child: FilledButton.icon(
                key: const ValueKey('kit-open'),
                style: FilledButton.styleFrom(backgroundColor: AppColors.navy900, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(vertical: 13)),
                onPressed: () => launchUrl(readerUri(kitId), mode: LaunchMode.externalApplication),
                icon: const Icon(Icons.lock_open_outlined, size: 19),
                label: Text(s.t('work.kit.open'), style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ),
            const SizedBox(height: 6),
            Text(s.t('work.kit.openHint'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, fontSize: 12, height: 1.5)),
            if (about.isNotEmpty) ...[
              const SizedBox(height: 20),
              Heading(s.t('work.kit.about')),
              Surface(child: Text(about, style: TextStyle(color: AppColors.navy900, height: 1.7, fontSize: 13.5))),
            ],
            if (goals.isNotEmpty) ...[
              const SizedBox(height: 20),
              Heading(s.t('work.kit.objectives'), count: goals.length),
              Surface(
                child: Column(children: [
                  for (final (i, goal) in goals.indexed)
                    Padding(
                      padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Padding(padding: const EdgeInsets.only(top: 3), child: Icon(Icons.check_circle_outline, size: 16, color: AppColors.gold700)),
                        const SizedBox(width: 9),
                        Expanded(child: Text(goal, style: TextStyle(color: AppColors.navy900, height: 1.6, fontSize: 13.5))),
                      ]),
                    ),
                ]),
              ),
            ],
            const SizedBox(height: 20),
            Heading(s.t('work.kit.contents'), count: files.isEmpty ? null : files.length),
            if (files.isEmpty)
              Quiet(icon: Icons.folder_open_outlined, text: s.t('work.kit.empty'))
            else
              Surface(
                padding: EdgeInsets.zero,
                child: Column(children: [
                  for (final (i, f) in files.indexed) ...[
                    if (i > 0) Divider(height: 1, color: AppColors.navy100),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 11),
                      child: Row(children: [
                        Container(
                          width: 38,
                          height: 38,
                          decoration: BoxDecoration(color: AppColors.ivory, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.navy100)),
                          child: Icon(icon(f.str('kind')), size: 19, color: AppColors.gold700),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                            Text(f.str('name'), maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.navy900, fontSize: 13.5, height: 1.35)),
                            Text([f.str('kind').toUpperCase(), size(f.number('size'))].where((e) => e.isNotEmpty).join(' · '), textDirection: TextDirection.ltr, style: const TextStyle(color: AppColors.muted, fontSize: 11.5)),
                          ]),
                        ),
                        if (f.flag('presentable')) Tag(s.t('work.kit.presentable'), background: AppColors.gold100, foreground: AppColors.gold700),
                      ]),
                    ),
                  ],
                ]),
              ),
            const SizedBox(height: 16),
            Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Padding(padding: EdgeInsets.only(top: 2), child: Icon(Icons.verified_user_outlined, size: 15, color: AppColors.muted)),
              const SizedBox(width: 8),
              Expanded(child: Text((kit.obj('ownership')?.str(lang == 'en' ? 'en' : 'ar') ?? '').isNotEmpty ? kit.obj('ownership')!.str(lang == 'en' ? 'en' : 'ar') : s.t('work.kit.ownership'), style: const TextStyle(color: AppColors.muted, fontSize: 12, height: 1.6))),
            ]),
          ]);
        },
      ),
    );
  }
}
