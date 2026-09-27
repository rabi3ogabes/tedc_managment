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

  bool can(String permission) => permissions.contains('*') || permissions.contains(permission);
  bool hasRole(String role) => roles.contains(role);
  bool get isSchoolAdmin => hasRole('school_admin');
  bool get isSupervisor => hasRole('supervisor');
}
