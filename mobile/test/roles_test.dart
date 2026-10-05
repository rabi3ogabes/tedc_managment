import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/models.dart';

void main() {
  test('a person with several roles sees each one with its scope and which is active', () {
    final me = Me({
      'id': 'u1',
      'name': 'Layla',
      'roles': [
        {'id': 'g1', 'slug': 'school_admin', 'name': 'مدير مدرسة', 'scope_label_ar': 'مدرسة الوكرة', 'scope_label_en': 'Wakra School', 'active': true},
        {'id': 'g2', 'slug': 'employee', 'name': 'موظف', 'scope_label_ar': 'الوزارة', 'scope_label_en': 'Ministry', 'active': false},
      ],
      'active_role': {'id': 'g1', 'slug': 'school_admin', 'name': 'مدير مدرسة'},
      'permissions': ['schools.view'],
    });

    expect(me.roles, ['school_admin', 'employee']);
    expect(me.roleGrants.length, 2);
    expect(me.roleGrants.first.flag('active'), isTrue);
    expect(me.activeRole?.str('slug'), 'school_admin');
    expect(me.isSchoolAdmin, isTrue);
  });
}
