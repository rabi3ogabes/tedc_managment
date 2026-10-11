import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'community_screens.dart';

String ratingPath(String type, String id) => '/social/ratings/$type/$id';

/// Stars from 0 to 5 (half-filled stars are rounded); tappable when [onChanged] is given.
class Stars extends StatelessWidget {
  const Stars({super.key, required this.value, this.onChanged, this.size = 20});

  final num value;
  final ValueChanged<int>? onChanged;
  final double size;

  @override
  Widget build(BuildContext context) => Row(mainAxisSize: MainAxisSize.min, children: [
        for (var n = 1; n <= 5; n++)
          onChanged == null
              ? Icon(value >= n - .25 ? Icons.star_rounded : Icons.star_outline_rounded, size: size, color: AppColors.gold500)
              : IconButton(
                  key: ValueKey('star-$n'),
                  visualDensity: VisualDensity.compact,
                  tooltip: '$n',
                  onPressed: () => onChanged!(n),
                  icon: Icon(value >= n ? Icons.star_rounded : Icons.star_outline_rounded, size: size, color: AppColors.gold500),
                ),
      ]);
}

/// The average, how the stars are spread, the newest reviews, and a button to rate (COL-03).
class RatingCard extends ConsumerWidget {
  const RatingCard({super.key, required this.type, required this.id});

  final String type, id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final value = ref.watch(getProvider(ratingPath(type, id)));
    return value.when(
      loading: () => const Padding(padding: EdgeInsets.all(16), child: LinearProgressIndicator()),
      error: (_, _) => const SizedBox.shrink(), // not allowed to see these reviews
      data: (raw) {
        final d = Map<String, dynamic>.from((raw as Map)['data'] as Map);
        final dist = Map<String, dynamic>.from((d['distribution'] as Map?) ?? const {});
        final top = dist.values.fold<num>(1, (m, v) => (v as num) > m ? v : m);
        final mine = d.obj('mine');
        return Card(
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(context.tr('rating.title'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
              const SizedBox(height: 10),
              Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(d['average'] == null ? '—' : d.number('average').toStringAsFixed(1), style: TextStyle(fontSize: 34, fontWeight: FontWeight.w800, color: AppColors.navy900)),
                  Stars(value: d.number('average'), size: 16),
                  Text(d.number('count') == 0 ? context.tr('rating.none') : context.tr('rating.count').replaceAll('{n}', '${d.number('count')}'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
                ]),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(children: [
                    for (final n in [5, 4, 3, 2, 1])
                      Padding(
                        padding: const EdgeInsets.symmetric(vertical: 2),
                        child: Row(children: [
                          SizedBox(width: 12, child: Text('$n', style: const TextStyle(fontSize: 12, color: AppColors.muted))),
                          Expanded(
                            child: ClipRRect(
                              borderRadius: BorderRadius.circular(4),
                              child: LinearProgressIndicator(value: ((dist['$n'] ?? 0) as num) / top, minHeight: 6, color: AppColors.gold500, backgroundColor: AppColors.navy100),
                            ),
                          ),
                          SizedBox(width: 28, child: Text('${dist['$n'] ?? 0}', textAlign: TextAlign.end, style: const TextStyle(fontSize: 12, color: AppColors.muted))),
                        ]),
                      ),
                  ]),
                ),
              ]),
              if (mine?.str('status') == 'hidden')
                Padding(padding: const EdgeInsets.only(top: 10), child: Text(context.tr('rating.mineHidden'), style: TextStyle(fontSize: 12, color: AppColors.gold700))),
              if (d.flag('can_rate'))
                Align(
                  alignment: AlignmentDirectional.centerEnd,
                  child: TextButton.icon(
                    key: const ValueKey('rate-button'),
                    onPressed: () => showRatingSheet(context, type: type, id: id, stars: mine?.number('stars').toInt() ?? 0, review: mine?.str('review') ?? ''),
                    icon: const Icon(Icons.star_outline_rounded),
                    label: Text(context.tr(mine == null ? 'rating.rate' : 'rating.edit')),
                  ),
                ),
              for (final r in d.list('reviews').take(3)) ...[
                const Divider(height: 18),
                Row(children: [Stars(value: r.number('stars'), size: 14), const SizedBox(width: 6), Expanded(child: Text(r.str('author'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)))]),
                Padding(padding: const EdgeInsets.only(top: 2), child: Text(r.str('review'))),
              ],
            ]),
          ),
        );
      },
    );
  }
}

/// A bottom sheet to give stars and an optional review; it refreshes the summary when saved.
Future<void> showRatingSheet(BuildContext context, {required String type, required String id, int stars = 0, String review = ''}) =>
    showModalBottomSheet<void>(context: context, isScrollControlled: true, builder: (_) => RatingSheet(type: type, id: id, stars: stars, review: review));

class RatingSheet extends ConsumerStatefulWidget {
  const RatingSheet({super.key, required this.type, required this.id, this.stars = 0, this.review = ''});

  final String type, id, review;
  final int stars;

  @override
  ConsumerState<RatingSheet> createState() => _RatingSheetState();
}

class _RatingSheetState extends ConsumerState<RatingSheet> {
  late int _stars = widget.stars;
  late final _review = TextEditingController(text: widget.review);
  bool _busy = false;

  @override
  void dispose() {
    _review.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/social/ratings', {'subject_type': widget.type, 'subject_id': widget.id, 'stars': _stars, 'review': _review.text.trim().isEmpty ? null : _review.text.trim()});
      ref.invalidate(getProvider(ratingPath(widget.type, widget.id)));
      if (!mounted) return;
      showSnack(context, context.tr('rating.saved'));
      Navigator.of(context).pop();
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(16, 16, 16, MediaQuery.of(context).viewInsets.bottom + 16),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(context.tr('rating.rate'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          Center(child: Stars(value: _stars, size: 34, onChanged: (n) => setState(() => _stars = n))),
          const SizedBox(height: 8),
          TextField(key: const ValueKey('review-field'), controller: _review, minLines: 2, maxLines: 5, maxLength: 1000, decoration: InputDecoration(labelText: context.tr('rating.review'))),
          Align(
            alignment: AlignmentDirectional.centerEnd,
            child: FilledButton(key: const ValueKey('rating-save'), onPressed: _stars == 0 || _busy ? null : _save, child: Text(context.tr('common.save'))),
          ),
        ]),
      );
}

/// Private notes on a lesson or a material: only their author sees them (COL-02).
class NotesView extends ConsumerStatefulWidget {
  const NotesView({super.key, required this.type, required this.id});

  final String type, id;

  @override
  ConsumerState<NotesView> createState() => _NotesViewState();
}

class _NotesViewState extends ConsumerState<NotesView> {
  final _body = TextEditingController();
  bool _busy = false;

  String get _path => '/social/notes?subject_type=${widget.type}&subject_id=${widget.id}';

  @override
  void dispose() {
    _body.dispose();
    super.dispose();
  }

  Future<void> _add() async {
    if (_body.text.trim().isEmpty) return;
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('/social/notes', {'subject_type': widget.type, 'subject_id': widget.id, 'body': _body.text.trim()});
      _body.clear();
      ref.invalidate(getProvider(_path));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _delete(String id) async {
    try {
      await ref.read(apiProvider).delete('/social/notes/$id');
      ref.invalidate(getProvider(_path));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final notes = ref.watch(getProvider(_path));
    return ListView(padding: const EdgeInsets.all(16), children: [
      Text(context.tr('notes.hint'), style: const TextStyle(color: AppColors.muted, fontSize: 13)),
      const SizedBox(height: 8),
      TextField(controller: _body, minLines: 2, maxLines: 6, maxLength: 4000, decoration: InputDecoration(labelText: context.tr('notes.add'))),
      Align(alignment: AlignmentDirectional.centerEnd, child: FilledButton(onPressed: _busy ? null : _add, child: Text(context.tr('notes.add')))),
      const SizedBox(height: 8),
      AsyncView(
        value: notes,
        onRetry: () => ref.invalidate(getProvider(_path)),
        builder: (raw) {
          final rows = Map<String, dynamic>.from(raw as Map).list('data');
          if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(12), child: Text(context.tr('notes.empty'), style: const TextStyle(color: AppColors.muted)));
          return Column(children: [
            for (final n in rows)
              Card(
                color: AppColors.gold100,
                child: ListTile(
                  title: Text(n.str('body')),
                  trailing: IconButton(tooltip: context.tr('common.delete'), onPressed: () => _delete(n.str('id')), icon: const Icon(Icons.delete_outline)),
                ),
              ),
          ]);
        },
      ),
    ]);
  }
}

/// A training material with its discussion, the person's private notes, and its rating.
class MaterialScreen extends ConsumerWidget {
  const MaterialScreen({super.key, required this.id, this.title = '', this.url = ''});

  final String id, title, url;

  Future<void> _open(BuildContext context, WidgetRef ref) async {
    try {
      var link = url;
      if (link.isEmpty) {
        final res = await ref.read(apiProvider).get('/me/materials/$id/download');
        link = ((res as Map)['data'] as Map)['url'].toString();
      }
      await launchUrl(Uri.parse(link), mode: LaunchMode.externalApplication);
    } catch (e) {
      if (context.mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final thread = ref.watch(getProvider('/social/materials/$id/thread'));
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        backgroundColor: AppColors.ivory,
        appBar: AppBar(
          title: Text(title.isEmpty ? context.tr('training.materials') : title, overflow: TextOverflow.ellipsis),
          actions: [IconButton(tooltip: context.tr('material.open'), onPressed: () => _open(context, ref), icon: const Icon(Icons.open_in_new))],
          bottom: TabBar(tabs: [Tab(text: context.tr('material.discuss')), Tab(text: context.tr('notes.title')), Tab(text: context.tr('rating.title'))]),
        ),
        body: TabBarView(children: [
          thread.when(
            loading: () => const LoadingView(),
            error: (_, _) => EmptyView(text: context.tr('material.noDiscussion')),
            data: (raw) => SpaceFeed(space: Map<String, dynamic>.from((raw as Map)['data'] as Map)),
          ),
          NotesView(type: 'material', id: id),
          ListView(padding: const EdgeInsets.all(16), children: [RatingCard(type: 'material', id: id)]),
        ]),
      ),
    );
  }
}
