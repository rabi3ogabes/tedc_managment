import 'dart:io';

import 'package:dio/dio.dart';
import 'package:file_picker/file_picker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import 'package:open_filex/open_filex.dart';
import 'package:path_provider/path_provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

const _mb = 1024 * 1024;

/// What a space lets people attach (sent by the server with the space).
class FileLimits {
  const FileLimits({this.allowed = true, this.fileMaxMb = 10, this.filesPerPost = 10, this.filesPerComment = 5, this.directMaxMb = 4, this.extensions = _defaultExtensions});

  factory FileLimits.fromJson(Json? j) => j == null
      ? const FileLimits()
      : FileLimits(
          allowed: j['allowed'] != false,
          fileMaxMb: j.number('file_max_mb').toInt(),
          filesPerPost: j.number('files_per_post').toInt(),
          filesPerComment: j.number('files_per_comment').toInt(),
          directMaxMb: (j['direct_max_mb'] as num?)?.toInt() ?? 4,
          extensions: (j['extensions'] as List? ?? _defaultExtensions).map((e) => e.toString()).toList(),
        );

  static const _defaultExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp4', 'mp3', 'm4a', 'zip'];

  final bool allowed;
  final int fileMaxMb, filesPerPost, filesPerComment, directMaxMb;
  final List<String> extensions;
}

String fileExtension(String name) => name.contains('.') ? name.split('.').last.toLowerCase() : '';

/// A file or link being added to a post or comment.
class AttachmentDraft {
  AttachmentDraft.file({required this.name, required this.size, required this.path}) : url = null;
  AttachmentDraft.link({required this.url, required this.name}) : size = 0, path = null;

  final String name;
  final int size;
  final String? path;
  final String? url;
  double progress = 0;
  String status = 'uploading'; // uploading | ready | error
  String? uploadId;
  String? error;
  CancelToken? cancel;

  bool get isLink => url != null;
  bool get isImage => const ['jpg', 'jpeg', 'png', 'webp', 'gif'].contains(fileExtension(name));
}

/// Sends one file and returns its upload id; [progress] goes from 0 to 1.
typedef Uploader = Future<String> Function(AttachmentDraft draft, void Function(double) progress, CancelToken cancel);

/// The files and links of one post or comment. Small files go through the API, big ones straight to storage; the
/// server checks every file again before it can be attached.
class AttachmentController extends ChangeNotifier {
  AttachmentController({required this.spaceId, required this.limits, required this.max, ApiClient? api, Uploader? uploader}) : _api = api, _uploader = uploader; // ignore: prefer_initializing_formals

  final String spaceId;
  final FileLimits limits;
  final int max;
  final ApiClient? _api;
  final Uploader? _uploader;
  final List<AttachmentDraft> drafts = [];
  bool _disposed = false;

  bool get uploading => drafts.any((d) => d.status == 'uploading');
  bool get failed => drafts.any((d) => d.status == 'error');
  bool get isEmpty => drafts.isEmpty;

  /// What the post or comment sends: uploads that finished, and links.
  List<Map<String, dynamic>> get payload => [
        for (final d in drafts)
          if (d.isLink) {'type': 'link', 'url': d.url, 'name': d.name} else if (d.status == 'ready' && d.uploadId != null) {'type': 'file', 'upload_id': d.uploadId},
      ];

  /// Starts sending a picked file. Returns the reason (a string key) when it is refused, or null.
  String? addFile(String path, String name, int size) {
    if (drafts.length >= max) return 'files.tooMany';
    if (!limits.extensions.contains(fileExtension(name))) return 'files.badType';
    if (size <= 0 || size > limits.fileMaxMb * _mb) return 'files.tooBig';
    final d = AttachmentDraft.file(name: name, size: size, path: path);
    drafts.add(d);
    _changed();
    _send(d);
    return null;
  }

  String? addLink(String url, String name) {
    if (drafts.length >= max) return 'files.tooMany';
    final u = url.trim();
    if (!RegExp(r'^https?://[^\s<>"]+$', caseSensitive: false).hasMatch(u)) return 'files.badLink';
    final d = AttachmentDraft.link(url: u, name: name.trim().isEmpty ? u : name.trim())..status = 'ready';
    drafts.add(d);
    _changed();
    return null;
  }

  void retry(AttachmentDraft d) => _send(d);

  void remove(AttachmentDraft d) {
    d.cancel?.cancel();
    if (d.uploadId != null && _api != null) _api.delete('/social/uploads/${d.uploadId}').catchError((_) => null);
    drafts.remove(d);
    _changed();
  }

  /// After the post or comment was sent: the uploads now belong to it.
  void clear() {
    drafts.clear();
    _changed();
  }

  Future<void> _send(AttachmentDraft d) async {
    d
      ..status = 'uploading'
      ..progress = 0
      ..error = null
      ..cancel = CancelToken();
    _changed();
    try {
      d.uploadId = await (_uploader ?? _defaultUpload)(d, (p) {
        d.progress = p.clamp(0, 1).toDouble();
        _changed();
      }, d.cancel!);
      d
        ..status = 'ready'
        ..progress = 1;
    } catch (e) {
      if (d.cancel?.isCancelled ?? false) return;
      d
        ..status = 'error'
        ..error = ApiException.from(e).message;
    }
    _changed();
  }

  Future<String> _defaultUpload(AttachmentDraft d, void Function(double) progress, CancelToken cancel) async {
    final api = _api!;
    if (d.size <= limits.directMaxMb * _mb) {
      final form = FormData.fromMap({'space_id': spaceId, 'file': await MultipartFile.fromFile(d.path!, filename: d.name)});
      final res = await api.upload('/social/uploads', form, onProgress: progress, cancel: cancel);
      return ((res as Map)['data'] as Map)['id'].toString();
    }
    final ext = fileExtension(d.name);
    final signed = await api.post('/social/uploads/sign', {'space_id': spaceId, 'name': d.name, 'mime': _mime(ext), 'size': d.size});
    final data = Map<String, dynamic>.from((signed as Map)['data'] as Map);
    d.uploadId = data['id'].toString();
    await api.putSigned(Map<String, dynamic>.from(data['upload'] as Map), d.path!, d.name, onProgress: (p) => progress(p * .99), cancel: cancel);
    await api.post('/social/uploads/${d.uploadId}/complete');
    return d.uploadId!;
  }

  void _changed() {
    if (!_disposed) notifyListeners();
  }

  @override
  void dispose() {
    _disposed = true;
    for (final d in drafts) {
      d.cancel?.cancel();
    }
    super.dispose();
  }
}

String _mime(String ext) => switch (ext) {
      'pdf' => 'application/pdf',
      'jpg' || 'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'webp' => 'image/webp',
      'gif' => 'image/gif',
      'mp4' => 'video/mp4',
      'mp3' => 'audio/mpeg',
      'm4a' => 'audio/mp4',
      'zip' => 'application/zip',
      _ => 'application/octet-stream',
    };

String fileSize(int bytes) => bytes >= _mb ? '${(bytes / _mb).toStringAsFixed(1)} MB' : '${(bytes / 1024).ceil()} KB';

/// The attach, camera and link buttons plus a chip for each file with its progress.
class AttachmentTray extends StatelessWidget {
  const AttachmentTray({super.key, required this.controller, this.compact = false});

  final AttachmentController controller;
  final bool compact;

  Future<void> _pickFiles(BuildContext context) async {
    // pickFiles lets the person choose several files at once (an empty list when they cancel).
    final picked = await FilePicker.pickFiles(type: FileType.custom, allowedExtensions: controller.limits.extensions);
    if (!context.mounted) return;
    for (final f in picked) {
      final path = f.path;
      if (path == null) continue;
      final size = await File(path).length();
      if (!context.mounted) return;
      _report(context, controller.addFile(path, f.name, size), f.name);
    }
  }

  Future<void> _camera(BuildContext context) async {
    final shot = await ImagePicker().pickImage(source: ImageSource.camera, imageQuality: 85, maxWidth: 2560);
    if (shot == null) return;
    final size = await File(shot.path).length();
    if (context.mounted) _report(context, controller.addFile(shot.path, shot.name, size), shot.name);
  }

  Future<void> _link(BuildContext context) async {
    final url = TextEditingController();
    final name = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(ctx.tr('files.link')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(controller: url, keyboardType: TextInputType.url, textDirection: TextDirection.ltr, decoration: const InputDecoration(hintText: 'https://')),
          const SizedBox(height: 8),
          TextField(controller: name, decoration: InputDecoration(labelText: ctx.tr('files.linkName'))),
        ]),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: Text(ctx.tr('common.cancel'))),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: Text(ctx.tr('files.add'))),
        ],
      ),
    );
    if (ok == true && context.mounted) _report(context, controller.addLink(url.text, name.text), url.text);
  }

  void _report(BuildContext context, String? reason, String name) {
    if (reason == null) return;
    final limits = controller.limits;
    final text = context.tr(reason).replaceAll('{name}', name).replaceAll('{mb}', '${limits.fileMaxMb}').replaceAll('{n}', '${controller.max}');
    showSnack(context, text, error: true);
  }

  @override
  Widget build(BuildContext context) {
    if (!controller.limits.allowed) return const SizedBox.shrink();
    return ListenableBuilder(
      listenable: controller,
      builder: (context, _) {
        final full = controller.drafts.length >= controller.max;
        return Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          for (final d in controller.drafts) _DraftChip(draft: d, onRemove: () => controller.remove(d), onRetry: () => controller.retry(d)),
          Wrap(spacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
            TextButton.icon(key: const ValueKey('attach-file'), onPressed: full ? null : () => _pickFiles(context), icon: const Icon(Icons.attach_file, size: 20), label: Text(context.tr('files.attach'))),
            IconButton(tooltip: context.tr('files.camera'), onPressed: full ? null : () => _camera(context), icon: const Icon(Icons.photo_camera_outlined)),
            IconButton(tooltip: context.tr('files.link'), onPressed: full ? null : () => _link(context), icon: const Icon(Icons.link)),
            if (!compact)
              Text(context.tr('files.hint').replaceAll('{n}', '${controller.max}').replaceAll('{mb}', '${controller.limits.fileMaxMb}'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          ]),
        ]);
      },
    );
  }
}

class _DraftChip extends StatelessWidget {
  const _DraftChip({required this.draft, required this.onRemove, required this.onRetry});

  final AttachmentDraft draft;
  final VoidCallback onRemove, onRetry;

  @override
  Widget build(BuildContext context) {
    final d = draft;
    final error = d.status == 'error';
    return Container(
      margin: const EdgeInsets.only(bottom: 6),
      padding: const EdgeInsets.fromLTRB(8, 6, 4, 6),
      decoration: BoxDecoration(
        color: error ? AppColors.danger.withValues(alpha: .06) : Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: error ? AppColors.danger.withValues(alpha: .4) : AppColors.navy100),
      ),
      child: Row(children: [
        SizedBox(
          width: 40,
          height: 40,
          child: Stack(alignment: Alignment.center, children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: d.isImage && d.path != null
                  ? Image.file(File(d.path!), width: 40, height: 40, fit: BoxFit.cover, cacheWidth: 120)
                  : Container(width: 40, height: 40, color: AppColors.navy100, child: Icon(d.isLink ? Icons.link : fileIcon(d.name), color: AppColors.navy800, size: 20)),
            ),
            if (d.status == 'uploading') SizedBox(width: 34, height: 34, child: CircularProgressIndicator(value: d.progress > 0 ? d.progress : null, strokeWidth: 3, color: AppColors.gold500, backgroundColor: Colors.white70)),
          ]),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(d.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
            Text(
              d.isLink ? Uri.tryParse(d.url!)?.host ?? d.url! : error ? (d.error ?? '') : d.status == 'uploading' ? '${(d.progress * 100).round()}%' : fileSize(d.size),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontSize: 12, color: error ? AppColors.danger : AppColors.muted),
            ),
          ]),
        ),
        if (error) IconButton(tooltip: context.tr('files.retry'), onPressed: onRetry, icon: const Icon(Icons.refresh, size: 20)),
        IconButton(tooltip: context.tr('files.remove'), onPressed: onRemove, icon: const Icon(Icons.close, size: 20)),
      ]),
    );
  }
}

IconData fileIcon(String name) => switch (fileExtension(name)) {
      'pdf' => Icons.picture_as_pdf_outlined,
      'xls' || 'xlsx' => Icons.table_chart_outlined,
      'ppt' || 'pptx' => Icons.slideshow_outlined,
      'zip' => Icons.folder_zip_outlined,
      'mp3' || 'm4a' => Icons.audiotrack_outlined,
      'mp4' => Icons.movie_outlined,
      _ => Icons.description_outlined,
    };

/// What a post or comment carries: an image grid that opens a viewer, and cards for files and links.
class AttachmentView extends ConsumerWidget {
  const AttachmentView({super.key, required this.items, this.compact = false});

  final List<Json> items;
  final bool compact;

  Future<void> _openFile(BuildContext context, WidgetRef ref, Json a) async {
    try {
      // A fresh short-lived link, then the file is saved in the app's temporary folder and opened.
      final res = await ref.read(apiProvider).get('/social/files/${a.str('id')}');
      final url = ((res as Map)['data'] as Map)['url'].toString();
      final dir = await getTemporaryDirectory();
      final safe = a.str('name').replaceAll(RegExp(r'[\\/:*?"<>|]'), '_');
      final file = File('${dir.path}/${a.str('id')}_$safe');
      await Dio().download(url, file.path);
      await OpenFilex.open(file.path, type: a.str('mime'));
    } catch (e) {
      if (context.mounted) showSnack(context, ApiException.from(e).message, error: true);
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (items.isEmpty) return const SizedBox.shrink();
    final images = items.where((a) => a.str('type') == 'file' && a.str('kind') == 'image').toList();
    final others = items.where((a) => !(a.str('type') == 'file' && a.str('kind') == 'image')).toList();
    return Padding(
      padding: EdgeInsets.only(top: compact ? 6 : 10),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        if (images.isNotEmpty) _Grid(images: images, compact: compact),
        for (final a in others)
          Card(
            margin: const EdgeInsets.only(top: 6),
            child: ListTile(
              dense: true,
              leading: Icon(a.str('type') == 'link' ? Icons.link : fileIcon(a.str('name')), color: a.str('kind') == 'pdf' ? AppColors.danger : AppColors.navy800),
              title: Text(a.str('name'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(a.str('type') == 'link' ? (Uri.tryParse(a.str('url'))?.host ?? '') : '${fileExtension(a.str('name')).toUpperCase()} · ${fileSize(a.number('size').toInt())}', textDirection: TextDirection.ltr),
              trailing: Icon(a.str('type') == 'link' ? Icons.open_in_new : Icons.download_outlined, size: 20),
              onTap: () => a.str('type') == 'link' ? launchUrl(Uri.parse(a.str('url')), mode: LaunchMode.externalApplication) : _openFile(context, ref, a),
            ),
          ),
      ]),
    );
  }
}

class _Grid extends StatelessWidget {
  const _Grid({required this.images, required this.compact});

  final List<Json> images;
  final bool compact;

  void _open(BuildContext context, int index) => Navigator.of(context).push(MaterialPageRoute<void>(fullscreenDialog: true, builder: (_) => ImageViewer(images: images, start: index)));

  @override
  Widget build(BuildContext context) {
    if (images.length == 1) {
      final a = images.first;
      final w = a.number('width'), h = a.number('height');
      return GestureDetector(
        onTap: () => _open(context, 0),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(16),
          child: AspectRatio(aspectRatio: w > 0 && h > 0 ? (w / h).clamp(.6, 2.2).toDouble() : 4 / 3, child: _NetImage(url: a.str('url'), label: a.str('name'))),
        ),
      );
    }
    final shown = images.take(4).toList();
    return ClipRRect(
      borderRadius: BorderRadius.circular(16),
      child: GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: 4,
        crossAxisSpacing: 4,
        children: [
          for (var i = 0; i < shown.length; i++)
            GestureDetector(
              onTap: () => _open(context, i),
              child: Stack(fit: StackFit.expand, children: [
                _NetImage(url: shown[i].str('thumb_url').isNotEmpty ? shown[i].str('thumb_url') : shown[i].str('url'), label: shown[i].str('name')),
                if (i == 3 && images.length > 4)
                  Container(color: Colors.black54, alignment: Alignment.center, child: Text('+${images.length - 4}', style: const TextStyle(color: Colors.white, fontSize: 24, fontWeight: FontWeight.w800))),
              ]),
            ),
        ],
      ),
    );
  }
}

class _NetImage extends StatelessWidget {
  const _NetImage({required this.url, required this.label});

  final String url, label;

  @override
  Widget build(BuildContext context) => Semantics(
        image: true,
        label: label,
        child: url.isEmpty
            ? Container(color: AppColors.navy100)
            : Image.network(url, fit: BoxFit.cover, errorBuilder: (_, _, _) => Container(color: AppColors.navy100, child: const Icon(Icons.broken_image_outlined, color: AppColors.muted))),
      );
}

/// Full-screen photos: swipe between them and pinch to zoom.
class ImageViewer extends StatefulWidget {
  const ImageViewer({super.key, required this.images, required this.start});

  final List<Json> images;
  final int start;

  @override
  State<ImageViewer> createState() => _ImageViewerState();
}

class _ImageViewerState extends State<ImageViewer> {
  late final _pages = PageController(initialPage: widget.start);
  late int _index = widget.start;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        backgroundColor: Colors.black,
        appBar: AppBar(
          backgroundColor: Colors.black,
          foregroundColor: Colors.white,
          title: Text(widget.images[_index].str('name'), overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 15)),
          actions: [if (widget.images.length > 1) Center(child: Padding(padding: const EdgeInsetsDirectional.only(end: 16), child: Text('${_index + 1} / ${widget.images.length}', textDirection: TextDirection.ltr)))],
        ),
        body: PageView.builder(
          controller: _pages,
          itemCount: widget.images.length,
          onPageChanged: (i) => setState(() => _index = i),
          itemBuilder: (_, i) => InteractiveViewer(maxScale: 4, child: Center(child: Image.network(widget.images[i].str('url'), fit: BoxFit.contain))),
        ),
      );
}
