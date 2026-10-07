import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/brand.dart';
import '../../core/widgets/widgets.dart';

/// My account: everything known about the user, read-only. A wrong or missing detail is corrected by sending a
/// request that an administrator reviews, so the record stays trustworthy.
class AccountScreen extends ConsumerWidget {
  const AccountScreen({super.key});

  static const path = '/me/account';
  static const requestsPath = '/me/account/requests';

  static void _refresh(WidgetRef ref) {
    ref.invalidate(getProvider(path));
    ref.invalidate(getProvider(requestsPath));
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final account = ref.watch(getProvider(path));

    return Scaffold(
      backgroundColor: AppColors.ivory,
      body: RefreshIndicator(
        color: AppColors.gold500,
        onRefresh: () async {
          _refresh(ref);
          await ref.read(getProvider(path).future);
        },
        child: AsyncView(
          value: account,
          onRetry: () => _refresh(ref),
          builder: (raw) {
            final data = Map<String, dynamic>.from((raw as Map)['data'] as Map);
            final identity = data.obj('identity') ?? {};
            final stats = data.obj('stats') ?? {};
            final sections = data.list('sections');
            final skills = data.list('skills');
            final info = data.obj('account') ?? {};
            final missing = stats.number('missing').toInt();
            final pending = stats.number('pending').toInt();
            final fmt = Fmt(s.languageCode);

            return CustomScrollView(slivers: [
              SliverToBoxAdapter(child: _Hero(identity: identity)),
              SliverPadding(
                padding: const EdgeInsets.fromLTRB(20, 16, 20, 40),
                sliver: SliverList.list(children: [
                  _LockedBanner(missing: missing, pending: pending),
                  for (final section in sections) ...[
                    SectionTitle(section.str('title')),
                    Card(
                      clipBehavior: Clip.antiAlias,
                      child: Column(children: [
                        for (final (i, field) in section.list('fields').indexed) ...[
                          if (i > 0) Divider(height: 1, color: AppColors.navy100),
                          _FieldTile(
                            field: field,
                            fmt: fmt,
                            onRequest: () => _openRequest(context, ref, field),
                          ),
                        ],
                      ]),
                    ),
                  ],
                  if (skills.isNotEmpty) ...[
                    SectionTitle(s.t('account.skills')),
                    Wrap(spacing: 8, runSpacing: 8, children: [
                      for (final skill in skills)
                        Chip(
                          avatar: CircleAvatar(backgroundColor: AppColors.navy900, child: Text('${skill.number('level').toInt()}', style: TextStyle(color: AppColors.gold300, fontSize: 11, fontWeight: FontWeight.w800))),
                          label: Text(skill.str('name')),
                          backgroundColor: Colors.white,
                          side: BorderSide(color: AppColors.navy100),
                        ),
                    ]),
                  ],
                  SectionTitle(s.t('account.accountInfo')),
                  Card(
                    child: Column(children: [
                      ListTile(leading: Icon(Icons.badge_outlined, color: AppColors.gold700), title: Text(s.t('account.roles')), subtitle: Text((info['roles'] as List? ?? const []).join('، '))),
                      Divider(height: 1, color: AppColors.navy100),
                      ListTile(leading: Icon(Icons.event_available_outlined, color: AppColors.gold700), title: Text(s.t('account.memberSince')), subtitle: Text(fmt.date(DateTime.tryParse(info.str('member_since'))))),
                      if (info.str('last_login_at').isNotEmpty) ...[
                        Divider(height: 1, color: AppColors.navy100),
                        ListTile(leading: Icon(Icons.login, color: AppColors.gold700), title: Text(s.t('account.lastLogin')), subtitle: Text(fmt.dateTime(info.date('last_login_at')))),
                      ],
                    ]),
                  ),
                  SectionTitle(s.t('account.myRequests')),
                  const _Requests(),
                ]),
              ),
            ]);
          },
        ),
      ),
    );
  }

  Future<void> _openRequest(BuildContext context, WidgetRef ref, Json field) async {
    if (field['pending'] != null) {
      showSnack(context, context.s.t('account.alreadyPending'));
      return;
    }
    final sent = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      backgroundColor: Colors.white,
      builder: (_) => _RequestSheet(field: field),
    );
    if (sent == true) {
      _refresh(ref);
      if (context.mounted) showSnack(context, context.s.t('account.requestSent'));
    }
  }
}

class _Hero extends StatelessWidget {
  const _Hero({required this.identity});

  final Json identity;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final name = identity.str('name');
    return Container(
      decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.vertical(bottom: Radius.circular(32))),
      child: Stack(children: [
        const Positioned.fill(child: DotPattern(opacity: .12)),
        SafeArea(
          bottom: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(8, 4, 20, 26),
            child: Column(children: [
              Align(alignment: AlignmentDirectional.centerStart, child: BackButton(color: Colors.white, onPressed: () => Navigator.maybePop(context))),
              CircleAvatar(
                radius: 40,
                backgroundColor: AppColors.gold500,
                child: Text(name.isEmpty ? '' : name.characters.first, style: TextStyle(fontSize: 32, color: AppColors.navy950, fontWeight: FontWeight.w800)),
              ),
              const SizedBox(height: 12),
              Text(name, textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontSize: 21, fontWeight: FontWeight.w800)),
              if (identity.str('subtitle').isNotEmpty) Text(identity.str('subtitle'), textAlign: TextAlign.center, style: TextStyle(color: AppColors.gold300, fontSize: 13)),
              const SizedBox(height: 12),
              Wrap(spacing: 8, runSpacing: 8, alignment: WrapAlignment.center, children: [
                if (identity.str('employee_no').isNotEmpty) _pill(Icons.tag, identity.str('employee_no'), ltr: true),
                _pill(Icons.verified_user_outlined, s.t('account.readOnly')),
              ]),
            ]),
          ),
        ),
      ]),
    );
  }

  Widget _pill(IconData icon, String text, {bool ltr = false}) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(20), border: Border.all(color: Colors.white.withValues(alpha: .22))),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 15, color: AppColors.gold300),
          const SizedBox(width: 6),
          Text(text, textDirection: ltr ? TextDirection.ltr : null, style: const TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w700)),
        ]),
      );
}

/// Explains that the details are locked, and nudges the user to complete the missing ones.
class _LockedBanner extends StatelessWidget {
  const _LockedBanner({required this.missing, required this.pending});

  final int missing;
  final int pending;

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: AppColors.gold100.withValues(alpha: .55), borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.gold300)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Icon(Icons.lock_outline, color: AppColors.gold700, size: 20),
          const SizedBox(width: 8),
          Expanded(child: Text(s.t('account.lockedTitle'), style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15))),
        ]),
        const SizedBox(height: 6),
        Text(s.t('account.lockedText'), style: TextStyle(color: AppColors.navy700, height: 1.5, fontSize: 13)),
        if (missing > 0 || pending > 0) ...[
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 6, children: [
            if (missing > 0) _chip(Icons.error_outline, '$missing ${s.t('account.missingCount')}', AppColors.warning),
            if (pending > 0) _chip(Icons.hourglass_top, '$pending ${s.t('account.pendingCount')}', AppColors.navy700),
          ]),
        ],
      ]),
    );
  }

  Widget _chip(IconData icon, String text, Color color) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [Icon(icon, size: 15, color: color), const SizedBox(width: 5), Text(text, style: TextStyle(color: color, fontWeight: FontWeight.w700, fontSize: 12.5))]),
      );
}

/// One locked detail: label, value (or "not recorded"), and the request-a-change button.
class _FieldTile extends StatelessWidget {
  const _FieldTile({required this.field, required this.fmt, required this.onRequest});

  final Json field;
  final Fmt fmt;
  final VoidCallback onRequest;

  String _value() {
    final value = field.str('value');
    if (value.isEmpty) return '';
    switch (field.str('type')) {
      case 'date':
        return fmt.date(DateTime.tryParse(value));
      case 'number':
        return fmt.number(num.tryParse(value), digits: 1);
      default:
        return field.str('display', value);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final missing = field.flag('missing');
    final pending = field.obj('pending');
    final type = field.str('type');
    final ltr = type == 'email' || type == 'phone';

    return Container(
      color: missing ? AppColors.gold100.withValues(alpha: .35) : null,
      child: ListTile(
        onTap: pending == null ? onRequest : null,
        leading: Icon(missing ? Icons.error_outline : Icons.lock_outline, size: 20, color: missing ? AppColors.warning : AppColors.muted),
        title: Text(field.str('label'), style: const TextStyle(fontSize: 12.5, color: AppColors.muted, fontWeight: FontWeight.w600)),
        subtitle: missing
            ? Text(s.t('account.notRecorded'), style: const TextStyle(color: AppColors.warning, fontWeight: FontWeight.w700, fontStyle: FontStyle.italic, fontSize: 15))
            : Text(_value(), textDirection: ltr ? TextDirection.ltr : null, textAlign: ltr && s.isArabic ? TextAlign.right : null, style: TextStyle(color: AppColors.navy950, fontWeight: FontWeight.w700, fontSize: 15.5)),
        trailing: pending != null
            ? Chip(
                avatar: Icon(Icons.hourglass_top, size: 14, color: AppColors.navy700),
                label: Text(s.t('account.underReview'), style: const TextStyle(fontSize: 11.5, fontWeight: FontWeight.w700)),
                backgroundColor: AppColors.navy100,
                side: BorderSide.none,
                visualDensity: VisualDensity.compact,
              )
            : TextButton.icon(
                onPressed: onRequest,
                icon: Icon(missing ? Icons.add_circle_outline : Icons.edit_note, size: 18),
                label: Text(missing ? s.t('account.complete') : s.t('account.requestChange'), style: const TextStyle(fontSize: 12.5, fontWeight: FontWeight.w800)),
                style: TextButton.styleFrom(foregroundColor: AppColors.navy900, padding: const EdgeInsets.symmetric(horizontal: 8)),
              ),
      ),
    );
  }
}

/// The user's earlier change requests and their outcome.
class _Requests extends ConsumerWidget {
  const _Requests();

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final s = context.s;
    final fmt = Fmt(s.languageCode);
    final requests = ref.watch(getProvider(AccountScreen.requestsPath));

    return requests.when(
      loading: () => const LoadingView(),
      error: (_, _) => const SizedBox.shrink(),
      data: (raw) {
        final items = Map<String, dynamic>.from(raw as Map).list('data');
        if (items.isEmpty) return Card(child: Padding(padding: const EdgeInsets.all(20), child: Center(child: Text(s.t('account.noRequests'), style: const TextStyle(color: AppColors.muted)))));
        return Card(
          child: Column(children: [
            for (final (i, r) in items.indexed) ...[
              if (i > 0) Divider(height: 1, color: AppColors.navy100),
              ListTile(
                isThreeLine: true,
                leading: _statusIcon(r.str('status')),
                title: Text('${r.str('label')}  ›  ${r.str('requested_value')}', style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14.5)),
                subtitle: Text([
                  s.t('account.status.${r.str('status')}'),
                  if (r.flag('applied')) s.t('account.applied'),
                  if (r.str('review_note').isNotEmpty) r.str('review_note'),
                  fmt.dateTime(r.date('created_at')),
                ].join(' · ')),
                trailing: r.str('status') == 'pending'
                    ? IconButton(
                        tooltip: s.t('account.withdraw'),
                        icon: const Icon(Icons.close, color: AppColors.muted),
                        onPressed: () async {
                          await ref.read(apiProvider).dio.delete('${AccountScreen.requestsPath}/${r.str('id')}');
                          AccountScreen._refresh(ref);
                        },
                      )
                    : null,
              ),
            ],
          ]),
        );
      },
    );
  }

  Widget _statusIcon(String status) {
    final (icon, color) = switch (status) {
      'approved' => (Icons.check_circle, AppColors.success),
      'rejected' => (Icons.cancel, AppColors.danger),
      'cancelled' => (Icons.remove_circle_outline, AppColors.muted),
      _ => (Icons.hourglass_top, AppColors.warning),
    };
    return Icon(icon, color: color);
  }
}

/// The request form: shows the locked current value and asks for the correct one.
class _RequestSheet extends ConsumerStatefulWidget {
  const _RequestSheet({required this.field});

  final Json field;

  @override
  ConsumerState<_RequestSheet> createState() => _RequestSheetState();
}

class _RequestSheetState extends ConsumerState<_RequestSheet> {
  final _value = TextEditingController();
  final _note = TextEditingController();
  late String _kind = widget.field.flag('missing') ? 'missing' : 'wrong';
  String? _selected;
  DateTime? _date;
  bool _sending = false;
  String? _error;

  String get _type => widget.field.str('type');

  String? get _answer {
    switch (_type) {
      case 'select':
        return _selected;
      case 'date':
        return _date == null ? null : '${_date!.year.toString().padLeft(4, '0')}-${_date!.month.toString().padLeft(2, '0')}-${_date!.day.toString().padLeft(2, '0')}';
      default:
        final v = _value.text.trim();
        return v.isEmpty ? null : v;
    }
  }

  Future<void> _submit() async {
    final answer = _answer;
    if (answer == null) return;
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      await ref.read(apiProvider).post('/me/account/requests', {
        'field': widget.field.str('key'),
        'kind': _kind,
        'requested_value': answer,
        if (_note.text.trim().isNotEmpty) 'note': _note.text.trim(),
      });
      if (mounted) Navigator.pop(context, true);
    } catch (e) {
      if (mounted) setState(() => _error = ApiException.from(e).message);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  void dispose() {
    _value.dispose();
    _note.dispose();
    super.dispose();
  }

  Widget _input(BuildContext context) {
    final s = context.s;
    switch (_type) {
      case 'select':
        final options = widget.field.list('options');
        return DropdownButtonFormField<String>(
          initialValue: _selected,
          decoration: InputDecoration(labelText: s.t('account.correctValue')),
          items: [for (final o in options) DropdownMenuItem(value: o.str('value'), child: Text(o.str('label')))],
          onChanged: (v) => setState(() => _selected = v),
        );
      case 'date':
        return OutlinedButton.icon(
          onPressed: () async {
            final now = DateTime.now();
            final picked = await showDatePicker(context: context, initialDate: _date ?? DateTime(now.year - 30), firstDate: DateTime(1940), lastDate: now);
            if (picked != null && mounted) setState(() => _date = picked);
          },
          icon: const Icon(Icons.calendar_month_outlined),
          label: Text(_date == null ? s.t('account.pickDate') : Fmt(s.languageCode).date(_date)),
          style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52), alignment: AlignmentDirectional.centerStart),
        );
      default:
        final number = _type == 'number';
        final ltr = _type == 'email' || _type == 'phone';
        return TextField(
          controller: _value,
          onChanged: (_) => setState(() {}),
          textDirection: ltr ? TextDirection.ltr : null,
          keyboardType: _type == 'phone' ? TextInputType.phone : _type == 'email' ? TextInputType.emailAddress : number ? const TextInputType.numberWithOptions(decimal: true) : TextInputType.text,
          maxLength: 255,
          decoration: InputDecoration(labelText: s.t('account.correctValue'), counterText: ''),
        );
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final missing = widget.field.flag('missing');
    final current = widget.field.str('display', widget.field.str('value'));

    return Padding(
      padding: EdgeInsets.fromLTRB(20, 0, 20, MediaQuery.viewInsetsOf(context).bottom + 20),
      child: SingleChildScrollView(
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text('${s.t('account.requestChange')}: ${widget.field.str('label')}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(s.t('account.sheetHint'), style: const TextStyle(color: AppColors.muted, fontSize: 13)),
          const SizedBox(height: 14),
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(color: AppColors.navy100.withValues(alpha: .5), borderRadius: BorderRadius.circular(16)),
            child: Row(children: [
              const Icon(Icons.lock_outline, size: 18, color: AppColors.muted),
              const SizedBox(width: 10),
              Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(s.t('account.currentValue'), style: const TextStyle(fontSize: 11.5, color: AppColors.muted, fontWeight: FontWeight.w600)),
                Text(missing ? s.t('account.notRecorded') : current, style: TextStyle(fontWeight: FontWeight.w700, color: missing ? AppColors.warning : AppColors.navy950)),
              ])),
            ]),
          ),
          const SizedBox(height: 14),
          SegmentedButton<String>(
            showSelectedIcon: false,
            segments: [
              ButtonSegment(value: 'wrong', label: Text(s.t('account.kind.wrong'), style: const TextStyle(fontSize: 12))),
              ButtonSegment(value: 'missing', label: Text(s.t('account.kind.missing'), style: const TextStyle(fontSize: 12))),
              ButtonSegment(value: 'update', label: Text(s.t('account.kind.update'), style: const TextStyle(fontSize: 12))),
            ],
            selected: {_kind},
            onSelectionChanged: (v) => setState(() => _kind = v.first),
          ),
          const SizedBox(height: 14),
          _input(context),
          const SizedBox(height: 10),
          TextField(controller: _note, maxLines: 2, maxLength: 500, decoration: InputDecoration(labelText: s.t('account.noteOptional'), counterText: '')),
          if (_error != null) Padding(padding: const EdgeInsets.only(top: 8), child: Text(_error!, style: const TextStyle(color: AppColors.danger))),
          const SizedBox(height: 14),
          FilledButton.icon(
            onPressed: _answer == null || _sending ? null : _submit,
            icon: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white)) : const Icon(Icons.send_outlined),
            label: Text(s.t('account.sendRequest')),
          ),
        ]),
      ),
    );
  }
}
