/// Thin, null-safe views over API JSON. Kept as wrappers to stay resilient to additive API changes.
typedef Json = Map<String, dynamic>;

extension JsonX on Json {
  String str(String key, [String fallback = '']) => this[key]?.toString() ?? fallback;
  num number(String key) => (this[key] as num?) ?? 0;
  bool flag(String key) => this[key] == true;
  Json? obj(String key) => this[key] is Map ? Map<String, dynamic>.from(this[key] as Map) : null;
  List<Json> list(String key) => (this[key] as List? ?? const []).whereType<Map>().map((e) => Map<String, dynamic>.from(e)).toList();
  DateTime? date(String key) => this[key] == null ? null : DateTime.tryParse(this[key].toString())?.toLocal();
}

class Me {
  Me(this.json);

  final Json json;

  String get id => json.str('id');
  String get name => json.str('name');
  String get email => json.str('email');
  String get locale => json.str('locale', 'ar');
  List<String> get roles => json.list('roles').map((r) => r.str('slug')).toList();
  List<String> get permissions => (json['permissions'] as List? ?? const []).map((e) => e.toString()).toList();
  Json? get employee => json.obj('employee');
  bool get isTrainer => json.str('trainer_id').isNotEmpty;

  /// Every role the person holds, each at a scope (id, slug, name, scope_label_ar/en, landing_route, active).
  List<Json> get roleGrants => json.list('roles');
  Json? get activeRole => json.obj('active_role');

  bool can(String permission) => permissions.contains('*') || permissions.contains(permission);
  bool hasRole(String role) => roles.contains(role);
  bool get isSchoolAdmin => hasRole('school_admin');
  bool get isSupervisor => hasRole('supervisor');

  /// The role the person is working in right now: the one they chose, else a trainee when they have an employee
  /// profile, else the first role they hold.
  String get workSlug => activeRole?.str('slug') ?? (employee != null || roles.isEmpty ? 'employee' : roles.first);

  bool get worksAsSupervisor => workSlug == 'supervisor';

  bool get worksAsTrainer => workSlug == 'trainer';

  /// «مشرف» and «مدرب» get an app laid out around the programmes given to them (see features/workspace): which of the
  /// two the person is working as, or null for everybody else. The same person working as a trainee keeps the trainee's app.
  String? get workspace => switch (workSlug) { 'supervisor' => 'supervisor', 'trainer' => 'trainer', _ => null };

  /// The grant that lets the person work as a supervisor or a trainer, when they hold one and are not working in it now:
  /// the way back for somebody who went to their own training.
  Json? get workGrant {
    for (final g in roleGrants) {
      if (const ['supervisor', 'trainer'].contains(g.str('slug')) && !g.flag('active') && g.str('id').isNotEmpty) return g;
    }
    return null;
  }

  /// The grant that lets the person work as a trainee, when they hold one and are not working in it now.
  Json? get traineeGrant {
    for (final g in roleGrants) {
      if (g.str('slug') == 'employee' && !g.flag('active') && g.str('id').isNotEmpty) return g;
    }
    return null;
  }
}
