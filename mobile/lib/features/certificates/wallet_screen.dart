import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';
import '../../core/widgets/widgets.dart';

/// Certificate wallet: every issued certificate as a premium card with its verification QR.
class WalletScreen extends ConsumerWidget {
  const WalletScreen({super.key});

  static const path = '/me/certificates';
  static const trainerPath = '/me/trainer-certificates';

  Future<void> _open(BuildContext context, WidgetRef ref, Json cert) async {
    try {
      final base = cert.str('kind') == 'trainer' ? 'trainer-certificates' : 'certificates';
      final bytes = await ref.read(apiProvider).bytes('/$base/${cert.str('id')}/download');
      final dir = await getTemporaryDirectory();
      final file = File('${dir.path}/${cert.str('certificate_no')}.pdf');
      await file.writeAsBytes(bytes, flush: true);
      await OpenFilex.open(file.path, type: 'application/pdf');
    } catch (e) {
      if (context.mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  /// Both lists as one value: loading while either loads, an error only when both fail.
  AsyncValue<dynamic> _combined(WidgetRef ref) {
    final a = ref.watch(getProvider(path));
    final b = ref.watch(getProvider(trainerPath));
    if (a.isLoading && b.isLoading) return const AsyncLoading();
    if (a.hasError && b.hasError) return AsyncError(a.error!, a.stackTrace ?? StackTrace.empty);
    List<Map<String, dynamic>> rows(AsyncValue<dynamic> v) => v.value == null ? const [] : Map<String, dynamic>.from(v.value as Map).list('data');
    return AsyncData({'data': [...rows(b), ...rows(a)]});
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);

    return Scaffold(
      appBar: AppBar(title: Text(s.t('certs.title'))),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(getProvider(trainerPath));
          await ref.refresh(getProvider(path).future).catchError((_) => <String, dynamic>{});
        },
        child: AsyncView(
          // Trainee and trainer certificates side by side; an account may have only one of the two.
          value: _combined(ref),
          onRetry: () {
            ref.invalidate(getProvider(path));
            ref.invalidate(getProvider(trainerPath));
          },
          builder: (raw) {
            final items = Map<String, dynamic>.from(raw as Map).list('data');
            if (items.isEmpty) return ListView(children: const [EmptyView(icon: Icons.workspace_premium_outlined)]);
            return ListView.separated(
              padding: const EdgeInsets.all(16),
              itemCount: items.length,
              separatorBuilder: (_, _) => const SizedBox(height: 16),
              itemBuilder: (_, i) {
                final c = items[i];
                final trainer = c.str('kind') == 'trainer';
                final locked = !c.flag('downloadable');
                return Container(
                  clipBehavior: Clip.antiAlias,
                  decoration: BoxDecoration(
                    gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [AppColors.navy900, AppColors.navy800, AppColors.navy700]),
                    borderRadius: BorderRadius.circular(26),
                    boxShadow: [BoxShadow(color: AppColors.navy900.withValues(alpha: .25), blurRadius: 24, offset: const Offset(0, 10))],
                  ),
                  child: Stack(children: [
                    const Positioned.fill(child: DotPattern(opacity: .14)),
                    Padding(
                      padding: const EdgeInsets.all(20),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [const OfficialEmblem(size: 44), const Spacer(), if (locked) Icon(Icons.lock_outline, color: AppColors.gold300) else StatusChip(c.str('status'))]),
                        const SizedBox(height: 18),
                        Text(s.t(trainer ? 'certs.trainerKind' : (c.str('type') == 'attendance' ? 'certs.attendanceKind' : 'certs.traineeKind')), style: TextStyle(color: AppColors.gold300, fontSize: 12, fontWeight: FontWeight.w700)),
                        const SizedBox(height: 4),
                        Text(c.obj('program')?.str('title') ?? '', style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 4),
                        Text(
                          c.date('issued_at') == null ? '${s.t('certs.progress')}: ${fmt.number(c.number('sessions_done'))}/${fmt.number(c.number('sessions_total'))} · ${fmt.number(c.number('hours'))} ${s.t('common.hours')}' : '${fmt.number(c.number('hours'))} ${s.t('common.hours')} · ${fmt.date(c.date('issued_at'))}',
                          style: TextStyle(color: AppColors.gold300),
                        ),
                        if (locked) ...[
                          const SizedBox(height: 14),
                          Text(s.t(trainer ? 'certs.trainerLocked' : 'certs.locked'), style: const TextStyle(color: Colors.white70, fontSize: 13)),
                          if (!trainer) ...[
                            const SizedBox(height: 14),
                            FilledButton.icon(
                              onPressed: () => context.go('/training'),
                              style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
                              icon: const Icon(Icons.rate_review_outlined),
                              label: Text(s.t('certs.fillSurvey')),
                            ),
                          ],
                        ] else ...[
                        const SizedBox(height: 18),
                        Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(s.t('certs.number'), style: const TextStyle(color: Colors.white54, fontSize: 11)),
                              Text(c.str('certificate_no'), textDirection: TextDirection.ltr, style: const TextStyle(color: Colors.white, fontFamily: 'monospace')),
                            ]),
                          ),
                          Container(
                            padding: const EdgeInsets.all(6),
                            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                            child: QrImageView(data: c.str('verification_url'), size: 84, padding: EdgeInsets.zero, eyeStyle: QrEyeStyle(color: AppColors.navy900, eyeShape: QrEyeShape.square), dataModuleStyle: QrDataModuleStyle(color: AppColors.navy900, dataModuleShape: QrDataModuleShape.square)),
                          ),
                        ]),
                        const SizedBox(height: 16),
                        FilledButton.icon(
                          onPressed: () => _open(context, ref, c),
                          style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
                          icon: const Icon(Icons.picture_as_pdf_outlined),
                          label: Text(s.t('certs.open')),
                        ),
                        ],
                      ]),
                    ),
                  ]),
                );
              },
            );
          },
        ),
      ),
    );
  }
}
