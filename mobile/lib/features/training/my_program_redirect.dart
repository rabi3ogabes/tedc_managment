import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/widgets/widgets.dart';

/// Opens the signed-in user's own registration of a program (notifications only know the program).
class MyProgramRedirect extends ConsumerWidget {
  const MyProgramRedirect({super.key, required this.programId});

  final String programId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final registrations = ref.watch(getProvider('/me/registrations'));
    registrations.whenData((raw) {
      final list = Map<String, dynamic>.from(raw as Map).list('data');
      final mine = list.where((r) => r.str('program_id') == programId).firstOrNull;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!context.mounted) return;
        if (mine != null) {
          context.pushReplacement('/registrations/${mine.str('id')}');
        } else {
          context.go('/training');
        }
      });
    });
    return Scaffold(
      body: registrations.hasError ? ErrorView(message: '', onRetry: () => ref.invalidate(getProvider('/me/registrations'))) : const LoadingView(),
    );
  }
}
