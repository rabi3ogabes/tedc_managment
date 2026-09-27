import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';

class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: AppColors.navy950,
      body: Center(child: Column(mainAxisSize: MainAxisSize.min, children: [
        BrandMark(size: 84),
        SizedBox(height: 24),
        SizedBox(width: 28, height: 28, child: CircularProgressIndicator(color: AppColors.gold500, strokeWidth: 2.5)),
      ])),
    );
  }
}
