import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The delivery modes shown as tabs, in order.
const programModes = ['in_person', 'online', 'hybrid'];

/// «My upcoming programs» on the home screen: the programs the person is registered in, as a slider with one tab per
/// delivery mode (in person · online · hybrid).
class UpcomingProgramsSlider extends StatefulWidget {
  const UpcomingProgramsSlider({super.key, required this.items});

  /// Rows of `/me/home` → `upcoming_programs`: registration_id, registration_status, mode, next_session_at, program.
  final List<Json> items;

  @override
  State<UpcomingProgramsSlider> createState() => _UpcomingProgramsSliderState();
}

class _UpcomingProgramsSliderState extends State<UpcomingProgramsSlider> {
  String? _mode;
  int _page = 0;
  PageController _pages = PageController(viewportFraction: .88);

  List<Json> _of(String mode) => widget.items.where((r) => r.str('mode', 'in_person') == mode).toList();

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  /// A new controller per tab, so every tab starts on its first card (and a page change never resets the one in use).
  void _pick(String mode) {
    final old = _pages;
    setState(() {
      _mode = mode;
      _page = 0;
      _pages = PageController(viewportFraction: .88);
    });
    WidgetsBinding.instance.addPostFrameCallback((_) => old.dispose());
  }

  @override
  Widget build(BuildContext context) {
    if (widget.items.isEmpty) return const SizedBox.shrink();
    final s = context.s;
    // The first tab that has something is selected until the person picks another one.
    final mode = _mode ?? programModes.firstWhere((m) => _of(m).isNotEmpty, orElse: () => programModes.first);
    final shown = _of(mode);

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      SectionTitle(s.t('home.myPrograms')),
      SizedBox(
        height: 44,
        child: ListView(scrollDirection: Axis.horizontal, children: [
          for (final m in programModes)
            Padding(
              padding: const EdgeInsetsDirectional.only(end: 8),
              child: ChoiceChip(
                label: Text('${s.t('mode.$m')} · ${Fmt(s.languageCode).number(_of(m).length)}'),
                selected: m == mode,
                showCheckmark: false,
                selectedColor: AppColors.navy900,
                labelStyle: TextStyle(fontWeight: FontWeight.w700, color: m == mode ? Colors.white : AppColors.navy900),
                onSelected: (_) => _pick(m),
              ),
            ),
        ]),
      ),
      const SizedBox(height: 12),
      if (shown.isEmpty)
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .5), borderRadius: BorderRadius.circular(18)),
          child: Text(s.t('home.noModePrograms'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted)),
        )
      else ...[
        // The neighbouring cards peek in from both sides; the one in the middle is the largest.
        SizedBox(
          height: 196,
          child: PageView.builder(
            key: ValueKey(mode),
            controller: _pages,
            itemCount: shown.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (_, i) => AnimatedBuilder(
              animation: _pages,
              builder: (_, child) {
                final page = _pages.hasClients && _pages.position.haveDimensions ? (_pages.page ?? _page.toDouble()) : _page.toDouble();
                final scale = (1 - (page - i).abs() * .06).clamp(.9, 1.0);
                return Transform.scale(scale: scale, child: child);
              },
              child: Padding(padding: const EdgeInsets.symmetric(horizontal: 5), child: _ProgramSlide(row: shown[i])),
            ),
          ),
        ),
        if (shown.length > 1)
          Padding(
            padding: const EdgeInsets.only(top: 12),
            child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              for (var i = 0; i < shown.length; i++)
                AnimatedContainer(
                  duration: const Duration(milliseconds: 220),
                  curve: Curves.easeOutCubic,
                  margin: const EdgeInsets.symmetric(horizontal: 3),
                  width: i == _page ? 22 : 7,
                  height: 7,
                  decoration: BoxDecoration(color: i == _page ? AppColors.navy900 : AppColors.navy100, borderRadius: BorderRadius.circular(99)),
                ),
            ]),
          ),
      ],
    ]);
  }
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
        borderRadius: BorderRadius.circular(26),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: id.isEmpty ? null : open,
          child: Ink(
            decoration: BoxDecoration(
              gradient: LinearGradient(begin: Alignment.topRight, end: Alignment.bottomLeft, colors: [AppColors.navy900, AppColors.navy700]),
              borderRadius: BorderRadius.circular(26),
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
            borderRadius: BorderRadius.circular(26),
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
