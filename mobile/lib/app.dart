import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'core/providers.dart';
import 'core/theme/app_style.dart';
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
    ref.watch(appThemeProvider);
    ref.watch(fontEpochProvider);

    // Keyed by the palette: when the administrator changes the colours every screen is built again with them.
    return KeyedSubtree(
      key: ValueKey('${AppColors.signature}|${AppStyle.signature}'),
      child: MaterialApp.router(
        title: 'شاهين - مشروع بوابة التدريب',
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light(locale),
        locale: locale,
        supportedLocales: const [Locale('ar'), Locale('en')],
        localizationsDelegates: GlobalMaterialLocalizations.delegates,
        // Plugins that still draw with the Flutter SDK's own Material library (the scanner, the PDF reader, the video player)
        // read the app's theme and translations through this bridge until they move to material_ui themselves. The package marks
        // the bridge as deprecated to say it is temporary; it is the documented way to carry those plugins meanwhile.
        // ignore: deprecated_member_use
        builder: (context, child) => MaterialUiCompatibilityBridge(child: child!),
        routerConfig: router,
      ),
    );
  }
}
