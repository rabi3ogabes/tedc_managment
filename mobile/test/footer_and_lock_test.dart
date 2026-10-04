import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/features/shell/app_nav_bar.dart';

void main() {
  test('every screen opened on top of the tabs highlights the section it belongs to', () {
    expect(tabIndexFor('/home'), 0);
    expect(tabIndexFor('/programs/CODE-1'), 1);
    expect(tabIndexFor('/training'), 2);
    expect(tabIndexFor('/sessions/abc'), 2);
    expect(tabIndexFor('/scan'), 2);
    expect(tabIndexFor('/lessons/9'), 2);
    expect(tabIndexFor('/certificates'), 3);
    expect(tabIndexFor('/account'), 4);
    expect(tabIndexFor('/notifications'), 0);
  });
}
