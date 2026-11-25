import '../../core/models.dart';
import '../duties/duties_model.dart';

/// A session together with the group it belongs to.
typedef Slot = ({Json group, Json session});

/// The app of somebody who runs programmes — a supervisor or a trainer: the small decisions its screens make from the
/// groups of `GET /me/duties` (see [Duties]), from the trainees of one group (`GET /me/duties/{group}`) and from the
/// attendance sheet of a session, kept apart from the widgets so they can be tested by themselves.
abstract final class Workspace {
  /// The trainees of a group: `held` (sessions of the group held so far) and `trainees`, each with `registration_id`,
  /// `name`, `employee_no`, `school` and `attended`.
  static String traineesPath(String groupId) => '/me/duties/$groupId';

  static String attendancePath(String sessionId) => '/admin/sessions/$sessionId/attendance';

  static String qrPath(String sessionId) => '/admin/sessions/$sessionId/qr';

  static String staffScanPath(String sessionId) => '/admin/sessions/$sessionId/staff-scan';

  /// A trainee who left before the end (`registration_id`, `at`, `reason`); DELETE `…/{registration}` takes it back.
  static String earlyLeavePath(String sessionId) => '/admin/sessions/$sessionId/early-leave';

  /// A comment about one trainee in one session (`note`; empty removes it).
  static String notePath(String sessionId, String registrationId) => '/admin/sessions/$sessionId/attendance/$registrationId/note';

  /// The person's own remark about a session (`body`; empty removes it).
  static String remarkPath(String sessionId) => '/admin/sessions/$sessionId/remark';

  /// A training kit as its trainer reads about it: what it is for and what it holds.
  static String kitPath(String kitId, [String programId = '']) => '/me/teaching/kits/$kitId${programId.isEmpty ? '' : '?program=$programId'}';

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

  // ── hours ────────────────────────────────────────────────────────────────────────────────────────────────────────

  /// How long a session lasts, in hours.
  static double sessionHours(Json session) {
    final from = session.date('starts_at');
    final to = session.date('ends_at');
    return from == null || to == null || !to.isAfter(from) ? 0 : to.difference(from).inMinutes / 60;
  }

  /// The hours of a group: what its sessions add up to, else the hours written for the programme.
  static double groupHours(Json group) {
    final scheduled = group.list('sessions').fold<double>(0, (n, s) => n + sessionHours(s));
    return scheduled > 0 ? scheduled : (group.obj('program')?.number('hours') ?? 0).toDouble();
  }

  /// The hours still to give: the sessions running now or still to start, in every group.
  static double hoursAhead(List<Json> groups) => slots(groups).where((x) => x.session.str('when') != 'past').fold(0, (n, x) => n + sessionHours(x.session));

  /// Hours as a number to read: whole when they are whole (3), else one decimal (2.5).
  static String hoursText(num hours) => hours == hours.roundToDouble() ? hours.toStringAsFixed(0) : hours.toStringAsFixed(1);

  /// When a group starts: its first session (date and time), else the day the server gives.
  static DateTime? firstStart(Json group) {
    final sessions = group.list('sessions');
    return sessions.isEmpty ? group.date('from') : (sessions.first.date('starts_at') ?? group.date('from'));
  }

  // ── kits, people, surveys ────────────────────────────────────────────────────────────────────────────────────────

  /// «الحقائب التدريبية» of a group's programme, as the server gives them to the people who teach it.
  static List<Json> kits(Json group) => group.list('kits');

  /// The content of an online programme, read in the app («حقيبة البرنامج»).
  static bool hasCourse(Json group) => group.obj('program')?.flag('has_course') ?? false;

  /// How many kits a group has to open, for somebody who teaches it: its training kits, and the online content when the
  /// programme has it. A supervisor is not given the kits.
  static int kitCount(Json group) => Duties.teaches(group) ? kits(group).length + (hasCourse(group) ? 1 : 0) : 0;

  /// The parts of a group's page: the kits are a part of their own for somebody who teaches the group and has one.
  static List<String> parts(Json group) => ['sessions', 'trainees', 'team', if (kitCount(group) > 0) 'kits', 'surveys'];

  /// How to reach a person the server gave the contact of (the supervisor of a group): nothing for oneself, and nothing
  /// when the account has neither a telephone nor an e-mail.
  static ({String phone, String email})? contact(Json? person) {
    if (person == null || person.flag('me')) return null;
    final phone = person.str('phone').trim();
    final email = person.str('email').trim();
    return phone.isEmpty && email.isEmpty ? null : (phone: phone, email: email);
  }

  /// One group per programme, in the order given: the two surveys and the kits belong to the programme, not to each of its groups.
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

  // ── the attendance sheet ─────────────────────────────────────────────────────────────────────────────────────────

  /// The rows a search leaves: every word typed must be in the name, the number or the school.
  static List<Json> search(List<Json> rows, String query) {
    final words = query.trim().toLowerCase().split(RegExp(r'\s+')).where((w) => w.isNotEmpty).toList();
    if (words.isEmpty) return rows;
    return [
      for (final r in rows)
        if (words.every((w) => '${r.str('name')} ${r.str('employee_no')} ${r.str('school')}'.toLowerCase().contains(w))) r,
    ];
  }

  /// The rows of an attendance sheet left after a filter: one mark, the ones who left early (`early`), or everybody (`all`).
  static List<Json> marked(List<Json> rows, String status) => switch (status) {
        'all' => rows,
        'early' => [for (final r in rows) if (leftEarly(r)) r],
        _ => [for (final r in rows) if (r.str('status') == status) r],
      };

  /// Whether a row is of somebody who came (present or late): only they can leave early.
  static bool came(Json row) => row.str('status') == 'present' || row.str('status') == 'late';

  static bool leftEarly(Json row) => row.str('left_early_at').isNotEmpty;

  /// The time offered for an early leave: now while the session runs, else half an hour before its end (the middle of a
  /// session shorter than an hour).
  static DateTime leaveDefault(DateTime starts, DateTime ends, DateTime now) {
    if (now.isAfter(starts) && now.isBefore(ends)) return now;
    final length = ends.difference(starts);
    return length > const Duration(hours: 1) ? ends.subtract(const Duration(minutes: 30)) : starts.add(length ~/ 2);
  }

  /// An early leave is after the session started and before it ends.
  static bool leaveValid(DateTime at, DateTime starts, DateTime ends) => at.isAfter(starts) && at.isBefore(ends);

  /// A clock time chosen for the day of a session.
  static DateTime onDayOf(DateTime day, int hour, int minute) => DateTime(day.year, day.month, day.day, hour, minute);

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
