import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_style.dart';
import '../../core/theme/app_theme.dart';
import '../home/upcoming_programs.dart';
import 'duties_model.dart';

/// «برامجي» on the home screen of a supervisor or a trainer: the programmes given to them that are running or still to
/// start, as the same deck of banners a trainee sees for the programmes they are registered in. It draws nothing while it
/// loads, when the person was given nothing, and on a server that does not know the list yet, so the home screen of
/// everybody else stays as it was.
class AssignedProgrammes extends ConsumerWidget {
  const AssignedProgrammes({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final raw = ref.watch(getProvider(Duties.path)).value;
    final groups = Duties.groups(raw);
    return groups.isEmpty ? const SizedBox.shrink() : AssignedDeck(groups: groups);
  }
}

/// The deck itself, from the groups of `/me/duties`.
class AssignedDeck extends StatelessWidget {
  const AssignedDeck({super.key, required this.groups});

  final List<Json> groups;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final ahead = Duties.ahead(groups);
    final all = TextButton(onPressed: () => context.push('/my-programs'), child: Text(s.t('common.viewAll')));
    if (ahead.isEmpty) {
      // Everything given to the person is over: say so in one line, with the way to the list.
      return Padding(
        padding: const EdgeInsets.only(top: 8),
        child: Container(
          padding: const EdgeInsetsDirectional.fromSTEB(16, 6, 6, 6),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(AppStyle.radius(4)), border: Border.all(color: AppColors.navy100)),
          child: Row(children: [
            Icon(Icons.event_available_outlined, color: AppColors.gold700, size: 20),
            const SizedBox(width: 10),
            Expanded(child: Text(s.t('duties.allEnded'), style: const TextStyle(color: AppColors.muted, fontWeight: FontWeight.w600, fontSize: 13))),
            all,
          ]),
        ),
      );
    }
    return ProgramDeck(title: s.t('duties.title'), count: ahead.length, action: all, builder: (_, i) => _DutyBanner(group: ahead[i]));
  }
}

/// One group as a banner: in which capacity, where it stands, its name, and its session to act on.
class _DutyBanner extends StatelessWidget {
  const _DutyBanner({required this.group});

  final Json group;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final session = Duties.focus(group);
    final starts = session?.date('starts_at') ?? group.date('from');
    final place = Duties.name(session?.obj('place'), lang);
    final when = session == null
        ? (starts != null ? fmt.date(starts) : '')
        : session.str('when') == 'now'
            ? [s.t('duties.session.now'), if (place.isNotEmpty) place].join(' · ')
            : '${s.t('duties.session.${Duties.sessionLabel(session)}')}: ${fmt.date(starts)} · ${fmt.time(starts)}';
    return ProgramBanner(
      title: Duties.title(group, lang),
      pills: [(s.t('duties.capacity.${Duties.capacity(group)}'), true), (s.t('duties.phase.${group.str('phase')}'), false)],
      line: when,
      action: s.t('duties.details'),
      actionIcon: Icons.visibility_outlined,
      icon: Duties.supervises(group) ? Icons.supervisor_account : Icons.co_present,
      onTap: () => context.push('/my-programs'),
    );
  }
}
