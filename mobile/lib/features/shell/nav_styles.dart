import 'dart:ui';

import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';

/// One tab of the bottom bar.
class NavTab {
  const NavTab(this.icon, this.selectedIcon, this.label);

  final IconData icon;
  final IconData selectedIcon;
  final String label;
}

/// Draws the bottom navigation bar in the style the administrator chose in the dashboard
/// (classic · floating · centre button · neumorphism · glass · outline). Every style has the same five tabs, the same
/// tap size and the same screen-reader labels; only the look changes.
class StyledNavBar extends StatelessWidget {
  const StyledNavBar({super.key, required this.style, required this.tabs, required this.selectedIndex, required this.onSelected});

  final String style;
  final List<NavTab> tabs;
  final int selectedIndex;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    switch (style) {
      case 'floating':
        return _Floating(tabs: tabs, selected: selectedIndex, onSelected: onSelected);
      case 'center_fab':
        return _CenterFab(tabs: tabs, selected: selectedIndex, onSelected: onSelected);
      case 'neumorphism':
        return _Neumorphic(tabs: tabs, selected: selectedIndex, onSelected: onSelected);
      case 'glass':
        return _Glass(tabs: tabs, selected: selectedIndex, onSelected: onSelected);
      case 'outline':
        return _Outline(tabs: tabs, selected: selectedIndex, onSelected: onSelected);
      default:
        return _Classic(tabs: tabs, selected: selectedIndex, onSelected: onSelected);
    }
  }
}

/// A tab's tap target: at least 48 logical pixels, announced as a selected tab to screen readers.
class _Hit extends StatelessWidget {
  const _Hit({required this.tab, required this.selected, required this.onTap, required this.child});

  final NavTab tab;
  final bool selected;
  final VoidCallback onTap;
  final Widget child;

  @override
  Widget build(BuildContext context) => Semantics(
        button: true,
        selected: selected,
        label: tab.label,
        excludeSemantics: true,
        child: InkResponse(onTap: onTap, radius: 32, containedInkWell: false, child: ConstrainedBox(constraints: const BoxConstraints(minHeight: 48, minWidth: 48), child: child)),
      );
}

const _fast = Duration(milliseconds: 200);

Widget _label(String text, bool selected, Color on, Color off, {double size = 11}) => Text(
      text,
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: TextStyle(fontSize: size, fontWeight: selected ? FontWeight.w800 : FontWeight.w500, color: selected ? on : off),
    );

/// The standard bar (the default).
class _Classic extends StatelessWidget {
  const _Classic({required this.tabs, required this.selected, required this.onSelected});

  final List<NavTab> tabs;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(border: Border(top: BorderSide(color: AppColors.navy100))),
        child: NavigationBar(
          selectedIndex: selected,
          height: 68,
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          onDestinationSelected: onSelected,
          destinations: [for (final t in tabs) NavigationDestination(icon: Icon(t.icon), selectedIcon: Icon(t.selectedIcon), label: t.label)],
        ),
      );
}

/// A rounded bar that floats above the bottom edge, detached from it, with a soft shadow.
class _Floating extends StatelessWidget {
  const _Floating({required this.tabs, required this.selected, required this.onSelected});

  final List<NavTab> tabs;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) => SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 6, 16, 12),
          child: Container(
            height: 66,
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surface,
              borderRadius: BorderRadius.circular(28),
              border: Border.all(color: AppColors.navy100),
              boxShadow: [BoxShadow(color: AppColors.navy950.withValues(alpha: .18), blurRadius: 24, offset: const Offset(0, 10))],
            ),
            child: Row(children: [
              for (var i = 0; i < tabs.length; i++)
                Expanded(
                  child: _Hit(
                    tab: tabs[i],
                    selected: i == selected,
                    onTap: () => onSelected(i),
                    child: AnimatedContainer(
                      duration: _fast,
                      curve: Curves.easeOutCubic,
                      margin: const EdgeInsets.symmetric(horizontal: 3, vertical: 7),
                      decoration: BoxDecoration(color: i == selected ? AppColors.navy900 : Colors.transparent, borderRadius: BorderRadius.circular(20)),
                      child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                        Icon(i == selected ? tabs[i].selectedIcon : tabs[i].icon, size: 22, color: i == selected ? AppColors.gold300 : AppColors.muted),
                        _label(tabs[i].label, i == selected, Colors.white, AppColors.muted, size: 10),
                      ]),
                    ),
                  ),
                ),
            ]),
          ),
        ),
      );
}

/// A bar whose middle tab is a raised round button.
class _CenterFab extends StatelessWidget {
  const _CenterFab({required this.tabs, required this.selected, required this.onSelected});

  final List<NavTab> tabs;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    final mid = tabs.length ~/ 2;
    return SafeArea(
      top: false,
      child: SizedBox(
        height: 84,
        child: Stack(clipBehavior: Clip.none, alignment: Alignment.bottomCenter, children: [
          Container(
            height: 64,
            decoration: BoxDecoration(
              color: Theme.of(context).colorScheme.surface,
              border: Border(top: BorderSide(color: AppColors.navy100)),
              boxShadow: [BoxShadow(color: AppColors.navy950.withValues(alpha: .08), blurRadius: 16, offset: const Offset(0, -4))],
            ),
            child: Row(children: [
              for (var i = 0; i < tabs.length; i++)
                Expanded(
                  child: i == mid
                      ? Padding(padding: const EdgeInsets.only(top: 40), child: Center(child: _label(tabs[i].label, i == selected, AppColors.navy900, AppColors.muted, size: 10)))
                      : _Hit(
                          tab: tabs[i],
                          selected: i == selected,
                          onTap: () => onSelected(i),
                          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                            Icon(i == selected ? tabs[i].selectedIcon : tabs[i].icon, size: 24, color: i == selected ? AppColors.navy900 : AppColors.muted),
                            const SizedBox(height: 2),
                            _label(tabs[i].label, i == selected, AppColors.navy900, AppColors.muted),
                          ]),
                        ),
                ),
            ]),
          ),
          Positioned(
            top: 0,
            child: _Hit(
              tab: tabs[mid],
              selected: mid == selected,
              onTap: () => onSelected(mid),
              child: AnimatedContainer(
                duration: _fast,
                width: 58,
                height: 58,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  gradient: mid == selected ? AppColors.goldGradient : AppColors.navyGradient,
                  border: Border.all(color: Theme.of(context).colorScheme.surface, width: 4),
                  boxShadow: [BoxShadow(color: AppColors.navy950.withValues(alpha: .35), blurRadius: 14, offset: const Offset(0, 6))],
                ),
                child: Icon(tabs[mid].selectedIcon, color: mid == selected ? AppColors.navy950 : Colors.white, size: 26),
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

/// Soft, extruded buttons on a matching background; the chosen tab is pressed in.
class _Neumorphic extends StatelessWidget {
  const _Neumorphic({required this.tabs, required this.selected, required this.onSelected});

  final List<NavTab> tabs;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) {
    final base = AppColors.ivory;
    final dark = Color.alphaBlend(Colors.black.withValues(alpha: .13), base);
    final light = Color.alphaBlend(Colors.white.withValues(alpha: .85), base);
    return Container(
      color: base,
      child: SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 10, 14, 10),
          child: Row(children: [
            for (var i = 0; i < tabs.length; i++)
              Expanded(
                child: _Hit(
                  tab: tabs[i],
                  selected: i == selected,
                  onTap: () => onSelected(i),
                  child: AnimatedContainer(
                    duration: _fast,
                    curve: Curves.easeOutCubic,
                    height: 56,
                    margin: const EdgeInsets.symmetric(horizontal: 4),
                    decoration: BoxDecoration(
                      color: base,
                      borderRadius: BorderRadius.circular(18),
                      gradient: i == selected ? LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [dark, light]) : null,
                      boxShadow: i == selected ? null : [BoxShadow(color: dark, blurRadius: 8, offset: const Offset(3, 3)), BoxShadow(color: light, blurRadius: 8, offset: const Offset(-3, -3))],
                    ),
                    child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                      Icon(i == selected ? tabs[i].selectedIcon : tabs[i].icon, size: 22, color: i == selected ? AppColors.navy900 : AppColors.muted),
                      _label(tabs[i].label, i == selected, AppColors.navy900, AppColors.muted, size: 10),
                    ]),
                  ),
                ),
              ),
          ]),
        ),
      ),
    );
  }
}

/// A frosted, translucent bar with a thin light edge.
class _Glass extends StatelessWidget {
  const _Glass({required this.tabs, required this.selected, required this.onSelected});

  final List<NavTab> tabs;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) => Container(
        // A soft tint behind the glass so the blur has something to frost.
        decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [AppColors.ivory, AppColors.navy100])),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(14, 6, 14, 10),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(26),
              child: BackdropFilter(
                filter: ImageFilter.blur(sigmaX: 18, sigmaY: 18),
                child: Container(
                  height: 64,
                  decoration: BoxDecoration(
                    borderRadius: BorderRadius.circular(26),
                    gradient: LinearGradient(begin: Alignment.topLeft, end: Alignment.bottomRight, colors: [Colors.white.withValues(alpha: .72), Colors.white.withValues(alpha: .38)]),
                    border: Border.all(color: Colors.white.withValues(alpha: .85), width: 1.2),
                  ),
                  child: Row(children: [
                    for (var i = 0; i < tabs.length; i++)
                      Expanded(
                        child: _Hit(
                          tab: tabs[i],
                          selected: i == selected,
                          onTap: () => onSelected(i),
                          child: AnimatedContainer(
                            duration: _fast,
                            margin: const EdgeInsets.symmetric(horizontal: 3, vertical: 6),
                            decoration: BoxDecoration(
                              color: i == selected ? AppColors.navy900.withValues(alpha: .12) : Colors.transparent,
                              borderRadius: BorderRadius.circular(18),
                              border: i == selected ? Border.all(color: AppColors.navy900.withValues(alpha: .25)) : null,
                            ),
                            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                              Icon(i == selected ? tabs[i].selectedIcon : tabs[i].icon, size: 22, color: i == selected ? AppColors.navy900 : AppColors.muted),
                              _label(tabs[i].label, i == selected, AppColors.navy900, AppColors.muted, size: 10),
                            ]),
                          ),
                        ),
                      ),
                  ]),
                ),
              ),
            ),
          ),
        ),
      );
}

/// Thin outline icons, no fills: the chosen tab gets an outlined pill and a gold underline.
class _Outline extends StatelessWidget {
  const _Outline({required this.tabs, required this.selected, required this.onSelected});

  final List<NavTab> tabs;
  final int selected;
  final ValueChanged<int> onSelected;

  @override
  Widget build(BuildContext context) => DecoratedBox(
        decoration: BoxDecoration(color: Theme.of(context).colorScheme.surface, border: Border(top: BorderSide(color: AppColors.navy900.withValues(alpha: .35), width: 1))),
        child: SafeArea(
          top: false,
          child: SizedBox(
            height: 64,
            child: Row(children: [
              for (var i = 0; i < tabs.length; i++)
                Expanded(
                  child: _Hit(
                    tab: tabs[i],
                    selected: i == selected,
                    onTap: () => onSelected(i),
                    child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
                      AnimatedContainer(
                        duration: _fast,
                        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                        decoration: BoxDecoration(borderRadius: BorderRadius.circular(16), border: Border.all(color: i == selected ? AppColors.navy900 : Colors.transparent, width: 1.4)),
                        child: Icon(tabs[i].icon, size: 22, color: i == selected ? AppColors.navy900 : AppColors.muted),
                      ),
                      const SizedBox(height: 3),
                      _label(tabs[i].label, i == selected, AppColors.navy900, AppColors.muted, size: 10),
                    ]),
                  ),
                ),
            ]),
          ),
        ),
      );
}
