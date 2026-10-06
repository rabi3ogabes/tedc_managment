import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// Which optional notifications reach the person, by channel, and whether a chime plays when one arrives.
/// Mandatory ones (acceptance, cancellation and similar decisions) always do.
class NotificationPrefsScreen extends ConsumerStatefulWidget {
  const NotificationPrefsScreen({super.key});

  @override
  ConsumerState<NotificationPrefsScreen> createState() => _NotificationPrefsScreenState();
}

class _NotificationPrefsScreenState extends ConsumerState<NotificationPrefsScreen> {
  static const _channels = ['push', 'email', 'sms'];
  Map<String, Map<String, bool>>? _groups;
  bool? _sound;
  bool _saving = false;

  void _load(Json data) {
    _groups ??= {
      for (final g in data.list('groups'))
        g.str('group'): {for (final c in _channels) c: (g.obj('channels')?[c] ?? true) == true},
    };
    _sound ??= data.flag('sound');
  }

  Future<void> _save() async {
    setState(() => _saving = true);
    try {
      await ref.read(apiProvider).put('/me/notification-preferences', {
        'sound': _sound,
        'groups': [for (final e in _groups!.entries) {'group': e.key, 'channels': e.value}],
      });
      if (mounted) showSnack(context, context.s.t('prefs.saved'));
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = context.s;
    final res = ref.watch(getProvider('/me/notification-preferences'));
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(s.t('prefs.title'))),
      body: AsyncView(
        value: res,
        onRetry: () => ref.invalidate(getProvider('/me/notification-preferences')),
        builder: (raw) {
          _load(Map<String, dynamic>.from((raw as Map)['data'] as Map));
          return ListView(padding: const EdgeInsets.all(16), children: [
            Text(s.t('prefs.hint')),
            const SizedBox(height: 8),
            SwitchListTile(title: Text(s.t('prefs.sound')), value: _sound ?? true, onChanged: (v) => setState(() => _sound = v)),
            for (final g in _groups!.entries)
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(s.t('prefs.group.${g.key}'), style: const TextStyle(fontWeight: FontWeight.w800)),
                    Wrap(spacing: 8, children: [
                      for (final c in _channels)
                        FilterChip(label: Text(s.t('prefs.channel.$c')), selected: g.value[c] ?? true, onSelected: (v) => setState(() => g.value[c] = v)),
                    ]),
                  ]),
                ),
              ),
            const SizedBox(height: 12),
            FilledButton(onPressed: _saving ? null : _save, child: Text(s.t('prefs.save'))),
          ]);
        },
      ),
    );
  }
}
