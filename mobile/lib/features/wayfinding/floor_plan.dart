import 'dart:math' as math;
import 'dart:ui' as ui;

import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';
import 'engine.dart';

/// The centre's floor plan as a vector drawing in the app's colours (docs/wayfinding.md): stone corridors, room cards, the
/// building's cores, stairs and lifts, the route, the visitor and the destination. It pans and zooms with the fingers, and its
/// camera keeps [focus] framed in the part of the screen that [inset] leaves visible, gliding whenever it changes.
class FloorPlanView extends StatefulWidget {
  const FloorPlanView({
    super.key,
    required this.map,
    required this.floor,
    this.route,
    this.you,
    this.target,
    this.highlight,
    this.focus,
    this.inset = EdgeInsets.zero,
    this.onTap,
  });

  final CentreMap map;
  final String floor;
  final List<Pt>? route;
  final Pt? you;
  final Pt? target;
  final String? highlight;
  final List<Pt>? focus;
  final EdgeInsets inset;
  final void Function(Pt at, Place? place)? onTap;

  @override
  State<FloorPlanView> createState() => FloorPlanViewState();
}

class FloorPlanViewState extends State<FloorPlanView> with TickerProviderStateMixin {
  final _controller = TransformationController();
  late final AnimationController _glide = AnimationController(vsync: this, duration: const Duration(milliseconds: 650));
  late final AnimationController _pulse = AnimationController(vsync: this, duration: const Duration(milliseconds: 2000))..repeat();
  Animation<Matrix4>? _anim;
  Size _size = Size.zero;
  bool follow = true;
  final _outlines = <String, Path>{};

  @override
  void initState() {
    super.initState();
    _glide.addListener(() {
      final a = _anim;
      if (a != null) _controller.value = a.value;
    });
  }

  @override
  void dispose() {
    _glide.dispose();
    _pulse.dispose();
    _controller.dispose();
    super.dispose();
  }

  @override
  void didUpdateWidget(FloorPlanView old) {
    super.didUpdateWidget(old);
    if (_key(old.focus) != _key(widget.focus) || old.floor != widget.floor || old.inset != widget.inset) {
      if (follow || widget.focus == null) WidgetsBinding.instance.addPostFrameCallback((_) => recentre());
    }
  }

  String _key(List<Pt>? f) => f?.map((p) => '${p.x.round()},${p.y.round()}').join(';') ?? '';

  /// Glides the camera so the focus (or the whole floor) fills the visible part of the screen.
  void recentre({bool animate = true}) {
    if (_size == Size.zero) return;
    final f = widget.focus;
    double x0, y0, x1, y1;
    if (f == null || f.isEmpty) {
      x0 = 0;
      y0 = 0;
      x1 = widget.map.width;
      y1 = widget.map.height;
    } else {
      x0 = f.map((p) => p.x).reduce(math.min) - 70;
      y0 = f.map((p) => p.y).reduce(math.min) - 70;
      x1 = f.map((p) => p.x).reduce(math.max) + 70;
      y1 = f.map((p) => p.y).reduce(math.max) + 70;
    }
    final aw = math.max(120.0, _size.width - widget.inset.horizontal), ah = math.max(120.0, _size.height - widget.inset.vertical);
    final sMax = aw / (widget.map.width / 3.4);
    final sMin = math.min(aw / widget.map.width, ah / widget.map.height) * 0.92;
    final s = math.min(aw / (x1 - x0), ah / (y1 - y0)).clamp(sMin, sMax).toDouble();
    final cx = (x0 + x1) / 2, cy = (y0 + y1) / 2;
    final tx = widget.inset.left + aw / 2 - cx * s, ty = widget.inset.top + ah / 2 - cy * s;
    final to = Matrix4.identity()
      ..translateByDouble(tx, ty, 0, 1)
      ..scaleByDouble(s, s, 1, 1);
    if (!animate || MediaQuery.of(context).disableAnimations) {
      _controller.value = to;
      return;
    }
    _anim = Matrix4Tween(begin: _controller.value, end: to).animate(CurvedAnimation(parent: _glide, curve: Curves.easeOutCubic));
    _glide.forward(from: 0);
  }

  Path _outline(String floor) => _outlines.putIfAbsent(floor, () => svgPath(widget.map.floor(floor).outline));

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, box) {
      final size = Size(box.maxWidth, box.maxHeight);
      if (size != _size) {
        final first = _size == Size.zero;
        _size = size;
        WidgetsBinding.instance.addPostFrameCallback((_) => recentre(animate: !first));
      }
      return Stack(children: [
        Positioned.fill(
          child: GestureDetector(
            onTapUp: widget.onTap == null
                ? null
                : (d) {
                    final m = Matrix4.inverted(_controller.value);
                    final p = MatrixUtils.transformPoint(m, d.localPosition);
                    final at = Pt(p.dx, p.dy);
                    final hit = widget.map.places.where((x) => x.floor == widget.floor && x.contains(at)).firstOrNull;
                    widget.onTap!(at, hit);
                  },
            child: InteractiveViewer(
              transformationController: _controller,
              constrained: false,
              minScale: 0.15,
              maxScale: 8,
              boundaryMargin: const EdgeInsets.all(2000),
              onInteractionStart: (_) {
                _glide.stop();
                if (follow) setState(() => follow = false);
              },
              child: SizedBox(
                width: widget.map.width,
                height: widget.map.height,
                child: AnimatedBuilder(
                  animation: Listenable.merge([_controller, _pulse]),
                  builder: (_, _) => CustomPaint(
                    size: Size(widget.map.width, widget.map.height),
                    painter: _PlanPainter(
                      map: widget.map,
                      floor: widget.floor,
                      outline: _outline(widget.floor),
                      route: widget.route,
                      you: widget.you,
                      target: widget.target,
                      highlight: widget.highlight,
                      zoom: _controller.value.getMaxScaleOnAxis(),
                      pulse: _pulse.value,
                      arabic: Directionality.of(context) == TextDirection.rtl,
                      font: Theme.of(context).textTheme.bodyMedium,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
        PositionedDirectional(
          end: 14,
          bottom: widget.inset.bottom + 14,
          child: Material(
            color: Colors.white.withValues(alpha: .94),
            elevation: 6,
            shadowColor: Colors.black26,
            borderRadius: BorderRadius.circular(18),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              _ZoomButton(icon: Icons.add, onTap: () => _zoom(1.35)),
              const SizedBox(width: 44, child: Divider(height: 1)),
              _ZoomButton(icon: Icons.remove, onTap: () => _zoom(1 / 1.35)),
              const SizedBox(width: 44, child: Divider(height: 1)),
              _ZoomButton(
                icon: Icons.my_location,
                color: follow ? AppColors.gold700 : AppColors.navy900,
                onTap: () {
                  setState(() => follow = true);
                  recentre();
                },
              ),
            ]),
          ),
        ),
      ]);
    });
  }

  void _zoom(double k) {
    final c = Offset(_size.width / 2, _size.height / 2);
    final m = _controller.value.clone()
      ..translateByDouble(0, 0, 0, 1);
    final local = MatrixUtils.transformPoint(Matrix4.inverted(m), c);
    final next = m.clone()
      ..translateByDouble(local.dx, local.dy, 0, 1)
      ..scaleByDouble(k, k, 1, 1)
      ..translateByDouble(-local.dx, -local.dy, 0, 1);
    _anim = Matrix4Tween(begin: _controller.value, end: next).animate(CurvedAnimation(parent: _glide, curve: Curves.easeOutCubic));
    _glide.forward(from: 0);
    setState(() => follow = false);
  }
}

class _ZoomButton extends StatelessWidget {
  const _ZoomButton({required this.icon, required this.onTap, this.color});
  final IconData icon;
  final VoidCallback onTap;
  final Color? color;
  @override
  Widget build(BuildContext context) => InkWell(onTap: onTap, borderRadius: BorderRadius.circular(18), child: SizedBox(width: 46, height: 46, child: Icon(icon, color: color ?? AppColors.navy900)));
}

/// An SVG path of straight segments ("M x y L x y … Z"), as the map generator writes the corridors' edge.
Path svgPath(String d) {
  final path = Path()..fillType = PathFillType.evenOdd;
  final re = RegExp(r'([MLZ])([^MLZ]*)');
  for (final m in re.allMatches(d)) {
    final nums = m.group(2)!.trim().split(RegExp(r'[\s,]+')).where((e) => e.isNotEmpty).map(double.parse).toList();
    switch (m.group(1)) {
      case 'M':
        if (nums.length >= 2) path.moveTo(nums[0], nums[1]);
      case 'L':
        for (var i = 0; i + 1 < nums.length; i += 2) {
          path.lineTo(nums[i], nums[i + 1]);
        }
      case 'Z':
        path.close();
    }
  }
  return path;
}

class _Kind {
  const _Kind(this.fill, this.stroke, this.ink, this.tag);
  final Color fill, stroke, ink, tag;
}

class _PlanPainter extends CustomPainter {
  _PlanPainter({required this.map, required this.floor, required this.outline, this.route, this.you, this.target, this.highlight, required this.zoom, required this.pulse, required this.arabic, this.font});

  final CentreMap map;
  final String floor;
  final Path outline;
  final List<Pt>? route;
  final Pt? you, target;
  final String? highlight;
  final double zoom, pulse;
  final bool arabic;

  /// The app's text style (its typeface and fallbacks), so the plan is labelled in the brand font.
  final TextStyle? font;

  _Kind _kind(String k) {
    switch (k) {
      case 'lab':
        return _Kind(AppColors.navy100.withValues(alpha: .55), AppColors.navy100, AppColors.navy900, AppColors.navy700);
      case 'meet':
        return _Kind(AppColors.gold100, AppColors.gold300, AppColors.ink, AppColors.gold700);
      case 'public':
        return _Kind(const Color(0xFFF7F4EE), AppColors.gold300, AppColors.ink, AppColors.gold700);
      case 'office':
        return _Kind(const Color(0xFFEFECE6), const Color(0xFFDDD8CF), const Color(0xFF3F3A33), const Color(0xFF6B6459));
      case 'pray':
        return const _Kind(Color(0xFFECF7F1), Color(0xFFB7E0C8), Color(0xFF14532D), Color(0xFF15803D));
      case 'wc':
        return const _Kind(Color(0xFFF1F4F8), Color(0xFFD6DDE8), Color(0xFF334155), Color(0xFF64748B));
      default:
        return _Kind(Colors.white, AppColors.navy100, AppColors.navy900, AppColors.navy700);
    }
  }

  @override
  void paint(Canvas canvas, Size size) {
    final px = 1 / zoom; // one screen pixel in plan units: markers and the route keep their size on screen
    final shadow = Paint()
      ..color = Colors.black.withValues(alpha: .10)
      ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 6);

    // The floor: corridors in warm stone with a hairline edge.
    canvas.drawPath(outline.shift(const Offset(0, 3)), shadow);
    canvas.drawPath(outline, Paint()
      ..color = AppColors.gold300.withValues(alpha: .7)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 7
      ..strokeJoin = StrokeJoin.round);
    canvas.drawPath(outline, Paint()..color = const Color(0xFFFDFBF7));

    for (final s in map.shapes.where((s) => s.floor == floor)) {
      if (s.kind == 'void' && s.points != null) {
        final p = Path()..addPolygon(s.points!.map((q) => Offset(q.x, q.y)).toList(), true);
        canvas.save();
        canvas.clipPath(p);
        final b = p.getBounds();
        canvas.drawRect(b, Paint()..color = AppColors.ivory);
        final hatch = Paint()
          ..color = AppColors.navy100
          ..strokeWidth = 3;
        for (var x = b.left - b.height; x < b.right; x += 10) {
          canvas.drawLine(Offset(x, b.bottom), Offset(x + b.height, b.top), hatch);
        }
        canvas.restore();
        canvas.drawPath(p, Paint()
          ..color = AppColors.navy100
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5);
      } else if (s.rect != null) {
        final path = _chamfered(s.rect!, s.chamfer);
        canvas.drawPath(path.shift(const Offset(0, 3)), shadow);
        canvas.drawPath(path, Paint()..shader = ui.Gradient.linear(Offset(0, s.rect!.y), Offset(0, s.rect!.y + s.rect!.h), const [Color(0xFF3B3F4A), Color(0xFF23262E)]));
      }
    }

    for (final p in map.places.where((p) => p.floor == floor)) {
      final k = _kind(p.kind);
      final shape = p.outline != null
          ? (Path()..addPolygon(p.outline!.map((q) => Offset(q.x, q.y)).toList(), true))
          : (Path()..addRRect(RRect.fromRectAndRadius(Rect.fromLTWH(p.rect.x + 2, p.rect.y + 2, p.rect.w - 4, p.rect.h - 4), const Radius.circular(10))));
      final lit = p.id == highlight;
      if (lit) {
        canvas.drawPath(shape, Paint()
          ..color = AppColors.gold500.withValues(alpha: .25 + .35 * (0.5 + 0.5 * math.sin(pulse * 2 * math.pi)))
          ..style = PaintingStyle.stroke
          ..strokeWidth = 12
          ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 6));
      }
      canvas.drawPath(shape.shift(const Offset(0, 2.5)), shadow);
      canvas.drawPath(shape, Paint()..color = k.fill);
      canvas.drawPath(shape, Paint()
        ..color = lit ? AppColors.gold700 : k.stroke
        ..style = PaintingStyle.stroke
        ..strokeWidth = lit ? 4 : 1.5);
      _label(canvas, p, k);
    }

    for (final c in map.connectors) {
      final r = c.rect[floor];
      if (r == null) continue;
      final lift = c.kind == 'lift';
      final s = math.min(math.min(r.w, r.h), lift ? 30.0 : 46.0);
      final rect = RRect.fromRectAndRadius(Rect.fromCenter(center: Offset(r.centre.x, r.centre.y), width: s, height: s), Radius.circular(s * .26));
      canvas.drawRRect(rect.shift(const Offset(0, 2)), shadow);
      canvas.drawRRect(rect, Paint()..color = lift ? AppColors.navy900 : Colors.white);
      if (!lift) {
        canvas.drawRRect(rect, Paint()
          ..color = AppColors.navy100
          ..style = PaintingStyle.stroke
          ..strokeWidth = 1.5);
      }
      _icon(canvas, lift ? Icons.elevator_outlined : Icons.stairs_outlined, Offset(r.centre.x, r.centre.y), s * .62, lift ? AppColors.gold300 : AppColors.navy800);
    }

    final r = route;
    if (r != null && r.length > 1) {
      final path = Path()..moveTo(r.first.x, r.first.y);
      for (final q in r.skip(1)) {
        path.lineTo(q.x, q.y);
      }
      canvas.drawPath(path, Paint()
        ..color = AppColors.navy700.withValues(alpha: .35)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 16 * px
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round
        ..maskFilter = MaskFilter.blur(BlurStyle.normal, 6 * px));
      canvas.drawPath(path, Paint()
        ..color = AppColors.navy800
        ..style = PaintingStyle.stroke
        ..strokeWidth = 8 * px
        ..strokeCap = StrokeCap.round
        ..strokeJoin = StrokeJoin.round);
      // The dashes flow towards the destination.
      final dash = Paint()
        ..color = AppColors.gold300
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2.6 * px
        ..strokeCap = StrokeCap.round;
      final on = 10 * px, off = 14 * px;
      for (final metric in path.computeMetrics()) {
        var d = (pulse * (on + off) * 2) % (on + off);
        while (d < metric.length) {
          canvas.drawPath(metric.extractPath(d, math.min(d + on, metric.length)), dash);
          d += on + off;
        }
      }
    }

    final y = you;
    if (y != null) {
      final c = Offset(y.x, y.y);
      canvas.drawCircle(c, (14 + 16 * pulse) * px, Paint()..color = AppColors.navy700.withValues(alpha: .28 * (1 - pulse)));
      canvas.drawCircle(c, 13 * px, Paint()..color = Colors.white);
      canvas.drawCircle(c, 9 * px, Paint()..color = AppColors.navy800);
    }
    final t = target;
    if (t != null) {
      canvas.save();
      canvas.translate(t.x, t.y);
      canvas.scale(px);
      canvas.drawOval(Rect.fromCenter(center: const Offset(0, 2), width: 18, height: 7), Paint()..color = Colors.black.withValues(alpha: .18));
      final pin = Path()
        ..moveTo(0, 0)
        ..cubicTo(-9, -12, -15, -19, -15, -27)
        ..arcToPoint(const Offset(15, -27), radius: const Radius.circular(15))
        ..cubicTo(15, -19, 9, -12, 0, 0)
        ..close();
      canvas.drawPath(pin, Paint()..color = AppColors.gold500);
      canvas.drawPath(pin, Paint()
        ..color = Colors.white
        ..style = PaintingStyle.stroke
        ..strokeWidth = 3);
      canvas.drawCircle(const Offset(0, -27), 5.5, Paint()..color = Colors.white);
      canvas.restore();
    }
  }

  Path _chamfered(Rect4 r, double c) {
    if (c <= 0) return Path()..addRRect(RRect.fromRectAndRadius(Rect.fromLTWH(r.x, r.y, r.w, r.h), const Radius.circular(6)));
    return Path()
      ..moveTo(r.x + c, r.y)
      ..lineTo(r.x + r.w - c, r.y)
      ..lineTo(r.x + r.w, r.y + c)
      ..lineTo(r.x + r.w, r.y + r.h - c)
      ..lineTo(r.x + r.w - c, r.y + r.h)
      ..lineTo(r.x + c, r.y + r.h)
      ..lineTo(r.x, r.y + r.h - c)
      ..lineTo(r.x, r.y + c)
      ..close();
  }

  void _label(Canvas canvas, Place p, _Kind k) {
    final tall = p.rect.h > p.rect.w * 1.35;
    final span = tall ? p.rect.h : p.rect.w;
    final thin = tall ? p.rect.w : p.rect.h;
    final centre = p.outline != null
        ? Offset(p.outline!.map((q) => q.x).reduce((a, b) => a + b) / p.outline!.length, p.outline!.map((q) => q.y).reduce((a, b) => a + b) / p.outline!.length)
        : Offset(p.rect.centre.x, p.rect.centre.y);
    canvas.save();
    canvas.translate(centre.dx, centre.dy);
    if (tall) canvas.rotate(-math.pi / 2);
    if (p.number != null && (p.kind == 'train' || p.kind == 'lab')) {
      _text(canvas, arabic ? (p.kind == 'lab' ? 'معمل' : 'قاعة') : (p.kind == 'lab' ? 'Lab' : 'Room'), Offset(0, -math.min(thin, 90.0) * .2), math.min(13.0, thin * .16), k.tag, FontWeight.w600, span);
      _text(canvas, p.number!, Offset(0, math.min(thin, 90.0) * .13), math.min(math.min(40.0, thin * .42), span * .3), k.ink, FontWeight.w800, span);
    } else if (p.kind == 'wc' || p.kind == 'pray') {
      _icon(canvas, p.kind == 'wc' ? Icons.wc_rounded : Icons.mosque_outlined, Offset.zero, math.min(26.0, thin * .42), k.tag);
    } else {
      final name = arabic ? p.ar : p.en;
      _text(canvas, name, Offset.zero, math.max(10.0, math.min(math.min(22.0, span / math.max(4, name.length) * 1.5), thin * .24)), k.ink, FontWeight.w700, span - 8);
    }
    canvas.restore();
  }

  void _text(Canvas canvas, String s, Offset at, double size, Color color, FontWeight weight, double maxWidth) {
    final tp = TextPainter(
      text: TextSpan(text: s, style: TextStyle(fontFamily: font?.fontFamily, fontFamilyFallback: font?.fontFamilyFallback, fontSize: size, color: color, fontWeight: weight, height: 1.1)),
      textAlign: TextAlign.center,
      textDirection: arabic ? TextDirection.rtl : TextDirection.ltr,
      maxLines: 2,
    )..layout(maxWidth: math.max(10.0, maxWidth));
    tp.paint(canvas, at - Offset(tp.width / 2, tp.height / 2));
  }

  void _icon(Canvas canvas, IconData icon, Offset at, double size, Color color) {
    final tp = TextPainter(
      text: TextSpan(text: String.fromCharCode(icon.codePoint), style: TextStyle(fontSize: size, color: color, fontFamily: icon.fontFamily, package: icon.fontPackage)),
      textDirection: TextDirection.ltr,
    )..layout();
    tp.paint(canvas, at - Offset(tp.width / 2, tp.height / 2));
  }

  @override
  bool shouldRepaint(_PlanPainter old) => true;
}
