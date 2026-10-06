import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/l10n/strings.dart';
import '../../core/models.dart';
import '../../core/providers.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';

/// The training assistant: ask in Arabic or English; answers come from the platform's content and the person's own records, with sources, and a way to reach the trainer or support.
class AssistantScreen extends ConsumerStatefulWidget {
  const AssistantScreen({super.key});

  @override
  ConsumerState<AssistantScreen> createState() => _AssistantScreenState();
}

class _AssistantScreenState extends ConsumerState<AssistantScreen> {
  final _text = TextEditingController();
  final _scroll = ScrollController();
  final List<Json> _messages = [];
  String? _conversation;
  bool _busy = false;

  @override
  void dispose() {
    _text.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _ask(String question) async {
    final q = question.trim();
    if (q.isEmpty || _busy) return;
    _text.clear();
    setState(() {
      _busy = true;
      _messages.add({'role': 'user', 'content': q});
    });
    try {
      final res = await ref.read(apiProvider).post('/me/assistant/messages', {'message': q, 'conversation_id': _conversation});
      final data = Map<String, dynamic>.from((res as Map)['data'] as Map);
      _conversation = data.str('conversation_id');
      setState(() => _messages.add(Map<String, dynamic>.from(data['message'] as Map)));
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) _scroll.animateTo(_scroll.position.maxScrollExtent, duration: const Duration(milliseconds: 250), curve: Curves.easeOut);
      });
    }
  }

  Future<void> _rate(Json m, String feedback) async {
    try {
      await ref.read(apiProvider).post('/me/assistant/messages/${m.str('id')}/feedback', {'feedback': feedback});
      setState(() => m['feedback'] = feedback);
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    }
  }

  Future<void> _escalate(Json m, String target, [String? programId]) async {
    try {
      await ref.read(apiProvider).post('/me/assistant/messages/${m.str('id')}/escalate', {'target': target, 'program_id': programId});
      if (!mounted) return;
      showSnack(context, context.tr(target == 'trainer' ? 'ai.sentTrainer' : 'ai.sentSupport'));
      setState(() => m['meta'] = {...?m.obj('meta'), 'escalate': null});
    } catch (e) {
      if (mounted) showSnack(context, e.toString(), error: true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ar = context.s.isArabic;
    return Scaffold(
      backgroundColor: AppColors.ivory,
      appBar: AppBar(title: Text(context.tr('ai.title'))),
      body: Column(children: [
        Expanded(
          child: _messages.isEmpty
              ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(context.tr('ai.empty'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted))))
              : ListView(controller: _scroll, padding: const EdgeInsets.all(16), children: [
                  for (final m in _messages)
                    Align(
                      alignment: m.str('role') == 'user' ? AlignmentDirectional.centerEnd : AlignmentDirectional.centerStart,
                      child: Container(
                        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .86),
                        margin: const EdgeInsets.only(bottom: 12),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(color: m.str('role') == 'user' ? AppColors.navy900 : Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.navy100)),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Text(m.str('content'), style: TextStyle(color: m.str('role') == 'user' ? Colors.white : null)),
                          if (m.list('citations').isNotEmpty) ...[
                            const SizedBox(height: 8),
                            Text(context.tr('ai.sources'), style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: AppColors.muted)),
                            for (final c in m.list('citations')) Text('[${c.str('n')}] ${c.str('title')}', style: const TextStyle(fontSize: 12, color: AppColors.gold500)),
                          ],
                          if (m.str('role') == 'assistant' && m.str('id').isNotEmpty)
                            Row(children: [
                              IconButton(visualDensity: VisualDensity.compact, onPressed: () => _rate(m, 'up'), icon: Icon(m.str('feedback') == 'up' ? Icons.thumb_up : Icons.thumb_up_alt_outlined, size: 18)),
                              IconButton(visualDensity: VisualDensity.compact, onPressed: () => _rate(m, 'down'), icon: Icon(m.str('feedback') == 'down' ? Icons.thumb_down : Icons.thumb_down_alt_outlined, size: 18)),
                            ]),
                          if (m.obj('meta')?.obj('escalate') != null) ...[
                            Text(context.tr('ai.notSure'), style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700)),
                            Wrap(spacing: 8, children: [
                              for (final p in m.obj('meta')!.obj('escalate')!.list('trainer_programs'))
                                OutlinedButton(onPressed: () => _escalate(m, 'trainer', p.str('id')), child: Text('${context.tr('ai.askTrainer')}: ${ar ? p.str('title_ar') : p.str('title_en')}', overflow: TextOverflow.ellipsis)),
                              OutlinedButton(onPressed: () => _escalate(m, 'support'), child: Text(context.tr('ai.askSupport'))),
                            ]),
                          ],
                        ]),
                      ),
                    ),
                  if (_busy) const Padding(padding: EdgeInsets.all(8), child: LinearProgressIndicator()),
                ]),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
            child: Row(children: [
              Expanded(child: TextField(controller: _text, minLines: 1, maxLines: 4, textInputAction: TextInputAction.send, onSubmitted: _ask, decoration: InputDecoration(hintText: context.tr('ai.placeholder')))),
              IconButton(onPressed: _busy ? null : () => _ask(_text.text), icon: const Icon(Icons.send_rounded, color: AppColors.gold500)),
            ]),
          ),
        ),
      ]),
    );
  }
}
