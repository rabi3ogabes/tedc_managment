import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';

/// Quick search from the home screen: programs and news from the server (only what the active role may see) and the app's own pages.
class SearchScreen extends ConsumerStatefulWidget {
  const SearchScreen({super.key});

  @override
  ConsumerState<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends ConsumerState<SearchScreen> {
  final _controller = TextEditingController();
  Timer? _debounce;
  String _query = '';

  @override
  void dispose() {
    _debounce?.cancel();
    _controller.dispose();
    super.dispose();
  }

  void _onChanged(String value) {
    _debounce?.cancel();
    _debounce = Timer(const Duration(milliseconds: 300), () {
      if (mounted) setState(() => _query = value.trim());
    });
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final pages = <(String, String, IconData)>[
      (s.t('nav.home'), '/home', Icons.home_outlined),
      (s.t('nav.programs'), '/programs', Icons.menu_book_outlined),
      (s.t('nav.training'), '/training', Icons.school_outlined),
      (s.t('nav.certificates'), '/certificates', Icons.workspace_premium_outlined),
      (s.t('nav.profile'), '/profile', Icons.person_outline),
    ];
    final term = _query.toLowerCase();
    final matchingPages = term.isEmpty ? <(String, String, IconData)>[] : pages.where((p) => p.$1.toLowerCase().contains(term)).toList();
    final remote = _query.length >= 2 ? ref.watch(getProvider('/search?q=${Uri.encodeQueryComponent(_query)}')) : null;
    final groups = remote?.value is Map ? (remote!.value as Map)['data'] as List? ?? const [] : const [];

    return Scaffold(
      appBar: AppBar(
        title: TextField(
          controller: _controller,
          autofocus: true,
          onChanged: _onChanged,
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(hintText: s.t('search.hint'), border: InputBorder.none, filled: false),
        ),
      ),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        if (_query.isEmpty) Padding(padding: const EdgeInsets.only(top: 40), child: Center(child: Text(s.t('search.hint'), style: const TextStyle(color: AppColors.muted)))),
        if (matchingPages.isNotEmpty) ...[
          _Heading(s.t('search.pages')),
          for (final p in matchingPages) ListTile(leading: Icon(p.$3, color: AppColors.gold700), title: Text(p.$1), onTap: () => context.go(p.$2)),
        ],
        for (final raw in groups)
          if (raw is Map && (raw['items'] as List? ?? const []).isNotEmpty) ...[
            _Heading(s.t('search.${raw['type']}')),
            for (final item in (raw['items'] as List).whereType<Map>())
              ListTile(
                leading: Icon(raw['type'] == 'programs' ? Icons.menu_book_outlined : Icons.article_outlined, color: AppColors.gold700),
                title: Text(item['title'].toString()),
                subtitle: item['subtitle'] == null ? null : Text(item['subtitle'].toString()),
                onTap: raw['type'] == 'programs' && item['code'] != null ? () => context.push('/programs/${item['code']}') : null,
              ),
          ],
        if (_query.length >= 2 && remote != null && !remote.isLoading && groups.every((g) => g is Map && (g['items'] as List? ?? const []).isEmpty) && matchingPages.isEmpty)
          Padding(padding: const EdgeInsets.only(top: 40), child: Center(child: Text(s.t('search.none'), style: const TextStyle(color: AppColors.muted)))),
        if (remote?.isLoading ?? false) const Padding(padding: EdgeInsets.only(top: 24), child: Center(child: CircularProgressIndicator())),
      ]),
    );
  }
}

class _Heading extends StatelessWidget {
  const _Heading(this.text);

  final String text;

  @override
  Widget build(BuildContext context) => Padding(padding: const EdgeInsets.fromLTRB(4, 16, 4, 4), child: Text(text, style: const TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy800)));
}
