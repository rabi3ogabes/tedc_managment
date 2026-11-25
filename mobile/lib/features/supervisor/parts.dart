import 'package:material_ui/material_ui.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/theme/app_style.dart';
import '../../core/theme/app_theme.dart';
import '../duties/duties_model.dart';
import 'supervision.dart';

/// The pieces the supervisor's screens share: a small tag, a section heading, the attendance of a session as a line, the
/// three ways to take attendance, a session as a card, and a survey with its link.

/// The colours of where a session or a group stands.
(Color, Color) stateColors(String state) => switch (state) {
      'now' || 'running' => (const Color(0xFFE7F6EF), AppColors.success),
      'today' => (AppColors.gold100, AppColors.gold700),
      'later' || 'upcoming' => (const Color(0xFFE6F1FB), const Color(0xFF1D5C96)),
      _ => (AppColors.navy100, AppColors.muted),
    };

class Tag extends StatelessWidget {
  const Tag(this.text, {super.key, required this.background, required this.foreground, this.icon, this.latin = false});

  final String text;
  final Color background;
  final Color foreground;
  final IconData? icon;

  /// A programme code: always left to right, whatever the language.
  final bool latin;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(99)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (icon != null) ...[Icon(icon, size: 13, color: foreground), const SizedBox(width: 5)],
          Flexible(child: Text(text, maxLines: 1, overflow: TextOverflow.ellipsis, textDirection: latin ? TextDirection.ltr : null, style: TextStyle(color: foreground, fontSize: 11, fontWeight: FontWeight.w800))),
        ]),
      );
}

/// A heading above a part of a screen, with how many it holds and a way to the rest.
class Heading extends StatelessWidget {
  const Heading(this.title, {super.key, this.count, this.action, this.onAction});

  final String title;
  final int? count;
  final String? action;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(children: [
          // The title keeps the whole line up to the action: it never breaks in two to leave room for nothing.
          Expanded(
            child: Row(children: [
              Flexible(child: Text(title, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900))),
              if (count != null) ...[
                const SizedBox(width: 8),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 1),
                  decoration: BoxDecoration(color: AppColors.gold100, borderRadius: BorderRadius.circular(99)),
                  child: Text(Fmt('en').number(count), style: TextStyle(color: AppColors.gold700, fontSize: 12, fontWeight: FontWeight.w800)),
                ),
              ],
            ]),
          ),
          if (action != null && onAction != null) TextButton(onPressed: onAction, style: TextButton.styleFrom(visualDensity: VisualDensity.compact), child: Text(action!)),
        ]),
      );
}

/// A white surface with the app's corner and a hairline: every list of the supervisor's screens sits on one.
class Surface extends StatelessWidget {
  const Surface({super.key, required this.child, this.onTap, this.padding = const EdgeInsets.all(14), this.accent = false});

  final Widget child;
  final VoidCallback? onTap;
  final EdgeInsetsGeometry padding;

  /// A gold edge for the thing happening now.
  final bool accent;

  @override
  Widget build(BuildContext context) {
    final radius = BorderRadius.circular(AppStyle.radius(2));
    return Material(
      color: Colors.white,
      shape: RoundedRectangleBorder(borderRadius: radius, side: BorderSide(color: accent ? AppColors.gold500 : AppColors.navy100, width: accent ? 1.4 : 1)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(onTap: onTap, child: Padding(padding: padding, child: child)),
    );
  }
}

/// Who came to a session out of who was expected, as a bar and two numbers.
class AttendanceLine extends StatelessWidget {
  const AttendanceLine({super.key, required this.present, required this.expected, this.onDark = false, this.label});

  final num present;
  final num expected;
  final bool onDark;
  final String? label;

  @override
  Widget build(BuildContext context) {
    final fmt = Fmt(context.s.languageCode);
    final ink = onDark ? Colors.white : AppColors.navy900;
    return Row(children: [
      if (label != null) ...[Text(label!, style: TextStyle(color: onDark ? Colors.white70 : AppColors.muted, fontSize: 12, fontWeight: FontWeight.w600)), const SizedBox(width: 10)],
      Expanded(
        child: ClipRRect(
          borderRadius: BorderRadius.circular(99),
          child: LinearProgressIndicator(
            value: Duties.percent(present, expected) / 100,
            minHeight: 6,
            backgroundColor: onDark ? Colors.white.withValues(alpha: .18) : AppColors.navy100,
            valueColor: AlwaysStoppedAnimation(onDark ? AppColors.gold300 : AppColors.gold500),
          ),
        ),
      ),
      const SizedBox(width: 10),
      Text(expected > 0 ? '${fmt.number(present)} / ${fmt.number(expected)}' : '—', textDirection: TextDirection.ltr, style: TextStyle(color: ink, fontWeight: FontWeight.w800, fontSize: 13)),
    ]);
  }
}

/// The three ways to take the attendance of a session: the sheet, the code the trainees scan, and scanning a trainee's own code.
class SessionActions extends StatelessWidget {
  const SessionActions({super.key, required this.sessionId, this.onDark = false, this.codes = true});

  final String sessionId;
  final bool onDark;

  /// The two codes are for a session of today; a session already held keeps only its sheet.
  final bool codes;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final quiet = onDark
        ? OutlinedButton.styleFrom(foregroundColor: Colors.white, side: BorderSide(color: Colors.white.withValues(alpha: .45)), padding: const EdgeInsets.symmetric(vertical: 11, horizontal: 8))
        : OutlinedButton.styleFrom(foregroundColor: AppColors.navy900, side: BorderSide(color: AppColors.navy100), padding: const EdgeInsets.symmetric(vertical: 11, horizontal: 8));
    Widget label(String key) => Flexible(child: Text(s.t(key), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)));
    return Column(children: [
      SizedBox(
        width: double.infinity,
        child: FilledButton(
          key: ValueKey('sheet-$sessionId'),
          style: FilledButton.styleFrom(backgroundColor: onDark ? AppColors.gold500 : AppColors.navy900, foregroundColor: onDark ? AppColors.navy950 : Colors.white, padding: const EdgeInsets.symmetric(vertical: 12)),
          onPressed: () => context.push('/roll-call/$sessionId'),
          child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [const Icon(Icons.fact_check_outlined, size: 19), const SizedBox(width: 8), label('sup.act.sheet')]),
        ),
      ),
      if (codes) ...[
        const SizedBox(height: 8),
        Row(children: [
          Expanded(
            child: OutlinedButton(
              key: ValueKey('code-$sessionId'),
              style: quiet,
              onPressed: () => context.push('/session-qr/$sessionId'),
              child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [const Icon(Icons.qr_code_2, size: 18), const SizedBox(width: 6), label('sup.act.code')]),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: OutlinedButton(
              key: ValueKey('scan-$sessionId'),
              style: quiet,
              onPressed: () => context.push('/staff-scan/$sessionId'),
              child: Row(mainAxisAlignment: MainAxisAlignment.center, children: [const Icon(Icons.qr_code_scanner, size: 18), const SizedBox(width: 6), label('sup.act.scan')]),
            ),
          ),
        ]),
      ],
    ]);
  }
}

/// A session as a card: its time, the programme, the room, where it stands, who came, and the ways to take attendance
/// when the person does that and the session is of today.
class SlotCard extends StatelessWidget {
  const SlotCard({super.key, required this.slot, required this.mayAttend, this.showDate = false, this.inGroup = false});

  final Slot slot;
  final bool mayAttend;

  /// A session of another day names its day above the time.
  final bool showDate;

  /// On the page of its own group the session is named by itself; anywhere else it is named by its programme.
  final bool inGroup;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final lang = s.languageCode;
    final fmt = Fmt(lang);
    final session = slot.session;
    final when = session.str('when');
    final live = when == 'now';
    final past = when == 'past';
    final title = session.str(lang == 'en' ? 'title_en' : 'title_ar');
    final place = Duties.name(session.obj('place'), lang);
    final starts = session.date('starts_at');
    final (bg, fg) = stateColors(when);
    final attends = mayAttend && when != 'later';
    final head = inGroup ? (title.isNotEmpty ? title : fmt.weekdayDate(starts)) : Duties.title(slot.group, lang);
    final under = [if (!inGroup && title.isNotEmpty) title, if (place.isNotEmpty) place].join(' · ');

    return Surface(
      accent: live,
      onTap: attends ? () => context.push('/roll-call/${session.str('id')}') : null,
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            width: 66,
            padding: const EdgeInsets.symmetric(vertical: 9, horizontal: 4),
            decoration: BoxDecoration(color: past ? AppColors.navy700 : AppColors.navy900, borderRadius: BorderRadius.circular(14)),
            child: Column(children: [
              if (showDate) Text(fmt.dayMonth(starts), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white70, fontSize: 10.5, fontWeight: FontWeight.w700)),
              Text(fmt.time(starts), maxLines: 1, style: TextStyle(color: AppColors.gold300, fontSize: 12.5, fontWeight: FontWeight.w800)),
              if (!showDate) Text(fmt.time(session.date('ends_at')), maxLines: 1, style: const TextStyle(color: Colors.white70, fontSize: 10.5)),
            ]),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(head, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900, height: 1.25)),
              if (under.isNotEmpty) Padding(padding: const EdgeInsets.only(top: 2), child: Text(under, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.muted, fontSize: 12.5))),
            ]),
          ),
          const SizedBox(width: 8),
          Tag(s.t('duties.when.$when'), background: bg, foreground: fg, icon: live ? Icons.sensors : null),
        ]),
        if (when != 'later') ...[
          const SizedBox(height: 12),
          AttendanceLine(present: session.number('present'), expected: session.number('expected'), label: s.t('duties.attendance')),
        ],
        if (attends && Duties.takesAttendance(session)) ...[const SizedBox(height: 12), SessionActions(sessionId: session.str('id'))],
      ]),
    );
  }
}

/// One survey of a programme: which one, where it stands, how many answered, and its link as a code, a copy, or opened.
class SurveyLink extends StatelessWidget {
  const SurveyLink({super.key, required this.kind, required this.form, required this.programme});

  /// `trainee` (the trainees' survey of the programme) or `reflection` (the trainer's own).
  final String kind;
  final Json form;
  final String programme;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final status = form.str('status');
    final url = form.str('url');
    final (answered, asked) = Supervision.answers(kind, form);
    final (bg, fg) = switch (status) {
      'open' => (const Color(0xFFE7F6EF), AppColors.success),
      'done' => (const Color(0xFFE7F6EF), AppColors.success),
      'scheduled' || 'waiting' => (AppColors.gold100, AppColors.gold700),
      _ => (AppColors.navy100, AppColors.muted),
    };
    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Row(children: [
        Container(
          width: 38,
          height: 38,
          decoration: BoxDecoration(color: AppColors.ivory, borderRadius: BorderRadius.circular(12), border: Border.all(color: AppColors.navy100)),
          child: Icon(kind == 'trainee' ? Icons.groups_2_outlined : Icons.co_present_outlined, size: 19, color: AppColors.gold700),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(s.t('sup.survey.$kind'), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 14, color: AppColors.navy900)),
            Text(s.t('sup.survey.answered').replaceFirst('{a}', fmt.number(answered)).replaceFirst('{n}', fmt.number(asked)), style: const TextStyle(color: AppColors.muted, fontSize: 12)),
          ]),
        ),
        Tag(s.t('sup.survey.st.$status'), background: bg, foreground: fg),
      ]),
      const SizedBox(height: 10),
      // The link itself, as it is: pressing it copies it.
      Material(
        color: AppColors.ivory,
        borderRadius: BorderRadius.circular(12),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          key: ValueKey('survey-copy-$kind'),
          onTap: () => copyLink(context, url),
          child: Padding(
            padding: const EdgeInsetsDirectional.fromSTEB(12, 9, 8, 9),
            child: Row(children: [
              Icon(Icons.link, size: 17, color: AppColors.gold700),
              const SizedBox(width: 8),
              Expanded(child: Text(url.replaceFirst(RegExp(r'^https?://'), ''), key: ValueKey('survey-link-$kind'), maxLines: 1, overflow: TextOverflow.ellipsis, textDirection: TextDirection.ltr, textAlign: TextAlign.left, style: TextStyle(color: AppColors.navy900, fontSize: 12.5, fontWeight: FontWeight.w700))),
              const SizedBox(width: 8),
              Tooltip(message: s.t('sup.survey.copy'), child: Icon(Icons.copy_outlined, size: 17, color: AppColors.navy900)),
            ]),
          ),
        ),
      ),
      const SizedBox(height: 8),
      Row(children: [
        Expanded(
          child: FilledButton.icon(
            key: ValueKey('survey-code-$kind'),
            style: FilledButton.styleFrom(backgroundColor: AppColors.navy900, foregroundColor: Colors.white, padding: const EdgeInsets.symmetric(vertical: 10)),
            onPressed: () => showSurveyCode(context, kind: kind, url: url, programme: programme),
            icon: const Icon(Icons.qr_code_2, size: 18),
            label: Text(s.t('sup.survey.code'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800)),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: OutlinedButton.icon(
            style: OutlinedButton.styleFrom(foregroundColor: AppColors.navy900, side: BorderSide(color: AppColors.navy100), padding: const EdgeInsets.symmetric(vertical: 10)),
            onPressed: () => launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication),
            icon: const Icon(Icons.open_in_new, size: 17),
            label: Text(s.t('sup.survey.open'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800)),
          ),
        ),
      ]),
    ]);
  }
}

Future<void> copyLink(BuildContext context, String url) async {
  final messenger = ScaffoldMessenger.maybeOf(context);
  final text = context.s.t('sup.survey.copied');
  await Clipboard.setData(ClipboardData(text: url));
  messenger?.showSnackBar(SnackBar(content: Text(text), behavior: SnackBarBehavior.floating, backgroundColor: AppColors.navy900));
}

/// The link of a survey as a code the room scans from this phone, with the link under it.
void showSurveyCode(BuildContext context, {required String kind, required String url, required String programme}) {
  final s = context.s;
  showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    showDragHandle: true,
    backgroundColor: Colors.white,
    builder: (sheet) => SafeArea(
      child: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Text(s.t('sup.survey.$kind'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800, color: AppColors.navy900)),
          const SizedBox(height: 2),
          Text(programme, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, fontSize: 13)),
          const SizedBox(height: 16),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(24), border: Border.all(color: AppColors.navy100)),
            child: QrImageView(data: url, size: 250, eyeStyle: QrEyeStyle(eyeShape: QrEyeShape.square, color: AppColors.navy950), dataModuleStyle: QrDataModuleStyle(dataModuleShape: QrDataModuleShape.square, color: AppColors.navy950)),
          ),
          const SizedBox(height: 14),
          Text(s.t('sup.survey.${kind}Hint'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, height: 1.6, fontSize: 13)),
          const SizedBox(height: 10),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
            decoration: BoxDecoration(color: AppColors.ivory, borderRadius: BorderRadius.circular(12)),
            child: Text(url, textAlign: TextAlign.center, textDirection: TextDirection.ltr, maxLines: 2, overflow: TextOverflow.ellipsis, style: TextStyle(color: AppColors.navy900, fontSize: 12.5, fontWeight: FontWeight.w600)),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(onPressed: () => copyLink(sheet, url), icon: const Icon(Icons.copy_outlined, size: 18), label: Text(s.t('sup.survey.copy'))),
        ]),
      ),
    ),
  );
}

/// A quiet message where a list has nothing in it.
class Quiet extends StatelessWidget {
  const Quiet({super.key, required this.icon, required this.text, this.hint});

  final IconData icon;
  final String text;
  final String? hint;

  @override
  Widget build(BuildContext context) => Container(
        width: double.infinity,
        padding: const EdgeInsets.symmetric(vertical: 26, horizontal: 20),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(AppStyle.radius(2)), border: Border.all(color: AppColors.navy100)),
        child: Column(children: [
          Icon(icon, size: 34, color: AppColors.gold500),
          const SizedBox(height: 10),
          Text(text, textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
          if (hint != null) Padding(padding: const EdgeInsets.only(top: 4), child: Text(hint!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted, fontSize: 12.5, height: 1.5))),
        ]),
      );
}
