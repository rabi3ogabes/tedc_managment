import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:supabase_flutter/supabase_flutter.dart' hide Session;

import '../../core/auth/session_store.dart';
import '../../core/config.dart';
import '../../core/l10n/strings.dart';
import '../../core/providers.dart';
import '../../core/push/push_service.dart';
import '../../core/widgets/push_banner.dart';
import '../../core/theme/app_theme.dart';

/// Main navigation: Home · Programs · My Training · Certificates · Notifications · Profile.
class HomeShell extends ConsumerStatefulWidget {
  const HomeShell({super.key, required this.shell});

  final StatefulNavigationShell shell;

  @override
  ConsumerState<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends ConsumerState<HomeShell> {
  RealtimeChannel? _channel;
  Timer? _poll;
  StreamSubscription<PushMessage>? _pushMessages;
  StreamSubscription<String>? _pushTaps;
  PushMessage? _banner;
  Timer? _bannerTimer;

  @override
  void initState() {
    super.initState();
    _listenForNotifications();
    _startPush();
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
      if (mounted) context.go(route);
    });
    push.start();
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
    _pushMessages?.cancel();
    _pushTaps?.cancel();
    _bannerTimer?.cancel();
    _poll?.cancel();
    if (_channel != null) Supabase.instance.client.removeChannel(_channel!);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final unread = ref.watch(getProvider('/me/notifications?unread=1&per_page=1')).value;
    final meta = unread is Map ? unread['meta'] : null;
    final num count = meta is Map ? (meta['total'] as num? ?? 0) : 0;

    return Scaffold(
      body: Stack(children: [
        widget.shell,
        PushBanner(
          message: _banner,
          onTap: (route) {
            setState(() => _banner = null);
            context.go(route);
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
            NavigationDestination(
              icon: Badge(isLabelVisible: count > 0, label: Text('$count'), backgroundColor: AppColors.gold500, textColor: AppColors.navy950, child: const Icon(Icons.notifications_outlined)),
              selectedIcon: const Icon(Icons.notifications),
              label: s.t('nav.notifications'),
            ),
            NavigationDestination(icon: const Icon(Icons.person_outline), selectedIcon: const Icon(Icons.person), label: s.t('nav.profile')),
          ],
        ),
      ),
    );
  }
}
