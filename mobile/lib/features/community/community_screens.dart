import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'attachments.dart';

String _plain(String html) => html.replaceAll(RegExp(r'<br\s*/?>'), '\n').replaceAll(RegExp(r'</p>'), '\n').replaceAll(RegExp(r'<[^>]+>'), '').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>').trim();

String _title(BuildContext context, Json s) => context.s.isArabic ? s.str('title_ar') : s.str('title_en');

/// Communities, forums and channels: the ones the person belongs to and the open ones to join.
class CommunitiesScreen extends ConsumerWidget {
  const CommunitiesScreen({super.key});

  Future<void> _join(BuildContext context, WidgetRef ref, Json space) async {
    try {
      final res = await ref.read(apiProvider).post('/social/spaces/${space.str('id')}/join');
      final pending = res is Map && res['status'] == 'pending';
      if (context.mounted) showSnack(context, context.tr(pending ? 'soc.requested' : 'soc.joined'));
      ref.invalidate(getProvider('/social/spaces'));
    } catch (e) {
      if (context.mounted) showSnack(context, e.toString(), error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final spaces = ref.watch(getProvider('/social/spaces'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('soc.title'))),
      body: RefreshIndicator(
        onRefresh: () async => ref.invalidate(getProvider('/social/spaces')),
        child: AsyncView(
          value: spaces,
          onRetry: () => ref.invalidate(getProvider('/social/spaces')),
          builder: (raw) {
            final rows = Map<String, dynamic>.from(raw as Map).list('data');
            final mine = rows.where((r) => r.str('my_role').isNotEmpty).toList();
            final other = rows.where((r) => r.str('my_role').isEmpty && r.str('type') == 'community').toList();
            return ListView(padding: const EdgeInsets.all(16), children: [
              Text(context.tr('soc.mine'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              if (mine.isEmpty) Padding(padding: const EdgeInsets.all(12), child: Text(context.tr('soc.emptyMine'))),
              for (final s in mine)
                Card(
                  child: ListTile(
                    leading: Icon(s.str('type') == 'community' ? Icons.groups_outlined : Icons.forum_outlined, color: AppColors.gold500),
                    title: Text(_title(context, s), style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('${context.tr('soc.type.${s.str('type')}')} · ${s.str('posts_count')}'),
                    onTap: () => context.push('/communities/${s.str('id')}'),
                  ),
                ),
              if (other.isNotEmpty) ...[
                const SizedBox(height: 16),
                Text(context.tr('soc.discover'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                const SizedBox(height: 8),
                for (final s in other)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Icon(Icons.groups_outlined, color: AppColors.gold500),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(_title(context, s), style: const TextStyle(fontWeight: FontWeight.w700)),
                              const SizedBox(height: 4),
                              Text(s.str('my_status') == 'pending' ? context.tr('soc.pending') : (context.s.isArabic ? s.str('description_ar') : s.str('description_en')), maxLines: 3, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 13)),
                            ]),
                          ),
                        ]),
                        if (s.str('my_status') != 'pending' && s.str('join_policy') != 'invite') ...[
                          const SizedBox(height: 8),
                          Align(
                            alignment: AlignmentDirectional.centerEnd,
                            child: OutlinedButton(onPressed: () => _join(context, ref, s), child: Text(context.tr(s.str('join_policy') == 'request' ? 'soc.request' : 'soc.join'))),
                          ),
                        ],
                      ]),
                    ),
                  ),
              ],
            ]);
          },
        ),
      ),
    );
  }
}

/// One space: the feed, a new-post button, and a way into each post.
class SpaceScreen extends ConsumerWidget {
  const SpaceScreen({super.key, required this.id});

  final String id;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final space = ref.watch(getProvider('/social/spaces/$id'));
    final s = space.value is Map ? Map<String, dynamic>.from((space.value as Map)['data'] as Map) : <String, dynamic>{};
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.isEmpty ? context.tr('soc.title') : _title(context, s))),
      floatingActionButton: s.flag('can_post') && !s.flag('archived')
          ? FloatingActionButton.extended(onPressed: () => composePost(context, s), icon: const Icon(Icons.edit_outlined), label: Text(context.tr('soc.newPost')))
          : null,
      body: AsyncView(
        value: space,
        onRetry: () => ref.invalidate(getProvider('/social/spaces/$id')),
        builder: (_) => SpaceFeed(space: s, showComposeButton: false),
      ),
    );
  }
}

/// Opens the composer for a new post in [space]; the feed refreshes when it is published.
Future<void> composePost(BuildContext context, Json space) => showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (_) => PostComposerSheet(space: space),
    );

/// The posts of a space (also used inside a material's discussion tab).
class SpaceFeed extends ConsumerWidget {
  const SpaceFeed({super.key, required this.space, this.showComposeButton = true});

  final Json space;
  final bool showComposeButton;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final id = space.str('id');
    final posts = ref.watch(getProvider('/social/spaces/$id/posts'));
    final canPost = space.flag('can_post') && !space.flag('archived');
    return RefreshIndicator(
      onRefresh: () async => ref.invalidate(getProvider('/social/spaces/$id/posts')),
      child: AsyncView(
        value: posts,
        onRetry: () => ref.invalidate(getProvider('/social/spaces/$id/posts')),
        builder: (raw) {
          final rows = Map<String, dynamic>.from(raw as Map).list('data');
          return ListView(padding: const EdgeInsets.fromLTRB(16, 16, 16, 96), children: [
            if (showComposeButton && canPost)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: OutlinedButton.icon(onPressed: () => composePost(context, space), icon: const Icon(Icons.edit_outlined), label: Text(context.tr('soc.newPost'))),
              ),
            if (rows.isEmpty) EmptyView(text: context.tr('soc.noPosts')),
            for (final p in rows)
              Card(
                child: InkWell(
                  borderRadius: BorderRadius.circular(16),
                  onTap: () => context.push('/communities/$id/posts/${p.str('id')}'),
                  child: Padding(
                    padding: const EdgeInsets.all(14),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Row(children: [
                        if (p.flag('is_pinned')) Icon(Icons.push_pin, size: 16, color: AppColors.gold500),
                        Expanded(child: Text(p.obj('author')?.flag('anonymous') ?? false ? context.tr('soc.anonymous') : p.obj('author')?.str('name') ?? '', style: const TextStyle(fontWeight: FontWeight.w700))),
                        Chip(label: Text(context.tr('soc.kind.${p.str('kind')}')), visualDensity: VisualDensity.compact),
                      ]),
                      if (p.str('title').isNotEmpty) Text(p.str('title'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                      if (p.str('body').isNotEmpty) ...[const SizedBox(height: 4), Text(_plain(p.str('body')), maxLines: 4, overflow: TextOverflow.ellipsis)],
                      AttachmentView(items: p.list('attachments'), compact: true),
                      const SizedBox(height: 8),
                      Row(children: [
                        const Icon(Icons.thumb_up_alt_outlined, size: 16, color: AppColors.muted),
                        Text(' ${p.str('reactions_count')}   ', style: const TextStyle(color: AppColors.muted)),
                        const Icon(Icons.chat_bubble_outline, size: 16, color: AppColors.muted),
                        Text(' ${p.str('comments_count')}', style: const TextStyle(color: AppColors.muted)),
                        if (p.str('accepted_answer_id').isNotEmpty) ...[const Spacer(), const Icon(Icons.check_circle, size: 18, color: Colors.green)],
                      ]),
                    ]),
                  ),
                ),
              ),
          ]);
        },
      ),
    );
  }
}

/// Writes a post: a discussion or a question, with files, photos and links.
class PostComposerSheet extends ConsumerStatefulWidget {
  const PostComposerSheet({super.key, required this.space});

  final Json space;

  @override
  ConsumerState<PostComposerSheet> createState() => _PostComposerSheetState();
}

class _PostComposerSheetState extends ConsumerState<PostComposerSheet> {
  final _title = TextEditingController();
  final _body = TextEditingController();
  var _kind = 'discussion';
  bool _busy = false;
  late final AttachmentController _files;

  @override
  void initState() {
    super.initState();
    final limits = FileLimits.fromJson(widget.space.obj('file_limits'));
    _files = AttachmentController(spaceId: widget.space.str('id'), limits: limits, max: limits.filesPerPost, api: ref.read(apiProvider))..addListener(_redraw);
    _body.addListener(_redraw);
  }

  void _redraw() => setState(() {});

  @override
  void dispose() {
    _files.dispose();
    _title.dispose();
    _body.dispose();
    super.dispose();
  }

  bool get _ready => !_busy && !_files.uploading && !_files.failed && (_body.text.trim().isNotEmpty || _files.payload.isNotEmpty);

  Future<void> _publish() async {
    setState(() => _busy = true);
    final id = widget.space.str('id');
    try {
      final esc = _body.text.trim().replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('\n', '<br>');
      final res = await ref.read(apiProvider).post('/social/spaces/$id/posts', {
        'kind': _kind,
        'title': _title.text.trim().isEmpty ? null : _title.text.trim(),
        'body': esc.isEmpty ? '' : '<p>$esc</p>',
        if (_files.payload.isNotEmpty) 'attachments': _files.payload,
      });
      _files.clear();
      ref.invalidate(getProvider('/social/spaces/$id/posts'));
      final held = res is Map && res['data'] is Map && (res['data'] as Map)['status'] == 'hidden';
      if (!mounted) return;
      showSnack(context, context.tr(held ? 'soc.held' : 'soc.published'));
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
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
            Wrap(spacing: 8, children: [
              for (final k in ['discussion', 'question']) ChoiceChip(label: Text(context.tr('soc.kind.$k')), selected: _kind == k, onSelected: (_) => setState(() => _kind = k)),
            ]),
            const SizedBox(height: 8),
            TextField(controller: _title, decoration: InputDecoration(labelText: context.tr('soc.postTitle'))),
            const SizedBox(height: 8),
            TextField(controller: _body, minLines: 3, maxLines: 8, decoration: InputDecoration(labelText: context.tr('soc.postBody'))),
            const SizedBox(height: 8),
            AttachmentTray(controller: _files),
            const SizedBox(height: 8),
            Align(
              alignment: AlignmentDirectional.centerEnd,
              child: FilledButton(key: const ValueKey('publish-post'), onPressed: _ready ? _publish : null, child: Text(context.tr(_files.uploading ? 'files.waiting' : 'soc.publish'))),
            ),
          ]),
        ),
      );
}

/// A post with its comments, a like button and a box to answer.
class PostScreen extends ConsumerStatefulWidget {
  const PostScreen({super.key, required this.id});

  final String id;

  @override
  ConsumerState<PostScreen> createState() => _PostScreenState();
}

class _PostScreenState extends ConsumerState<PostScreen> {
  final _comment = TextEditingController();
  bool _busy = false;
  AttachmentController? _files;

  @override
  void initState() {
    super.initState();
    _comment.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _comment.dispose();
    _files?.dispose();
    super.dispose();
  }

  /// The comment's files follow the limits of the post's space (sent with the post).
  AttachmentController _filesFor(Json post) => _files ??= () {
        final limits = FileLimits.fromJson(post.obj('file_limits'));
        return AttachmentController(spaceId: post.str('space_id'), limits: limits, max: limits.filesPerComment, api: ref.read(apiProvider))..addListener(() => setState(() {}));
      }();

  Future<void> _send() async {
    final files = _files;
    if (_comment.text.trim().isEmpty && (files == null || files.payload.isEmpty)) return;
    setState(() => _busy = true);
    try {
      final esc = _comment.text.trim().replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('\n', '<br>');
      await ref.read(apiProvider).post('/social/posts/${widget.id}/comments', {'body': esc.isEmpty ? null : '<p>$esc</p>', if (files != null && files.payload.isNotEmpty) 'attachments': files.payload});
      files?.clear();
      _comment.clear();
      ref.invalidate(getProvider('/social/posts/${widget.id}'));
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _like(String type, String id) async {
    try {
      await ref.read(apiProvider).post('/social/reactions', {'target_type': type, 'target_id': id, 'type': 'like'});
      ref.invalidate(getProvider('/social/posts/${widget.id}'));
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final post = ref.watch(getProvider('/social/posts/${widget.id}'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('soc.post'))),
      body: AsyncView(
        value: post,
        onRetry: () => ref.invalidate(getProvider('/social/posts/${widget.id}')),
        builder: (raw) {
          final p = Map<String, dynamic>.from((raw as Map)['data'] as Map);
          final comments = p.list('comments');
          return Column(children: [
            Expanded(
              child: ListView(padding: const EdgeInsets.all(16), children: [
                Text(p.obj('author')?.flag('anonymous') ?? false ? context.tr('soc.anonymous') : p.obj('author')?.str('name') ?? '', style: const TextStyle(fontWeight: FontWeight.w700)),
                if (p.str('title').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 4), child: Text(p.str('title'), style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
                const SizedBox(height: 8),
                if (p.str('body').isNotEmpty) Text(_plain(p.str('body'))),
                AttachmentView(items: p.list('attachments')),
                if (p.obj('poll') != null) ..._poll(context, p.obj('poll')!),
                const SizedBox(height: 8),
                Row(children: [
                  TextButton.icon(onPressed: () => _like('post', widget.id), icon: Icon(p.str('my_reaction').isEmpty ? Icons.thumb_up_alt_outlined : Icons.thumb_up_alt), label: Text(p.str('reactions_count'))),
                ]),
                const Divider(),
                for (final c in comments)
                  Card(
                    color: c.flag('accepted') ? Colors.green.shade50 : null,
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          Expanded(child: Text(c.obj('author')?.str('name') ?? '', style: const TextStyle(fontWeight: FontWeight.w700))),
                          if (c.flag('accepted')) const Icon(Icons.check_circle, size: 18, color: Colors.green),
                        ]),
                        if (c.str('body').isNotEmpty) Text(_plain(c.str('body'))) else if (c.list('attachments').isEmpty) Text(context.tr('soc.hidden')),
                        AttachmentView(items: c.list('attachments'), compact: true),
                        Align(alignment: AlignmentDirectional.centerEnd, child: TextButton.icon(onPressed: () => _like('comment', c.str('id')), icon: const Icon(Icons.thumb_up_alt_outlined, size: 16), label: Text(c.str('reactions')))),
                      ]),
                    ),
                  ),
              ]),
            ),
            if (!p.flag('is_locked'))
              SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
                  child: Column(mainAxisSize: MainAxisSize.min, children: [
                    if (p.obj('file_limits') != null) AttachmentTray(controller: _filesFor(p), compact: true),
                    Row(children: [
                      Expanded(child: TextField(controller: _comment, minLines: 1, maxLines: 4, decoration: InputDecoration(hintText: context.tr('soc.writeComment')))),
                      IconButton(
                        tooltip: context.tr('soc.send'),
                        onPressed: _busy || (_files?.uploading ?? false) || (_files?.failed ?? false) || (_comment.text.trim().isEmpty && (_files?.payload.isEmpty ?? true)) ? null : _send,
                        icon: Icon(Icons.send_rounded, color: AppColors.gold500),
                      ),
                    ]),
                  ]),
                ),
              ),
          ]);
        },
      ),
    );
  }

  List<Widget> _poll(BuildContext context, Json poll) {
    final options = poll.list('options');
    final mine = (poll['mine'] as List? ?? const []).map((e) => e.toString()).toSet();
    return [
      const SizedBox(height: 12),
      Text(poll.str('question'), style: const TextStyle(fontWeight: FontWeight.w700)),
      for (final o in options)
        ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          leading: Icon(mine.contains(o.str('id')) ? Icons.radio_button_checked : Icons.radio_button_off, color: AppColors.gold500),
          title: Text(o.str('text')),
          trailing: Text(o.str('votes')),
          onTap: () async {
            try {
              await ref.read(apiProvider).post('/social/polls/${poll.str('id')}/vote', {'option_ids': [o.str('id')]});
              ref.invalidate(getProvider('/social/posts/${widget.id}'));
            } catch (e) {
              if (context.mounted) showSnack(context, e.toString(), error: true);
            }
          },
        ),
    ];
  }
}
