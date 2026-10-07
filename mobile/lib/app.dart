import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/providers.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/brand.dart';
import 'router.dart';

class TedcApp extends ConsumerWidget {
  const TedcApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final locale = ref.watch(localeProvider);
    final router = ref.watch(routerProvider);
    ref.watch(brandProvider);
    ref.watch(fontEpochProvider);

    // Keyed by the palette: when the administrator changes the colours every screen is built again with them.
    return KeyedSubtree(
      key: ValueKey(AppColors.signature),
      child: MaterialApp.router(
        title: 'مركز التدريب والتطوير',
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light(locale),
        locale: locale,
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        routerConfig: router,
      ),
    );
  }
}
