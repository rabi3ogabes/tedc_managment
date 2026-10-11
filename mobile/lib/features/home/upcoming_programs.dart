import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/theme/app_style.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// «My upcoming programs» on the home screen: the programs the person is registered in, as a deck of cards. The first card
/// is in front, the next two peek out behind it on the left and the right; swipe the front card away (or use the arrows) to bring the next one forward.
class UpcomingProgramsSlider extends StatefulWidget {
  const UpcomingProgramsSlider({super.key, required this.items});

  /// Rows of `/me/home` → `upcoming_programs`: registration_id, registration_status, mode, next_session_at, program.
  final List<Json> items;

  @override
  State<UpcomingProgramsSlider> createState() => _UpcomingProgramsSliderState();
}

class _UpcomingProgramsSliderState extends State<UpcomingProgramsSlider> with SingleTickerProviderStateMixin {
  static const _height = 196.0;

  int _top = 0;
  double _dx = 0;
  double _from = 0;
  double _to = 0;
  late final AnimationController _c = AnimationController(vsync: this, duration: const Duration(milliseconds: 260))
    ..addListener(() => setState(() => _dx = _from + (_to - _from) * Curves.easeOutCubic.transform(_c.value)));

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  int get _count => widget.items.length;

  void _run(double from, double to, [VoidCallback? done]) {
    _from = from;
    _to = to;
    _c.duration = MediaQuery.disableAnimationsOf(context) ? Duration.zero : const Duration(milliseconds: 260);
    _c.forward(from: 0).whenComplete(() => done?.call());
  }

  /// Sends the front card off to [side] (+1 right, -1 left) and brings the next (or, going back, the previous) one forward.
  void _move(int step, double side, double width) {
    if (_count < 2 || _c.isAnimating) return;
    _run(_dx, side * width * 1.1, () {
      if (!mounted) return;
      setState(() {
        _top = (_top + step) % _count;
        if (_top < 0) _top += _count;
        _dx = 0;
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_count == 0) return const SizedBox.shrink();
    final s = context.s;
    final top = _top % _count;
    final behind = [for (var k = math.min(2, _count - 1); k >= 1; k--) k];

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SectionTitle(s.t('home.myPrograms')),
      LayoutBuilder(builder: (context, box) {
        final width = box.maxWidth;
        return SizedBox(
          height: _height + 26,
          child: Stack(clipBehavior: Clip.none, children: [
            // The cards behind, drawn from the back: the third peeks out on the right, the second on the left.
            for (final k in behind)
              Positioned(
                left: 0,
                right: 0,
                top: 0,
                height: _height,
                child: Transform.translate(
                  offset: Offset((k == 1 ? -1 : 1) * 14.0 * (1 - _progress(width) * (k == 1 ? 1 : 0)), k * 10.0 - _progress(width) * (k == 1 ? 10 : 0)),
                  child: Transform.rotate(
                    angle: (k == 1 ? -.035 : .035) * (1 - _progress(width) * (k == 1 ? 1 : 0)),
                    child: Transform.scale(
                      scale: 1 - k * .045 + _progress(width) * (k == 1 ? .045 : 0),
                      child: IgnorePointer(child: _dim(_ProgramSlide(row: widget.items[(top + k) % _count]), k)),
                    ),
                  ),
                ),
              ),
            // The front card follows the finger and tips a little as it goes.
            Positioned(
              left: 0,
              right: 0,
              top: 0,
              height: _height,
              child: GestureDetector(
                behavior: HitTestBehavior.translucent,
                onHorizontalDragStart: (_) => _c.stop(),
                onHorizontalDragUpdate: _count < 2 ? null : (d) => setState(() => _dx += d.delta.dx),
                onHorizontalDragEnd: _count < 2
                    ? null
                    : (d) {
                        final v = d.primaryVelocity ?? 0;
                        if (_dx.abs() > width * .28 || v.abs() > 700) {
                          _move(1, (_dx != 0 ? _dx : v).sign, width);
                        } else {
                          _run(_dx, 0);
                        }
                      },
                child: Transform.translate(
                  offset: Offset(_dx, 0),
                  child: Transform.rotate(angle: (_dx / width) * .18, child: _ProgramSlide(row: widget.items[top])),
                ),
              ),
            ),
          ]),
        );
      }),
      if (_count > 1)
        Row(mainAxisAlignment: MainAxisAlignment.center, children: [
          IconButton(
            tooltip: s.t('home.deckPrev'),
            onPressed: () => _move(-1, 1, MediaQuery.sizeOf(context).width),
            icon: Icon(Icons.chevron_left, color: AppColors.navy900),
          ),
          for (var i = 0; i < _count; i++)
            AnimatedContainer(
              duration: const Duration(milliseconds: 220),
              curve: Curves.easeOutCubic,
              margin: const EdgeInsets.symmetric(horizontal: 3),
              width: i == top ? 22 : 7,
              height: 7,
              decoration: BoxDecoration(color: i == top ? AppColors.navy900 : AppColors.navy100, borderRadius: BorderRadius.circular(99)),
            ),
          IconButton(
            tooltip: s.t('home.deckNext'),
            onPressed: () => _move(1, -1, MediaQuery.sizeOf(context).width),
            icon: Icon(Icons.chevron_right, color: AppColors.navy900),
          ),
        ]),
    ]);
  }

  /// How far the front card has gone (0..1): the second card comes forward as it leaves.
  double _progress(double width) => (_dx.abs() / (width * .6)).clamp(0.0, 1.0);

  /// The cards behind are a little paler so the front one stands out.
  Widget _dim(Widget child, int depth) => Opacity(opacity: depth == 1 ? .92 : .8, child: child);
}

IconData _modeIcon(String mode) => switch (mode) {
      'online' => Icons.laptop_chromebook,
      'hybrid' => Icons.devices,
      _ => Icons.school,
    };

/// One banner: a brand-coloured card with a soft wave, the program's name, its next session and a gold button.
class _ProgramSlide extends StatelessWidget {
  const _ProgramSlide({required this.row});

  final Json row;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final program = row.obj('program') ?? const {};
    final mode = row.str('mode', 'in_person');
    final next = row.date('next_session_at');
    final start = program.date('start_date');
    final id = row.str('registration_id');
    final when = next != null ? '${s.t('home.nextSessionAt')}: ${fmt.date(next)} · ${fmt.time(next)}' : (start != null ? fmt.date(start) : '');
    void open() => context.push('/registrations/$id');
    return Semantics(
      button: true,
      label: program.str('title'),
      child: Material(
        color: Colors.transparent,
        borderRadius: BorderRadius.circular(AppStyle.radius(6)),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: id.isEmpty ? null : open,
          child: Ink(
            decoration: BoxDecoration(
              gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [AppColors.navy900, AppColors.navy700]),
              borderRadius: BorderRadius.circular(AppStyle.radius(6)),
              boxShadow: [BoxShadow(color: AppColors.navy900.withValues(alpha: .28), blurRadius: 22, offset: const Offset(0, 10))],
            ),
            child: Stack(children: [
              Positioned.fill(child: CustomPaint(painter: _WavePainter(AppColors.gold300))),
              Padding(
                padding: const EdgeInsets.all(18),
                child: Row(children: [
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        _Pill(s.t('mode.$mode'), gold: true),
                        const SizedBox(width: 6),
                        Flexible(child: _Pill(s.status(row.str('registration_status')))),
                      ]),
                      const SizedBox(height: 10),
                      Text(program.str('title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 19, fontWeight: FontWeight.w800, height: 1.25)),
                      const SizedBox(height: 4),
                      Text(when, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white70, fontSize: 12.5)),
                      const Spacer(),
                      // The call to action, like the banner it is modelled on: a pill button with an icon.
                      Container(
                        height: 40,
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        decoration: BoxDecoration(gradient: AppColors.goldGradient, borderRadius: BorderRadius.circular(99)),
                        child: Row(mainAxisSize: MainAxisSize.min, children: [
                          Icon(Icons.play_circle_outline, size: 18, color: AppColors.navy950),
                          const SizedBox(width: 8),
                          Text(s.t('home.openProgram'), style: TextStyle(color: AppColors.navy950, fontWeight: FontWeight.w800, fontSize: 13)),
                        ]),
                      ),
                    ]),
                  ),
                  const SizedBox(width: 10),
                  _Visual(icon: _modeIcon(mode)),
                ]),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

/// The picture side of the banner: a frosted tile with the delivery mode's icon, tilted a little.
class _Visual extends StatelessWidget {
  const _Visual({required this.icon});

  final IconData icon;

  @override
  Widget build(BuildContext context) => Transform.rotate(
        angle: .12,
        child: Container(
          width: 96,
          height: 96,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(AppStyle.radius(6)),
            gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Colors.white.withValues(alpha: .26), Colors.white.withValues(alpha: .08)]),
            border: Border.all(color: Colors.white.withValues(alpha: .35)),
          ),
          child: Icon(icon, size: 46, color: AppColors.gold300),
        ),
      );
}

/// The soft wave drawn across the banner.
class _WavePainter extends CustomPainter {
  _WavePainter(this.color);

  final Color color;

  @override
  void paint(Canvas canvas, Size size) {
    final line = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2
      ..color = color.withValues(alpha: .28);
    for (var k = 0; k < 2; k++) {
      final y = size.height * (.62 + k * .14);
      final path = Path()..moveTo(0, y);
      path.cubicTo(size.width * .25, y - 34, size.width * .45, y + 34, size.width * .7, y - 6);
      path.cubicTo(size.width * .85, y - 30, size.width * .95, y - 10, size.width, y - 22);
      canvas.drawPath(path, line);
    }
  }

  @override
  bool shouldRepaint(_WavePainter old) => old.color != color;
}

class _Pill extends StatelessWidget {
  const _Pill(this.text, {this.gold = false});

  final String text;
  final bool gold;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: gold ? AppColors.gold500 : Colors.white.withValues(alpha: .16), borderRadius: BorderRadius.circular(99)),
        child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(color: gold ? AppColors.navy950 : Colors.white, fontSize: 11, fontWeight: FontWeight.w800)),
      );
}
