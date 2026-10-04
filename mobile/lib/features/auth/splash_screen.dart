import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';

/// The animated intro shown on every launch, after the system launch screen and before the app opens:
/// a gold ring draws itself around the emblem, the emblem rises and catches a sweep of light, then the name of the
/// center and the Ministry's signature appear. Tapping skips ahead; with "remove animations" switched on in the
/// system settings the final frame is shown briefly instead.
class SplashScreen extends ConsumerStatefulWidget {
  const SplashScreen({super.key});

  @override
  ConsumerState<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends ConsumerState<SplashScreen> with SingleTickerProviderStateMixin {
  /// The whole piece lasts [_total]; the last [_exit] of it is the fade-out into the app.
  static const _total = Duration(milliseconds: 3600);
  static const _holdAt = 0.9; // fraction of the timeline where the content is complete

  late final AnimationController _c = AnimationController(vsync: this, duration: _total);
  bool _started = false;
  bool _finished = false;
  bool _waiting = false; // the picture is complete but the saved session is still being read (slow network)

  /// Local base colour of the native launch screen, so the hand-over from it to this screen is invisible.
  static const _base = Color(0xFF6B102C);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_started) return;
    _started = true;
    if (MediaQuery.of(context).disableAnimations) {
      _c.value = _holdAt;
      WidgetsBinding.instance.addPostFrameCallback((_) => _leave());   // after the first frame, never while building
    } else {
      _c.animateTo(_holdAt, duration: _total * _holdAt, curve: Curves.linear).whenComplete(_leave);
    }
  }

  /// Waits for the saved session to be read (normally instant), then fades out and opens the app.
  Future<void> _leave() async {
    if (_finished || !mounted) return;
    if (ref.read(authProvider).isLoading) setState(() => _waiting = true);
    try {
      await ref.read(authProvider.future);
    } catch (_) {
      // No network and no saved profile: the router sends the user to sign in.
    }
    if (!mounted || _finished) return;
    if (_waiting) setState(() => _waiting = false);
    _finished = true;
    await _c.animateTo(1, duration: _total * (1 - _holdAt), curve: Curves.easeIn);
    if (mounted) ref.read(introDoneProvider.notifier).finish();
  }

  /// Tap: jump to the finished picture and carry on.
  void _skip() {
    if (_finished || _c.value >= _holdAt) return;
    _c.stop();
    _c.animateTo(_holdAt, duration: const Duration(milliseconds: 260), curve: Curves.easeOut).whenComplete(_leave);
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  /// A step of the timeline: 0 → 1 inside [from, to] (fractions of the whole), eased.
  double _step(double from, double to, [Curve curve = Curves.easeOutCubic]) {
    final t = ((_c.value - from) / (to - from)).clamp(0.0, 1.0);
    return curve.transform(t);
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: _skip,
      child: Scaffold(
        backgroundColor: _base,
        body: AnimatedBuilder(
          animation: _c,
          builder: (context, _) {
            final glow = _step(0.0, 0.25, Curves.easeOut);
            final ring = _step(0.04, 0.40, Curves.easeInOutCubic);
            final ringRest = 1 - 0.55 * _step(0.46, 0.66, Curves.easeInOut);
            final emblem = _step(0.10, 0.38);
            final shine = _step(0.42, 0.62, Curves.easeInOut);
            final name = _step(0.50, 0.70);
            final rule = _step(0.60, 0.78, Curves.easeInOutCubic);
            final subtitle = _step(0.66, 0.82);
            final signature = _step(0.76, 0.92);
            final leave = 1 - _step(_holdAt, 1.0, Curves.easeIn);

            return Stack(fit: StackFit.expand, children: [
              // Deep maroon with a slow-breathing light behind the emblem.
              DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: const Alignment(0, -0.18),
                    radius: 0.95 + 0.06 * math.sin(_c.value * math.pi * 2),
                    colors: [Color.lerp(_base, AppColors.navy900, glow)!, Color.lerp(_base, AppColors.navy950, glow)!],
                  ),
                ),
              ),
              Opacity(
                opacity: leave,
                child: Stack(fit: StackFit.expand, children: [
                  Align(
                    alignment: const Alignment(0, -0.12),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      SizedBox(
                        width: 210,
                        height: 210,
                        child: Stack(alignment: Alignment.center, children: [
                          CustomPaint(size: const Size.square(210), painter: _RingPainter(progress: ring, opacity: ringRest)),
                          Opacity(
                            opacity: emblem,
                            child: Transform.translate(
                              offset: Offset(0, 14 * (1 - emblem)),
                              child: Transform.scale(scale: 0.86 + 0.14 * emblem, child: _ShinyEmblem(size: 132, shine: shine)),
                            ),
                          ),
                        ]),
                      ),
                      const SizedBox(height: 30),
                      Opacity(
                        opacity: name,
                        child: Transform.translate(
                          offset: Offset(0, 16 * (1 - name)),
                          child: const Text(
                            'مركز التدريب والتطوير',
                            textAlign: TextAlign.center,
                            style: TextStyle(fontFamily: 'Tajawal', fontSize: 30, fontWeight: FontWeight.w800, color: Colors.white, height: 1.3),   // no letter-spacing: it would break Arabic letter joining
                          ),
                        ),
                      ),
                      const SizedBox(height: 12),
                      Container(
                        width: 110 * rule,
                        height: 2.5,
                        decoration: BoxDecoration(borderRadius: BorderRadius.circular(2), gradient: AppColors.goldGradient),
                      ),
                      const SizedBox(height: 12),
                      Opacity(
                        opacity: subtitle,
                        child: Transform.translate(
                          offset: Offset(0, 8 * (1 - subtitle)),
                          child: Text(
                            'TRAINING & DEVELOPMENT CENTER',
                            textDirection: TextDirection.ltr,
                            style: TextStyle(fontFamily: 'Tajawal', fontSize: 11.5, fontWeight: FontWeight.w600, letterSpacing: 2.6, color: AppColors.gold300.withValues(alpha: .95)),
                          ),
                        ),
                      ),
                      if (_waiting) const Padding(padding: EdgeInsets.only(top: 22), child: SizedBox(width: 22, height: 22, child: CircularProgressIndicator(color: AppColors.gold500, strokeWidth: 2))),
                    ]),
                  ),
                  // The Ministry's signature, in white, at the foot of the screen.
                  Align(
                    alignment: Alignment.bottomCenter,
                    child: SafeArea(
                      child: Padding(
                        padding: const EdgeInsets.only(bottom: 34),
                        child: Opacity(
                          opacity: signature,
                          child: Transform.translate(
                            offset: Offset(0, 10 * (1 - signature)),
                            child: ColorFiltered(
                              colorFilter: const ColorFilter.mode(Colors.white, BlendMode.srcIn),
                              child: Image.asset('assets/brand/ministry-lockup.webp', width: 236, filterQuality: FilterQuality.high, semanticLabel: 'وزارة التربية والتعليم والتعليم العالي'),
                            ),
                          ),
                        ),
                      ),
                    ),
                  ),
                ]),
              ),
            ]);
          },
        ),
      ),
    );
  }
}

/// The white emblem with a band of warm light passing over it.
class _ShinyEmblem extends StatelessWidget {
  const _ShinyEmblem({required this.size, required this.shine});

  final double size;
  final double shine; // 0 → 1 across the sweep

  @override
  Widget build(BuildContext context) {
    final image = Image.asset('assets/brand/emblem-white.png', width: size, height: size, filterQuality: FilterQuality.high, semanticLabel: 'Ministry of Education and Higher Education');
    if (shine <= 0 || shine >= 1) return image;
    final x = -1.6 + 3.2 * shine;
    return ShaderMask(
      blendMode: BlendMode.srcATop,
      shaderCallback: (bounds) => LinearGradient(
        begin: Alignment(x - 0.7, -0.6),
        end: Alignment(x + 0.7, 0.6),
        colors: [Colors.white.withValues(alpha: 0), AppColors.gold300, Colors.white.withValues(alpha: 0)],
        stops: const [0.0, 0.5, 1.0],
      ).createShader(bounds),
      child: image,
    );
  }
}

/// A fine gold ring that draws itself clockwise from the top.
class _RingPainter extends CustomPainter {
  _RingPainter({required this.progress, required this.opacity});

  final double progress;
  final double opacity;

  @override
  void paint(Canvas canvas, Size size) {
    if (progress <= 0) return;
    final rect = Offset.zero & size;
    final inset = rect.deflate(3);
    final paint = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.2
      ..strokeCap = StrokeCap.round
      ..shader = SweepGradient(
        transform: const GradientRotation(-math.pi / 2),
        colors: [AppColors.gold300.withValues(alpha: opacity), AppColors.gold500.withValues(alpha: opacity), const Color(0xFFE9DFC9).withValues(alpha: opacity), AppColors.gold300.withValues(alpha: opacity)],
      ).createShader(rect);
    canvas.drawArc(inset, -math.pi / 2, 2 * math.pi * progress, false, paint);
    // A soft bead at the head of the line while it is drawing.
    if (progress < 1) {
      final angle = -math.pi / 2 + 2 * math.pi * progress;
      final head = Offset(size.width / 2 + (size.width / 2 - 3) * math.cos(angle), size.height / 2 + (size.height / 2 - 3) * math.sin(angle));
      canvas.drawCircle(head, 4.5, Paint()..color = const Color(0xFFE9DFC9).withValues(alpha: 0.9)..maskFilter = const MaskFilter.blur(BlurStyle.normal, 3));
    }
  }

  @override
  bool shouldRepaint(covariant _RingPainter old) => old.progress != progress || old.opacity != opacity;
}
