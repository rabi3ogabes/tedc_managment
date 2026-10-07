import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// Announcements (pinned first, with audio, video, pictures and links) and events with registration.
class EventsScreen extends ConsumerStatefulWidget {
  const EventsScreen({super.key});

  @override
  ConsumerState<EventsScreen> createState() => _EventsScreenState();
}

class _EventsScreenState extends ConsumerState<EventsScreen> {
  Future<void> _open(String url) async {
    final uri = Uri.tryParse(url);
    if (uri != null && (uri.scheme == 'https' || uri.scheme == 'http')) await launchUrl(uri, mode: LaunchMode.externalApplication);
  }

  Future<void> _rsvp(Json e, bool going) async {
    try {
      await ref.read(apiProvider).post('/me/events/${e.str('id')}/rsvp', {'going': going});
      ref.invalidate(getProvider('/me/events'));
    } catch (err) {
      if (mounted) showSnack(context, err.toString(), error: true);
    }
  }

  List<Widget> _media(Json a) {
    final media = a.obj('media') ?? const {};
    final out = <Widget>[];
    for (final kind in ['audio', 'video', 'links']) {
      final items = (media[kind] as List? ?? const []).whereType<Map>();
      for (final m in items) {
        final url = m['url']?.toString() ?? '';
        if (url.isEmpty) continue;
        final label = (m['title']?.toString().isNotEmpty ?? false) ? m['title'].toString() : url;
        out.add(ListTile(
          dense: true,
          contentPadding: EdgeInsets.zero,
          leading: Icon(kind == 'audio' ? Icons.headphones : kind == 'video' ? Icons.play_circle_outline : Icons.link, color: AppColors.gold500),
          title: Text(label, maxLines: 1, overflow: TextOverflow.ellipsis),
          onTap: () => _open(url),
        ));
      }
    }
    return out;
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final announcements = ref.watch(getProvider('/me/announcements'));
    final events = ref.watch(getProvider('/me/events'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('events.title'))),
      body: RefreshIndicator(
        onRefresh: () async {
          ref.invalidate(getProvider('/me/announcements'));
          ref.invalidate(getProvider('/me/events'));
        },
        child: ListView(padding: const EdgeInsets.all(16), children: [
          Text(s.t('events.events'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          AsyncView(
            value: events,
            onRetry: () => ref.invalidate(getProvider('/me/events')),
            builder: (raw) {
              final rows = Map<String, dynamic>.from(raw as Map).list('data');
              if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(12), child: Text(s.t('events.empty')));
              return Column(children: [
                for (final e in rows)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Row(children: [
                          if (e.flag('is_pinned')) Icon(Icons.push_pin, size: 16, color: AppColors.gold500),
                          Expanded(child: Text(e.str('title'), style: const TextStyle(fontWeight: FontWeight.w800))),
                        ]),
                        if (e.obj('event')?.str('starts_at').isNotEmpty ?? false) Text(e.obj('event')!.str('starts_at').substring(0, 16).replaceFirst('T', ' '), style: const TextStyle(color: Colors.black54)),
                        if (e.obj('event')?.str('venue').isNotEmpty ?? false) Text(e.obj('event')!.str('venue')),
                        const SizedBox(height: 6),
                        Text(e.str('excerpt'), maxLines: 3, overflow: TextOverflow.ellipsis),
                        const SizedBox(height: 8),
                        if (e.obj('event')?.flag('rsvp') ?? false)
                          Row(children: [
                            if (e.str('my_rsvp') == 'going') Chip(label: Text(s.t('events.going'))),
                            if (e.str('my_rsvp') == 'waitlisted') Chip(label: Text(s.t('events.waitlisted'))),
                            const Spacer(),
                            if (e.str('my_rsvp') == 'going' || e.str('my_rsvp') == 'waitlisted')
                              OutlinedButton(style: OutlinedButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: () => _rsvp(e, false), child: Text(s.t('events.cancel')))
                            else
                              FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(0, 48)), onPressed: () => _rsvp(e, true), child: Text(s.t('events.register'))),
                          ])
                        else if (e.obj('event')?.str('registration_url').isNotEmpty ?? false)
                          Align(alignment: AlignmentDirectional.centerEnd, child: FilledButton(onPressed: () => _open(e.obj('event')!.str('registration_url')), child: Text(s.t('events.externalRegister')))),
                      ]),
                    ),
                  ),
              ]);
            },
          ),
          const SizedBox(height: 16),
          Text(s.t('events.announcements'), style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          AsyncView(
            value: announcements,
            onRetry: () => ref.invalidate(getProvider('/me/announcements')),
            builder: (raw) {
              final rows = Map<String, dynamic>.from(raw as Map).list('data');
              if (rows.isEmpty) return Padding(padding: const EdgeInsets.all(12), child: Text(s.t('events.noAnnouncements')));
              return Column(children: [
                for (final a in rows)
                  Card(
                    child: ExpansionTile(
                      leading: Icon(a.flag('is_pinned') ? Icons.push_pin : Icons.campaign_outlined, color: AppColors.gold500),
                      title: Text(a.str('title'), style: const TextStyle(fontWeight: FontWeight.w700)),
                      childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                      expandedCrossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(a.str('body').isNotEmpty ? a.str('body') : a.str('excerpt')),
                        ..._media(a),
                      ],
                    ),
                  ),
              ]);
            },
          ),
        ]),
      ),
    );
  }
}
