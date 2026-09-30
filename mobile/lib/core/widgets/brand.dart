import 'package:flutter/material.dart';

import '../theme/app_theme.dart';

/// The Ministry of Education and Higher Education emblem (official artwork, also the app icon) on a
/// white medallion with a soft Dune glow.
class OfficialEmblem extends StatelessWidget {
  const OfficialEmblem({super.key, this.size = 96});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      padding: EdgeInsets.all(size * .14),
      decoration: BoxDecoration(
        color: Colors.white,
        shape: BoxShape.circle,
        border: Border.all(color: AppColors.gold300, width: 2),
        boxShadow: [BoxShadow(color: AppColors.gold500.withValues(alpha: .35), blurRadius: size * .35, spreadRadius: 1)],
      ),
      child: Image.asset('assets/brand/moe-emblem.png', fit: BoxFit.contain, cacheWidth: 320, filterQuality: FilterQuality.medium, semanticLabel: 'Ministry of Education and Higher Education'),
    );
  }
}

/// Brand mark: a stylised academy portico in gold on navy.
class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 48});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: AppColors.navy900,
        borderRadius: BorderRadius.circular(size * .26),
        border: Border.all(color: AppColors.gold500.withValues(alpha: .35)),
        boxShadow: [BoxShadow(color: AppColors.gold500.withValues(alpha: .25), blurRadius: size * .4)],
      ),
      child: CustomPaint(painter: _PorticoPainter()),
    );
  }
}

class _PorticoPainter extends CustomPainter {
  @override
  void paint(Canvas canvas, Size s) {
    final gold = Paint()..color = AppColors.gold500;
    final light = Paint()..color = AppColors.gold300;
    final u = s.width / 64;
    canvas.drawPath(Path()..moveTo(32 * u, 11 * u)..lineTo(51 * u, 19.5 * u)..lineTo(51 * u, 23.5 * u)..lineTo(13 * u, 23.5 * u)..lineTo(13 * u, 19.5 * u)..close(), gold);
    for (final x in [17.5, 29.5, 41.5]) {
      canvas.drawRRect(RRect.fromLTRBR(x * u, 27 * u, (x + 5) * u, 44 * u, Radius.circular(u)), light);
    }
    canvas.drawRRect(RRect.fromLTRBR(13 * u, 46.5 * u, 51 * u, 52 * u, Radius.circular(1.5 * u)), gold);
  }

  @override
  bool shouldRepaint(covariant CustomPainter oldDelegate) => false;
}

/// Subtle gold dot grid used on dark hero surfaces.
class DotPattern extends StatelessWidget {
  const DotPattern({super.key, this.opacity = .18});

  final double opacity;

  @override
  Widget build(BuildContext context) => CustomPaint(painter: _DotsPainter(opacity), size: Size.infinite);
}

class _DotsPainter extends CustomPainter {
  _DotsPainter(this.opacity);

  final double opacity;

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()..color = AppColors.gold500.withValues(alpha: opacity);
    for (double x = 0; x < size.width; x += 22) {
      for (double y = 0; y < size.height; y += 22) {
        canvas.drawCircle(Offset(x, y), 1, paint);
      }
    }
  }

  @override
  bool shouldRepaint(covariant _DotsPainter old) => old.opacity != opacity;
}
