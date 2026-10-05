import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:supabase_flutter/supabase_flutter.dart' hide Session;

import '../../core/auth/session_store.dart';
import '../../core/biometric.dart';
import '../../core/config.dart';
import '../../core/l10n/strings.dart';
import '../../core/notification_route.dart';
import '../../core/providers.dart';
import '../../core/push/push_service.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/push_banner.dart';
import '../home/staff_home.dart';
import '../notifications/notification_bell.dart';
import '../security/biometric_offer.dart';
import 'app_nav_bar.dart';

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
    Future<void>.delayed(const Duration(milliseconds: 2500), _offerBiometric);
  }

  /// Once, on the first visit to the home screen: offer fingerprint sign-in if the phone has a lock.
  Future<void> _offerBiometric() async {
    if (!mounted) return;
    try {
      final store = ref.read(sessionStoreProvider);
      if (await store.biometricOffered() || ref.read(appLockProvider.notifier).enabled) return;
      if (!await ref.read(biometricServiceProvider).available() || !mounted) return;
      await store.markBiometricOffered();
      if (!mounted) return;
      final yes = await showModalBottomSheet<bool>(context: context, showDragHandle: true, builder: (_) => const BiometricOfferSheet());
      if (yes == true && mounted) await enableBiometricLogin(context, ref);
    } catch (_) {
      // Storage or the phone's security service is unavailable: the offer is simply skipped.
    }
  }

  /// "Who is online now" on the dashboard: a light heartbeat while the app is open on screen.
  void _startPresence() {
    Future<void>.delayed(const Duration(seconds: 3), _beat);
    _presence = Timer.periodic(const Duration(seconds: 30), (_) {
      if (_foreground) _beat();
    });
  }

  void _beat() {
    if (!mounted) return;
    final index = widget.shell.currentIndex.clamp(0, _screens.length - 1);
    // The screen in front (course, lesson, scan…) — its first segment only, never ids.
    final segments = GoRouter.of(context).routeInformationProvider.value.uri.pathSegments;
    final screen = segments.isEmpty ? _screens[index] : segments.first;
    ref.read(apiProvider).post('/me/presence', {'platform': 'mobile', 'path': '/app/$screen', 'app_version': AppConfig.appVersion}).catchError((_) => null);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed;
    // Fingerprint sign-in: note when the app went away and lock it if it was away long enough.
    final lock = ref.read(appLockProvider.notifier);
    if (state == AppLifecycleState.paused) lock.left();
    if (_foreground) {
      lock.returned();
      _beat();
    }
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
    Future<void>.delayed(const Duration(seconds: 2), () {
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
          Positioned(
            left: 14,
            top: MediaQuery.paddingOf(context).top + 10,
            child: Row(children: [
              NotificationBell(onDark: !staff),
              IconButton(onPressed: () => context.push('/search'), icon: Icon(Icons.search, color: staff ? AppColors.navy900 : Colors.white), tooltip: context.s.t('search.title')),
            ]),
          ),
        PushBanner(
          message: _banner,
          onTap: (route) {
            setState(() => _banner = null);
            _open(route);
          },
          onDismiss: () => setState(() => _banner = null),
        ),
      ]),
      bottomNavigationBar: AppNavBar(
        selectedIndex: widget.shell.currentIndex,
        onSelected: (i) => widget.shell.goBranch(i, initialLocation: i == widget.shell.currentIndex),
      ),
    );
  }
}
