import '../../core/models.dart';
import '../duties/duties_model.dart';

/// A session together with the group it belongs to.
typedef Slot = ({Json group, Json session});

/// The supervisor's app: the small decisions its screens make from the groups of `GET /me/duties` (see [Duties]) and from
/// the trainees of one group (`GET /me/duties/{group}`), kept apart from the widgets so they can be tested by themselves.
abstract final class Supervision {
  /// The trainees of a group: `held` (sessions of the group held so far) and `trainees`, each with `registration_id`,
  /// `name`, `employee_no`, `school` and `attended`.
  static String traineesPath(String groupId) => '/me/duties/$groupId';

  static String attendancePath(String sessionId) => '/admin/sessions/$sessionId/attendance';

  static String qrPath(String sessionId) => '/admin/sessions/$sessionId/qr';

  static String staffScanPath(String sessionId) => '/admin/sessions/$sessionId/staff-scan';

  /// Every session of every group, each with its group.
  static List<Slot> slots(List<Json> groups) => [
        for (final g in groups)
          for (final s in g.list('sessions')) (group: g, session: s),
      ];

  static bool _sameDay(DateTime? a, DateTime b) => a != null && a.year == b.year && a.month == b.month && a.day == b.day;

  static int _byStart(Slot a, Slot b) => (a.session.date('starts_at') ?? DateTime(0)).compareTo(b.session.date('starts_at') ?? DateTime(0));

  /// The sessions of today: the one running first, then the ones still to start, then the ones already held.
  static List<Slot> today(List<Json> groups, [DateTime? now]) {
    final at = now ?? DateTime.now();
    final rows = [for (final x in slots(groups)) if (x.session.str('when') == 'now' || _sameDay(x.session.date('starts_at'), at)) x]..sort(_byStart);
    int rank(Slot x) => switch (x.session.str('when')) { 'now' => 0, 'today' => 1, 'later' => 1, _ => 2 };
    return [for (final r in const [0, 1, 2]) ...rows.where((x) => rank(x) == r)];
  }

  /// The session to act on today: the one running, else the next one today. Null on a day with nothing left to run.
  static Slot? focus(List<Json> groups, [DateTime? now]) {
    for (final x in today(groups, now)) {
      if (x.session.str('when') != 'past') return x;
    }
    return null;
  }

  /// The sessions held before today, the newest first: where attendance is looked at again or corrected.
  static List<Slot> held(List<Json> groups, [DateTime? now]) {
    final at = now ?? DateTime.now();
    return [for (final x in slots(groups)) if (x.session.str('when') == 'past' && !_sameDay(x.session.date('starts_at'), at)) x]..sort((a, b) => _byStart(b, a));
  }

  /// The sessions of the days ahead, the soonest first.
  static List<Slot> ahead(List<Json> groups, [DateTime? now]) {
    final at = now ?? DateTime.now();
    return [for (final x in slots(groups)) if (x.session.str('when') == 'later' && !_sameDay(x.session.date('starts_at'), at)) x]..sort(_byStart);
  }

  /// The session with this id and its group, wherever it is.
  static Slot? slot(List<Json> groups, String sessionId) {
    for (final x in slots(groups)) {
      if (x.session.str('id') == sessionId) return x;
    }
    return null;
  }

  static Json? group(List<Json> groups, String id) {
    for (final g in groups) {
      if (g.str('id') == id) return g;
    }
    return null;
  }

  static List<Json> running(List<Json> groups) => Duties.inPhase(groups, 'running');

  static List<Json> upcoming(List<Json> groups) => Duties.inPhase(groups, 'upcoming');

  /// The trainees of the groups that are running or still to start.
  static int trainees(List<Json> groups) => Duties.ahead(groups).fold(0, (n, g) => n + g.number('trainees').toInt());

  /// One group per programme, in the order given: the two surveys belong to the programme, not to each of its groups.
  static List<Json> programmes(List<Json> groups) {
    final seen = <String>{};
    return [for (final g in groups) if (seen.add(g.obj('program')?.str('id') ?? g.str('id'))) g];
  }

  /// The two surveys of a group's programme that have a link: the trainees' survey and the trainer's own.
  static List<(String kind, Json form)> surveys(Json group) {
    final forms = group.obj('forms');
    return [
      for (final kind in const ['trainee', 'reflection'])
        if ((forms?.obj(kind)?.str('url') ?? '').isNotEmpty) (kind, forms!.obj(kind)!),
    ];
  }

  /// How many answered a survey out of how many were asked: every trainee for theirs, the trainers it was sent to for the
  /// trainer's own (before it is sent, the trainers of the programme).
  static (int answered, int asked) answers(String kind, Json form) {
    final answered = form.number('answered').toInt();
    if (kind == 'trainee') return (answered, form.number('expected').toInt());
    final assigned = form.number('assigned').toInt();
    return (answered, assigned > 0 ? assigned : form.number('trainers').toInt());
  }

  /// The rows a search leaves: every word typed must be in the name, the number or the school.
  static List<Json> search(List<Json> rows, String query) {
    final words = query.trim().toLowerCase().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).toList();
    if (words.isEmpty) return rows;
    return [
      for (final r in rows)
        if (words.every((w) => '${r.str('name')} ${r.str('employee_no')} ${r.str('school')}'.toLowerCase().contains(w))) r,
    ];
  }

  /// The rows of an attendance sheet left after a filter by mark (`all` keeps everything).
  static List<Json> marked(List<Json> rows, String status) => status == 'all' ? rows : [for (final r in rows) if (r.str('status') == status) r];

  /// A person's own attendance code (the one their app shows), as opposed to the code of a session.
  static bool personalCode(String? value) => value != null && value.startsWith('TEDC1P.');

  /// Whole seconds until the code of a session is replaced by the next one, never below zero.
  static int secondsLeft(DateTime? expires, DateTime now) => expires == null ? 0 : expires.difference(now).inSeconds.clamp(0, 86400).toInt();

  /// The first letter of a name, for the round mark beside it.
  static String initial(String name) {
    final t = name.trim();
    return t.isEmpty ? '؟' : t.substring(0, 1).toUpperCase();
  }
}
