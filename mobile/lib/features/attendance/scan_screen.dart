import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/api/api_client.dart';
import '../../core/biometric.dart';
import '../../core/l10n/strings.dart';
import '../../core/location/location_service.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';

/// QR attendance: scanning the trainer's rotating code checks the participant in (first scan)
/// or out (second scan). The server validates the time-window signature, and the phone's position is sent
/// with the code so attendance is only recorded at the training venue.
class ScanScreen extends ConsumerStatefulWidget {
  const ScanScreen({super.key, this.biometric = false});

  /// The program asks for the phone's security check before the camera opens.
  final bool biometric;

  @override
  ConsumerState<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends ConsumerState<ScanScreen> {
  final _controller = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates, formats: const [BarcodeFormat.qrCode]);
  bool _busy = false;
  late bool _verified = !widget.biometric;   // true once the phone's security check passed (or none is needed)
  bool _passed = false;   // the security check passed during this visit: the server is told
  bool _verifying = false;
  String? _verifyMessage;
  bool _locating = false;
  LocationIssue? _issue;
  ({bool ok, String message, bool already})? _result;
  bool _exiting = false;   // the participant is already present: the next scan (or the exit button) records the exit
  String? _lastCode;

  @override
  void initState() {
    super.initState();
    if (!_verified) WidgetsBinding.instance.addPostFrameCallback((_) => _verify());
  }

  /// Fingerprint / face / phone lock. The camera opens only after it succeeds.
  Future<bool> _verify() async {
    if (_verifying || !mounted) return _verified;
    final s = context.s;
    setState(() {
      _verifying = true;
      _verifyMessage = null;
    });
    final result = await ref.read(biometricServiceProvider).authenticate(s.t('bio.checkinReason'));
    if (!mounted) return false;
    setState(() {
      _verifying = false;
      _verified = result == BiometricResult.success;
      if (_verified) _passed = true;
      _verifyMessage = _verified ? null : s.t(result == BiometricResult.unavailable ? 'bio.unavailable' : result == BiometricResult.lockedOut ? 'lock.lockedOut' : 'lock.failed');
    });
    return _verified;
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    final value = capture.barcodes.firstOrNull?.rawValue;
    if (_busy || value == null || !value.startsWith('TEDC1.')) return;
    await _submit(value);
  }

  Future<void> _exit() {
    final code = _lastCode;
    if (code == null) return Future.value();
    setState(() => _result = null);
    return _submit(code, intent: 'check_out');
  }

  Future<void> _submit(String value, {bool retried = false, String? intent}) async {
    _lastCode = value;
    intent ??= _exiting ? 'check_out' : 'check_in';
    setState(() {
      _busy = true;
      _locating = true;
      _issue = null;
    });
    try {
      // Attendance must happen at the venue: read the position first and let the server compare it with the room.
      final place = await LocationService.current();
      if (!mounted) return;
      setState(() {
        _locating = false;
        _issue = place.issue;
      });
      final res = await ref.read(apiProvider).post('/me/attendance/scan', {'payload': value, 'intent': intent, if (_passed) 'biometric': true, ...?place.fix?.toJson()});
      if (!mounted) return;
      final already = res['data']['action'] == 'already_present';
      final verified = res['data']['attendance']?['location_status'] == 'verified';
      final message = res['data']['message'].toString();
      // Already present: nothing was recorded; from now on the only thing left to do is to leave.
      _exiting = already;
      setState(() => _result = (ok: true, message: !already && verified ? '$message\n${context.s.t('scan.verifiedHere')}' : message, already: already));
      ref.invalidate(getProvider('/me/registrations'));
      ref.invalidate(getProvider('/me/home'));
    } catch (e) {
      if (!mounted) return;
      final error = ApiException.from(e);
      // The program asks for the phone's security check and the code was scanned before it: verify now, then send the same code again.
      if (error.code == 'biometric_required' && !retried) {
        setState(() => _busy = false);
        if (await _verify() && mounted) return _submit(value, retried: true);
        return;
      }
      // The phone knows exactly why the position is missing, which is more useful than the generic server text.
      final issue = error.code == 'location_required' ? _issue : null;
      setState(() => _result = (ok: false, message: issue != null ? context.s.t('scan.location.${issue.name}') : error.message, already: false));
    } finally {
      if (mounted) {
        try {
          await _controller.stop();
        } catch (_) {
          // The camera was already released.
        }
      }
      if (mounted) {
        setState(() {
          _busy = false;
          _locating = false;
        });
      }
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(backgroundColor: Colors.black, foregroundColor: Colors.white, title: Text(s.t('scan.title'), style: const TextStyle(color: Colors.white))),
      body: Stack(children: [
        if (_verified) MobileScanner(controller: _controller, onDetect: _onDetect),
        if (!_verified) _BiometricGate(verifying: _verifying, message: _verifyMessage, onRetry: _verify),
        if (_verified) Center(
          child: Container(
            width: 250,
            height: 250,
            decoration: BoxDecoration(border: Border.all(color: AppColors.gold500, width: 3), borderRadius: BorderRadius.circular(28)),
          ),
        ),
        if (_verified) PositionedDirectional(
          start: 24,
          end: 24,
          bottom: 40,
          child: _result == null
              ? Text(_locating ? s.t('scan.locating') : s.t('scan.hint'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 15))
              : Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(22)),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Icon(_result!.already ? Icons.info_outline : _result!.ok ? Icons.verified : Icons.error_outline, size: 48, color: _result!.already ? AppColors.warning : _result!.ok ? AppColors.success : AppColors.danger),
                    const SizedBox(height: 8),
                    Text(_result!.message, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 12),
                    if (!_result!.ok && _issue != null && _issue != LocationIssue.timeout) ...[
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton.tonalIcon(
                          onPressed: () => LocationService.openSettings(_issue!),
                          icon: const Icon(Icons.settings_outlined),
                          label: Text(s.t('scan.openSettings')),
                        ),
                      ),
                      const SizedBox(height: 10),
                    ],
                    if (_result!.already) ...[
                      SizedBox(
                        width: double.infinity,
                        child: FilledButton.icon(onPressed: _exit, icon: const Icon(Icons.logout), label: Text(s.t('scan.exit'))),
                      ),
                      const SizedBox(height: 10),
                    ],
                    Row(children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () {
                            setState(() => _result = null);
                            _controller.start();
                          },
                          child: const Icon(Icons.refresh),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(child: FilledButton(onPressed: () => Navigator.pop(context), child: const Icon(Icons.check))),
                    ]),
                  ]),
                ),
        ),
      ]),
    );
  }
}

/// Shown before the camera when the program asks for fingerprint / face / phone-lock verification.
class _BiometricGate extends StatelessWidget {
  const _BiometricGate({required this.verifying, required this.message, required this.onRetry});

  final bool verifying;
  final String? message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Container(
      decoration: const BoxDecoration(gradient: AppColors.navyGradient),
      alignment: Alignment.center,
      padding: const EdgeInsets.all(32),
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        Container(
          width: 96,
          height: 96,
          decoration: const BoxDecoration(shape: BoxShape.circle, gradient: AppColors.goldGradient),
          child: verifying
              ? const Padding(padding: EdgeInsets.all(30), child: CircularProgressIndicator(strokeWidth: 3, color: AppColors.navy950))
              : const Icon(Icons.fingerprint, size: 56, color: AppColors.navy950),
        ),
        const SizedBox(height: 22),
        Text(s.t('bio.checkinTitle'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 20, fontWeight: FontWeight.w800)),
        const SizedBox(height: 8),
        Text(message ?? s.t('bio.checkinHint'), textAlign: TextAlign.center, style: TextStyle(color: message == null ? Colors.white70 : AppColors.gold300, height: 1.5)),
        const SizedBox(height: 22),
        if (!verifying) FilledButton.icon(onPressed: onRetry, icon: const Icon(Icons.fingerprint), label: Text(s.t('bio.checkinRetry'))),
      ]),
    );
  }
}
