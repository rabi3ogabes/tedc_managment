import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/location/location_service.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';

/// QR attendance: scanning the trainer's rotating code checks the participant in (first scan)
/// or out (second scan). The server validates the time-window signature, and the phone's position is sent
/// with the code so attendance is only recorded at the training venue.
class ScanScreen extends ConsumerStatefulWidget {
  const ScanScreen({super.key});

  @override
  ConsumerState<ScanScreen> createState() => _ScanScreenState();
}

class _ScanScreenState extends ConsumerState<ScanScreen> {
  final _controller = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates, formats: const [BarcodeFormat.qrCode]);
  bool _busy = false;
  bool _locating = false;
  LocationIssue? _issue;
  ({bool ok, String message})? _result;

  Future<void> _onDetect(BarcodeCapture capture) async {
    final value = capture.barcodes.firstOrNull?.rawValue;
    if (_busy || value == null || !value.startsWith('TEDC1.')) return;
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
      final res = await ref.read(apiProvider).post('/me/attendance/scan', {'payload': value, ...?place.fix?.toJson()});
      if (!mounted) return;
      final verified = res['data']['attendance']?['location_status'] == 'verified';
      final message = res['data']['message'].toString();
      setState(() => _result = (ok: true, message: verified ? '$message\n${context.s.t('scan.verifiedHere')}' : message));
      ref.invalidate(getProvider('/me/registrations'));
      ref.invalidate(getProvider('/me/home'));
    } catch (e) {
      if (!mounted) return;
      final error = ApiException.from(e);
      // The phone knows exactly why the position is missing, which is more useful than the generic server text.
      final issue = error.code == 'location_required' ? _issue : null;
      setState(() => _result = (ok: false, message: issue != null ? context.s.t('scan.location.${issue.name}') : error.message));
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
        MobileScanner(controller: _controller, onDetect: _onDetect),
        Center(
          child: Container(
            width: 250,
            height: 250,
            decoration: BoxDecoration(border: Border.all(color: AppColors.gold500, width: 3), borderRadius: BorderRadius.circular(28)),
          ),
        ),
        PositionedDirectional(
          start: 24,
          end: 24,
          bottom: 40,
          child: _result == null
              ? Text(_locating ? s.t('scan.locating') : s.t('scan.hint'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 15))
              : Container(
                  padding: const EdgeInsets.all(18),
                  decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(22)),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    Icon(_result!.ok ? Icons.verified : Icons.error_outline, size: 48, color: _result!.ok ? AppColors.success : AppColors.danger),
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
