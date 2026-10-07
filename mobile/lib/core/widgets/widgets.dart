import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../l10n/strings.dart';
import '../theme/app_theme.dart';

/// Renders an AsyncValue with consistent loading / error states and pull-to-refresh.
class AsyncView<T> extends StatelessWidget {
  const AsyncView({super.key, required this.value, required this.builder, this.onRetry});

  final AsyncValue<T> value;
  final Widget Function(T data) builder;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return value.when(
      data: builder,
      loading: () => const LoadingView(),
      error: (error, _) => ErrorView(message: ApiException.from(error).message, onRetry: onRetry),
    );
  }
}

/// Branded shimmer placeholder shaped like the content that is about to appear.
class LoadingView extends StatelessWidget {
  const LoadingView({super.key, this.items = 4});

  final int items;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: context.tr('common.loading'),
      child: Shimmer(
        // Works in both scrollable and fixed-height parents: only as many rows as fit are drawn.
        child: LayoutBuilder(builder: (context, constraints) {
          final fit = constraints.hasBoundedHeight ? ((constraints.maxHeight - 32 - 150) / 84).floor() + 1 : items;
          final count = fit.clamp(1, items);
          if (constraints.hasBoundedHeight && constraints.maxHeight < 200) {
            return Padding(padding: const EdgeInsets.all(8), child: SkeletonBox(height: (constraints.maxHeight - 16).clamp(8, 150).toDouble(), radius: 14));
          }
          return Padding(
            padding: const EdgeInsets.all(16),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              for (var i = 0; i < count; i++) ...[
                if (i > 0) const SizedBox(height: 14),
                i == 0 ? const SkeletonBox(height: 150, radius: 20) : const _SkeletonCard(),
              ],
            ]),
          );
        }),
      ),
    );
  }
}

class _SkeletonCard extends StatelessWidget {
  const _SkeletonCard();

  @override
  Widget build(BuildContext context) {
    return const Padding(
      padding: EdgeInsets.symmetric(vertical: 7),
      child: Row(children: [
        SkeletonBox(width: 56, height: 56, radius: 14),
        SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            SkeletonBox(height: 14),
            SizedBox(height: 10),
            FractionallySizedBox(widthFactor: .6, child: SkeletonBox(height: 12)),
          ]),
        ),
      ]),
    );
  }
}

/// A placeholder block; animate a group of them with [Shimmer].
class SkeletonBox extends StatelessWidget {
  const SkeletonBox({super.key, this.width, this.height = 12, this.radius = 8});

  final double? width;
  final double height;
  final double radius;

  @override
  Widget build(BuildContext context) => Container(
        width: width,
        height: height,
        decoration: BoxDecoration(color: const Color(0xFFEDE8E0), borderRadius: BorderRadius.circular(radius)),
      );
}

/// Sweeps a soft Dune-tinted highlight across its child (respects reduced motion).
class Shimmer extends StatefulWidget {
  const Shimmer({super.key, required this.child});

  final Widget child;

  @override
  State<Shimmer> createState() => _ShimmerState();
}

class _ShimmerState extends State<Shimmer> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(vsync: this, duration: const Duration(milliseconds: 1400));

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (MediaQuery.maybeDisableAnimationsOf(context) ?? false) {
      _controller.stop();
    } else if (!_controller.isAnimating) {
      _controller.repeat();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      child: widget.child,
      builder: (context, child) => ShaderMask(
        blendMode: BlendMode.srcATop,
        shaderCallback: (bounds) {
          final dx = bounds.width * (_controller.value * 2 - 0.5);
          return LinearGradient(
            colors: const [Color(0xFFEDE8E0), Color(0xFFF8F5EF), Color(0xFFEDE8E0)],
            stops: const [0.35, 0.5, 0.65],
            transform: _SlideGradient(dx),
          ).createShader(bounds);
        },
        child: child,
      ),
    );
  }
}

class _SlideGradient extends GradientTransform {
  const _SlideGradient(this.dx);

  final double dx;

  @override
  Matrix4? transform(Rect bounds, {TextDirection? textDirection}) => Matrix4.translationValues(dx - bounds.width / 2, 0, 0);
}

class ErrorView extends StatelessWidget {
  const ErrorView({super.key, this.message, this.onRetry});

  final String? message;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.error_outline, size: 40, color: AppColors.danger),
          const SizedBox(height: 12),
          Text(message ?? context.tr('common.error'), textAlign: TextAlign.center),
          if (onRetry != null) ...[
            const SizedBox(height: 12),
            OutlinedButton(onPressed: onRetry, child: Text(context.tr('common.retry'))),
          ],
        ]),
      ),
    );
  }
}

class EmptyView extends StatelessWidget {
  const EmptyView({super.key, this.icon = Icons.inbox_outlined, this.text});

  final IconData icon;
  final String? text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 48),
      child: Column(children: [
        Container(
          width: 64,
          height: 64,
          decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .6), borderRadius: BorderRadius.circular(20)),
          child: Icon(icon, color: AppColors.muted),
        ),
        const SizedBox(height: 12),
        Text(text ?? context.tr('common.empty'), style: const TextStyle(color: AppColors.muted)),
      ]),
    );
  }
}

class SectionTitle extends StatelessWidget {
  const SectionTitle(this.title, {super.key, this.action});

  final String title;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 24, bottom: 12),
      child: Row(children: [
        Container(width: 4, height: 20, decoration: BoxDecoration(gradient: AppColors.goldGradient, borderRadius: BorderRadius.circular(4))),
        const SizedBox(width: 10),
        Expanded(child: Text(title, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900))),
        ?action,
      ]),
    );
  }
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key, this.label});

  final String status;
  final String? label;

  static const _green = {'approved', 'completed', 'issued', 'valid', 'eligible', 'present', 'registration_open', 'fulfilled'};
  static const _red = {'rejected', 'cancelled', 'blocked', 'revoked', 'absent', 'critical', 'not_eligible'};
  static const _amber = {'pending', 'submitted', 'changes_requested', 'late', 'sent', 'high', 'under_review'};

  @override
  Widget build(BuildContext context) {
    final (bg, fg) = _green.contains(status)
        ? (const Color(0xFFE7F6EF), AppColors.success)
        : _red.contains(status)
            ? (const Color(0xFFFBEAEA), AppColors.danger)
            : _amber.contains(status)
                ? (const Color(0xFFFDF3E1), AppColors.warning)
                : (AppColors.navy100, AppColors.navy800);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: BorderRadius.circular(99)),
      child: Text(label ?? context.s.status(status), style: TextStyle(color: fg, fontSize: 11, fontWeight: FontWeight.w700)),
    );
  }
}

class StatTile extends StatelessWidget {
  const StatTile({super.key, required this.label, required this.value, required this.icon, this.dark = false});

  final String label;
  final String value;
  final IconData icon;
  final bool dark;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        gradient: dark ? AppColors.navyGradient : null,
        color: dark ? null : Colors.white,
        borderRadius: BorderRadius.circular(20),
        border: dark ? null : Border.all(color: AppColors.navy100),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(color: dark ? Colors.white.withValues(alpha: .1) : AppColors.gold100, borderRadius: BorderRadius.circular(12)),
          child: Icon(icon, size: 20, color: dark ? AppColors.gold300 : AppColors.gold700),
        ),
        const SizedBox(height: 12),
        Text(value, style: TextStyle(fontSize: 26, fontWeight: FontWeight.w800, color: dark ? AppColors.gold300 : AppColors.navy900)),
        Text(label, style: TextStyle(fontSize: 12, color: dark ? Colors.white70 : AppColors.muted)),
      ]),
    );
  }
}

class ProgressBar extends StatelessWidget {
  const ProgressBar(this.value, {super.key, this.color});

  final double value;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: BorderRadius.circular(99),
      child: LinearProgressIndicator(
        value: (value / 100).clamp(0, 1),
        minHeight: 7,
        backgroundColor: AppColors.navy100,
        valueColor: AlwaysStoppedAnimation(color ?? AppColors.gold500),
      ),
    );
  }
}

/// Green / red eligibility result with explanation per rule.
class EligibilityPanel extends StatelessWidget {
  const EligibilityPanel(this.result, {super.key});

  final Map<String, dynamic> result;

  @override
  Widget build(BuildContext context) {
    final eligible = result['eligible'] == true;
    final color = eligible ? AppColors.success : AppColors.danger;
    final checks = (result['checks'] as List? ?? const []).cast<Map>();
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .07),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: color.withValues(alpha: .3)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(eligible ? Icons.verified : Icons.block, color: color, size: 20),
          const SizedBox(width: 8),
          Text(result['label']?.toString() ?? '', style: TextStyle(color: color, fontWeight: FontWeight.w800)),
        ]),
        const SizedBox(height: 6),
        Text(result['summary']?.toString() ?? '', style: const TextStyle(fontSize: 13, color: AppColors.ink)),
        for (final c in checks) ...[
          const SizedBox(height: 6),
          Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Padding(
              padding: const EdgeInsets.only(top: 5),
              child: CircleAvatar(radius: 4, backgroundColor: c['passed'] == true ? AppColors.success : (c['mandatory'] == true ? AppColors.danger : AppColors.warning)),
            ),
            const SizedBox(width: 8),
            Expanded(child: Text(c['message']?.toString() ?? '', style: const TextStyle(fontSize: 12.5, color: AppColors.muted))),
          ]),
        ],
      ]),
    );
  }
}

/// Branded gradient cover for a program card.
class ProgramCover extends StatelessWidget {
  const ProgramCover({super.key, required this.code, this.category, this.categorySlug, this.height = 110});

  final String code;
  final String? category;
  final String? categorySlug;
  final double height;

  static const _palettes = {
    'leadership': [Color(0xFF5A0E24), Color(0xFF8A1538)],
    'pedagogy': [Color(0xFF3D0A1C), Color(0xFF756B54)],
    'digital': [Color(0xFF0B2A3A), Color(0xFF0E7490)],
    'assessment': [Color(0xFF1E1B4B), Color(0xFF5B21B6)],
    'wellbeing': [Color(0xFF3B0D24), Color(0xFF9D174D)],
    'professional': [Color(0xFF2A1A05), Color(0xFFA16207)],
  };

  @override
  Widget build(BuildContext context) {
    final colors = _palettes[categorySlug] ?? _palettes['pedagogy']!;
    return Container(
      height: height,
      decoration: BoxDecoration(gradient: LinearGradient(colors: colors, begin: Alignment.topRight, end: Alignment.bottomLeft)),
      child: Stack(children: [
        PositionedDirectional(end: -20, bottom: -30, child: Icon(Icons.auto_awesome, size: 120, color: AppColors.gold500.withValues(alpha: .18))),
        PositionedDirectional(
          start: 14,
          bottom: 10,
          end: 14,
          child: Row(children: [
            Text(code, textDirection: TextDirection.ltr, style: TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w700, letterSpacing: 1)),
            const Spacer(),
            if (category != null)
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                decoration: BoxDecoration(color: Colors.white.withValues(alpha: .15), borderRadius: BorderRadius.circular(99)),
                child: Text(category!, style: const TextStyle(color: Colors.white, fontSize: 11)),
              ),
          ]),
        ),
      ]),
    );
  }
}

void showSnack(BuildContext context, String message, {bool error = false}) {
  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Text(message),
    backgroundColor: error ? AppColors.danger : AppColors.navy900,
    behavior: SnackBarBehavior.floating,
  ));
}
