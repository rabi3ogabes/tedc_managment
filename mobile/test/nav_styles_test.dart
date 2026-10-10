import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tedc_mobile/core/theme/brand.dart';
import 'package:tedc_mobile/features/shell/nav_styles.dart';

const _tabs = [
  NavTab(Icons.home_outlined, Icons.home, 'Home'),
  NavTab(Icons.menu_book_outlined, Icons.menu_book, 'Programs'),
  NavTab(Icons.school_outlined, Icons.school, 'Training'),
  NavTab(Icons.workspace_premium_outlined, Icons.workspace_premium, 'Certificates'),
  NavTab(Icons.person_outline, Icons.person, 'Profile'),
];

void main() {
  test('the navigation style comes from the dashboard and falls back to classic', () {
    Brand of(Object? nav) => Brand.fromConfig({'data': {'navigation': nav}}, arabic: true);
    expect(of({'style': 'glass'}).navStyle, 'glass');
    expect(of({'style': 'center_fab'}).navStyle, 'center_fab');
    expect(of({'style': 'nonsense'}).navStyle, 'classic');
    expect(of(null).navStyle, 'classic');
    expect(Brand.none.navStyle, 'classic');
  });

  for (final style in Brand.navStyles) {
    testWidgets('$style bar shows the five tabs and reports the tapped one', (tester) async {
      var tapped = -1;
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          bottomNavigationBar: StyledNavBar(style: style, tabs: _tabs, selectedIndex: 0, onSelected: (i) => tapped = i),
        ),
      ));
      for (final t in _tabs) {
        expect(find.text(t.label), findsWidgets);
      }
      await tester.tap(find.text('Certificates').first);
      await tester.pump();
      expect(tapped, 3);
    });
  }
}
