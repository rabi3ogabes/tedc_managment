import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/l10n/strings.dart';
import '../../core/theme/brand.dart';
import 'nav_styles.dart';

/// The five tabs, in order. Every screen of the app shows the same footer so the user can always jump to another section.
const appTabs = ['/home', '/programs', '/training', '/certificates', '/profile'];

/// Which tab a screen belongs to (the one that should look selected under it).
int tabIndexFor(String location) {
  for (var i = 0; i < appTabs.length; i++) {
    if (location == appTabs[i] || location.startsWith('${appTabs[i]}/')) return i;
  }
  const training = ['/sessions', '/registrations', '/courses', '/lessons', '/tasks', '/surveys', '/my-program', '/scan'];
  if (training.any((p) => location == p || location.startsWith('$p/'))) return 2;
  if (location.startsWith('/account') || location.startsWith('/assignments') || location.startsWith('/my-needs') || location.startsWith('/approvals') || location.startsWith('/my-qr') || location.startsWith('/school') || location.startsWith('/needs-surveys')) return 4;
  return 0;   // notifications and anything else belong to home
}

class AppNavBar extends ConsumerWidget {
  const AppNavBar({super.key, required this.selectedIndex, required this.onSelected});

  final int selectedIndex;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    return StyledNavBar(
      style: ref.watch(brandProvider).navStyle,
      selectedIndex: selectedIndex,
      onSelected: onSelected,
      tabs: [
        NavTab(Icons.home_outlined, Icons.home, s.t('nav.home')),
        NavTab(Icons.menu_book_outlined, Icons.menu_book, s.t('nav.programs')),
        NavTab(Icons.school_outlined, Icons.school, s.t('nav.training')),
        NavTab(Icons.workspace_premium_outlined, Icons.workspace_premium, s.t('nav.certificates')),
        NavTab(Icons.person_outline, Icons.person, s.t('nav.profile')),
      ],
    );
  }
}

/// Wraps every screen that is opened on top of the tabs (a session, a lesson, the scanner…) so the footer stays visible.
/// Choosing a tab goes there and leaves the screen.
class DetailShell extends StatelessWidget {
  const DetailShell({super.key, required this.location, required this.child});

  final String location;
  final Widget child;

  @override
  Widget build(BuildContext context) => Scaffold(
        body: child,
        bottomNavigationBar: AppNavBar(selectedIndex: tabIndexFor(location), onSelected: (i) => context.go(appTabs[i])),
      );
}
