import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:supabase_flutter/supabase_flutter.dart' hide Session;

import '../../core/auth/session_store.dart';
import '../../core/config.dart';
import '../../core/l10n/strings.dart';
import '../../core/notification_route.dart';
import '../../core/providers.dart';
import '../../core/push/push_service.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/push_banner.dart';
import '../home/staff_home.dart';
import '../notifications/notification_bell.dart';

/// Main navigation: Home · Programs · My Training · Certificates · Notifications · Profile.
class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> with WidgetsBindingObserver {
  RealtimeChannel? _channel;
  Timer? _poll;
  StreamSubscription<PushMessage>? _pushMessages;
  StreamSubscription<String>? _pushTaps;
  PushMessage? _banner;
  Timer? _bannerTimer;
  Timer? _presence;
  bool _foreground = true;

  static const _screens = ['home', 'programs', 'training', 'certificates', 'profile'];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _listenForNotifications();
    _startPush();
    _startPresence();
  }

  /// "Who is online now" on the dashboard: a light heartbeat while the app is open on screen.
  void _startPresence() {
    Future<void>.delayed(const Duration(seconds: 3), _beat);
    _presence = Timer.periodic(const Duration(seconds: 45), (_) {
      if (_foreground) _beat();
    });
  }

  void _beat() {
    if (!mounted) return;
    final index = widget.shell.currentIndex.clamp(0, _screens.length - 1);
    ref.read(apiProvider).post('/me/presence', {'platform': 'mobile', 'path': '/app/${_screens[index]}', 'app_version': AppConfig.appVersion}).catchError((_) => null);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    if (_foreground) _beat();
  }

  @override
  void didUpdateWidget(HomeShell oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.shell.currentIndex != widget.shell.currentIndex) _beat();
  }

  /// Registers the phone for push notifications (when configured on the dashboard) and handles messages.
  void _startPush() {
    final push = ref.read(pushServiceProvider);
    _pushMessages = push.messages.listen((message) {
      _refreshBadge();
      _bannerTimer?.cancel();
      setState(() => _banner = message);
      _bannerTimer = Timer(const Duration(seconds: 6), () => mounted ? setState(() => _banner = null) : null);
    });
    _pushTaps = push.openedRoutes.listen((route) {
      _refreshBadge();
      if (mounted) _open(route);
    });
    // Firebase set-up and the permission prompt are not needed to show the first screen: start them after it.
    Future<void>.delayed(const Duration(seconds: 4), () {
      if (mounted) push.start();
    });
  }

  /// Supabase Realtime when configured (RLS restricts rows to the user); polling otherwise.
  Future<void> _listenForNotifications() async {
    final me = ref.read(authProvider).value;
    if (me == null) return;
    if (AppConfig.realtimeEnabled) {
      final Session? session = await ref.read(sessionStoreProvider).read();
      if (session == null) return;
      final client = Supabase.instance.client;
      client.realtime.setAuth(session.accessToken);
      _channel = client
          .channel('notifications:${me.id}')
          .onPostgresChanges(
            event: PostgresChangeEvent.insert,
            schema: 'public',
            table: 'notifications',
            filter: PostgresChangeFilter(type: PostgresChangeFilterType.eq, column: 'user_id', value: me.id),
            callback: (_) => _refreshBadge(),
          )
          .subscribe();
    } else {
      _poll = Timer.periodic(const Duration(minutes: 1), (_) => _refreshBadge());
    }
  }

  void _refreshBadge() {
    if (!mounted) return;
    ref.invalidate(getProvider('/me/notifications?unread=1&per_page=1'));
    ref.invalidate(getProvider('/me/notifications'));
    ref.invalidate(getProvider('/me/home'));
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _presence?.cancel();
    _pushMessages?.cancel();
    _pushTaps?.cancel();
    _bannerTimer?.cancel();
    _poll?.cancel();
    if (_channel != null) Supabase.instance.client.removeChannel(_channel!);
    super.dispose();
  }

  /// Tabs are switched with go(); screens opened on top (e.g. a survey) are pushed so Back works.
  void _open(String route) => NotificationRoute.isTab(route) ? context.go(route) : context.push(route);

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    // Accounts without an employee profile have no training pages: show an explanation instead of errors.
    final me = ref.watch(authProvider).value;
    final staff = me != null && me.employee == null;
    // A trainer without a trainee profile still reaches the certificate wallet (their thank-you certificates).
    final showStaffHome = staff && widget.shell.currentIndex < 4 && !(me.isTrainer && widget.shell.currentIndex == 3);

    return Scaffold(
      body: Stack(children: [
        showStaffHome ? const StaffHome() : widget.shell,
        // The notifications button lives at the left of the home screen (physical left in both languages).
        if (widget.shell.currentIndex == 0)
          Positioned(left: 14, top: MediaQuery.paddingOf(context).top + 10, child: NotificationBell(onDark: !staff)),
        PushBanner(
          message: _banner,
          onTap: (route) {
            setState(() => _banner = null);
            _open(route);
          },
          onDismiss: () => setState(() => _banner = null),
        ),
      ]),
      bottomNavigationBar: DecoratedBox(
        decoration: const BoxDecoration(border: Border(top: BorderSide(color: AppColors.navy100))),
        child: NavigationBar(
          selectedIndex: widget.shell.currentIndex,
          height: 68,
          labelBehavior: NavigationDestinationLabelBehavior.alwaysShow,
          onDestinationSelected: (i) => widget.shell.goBranch(i, initialLocation: i == widget.shell.currentIndex),
          destinations: [
            NavigationDestination(icon: const Icon(Icons.home_outlined), selectedIcon: const Icon(Icons.home), label: s.t('nav.home')),
            NavigationDestination(icon: const Icon(Icons.menu_book_outlined), selectedIcon: const Icon(Icons.menu_book), label: s.t('nav.programs')),
            NavigationDestination(icon: const Icon(Icons.school_outlined), selectedIcon: const Icon(Icons.school), label: s.t('nav.training')),
            NavigationDestination(icon: const Icon(Icons.workspace_premium_outlined), selectedIcon: const Icon(Icons.workspace_premium), label: s.t('nav.certificates')),
            NavigationDestination(icon: const Icon(Icons.person_outline), selectedIcon: const Icon(Icons.person), label: s.t('nav.profile')),
          ],
        ),
      ),
    );
  }
}
