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

  List<Json> _of(String mode) => widget.items.where((r) => r.str('mode', 'in_person') == mode).toList();

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
                onSelected: (_) => setState(() {
                  _mode = m;
                  _page = 0;
                }),
              ),
            ),
        ]),
      ),
      const SizedBox(height: 10),
      if (shown.isEmpty)
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .5), borderRadius: BorderRadius.circular(18)),
          child: Text(s.t('home.noModePrograms'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted)),
        )
      else ...[
        SizedBox(
          height: 178,
          // A new controller per tab, so every tab starts on its first card.
          child: PageView.builder(
            key: ValueKey(mode),
            controller: PageController(viewportFraction: .9),
            itemCount: shown.length,
            onPageChanged: (i) => setState(() => _page = i),
            itemBuilder: (_, i) => Padding(
              padding: const EdgeInsetsDirectional.only(end: 10),
              child: _ProgramSlide(row: shown[i]),
            ),
          ),
        ),
        if (shown.length > 1)
          Padding(
            padding: const EdgeInsets.only(top: 10),
            child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [
              for (var i = 0; i < shown.length; i++)
                AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  margin: const EdgeInsets.symmetric(horizontal: 3),
                  width: i == _page ? 20 : 7,
                  height: 7,
                  decoration: BoxDecoration(color: i == _page ? AppColors.navy900 : AppColors.navy100, borderRadius: BorderRadius.circular(99)),
                ),
            ]),
          ),
      ],
    ]);
  }
}

class _ProgramSlide extends StatelessWidget {
  const _ProgramSlide({required this.row});

  final Json row;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final program = row.obj('program') ?? const {};
    final next = row.date('next_session_at');
    final start = program.date('start_date');
    final id = row.str('registration_id');
    return Semantics(
      button: true,
      label: program.str('title'),
      child: Material(
        borderRadius: BorderRadius.circular(22),
        clipBehavior: Clip.antiAlias,
        color: Colors.transparent,
        child: InkWell(
          onTap: id.isEmpty ? null : () => context.push('/registrations/$id'),
          child: Ink(
            decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(22)),
            padding: const EdgeInsets.all(16),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                _Pill(s.t('mode.${row.str('mode', 'in_person')}'), gold: true),
                const SizedBox(width: 8),
                _Pill(s.status(row.str('registration_status'))),
              ]),
              const SizedBox(height: 10),
              Text(program.str('title'), maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w800, height: 1.3)),
              const Spacer(),
              Row(children: [
                Icon(Icons.event, size: 15, color: AppColors.gold300),
                const SizedBox(width: 6),
                Expanded(
                  child: Text(
                    next != null ? '${s.t('home.nextSessionAt')}: ${fmt.date(next)} · ${fmt.time(next)}' : (start != null ? fmt.date(start) : ''),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: Colors.white70, fontSize: 12.5),
                  ),
                ),
                const Icon(Icons.arrow_forward_ios, size: 14, color: Colors.white70),
              ]),
            ]),
          ),
        ),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill(this.text, {this.gold = false});

  final String text;
  final bool gold;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: gold ? AppColors.gold500 : Colors.white.withValues(alpha: .16), borderRadius: BorderRadius.circular(99)),
        child: Text(text, style: TextStyle(color: gold ? AppColors.navy950 : Colors.white, fontSize: 11, fontWeight: FontWeight.w800)),
      );
}
