import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/l10n/strings.dart';
import '../../core/theme/app_theme.dart';

/// The five tabs, in order. Every screen of the app shows the same footer so the user can always jump to another section.
const appTabs = ['/home', '/programs', '/training', '/certificates', '/profile'];

/// Which tab a screen belongs to (the one that should look selected under it).
int tabIndexFor(String location) {
  for (var i = 0; i < appTabs.length; i++) {
    if (location == appTabs[i] || location.startsWith('${appTabs[i]}/')) return i;
  }
  const training = ['/sessions', '/registrations', '/courses', '/lessons', '/tasks', '/surveys', '/my-program', '/scan'];
  if (training.any((p) => location == p || location.startsWith('$p/'))) return 2;
  if (location.startsWith('/account') || location.startsWith('/assignments') || location.startsWith('/school') || location.startsWith('/needs-surveys')) return 4;
  return 0;   // notifications and anything else belong to home
}

class AppNavBar extends StatelessWidget {
  const AppNavBar({super.key, required this.selectedIndex, required this.onSelected});

  final int selectedIndex;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return DecoratedBox(
      decoration: const BoxDecoration(border: Border(top: BorderSide(color: AppColors.navy100))),
      child: NavigationBar(
        selectedIndex: selectedIndex,
        height: 68,
        labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
        onDestinationSelected: onSelected,
        destinations: [
          NavigationDestination(icon: const Icon(Icons.home_outlined), selectedIcon: const Icon(Icons.home), label: s.t('nav.home')),
          NavigationDestination(icon: const Icon(Icons.menu_book_outlined), selectedIcon: const Icon(Icons.menu_book), label: s.t('nav.programs')),
          NavigationDestination(icon: const Icon(Icons.school_outlined), selectedIcon: const Icon(Icons.school), label: s.t('nav.training')),
          NavigationDestination(icon: const Icon(Icons.workspace_premium_outlined), selectedIcon: const Icon(Icons.workspace_premium), label: s.t('nav.certificates')),
          NavigationDestination(icon: const Icon(Icons.person_outline), selectedIcon: const Icon(Icons.person), label: s.t('nav.profile')),
        ],
      ),
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
