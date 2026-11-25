import 'dart:async';

import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../duties/duties_model.dart';
import 'workspace.dart';

/// «رمز الحضور»: the code of a session on the supervisor's phone, for the trainees to scan from their own app. The server
/// changes the code every few seconds, so the screen asks for the next one as each runs out, and shows who has come.
class SessionQrScreen extends ConsumerStatefulWidget {
  const SessionQrScreen({super.key, required this.sessionId, this.fromSheet = false});

  final String sessionId;

  /// Opened from the attendance sheet: going to the sheet is going back to it.
  final bool fromSheet;

  @override
  ConsumerState<SessionQrScreen> createState() => _SessionQrScreenState();
}

class _SessionQrScreenState extends ConsumerState<SessionQrScreen> {
  Json? _code;
  String? _error;
  Timer? _next;
  Timer? _tick;
  Timer? _count;
  int _left = 0;

  @override
  void initState() {
    super.initState();
    _load();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted && _code != null) setState(() => _left = Workspace.secondsLeft(_code!.date('expires_at'), DateTime.now()));
    });
    // Who has come changes as trainees scan: the count under the code follows.
    _count = Timer.periodic(const Duration(seconds: 12), (_) {
      if (mounted) ref.invalidate(getProvider(Workspace.attendancePath(widget.sessionId)));
    });
  }

  Future<void> _load() async {
    _next?.cancel();
    try {
      final raw = await ref.read(apiProvider).get(Workspace.qrPath(widget.sessionId));
      if (!mounted) return;
      final data = raw is Map ? Map<String, dynamic>.from(raw).obj('data') : null;
      if (data == null || data.str('payload').isEmpty) throw ApiException(context.s.t('common.error'));
      final expires = data.date('expires_at');
      setState(() {
        _code = data;
        _error = null;
        _left = Workspace.secondsLeft(expires, DateTime.now());
      });
      // Ask for the next code just after this one runs out (at least every half minute, whatever the clocks say).
      final wait = expires == null ? const Duration(seconds: 20) : expires.difference(DateTime.now()) + const Duration(milliseconds: 400);
      _next = Timer(wait < const Duration(seconds: 1) ? const Duration(seconds: 1) : (wait > const Duration(seconds: 30) ? const Duration(seconds: 30) : wait), _load);
    } catch (e) {
      if (!mounted) return;
      setState(() => _error = ApiException.from(e).message);
      // A code already on screen keeps being tried again; with none, the person retries by hand.
      if (_code != null) _next = Timer(const Duration(seconds: 5), _load);
    }
  }

  @override
  void dispose() {
    _next?.cancel();
    _tick?.cancel();
    _count?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final code = _code;
    final session = code?.obj('session');
    final slot = Workspace.slot(Duties.groups(ref.watch(getProvider(Duties.path)).value), widget.sessionId);
    final sheet = ref.watch(getProvider(Workspace.attendancePath(widget.sessionId))).value;
    final counts = sheet is Map ? Map<String, dynamic>.from(sheet).obj('data') : null;
    final rotation = (code?.number('rotation_seconds') ?? 0).toInt();

    return Scaffold(
      backgroundColor: AppColors.navy950,
      appBar: AppBar(
        backgroundColor: AppColors.navy950,
        foregroundColor: Colors.white,
        title: Text(s.t('work.act.code'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
      ),
      body: SafeArea(
        child: ListView(padding: const EdgeInsets.fromLTRB(24, 8, 24, 28), children: [
          if (slot != null) Text(Duties.title(slot.group, lang), textAlign: TextAlign.center, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800, height: 1.3)),
          if (session != null)
            Padding(
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                [if (session.str('title').isNotEmpty) session.str('title'), '${fmt.time(session.date('starts_at'))} – ${fmt.time(session.date('ends_at'))}'].join(' · '),
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white70, fontSize: 13),
              ),
            ),
          const SizedBox(height: 20),
          Center(
            child: Container(
              padding: const EdgeInsets.all(18),
              decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(28)),
              child: SizedBox(
                width: 268,
                height: 268,
                child: code == null
                    ? Center(
                        child: _error == null
                            ? CircularProgressIndicator(color: AppColors.navy900)
                            : Column(mainAxisSize: MainAxisSize.min, children: [
                                const Icon(Icons.error_outline, color: AppColors.danger, size: 36),
                                const SizedBox(height: 10),
                                Text(_error!, textAlign: TextAlign.center, style: TextStyle(color: AppColors.navy900, fontWeight: FontWeight.w700, height: 1.5)),
                                const SizedBox(height: 10),
                                OutlinedButton(onPressed: _load, child: Text(s.t('common.retry'))),
                              ]),
                      )
                    : QrImageView(
                        key: const ValueKey('session-code'),
                        data: code.str('payload'),
                        size: 268,
                        padding: EdgeInsets.zero,
                        eyeStyle: QrEyeStyle(eyeShape: QrEyeShape.square, color: AppColors.navy950),
                        dataModuleStyle: QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: AppColors.navy950),
                      ),
              ),
            ),
          ),
          if (code != null) ...[
            const SizedBox(height: 16),
            Center(
              child: SizedBox(
                width: 220,
                child: ClipRRect(
                  borderRadius: BorderRadius.circular(99),
                  child: LinearProgressIndicator(
                    value: rotation > 0 ? (_left / rotation).clamp(0.0, 1.0).toDouble() : null,
                    minHeight: 5,
                    backgroundColor: Colors.white.withValues(alpha: .16),
                    valueColor: AlwaysStoppedAnimation(AppColors.gold300),
                  ),
                ),
              ),
            ),
            const SizedBox(height: 8),
            Text(s.t('work.qr.renews').replaceFirst('{n}', fmt.number(_left)), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white70, fontSize: 12)),
          ],
          const SizedBox(height: 18),
          Text(s.t('work.qr.hint'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 14, height: 1.6)),
          if (counts != null) ...[
            const SizedBox(height: 18),
            Center(
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(99), border: Border.all(color: Colors.white.withValues(alpha: .2))),
                child: Row(mainAxisSize: MainAxisSize.min, children: [
                  Icon(Icons.how_to_reg_outlined, size: 18, color: AppColors.gold300),
                  const SizedBox(width: 8),
                  Text(s.t('work.qr.present'), style: const TextStyle(color: Colors.white70, fontSize: 13, fontWeight: FontWeight.w600)),
                  const SizedBox(width: 10),
                  Text('${fmt.number(counts.number('present'))} / ${fmt.number(counts.number('expected'))}', textDirection: TextDirection.ltr, style: TextStyle(color: AppColors.gold300, fontSize: 17, fontWeight: FontWeight.w800)),
                ]),
              ),
            ),
          ],
          const SizedBox(height: 22),
          Row(children: [
            Expanded(
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: BorderSide(color: Colors.white.withValues(alpha: .45)), padding: const EdgeInsets.symmetric(vertical: 12)),
                onPressed: () => widget.fromSheet ? context.pop() : context.pushReplacement('/roll-call/${widget.sessionId}'),
                icon: const Icon(Icons.fact_check_outlined, size: 18),
                label: Text(s.t('work.act.sheet'), style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: OutlinedButton.icon(
                style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: BorderSide(color: Colors.white.withValues(alpha: .45)), padding: const EdgeInsets.symmetric(vertical: 12)),
                onPressed: () => context.pushReplacement('/staff-scan/${widget.sessionId}${widget.fromSheet ? '?from=sheet' : ''}'),
                icon: const Icon(Icons.qr_code_scanner, size: 18),
                label: Text(s.t('work.act.scan'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ),
          ]),
        ]),
      ),
    );
  }
}

/// «مسح رمز متدرب»: the supervisor scans the code a trainee shows from their own app («رمز حضوري»). The first scan of a
/// trainee records their entry, the next one their leaving; the camera stays open for the next person in the line.
class StaffScanScreen extends ConsumerStatefulWidget {
  const StaffScanScreen({super.key, required this.sessionId, this.fromSheet = false});

  final String sessionId;

  /// Opened from the attendance sheet: going to the sheet is going back to it.
  final bool fromSheet;

  @override
  ConsumerState<StaffScanScreen> createState() => _StaffScanScreenState();
}

class _StaffScanScreenState extends ConsumerState<StaffScanScreen> {
  final _controller = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates, formats: const [BarcodeFormat.qrCode]);
  bool _busy = false;
  int _recorded = 0;
  Timer? _clear;
  ({bool ok, String message})? _result;

  Future<void> _onDetect(BarcodeCapture capture) async {
    final value = capture.barcodes.firstOrNull?.rawValue;
    if (_busy || value == null) return;
    if (!Workspace.personalCode(value)) {
      _show((ok: false, message: context.s.t('work.scan.notPersonal')));
      return;
    }
    setState(() => _busy = true);
    try {
      final raw = await ref.read(apiProvider).post(Workspace.staffScanPath(widget.sessionId), {'payload': value});
      if (!mounted) return;
      final data = raw is Map ? (Map<String, dynamic>.from(raw).obj('data') ?? const <String, dynamic>{}) : const <String, dynamic>{};
      _recorded++;
      _show((ok: true, message: context.s.t(data.str('action') == 'check_out' ? 'work.scan.out' : 'work.scan.in').replaceFirst('{name}', data.str('name'))));
      ref.invalidate(getProvider(Workspace.attendancePath(widget.sessionId)));
      ref.invalidate(getProvider(Duties.path));
    } catch (e) {
      if (mounted) _show((ok: false, message: ApiException.from(e).message));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// The answer stays long enough to be read, then the frame is free for the next code.
  void _show(({bool ok, String message}) result) {
    _clear?.cancel();
    setState(() => _result = result);
    _clear = Timer(const Duration(seconds: 4), () {
      if (mounted) setState(() => _result = null);
    });
  }

  @override
  void dispose() {
    _clear?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final result = _result;
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(backgroundColor: Colors.black, foregroundColor: Colors.white, title: Text(s.t('work.act.scan'), style: const TextStyle(color: Colors.white))),
      body: Stack(children: [
        MobileScanner(controller: _controller, onDetect: _onDetect),
        Center(
          child: Container(
            width: 250,
            height: 250,
            decoration: BoxDecoration(border: Border.all(color: result == null ? AppColors.gold500 : (result.ok ? AppColors.success : AppColors.danger), width: 3), borderRadius: BorderRadius.circular(28)),
            child: _busy ? const Center(child: CircularProgressIndicator(color: Colors.white)) : null,
          ),
        ),
        PositionedDirectional(
          start: 20,
          end: 20,
          bottom: 28,
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            if (result == null)
              Text(s.t('work.scan.hint'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 15, height: 1.5))
            else
              Container(
                key: const ValueKey('scan-result'),
                width: double.infinity,
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20)),
                child: Row(children: [
                  Icon(result.ok ? Icons.verified : Icons.error_outline, size: 34, color: result.ok ? AppColors.success : AppColors.danger),
                  const SizedBox(width: 12),
                  Expanded(child: Text(result.message, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.4))),
                ]),
              ),
            const SizedBox(height: 14),
            Row(children: [
              if (_recorded > 0)
                Expanded(child: Text(s.t('work.scan.count').replaceFirst('{n}', Fmt(s.languageCode).number(_recorded)), style: const TextStyle(color: Colors.white70, fontWeight: FontWeight.w700)))
              else
                const Spacer(),
              FilledButton.icon(
                style: FilledButton.styleFrom(backgroundColor: AppColors.gold500, foregroundColor: AppColors.navy950),
                onPressed: () => widget.fromSheet ? context.pop() : context.pushReplacement('/roll-call/${widget.sessionId}'),
                icon: const Icon(Icons.fact_check_outlined, size: 18),
                label: Text(s.t('work.act.sheet'), style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ]),
          ]),
        ),
      ]),
    );
  }
}
