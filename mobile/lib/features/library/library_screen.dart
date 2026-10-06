import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The digital library: search, open an item in the reader (rights decide whether it can be downloaded) and keep it on the shelf.
class LibraryScreen extends ConsumerStatefulWidget {
  const LibraryScreen({super.key});

  @override
  ConsumerState<LibraryScreen> createState() => _LibraryScreenState();
}

class _LibraryScreenState extends ConsumerState<LibraryScreen> {
  String _q = '';

  String get _path => _q.trim().length >= 2 ? '/me/library?q=${Uri.encodeQueryComponent(_q.trim())}' : '/me/library';

  Future<void> _open(Json item) async {
    try {
      final res = await ref.read(apiProvider).get('/me/library/${item.str('id')}/read');
      final url = ((res as Map)['data'] as Map)['url']?.toString();
      if (url != null && url.isNotEmpty) await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    }
  }

  Future<void> _shelf(Json item) async {
    try {
      await ref.read(apiProvider).put('/me/library/${item.str('id')}/shelf', {'on': !item.flag('on_shelf')});
      ref.invalidate(getProvider(_path));
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final ar = s.languageCode == 'ar';
    final list = ref.watch(getProvider(_path));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('library.title'))),
      body: Column(children: [
        Padding(padding: const EdgeInsets.fromLTRB(16, 12, 16, 4), child: TextField(decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: s.t('library.search'), border: const OutlineInputBorder()), onChanged: (v) => setState(() => _q = v))),
        Expanded(
          child: AsyncView(
            value: list,
            onRetry: () => ref.invalidate(getProvider(_path)),
            builder: (raw) {
              final rows = Map<String, dynamic>.from(raw as Map).list('data');
              if (rows.isEmpty) return Center(child: Text(s.t('library.empty')));
              return ListView(padding: const EdgeInsets.all(16), children: [
                for (final i in rows)
                  Card(
                    child: ListTile(
                      title: Text(i.str(ar ? 'title_ar' : 'title_en').isNotEmpty ? i.str(ar ? 'title_ar' : 'title_en') : i.str('title_ar'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text([s.t('library.type.${i.str('type')}'), if (i.obj('rights')?.flag('download') == false) s.t('library.viewOnly')].join(' • ')),
                      onTap: () => _open(i),
                      trailing: IconButton(icon: Icon(i.flag('on_shelf') ? Icons.bookmark : Icons.bookmark_border), onPressed: () => _shelf(i)),
                    ),
                  ),
              ]);
            },
          ),
        ),
      ]),
    );
  }
}
