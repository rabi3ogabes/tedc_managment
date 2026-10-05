/// Where a notification leads. The server puts a `route` in every notification (derived from its event and ids);
/// older notifications fall back to the event type.
class NotificationRoute {
  const NotificationRoute._();

  /// Bottom-navigation tabs — opened with go(); every other page is pushed so Back returns to where the user was.
  static const tabs = ['/home', '/programs', '/training', '/certificates', '/profile'];

  static const _allowed = [
    '/home', '/programs', '/training', '/certificates', '/profile', '/notifications', '/account', '/assignments', '/my-needs', '/approvals', '/scan', '/school',
    '/sessions/', '/courses/', '/lessons/', '/registrations/', '/tasks/', '/surveys/', '/needs-surveys', '/my-program/',
  ];

  static bool isTab(String route) => tabs.contains(route);

  static String resolve(String type, Map<String, dynamic> data) {
    final route = (data['route'] ?? '').toString();
    final concrete = route.startsWith('/') && _allowed.any((p) => route == p || (p.endsWith('/') && route.startsWith(p)) || route.startsWith('$p/')) && route != '/training' && route != '/notifications';
    if (concrete) return route;

    String id(String key) => (data[key] ?? '').toString();
    final group = type.split('.').first;
    if (group == 'session' && id('session_id').isNotEmpty) return '/sessions/${id('session_id')}';
    if (group == 'task' && id('task_id').isNotEmpty) return '/tasks/${id('task_id')}';
    if (type == 'impact.survey' && id('survey_id').isNotEmpty) return '/surveys/${id('survey_id')}';
    if (group == 'needs_survey' && id('needs_survey_id').isNotEmpty) return '/needs-surveys/${id('needs_survey_id')}';
    if (group == 'course' && id('registration_id').isNotEmpty) return '/courses/${id('registration_id')}';
    if (group == 'certificate') return '/certificates';
    if (group == 'profile') return '/account';
    if (group == 'individual_need') return '/my-needs';
    if (type == 'registration.pending_manager' || type == 'withdrawal.requested') return '/approvals';
    if (id('registration_id').isNotEmpty) return '/registrations/${id('registration_id')}';
    if (['survey', 'program', 'registration'].contains(group) && id('program_id').isNotEmpty) return '/my-program/${id('program_id')}';
    if (['registration', 'task', 'session', 'survey', 'program', 'impact'].contains(group)) return '/training';
    return route.isNotEmpty && _allowed.contains(route) ? route : '/notifications';
  }
}
