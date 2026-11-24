import '../../core/models.dart';

/// «برامجي»: the programme groups a person supervises or teaches, as `GET /me/duties` returns them (the same reading the
/// dashboard's «برامجي» page uses). These are the small decisions the screens make from a group, kept apart from the
/// widgets so they can be tested by themselves.
///
/// A group: `id`, `capacities` (supervisor, trainer), `phase` (running, upcoming, ended), `program` {id, code, title_ar,
/// title_en}, `group` {title_ar, title_en, only}, `from`, `to`, `supervisor`, `trainers`, `rooms`, `trainees`, `sessions`
/// (each with `when`: past, now, today, later; `focus`; `place`; `present`; `expected`) and `forms`.
abstract final class Duties {
  static const path = '/me/duties';

  /// The groups of the answer, whatever else it carries.
  static List<Json> groups(dynamic raw) => raw is Map ? (Map<String, dynamic>.from(raw).obj('data')?.list('groups') ?? const []) : const [];

  /// What the home screen shows: the groups running now and the ones still to start, in the order the server gave
  /// (running first, then the soonest). A finished group is history, not something assigned for the days ahead.
  static List<Json> ahead(List<Json> groups) => [for (final g in groups) if (g.str('phase') != 'ended') g];

  /// The session of a group to act on: the one the server marked (running now, else the next, else the last held).
  static Json? focus(Json group) {
    for (final s in group.list('sessions')) {
      if (s.flag('focus')) return s;
    }
    return null;
  }

  static bool supervises(Json group) => _capacities(group).contains('supervisor');

  static bool teaches(Json group) => _capacities(group).contains('trainer');

  /// The capacity to name first: a person who is both is, for this group, its supervisor.
  static String capacity(Json group) => supervises(group) ? 'supervisor' : 'trainer';

  static List<String> _capacities(Json group) => (group['capacities'] as List? ?? const []).map((e) => e.toString()).toList();

  /// A name the server sends in both languages, in the app's language (the other one when it is empty).
  static String name(Json? both, String lang) {
    if (both == null) return '';
    final first = both.str(lang == 'en' ? 'en' : 'ar');
    return first.isNotEmpty ? first : both.str(lang == 'en' ? 'ar' : 'en');
  }

  /// The programme's title, with the group's own title when the programme has more than one group.
  static String title(Json group, String lang) => group.obj('program')?.str(lang == 'en' ? 'title_en' : 'title_ar') ?? '';

  static String? groupTitle(Json group, String lang) {
    final g = group.obj('group');
    if (g == null || g.flag('only')) return null;
    final t = g.str(lang == 'en' ? 'title_en' : 'title_ar');
    return t.isEmpty ? null : t;
  }

  /// The names of a list of people, the reader first.
  static List<(String, bool)> people(List<Json> list, String lang) {
    final rows = [for (final p in list) (name(p, lang), p.flag('me'))]..removeWhere((p) => p.$1.isEmpty);
    return [...rows.where((p) => p.$2), ...rows.where((p) => !p.$2)];
  }

  /// How much of the room came, as a whole percentage; nobody expected is 0.
  static int percent(num present, num expected) => expected > 0 ? ((present / expected) * 100).round().clamp(0, 100) : 0;

  /// Attendance is taken for a session of today: the one running now, or one later today.
  static bool takesAttendance(Json session) => session.str('when') == 'now' || session.str('when') == 'today';

  /// Which label a session carries on a card: happening now, today, the next one, or the last one held.
  static String sessionLabel(Json session) => switch (session.str('when')) { 'now' => 'now', 'today' => 'today', 'later' => 'next', _ => 'last' };

  /// The groups left after the phase filter of the screen (`all` keeps everything).
  static List<Json> inPhase(List<Json> groups, String phase) => phase == 'all' ? groups : [for (final g in groups) if (g.str('phase') == phase) g];

  /// The phases that have a group, in the order they are offered.
  static List<String> phases(List<Json> groups) => [for (final p in const ['running', 'upcoming', 'ended']) if (groups.any((g) => g.str('phase') == p)) p];
}
