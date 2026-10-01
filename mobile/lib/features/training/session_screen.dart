import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// One session: when and where, and the way to be marked present — scan the venue's QR code for an in-person
/// session, or join the meeting for an online one (the join is the attendance). Notifications about a session
/// (reminders, "mark your attendance") open this page.
class SessionScreen extends ConsumerStatefulWidget {
  const SessionScreen({super.key, required this.id});

  final String id;

  @override
  ConsumerState<SessionScreen> createState() => _SessionScreenState();
}

class _SessionScreenState extends ConsumerState<SessionScreen> {
  Timer? _tick;
  bool _busy = false;
  Json? _join; // link and passcode returned by the last successful join

  String get _path => '/me/sessions/${widget.id}';

  @override
  void initState() {
    super.initState();
    // Keeps the countdown and the join window fresh while the page is open.
    _tick = Timer.periodic(const Duration(seconds: 20), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  Future<void> _joinSession() async {
    setState(() => _busy = true);
    try {
      final res = await ref.read(apiProvider).post('$_path/join');
      final data = Map<String, dynamic>.from(res['data'] as Map);
      setState(() => _join = data);
      ref.invalidate(getProvider(_path));
      ref.invalidate(getProvider('/me/registrations'));
      ref.invalidate(getProvider('/me/home'));
      await _openLink(data.str('join_url'));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _leave() async {
    setState(() => _busy = true);
    try {
      await ref.read(apiProvider).post('$_path/leave');
      ref.invalidate(getProvider(_path));
      ref.invalidate(getProvider('/me/registrations'));
    } catch (e) {
      if (mounted) showSnack(context, ApiException.from(e).message, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _openLink(String url) async {
    final uri = Uri.tryParse(url);
    if (uri == null || url.isEmpty) {
      if (mounted) showSnack(context, context.s.t('session.noLink'), error: true);
      return;
    }
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication) && mounted) showSnack(context, context.s.t('session.noLink'), error: true);
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final value = ref.watch(getProvider(_path));

    return Scaffold(
      appBar: AppBar(title: Text(s.t('session.title'))),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(getProvider(_path).future),
        child: AsyncView(
          value: value,
          onRetry: () => ref.invalidate(getProvider(_path)),
          builder: (raw) {
            final d = Map<String, dynamic>.from((raw as Map)['data'] as Map);
            final online = d.str('mode') == 'online';
            final onlineInfo = d.obj('online');
            final attendance = d.obj('attendance');
            final start = d.date('starts_at');
            final end = d.date('ends_at');
            final now = DateTime.now();
            final cancelled = d.str('status') == 'cancelled';
            final ended = end != null && end.isBefore(now);
            final live = start != null && end != null && !start.isAfter(now) && !ended;
            final joined = attendance != null && attendance.str('check_in_at').isNotEmpty;
            final left = attendance != null && attendance.str('check_in_at').isNotEmpty && attendance.str('check_out_at').isNotEmpty;
            final state = cancelled ? 'cancelled' : ended ? 'ended' : live ? 'live' : 'upcoming';

            return ListView(padding: const EdgeInsets.fromLTRB(16, 8, 16, 32), children: [
              Container(
                padding: const EdgeInsets.all(20),
                decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(26)),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    _Pill(icon: online ? Icons.videocam_outlined : Icons.place_outlined, label: s.t(online ? 'session.online' : 'session.inPerson')),
                    const SizedBox(width: 8),
                    _Pill(icon: live ? Icons.fiber_manual_record : Icons.schedule, label: s.t('session.state.$state'), highlight: live),
                  ]),
                  const SizedBox(height: 16),
                  Text(d.obj('program')?.str('title') ?? '', style: const TextStyle(color: AppColors.gold300, fontWeight: FontWeight.w700)),
                  const SizedBox(height: 4),
                  Text(d.str('title'), style: const TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 14),
                  Text('${fmt.weekdayDate(start)}\n${fmt.time(start)} – ${fmt.time(end)} · ${fmt.number(d.number('duration_minutes'))} ${s.t('session.minutes')}', style: const TextStyle(color: Colors.white70, height: 1.5)),
                  if (!online && d.str('location').isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Row(children: [const Icon(Icons.place_outlined, size: 16, color: AppColors.gold300), const SizedBox(width: 6), Expanded(child: Text(d.str('location'), style: const TextStyle(color: Colors.white)))]),
                  ],
                  if (d.str('trainer').isNotEmpty) ...[
                    const SizedBox(height: 8),
                    Row(children: [const Icon(Icons.person_outline, size: 16, color: AppColors.gold300), const SizedBox(width: 6), Text(d.str('trainer'), style: const TextStyle(color: Colors.white))]),
                  ],
                ]),
              ),
              const SizedBox(height: 16),

              // The way to be marked present.
              if (!cancelled && !ended) ...[
                if (online && onlineInfo != null) _OnlineCard(info: onlineInfo, joined: joined && !left, busy: _busy, join: _join, fmt: fmt, onJoin: _joinSession, onLeave: _leave, onOpen: _openLink)
                else if (!online && d.flag('can_scan'))
                  FilledButton.icon(
                    onPressed: () => context.push('/scan'),
                    style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54)),
                    icon: const Icon(Icons.qr_code_scanner),
                    label: Text(s.t('session.scan')),
                  )
                else if (!online)
                  _Note(icon: Icons.info_outline, text: s.t('session.scanLater')),
              ],
              if (cancelled) _Note(icon: Icons.event_busy_outlined, text: s.t('session.cancelledNote'), danger: true),

              // Attendance so far.
              if (attendance != null) ...[
                SectionTitle(s.t('session.attendance')),
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(children: [
                      Row(children: [Text(s.t('session.yourStatus'), style: const TextStyle(color: AppColors.muted)), const Spacer(), StatusChip(attendance.str('status'))]),
                      const Divider(height: 22),
                      _Line(s.t('session.joinedAt'), fmt.time(attendance.date('check_in_at'))),
                      if (left) _Line(s.t('session.leftAt'), fmt.time(attendance.date('check_out_at'))),
                      _Line(s.t('session.minutesAttended'), fmt.number(attendance.number('minutes_attended'))),
                    ]),
                  ),
                ),
              ] else if (ended && !cancelled)
                Padding(padding: const EdgeInsets.only(top: 8), child: _Note(icon: Icons.person_off_outlined, text: s.t('session.notAttended'), danger: true)),

              if ((onlineInfo?.str('recording_url') ?? '').isNotEmpty) ...[
                SectionTitle(s.t('session.recording')),
                OutlinedButton.icon(onPressed: () => _openLink(onlineInfo!.str('recording_url')), icon: const Icon(Icons.play_circle_outline), label: Text(s.t('session.watchRecording'))),
              ],
              if (d.str('description').isNotEmpty) ...[
                SectionTitle(s.t('session.about')),
                Text(d.str('description'), style: const TextStyle(height: 1.6)),
              ],
              const SizedBox(height: 16),
              TextButton.icon(
                onPressed: () => context.push('/registrations/${d.str('registration_id')}'),
                icon: const Icon(Icons.school_outlined),
                label: Text(s.t('session.openProgram')),
              ),
            ]);
          },
        ),
      ),
    );
  }
}

class _OnlineCard extends StatelessWidget {
  const _OnlineCard({required this.info, required this.joined, required this.busy, required this.join, required this.fmt, required this.onJoin, required this.onLeave, required this.onOpen});

  final Json info;
  final bool joined;
  final bool busy;
  final Json? join;
  final Fmt fmt;
  final VoidCallback onJoin;
  final VoidCallback onLeave;
  final Future<void> Function(String) onOpen;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final canJoin = info.flag('can_join');
    final opens = info.date('opens_at');
    final passcode = join?.str('passcode') ?? '';
    final url = join?.str('join_url') ?? '';
    final platform = (join?.str('platform').isNotEmpty ?? false) ? join!.str('platform') : info.str('platform');

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          if (platform.isNotEmpty) Text(s.t('session.platform.$platform'), style: const TextStyle(color: AppColors.gold700, fontWeight: FontWeight.w700)),
          if (info.str('instructions').isNotEmpty) Padding(padding: const EdgeInsets.only(top: 6), child: Text(info.str('instructions'), style: const TextStyle(height: 1.5, fontSize: 13))),
          const SizedBox(height: 14),
          FilledButton.icon(
            onPressed: busy || !canJoin ? null : onJoin,
            style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54), backgroundColor: AppColors.success),
            icon: busy ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.videocam),
            label: Text(joined ? s.t('session.rejoin') : s.t('session.join')),
          ),
          if (!canJoin && opens != null) Padding(padding: const EdgeInsets.only(top: 10), child: Text('${s.t('session.opensAt')} ${fmt.dateTime(opens)}', textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, fontSize: 12.5))),
          if (canJoin) Padding(padding: const EdgeInsets.only(top: 10), child: Text(s.t('session.joinHint'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, fontSize: 12.5))),
          if (passcode.isNotEmpty) ...[
            const SizedBox(height: 12),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .6), borderRadius: BorderRadius.circular(14)),
              child: Row(children: [
                Text('${s.t('session.passcode')}: ', style: const TextStyle(color: AppColors.muted)),
                Expanded(child: Text(passcode, textDirection: TextDirection.ltr, style: const TextStyle(fontWeight: FontWeight.w800, letterSpacing: 1))),
                IconButton(
                  icon: const Icon(Icons.copy, size: 18),
                  onPressed: () {
                    Clipboard.setData(ClipboardData(text: passcode));
                    showSnack(context, s.t('session.copied'));
                  },
                ),
              ]),
            ),
          ],
          if (url.isNotEmpty) TextButton.icon(onPressed: () => onOpen(url), icon: const Icon(Icons.open_in_new, size: 18), label: Text(s.t('session.openLink'))),
          if (joined) TextButton.icon(onPressed: busy ? null : onLeave, icon: const Icon(Icons.logout, size: 18), label: Text(s.t('session.leave'))),
        ]),
      ),
    );
  }
}

class _Pill extends StatelessWidget {
  const _Pill({required this.icon, required this.label, this.highlight = false});

  final IconData icon;
  final String label;
  final bool highlight;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: highlight ? AppColors.success : Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(99)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 13, color: Colors.white), const SizedBox(width: 5), Text(label, style: const TextStyle(color: Colors.white, fontSize: 11.5, fontWeight: FontWeight.w700))]),
      );
}

class _Note extends StatelessWidget {
  const _Note({required this.icon, required this.text, this.danger = false});

  final IconData icon;
  final String text;
  final bool danger;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: danger ? const Color(0xFFFBEAEA) : AppColors.gold100, borderRadius: BorderRadius.circular(16)),
        child: Row(children: [Icon(icon, color: danger ? AppColors.danger : AppColors.gold700), const SizedBox(width: 10), Expanded(child: Text(text, style: const TextStyle(height: 1.4)))]),
      );
}

class _Line extends StatelessWidget {
  const _Line(this.label, this.value);

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(children: [Text(label, style: const TextStyle(color: AppColors.muted)), const Spacer(), Text(value, style: const TextStyle(fontWeight: FontWeight.w700))]),
      );
}
