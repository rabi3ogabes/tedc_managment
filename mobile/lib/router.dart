import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'core/error_reporter.dart';
import 'core/providers.dart';
import 'features/course/course_screen.dart';
import 'features/course/lesson_screen.dart';
import 'features/needs/needs_survey_screen.dart';
import 'features/attendance/scan_screen.dart';
import 'features/auth/login_screen.dart';
import 'features/auth/splash_screen.dart';
import 'features/certificates/wallet_screen.dart';
import 'features/home/home_screen.dart';
import 'features/notifications/notifications_screen.dart';
import 'features/profile/profile_screen.dart';
import 'features/programs/program_detail_screen.dart';
import 'features/profile/account_screen.dart';
import 'features/programs/programs_screen.dart';
import 'features/school/school_screen.dart';
import 'features/training/my_program_redirect.dart';
import 'features/training/session_screen.dart';
import 'features/shell/home_shell.dart';
import 'features/training/my_training_screen.dart';
import 'features/training/registration_screen.dart';
import 'features/training/survey_screen.dart';
import 'features/training/task_screen.dart';

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = ValueNotifier<int>(0);
  ref.listen(authProvider, (_, _) => refresh.value++);
  ref.onDispose(refresh.dispose);

  final router = GoRouter(
    initialLocation: '/home',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authProvider);
      final location = state.matchedLocation;
      if (auth.isLoading && !auth.hasValue) return location == '/splash' ? null : '/splash';
      final loggedIn = auth.value != null;
      if (!loggedIn) return location == '/login' ? null : '/login';
      if (location == '/login' || location == '/splash') return '/home';
      return null;
    },
    routes: [
      GoRoute(path: '/splash', builder: (_, _) => const SplashScreen()),
      GoRoute(path: '/login', builder: (_, _) => const LoginScreen()),
      GoRoute(path: '/scan', builder: (_, _) => const ScanScreen()),
      GoRoute(path: '/notifications', builder: (_, _) => const NotificationsScreen()),
      GoRoute(path: '/account', builder: (_, _) => const AccountScreen()),
      GoRoute(path: '/school', builder: (_, _) => const SchoolScreen()),
      GoRoute(path: '/courses/:id', builder: (_, s) => CourseScreen(registrationId: s.pathParameters['id']!)),
      GoRoute(path: '/lessons/:id', builder: (_, s) => LessonScreen(id: s.pathParameters['id']!, registrationId: s.uri.queryParameters['registration'])),
      GoRoute(path: '/sessions/:id', builder: (_, s) => SessionScreen(id: s.pathParameters['id']!)),
      GoRoute(path: '/my-program/:programId', builder: (_, s) => MyProgramRedirect(programId: s.pathParameters['programId']!)),
      GoRoute(path: '/registrations/:id', builder: (_, s) => RegistrationScreen(id: s.pathParameters['id']!)),
      GoRoute(path: '/tasks/:id', builder: (_, s) => TaskScreen(taskId: s.pathParameters['id']!)),
      GoRoute(path: '/surveys/:id', builder: (_, s) => SurveyScreen(surveyId: s.pathParameters['id']!)),
      GoRoute(path: '/needs-surveys', builder: (_, _) => const NeedsSurveysScreen()),
      GoRoute(path: '/needs-surveys/:id', pageBuilder: (_, s) => MaterialPage(fullscreenDialog: true, child: NeedsSurveyScreen(id: s.pathParameters['id']!))),
      StatefulShellRoute.indexedStack(
        builder: (_, _, shell) => HomeShell(shell: shell),
        branches: [
          StatefulShellBranch(routes: [GoRoute(path: '/home', builder: (_, _) => const HomeScreen())]),
          StatefulShellBranch(routes: [
            GoRoute(
              path: '/programs',
              builder: (_, _) => const ProgramsScreen(),
              routes: [GoRoute(path: ':code', builder: (_, s) => ProgramDetailScreen(code: s.pathParameters['code']!))],
            ),
          ]),
          StatefulShellBranch(routes: [GoRoute(path: '/training', builder: (_, _) => const MyTrainingScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/certificates', builder: (_, _) => const WalletScreen())]),
          StatefulShellBranch(routes: [GoRoute(path: '/profile', builder: (_, _) => const ProfileScreen())]),
        ],
      ),
    ],
  );
  // The error log records which screen an error happened on.
  router.routeInformationProvider.addListener(() => ErrorReporter.route(router.routeInformationProvider.value.uri.path));
  return router;
});
