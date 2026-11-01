import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/l10n/strings.dart';
import '../../core/theme/app_theme.dart';
import '../../core/widgets/widgets.dart';
import 'engine.dart';
import 'floor_plan.dart';
import 'wayfinding_data.dart';

/// «دليل المركز» — the centre's indoor guide (docs/wayfinding.md). Anyone with the app, signed in or not, picks where to go
/// (their training room by name, a lab, the prayer room…), says where they are (the entrance, a "you are here" sign's QR code,
/// a tap on the plan) and is walked there step by step across both floors, the plan always framing where they stand and where
/// they are going. Opened from a session's «الطريق إلى القاعة» with `to=room:<id>`.
class WayfindingScreen extends ConsumerStatefulWidget {
  const WayfindingScreen({super.key, this.to, this.from});
  final String? to;
  final String? from;

  @override
  ConsumerState<WayfindingScreen> createState() => _WayfindingScreenState();
}

class _WayfindingScreenState extends ConsumerState<WayfindingScreen> {
  String? _to;
  String? _from;
  bool _avoidStairs = false;
  int _step = 0;
  String? _manualFloor;
  bool _picking = false;
  bool _arrived = false;
  String _filter = 'all';
  String _query = '';
  final _sheet = DraggableScrollableController();
  double _sheetSize = .5;

  @override
  void initState() {
    super.initState();
    _to = widget.to;
    _from = widget.from;
    _sheet.addListener(() {
      if (_sheet.isAttached && (_sheet.size - _sheetSize).abs() > .04) setState(() => _sheetSize = _sheet.size);
    });
  }

  @override
  void dispose() {
    _sheet.dispose();
    super.dispose();
  }

  void _go(String? to) => setState(() {
        _to = to;
        _step = 0;
        _arrived = false;
        _manualFloor = null;
      });

  void _start(String? from) => setState(() {
        _from = from;
        _step = 0;
        _arrived = false;
        _manualFloor = null;
      });

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final value = ref.watch(wayDataProvider);
    return Scaffold(
      backgroundColor: const Color(0xFFF3F1EC),
      body: AsyncView<WayData>(
        value: value,
        onRetry: () => ref.invalidate(wayDataProvider),
        builder: (data) => _body(context, s, data),
      ),
    );
  }

  Widget _body(BuildContext context, S s, WayData data) {
    final en = !s.isArabic;
    final origin = data.origin(_from) ?? data.defaultOrigin;
    final dest = data.destination(_to);
    final target = dest?.target;
    final route = target == null ? null : data.engine.route(origin.floor, origin.at, target, avoidStairs: _avoidStairs);
    final current = route == null ? null : route.steps[_step.clamp(0, route.steps.length - 1).toInt()];
    final floor = _manualFloor ?? current?.floor ?? (target != null && route == null ? target.floor : origin.floor);
    final leg = route?.legs.where((l) => l.floor == floor).firstOrNull?.points;
    final you = current != null ? (current.floor == floor ? current.at : null) : (origin.floor == floor ? origin.at : null);
    final next = route?.steps.skip(_step + 1).where((x) => x.floor == floor).firstOrNull;
    final goal = target != null && target.floor == floor ? target.at : (leg != null && route!.legs.first.floor == floor ? leg.last : null);
    // The plan frames where the visitor stands and where they are going on this floor; with nothing chosen yet, the whole floor.
    final focus = route == null ? <Pt>[] : <Pt>[if (you != null) you else if (leg != null) leg.first, if (next != null) next.at, ?goal];
    final h = MediaQuery.of(context).size.height;
    final top = MediaQuery.of(context).padding.top + 70;

    return Stack(children: [
      Positioned.fill(
        child: FloorPlanView(
          map: data.map,
          floor: floor,
          route: leg,
          you: you,
          target: target != null && target.floor == floor ? target.at : null,
          highlight: target != null && target.floor == floor ? target.id : null,
          focus: focus.isEmpty ? null : focus,
          inset: EdgeInsets.only(top: top, bottom: h * _sheetSize),
          onTap: (at, place) {
            if (_picking) {
              _start('$floor:${at.x.round()},${at.y.round()}');
              setState(() => _picking = false);
              return;
            }
            if (place != null) {
              final d = data.destinations.where((x) => x.place?.id == place.id).firstOrNull;
              if (d != null) _go(d.key);
            }
          },
        ),
      ),
      // Top bar: back, the guide's name, the floors, step-free routes.
      Positioned(
        top: MediaQuery.of(context).padding.top + 8,
        left: 12,
        right: 12,
        child: Row(children: [
          _Glass(
            child: IconButton(
              icon: const Icon(Icons.arrow_back_rounded),
              color: AppColors.navy900,
              onPressed: () => context.canPop() ? context.pop() : context.go('/home'),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _Glass(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                Text(s.t('way.title'), style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
                Text(s.t('way.subtitle'), maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 11, color: AppColors.muted)),
              ]),
            ),
          ),
          const SizedBox(width: 8),
          _Glass(
            padding: const EdgeInsets.all(4),
            child: Row(children: [
              for (final f in data.map.floors.reversed)
                GestureDetector(
                  onTap: () {
                    HapticFeedback.selectionClick();
                    setState(() => _manualFloor = f.id == current?.floor ? null : f.id);
                  },
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 200),
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
                    decoration: BoxDecoration(color: floor == f.id ? AppColors.navy900 : Colors.transparent, borderRadius: BorderRadius.circular(14)),
                    child: Row(children: [
                      Text(f.name(en), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w800, color: floor == f.id ? Colors.white : AppColors.navy900)),
                      if (route != null && route.legs.any((l) => l.floor == f.id) && floor != f.id) ...[
                        const SizedBox(width: 4),
                        Container(width: 7, height: 7, decoration: BoxDecoration(color: AppColors.gold500, shape: BoxShape.circle)),
                      ],
                    ]),
                  ),
                ),
            ]),
          ),
          const SizedBox(width: 8),
          _Glass(
            color: _avoidStairs ? AppColors.navy900 : null,
            child: IconButton(
              tooltip: s.t('way.avoidStairs'),
              icon: const Icon(Icons.accessible_rounded),
              color: _avoidStairs ? AppColors.gold300 : AppColors.navy900,
              onPressed: () => setState(() {
                _avoidStairs = !_avoidStairs;
                _step = 0;
              }),
            ),
          ),
        ]),
      ),
      if (_picking)
        Positioned(
          top: top + 6,
          left: 24,
          right: 24,
          child: Material(
            color: AppColors.navy900,
            borderRadius: BorderRadius.circular(18),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
              child: Row(children: [
                Icon(Icons.touch_app_rounded, color: AppColors.gold300),
                const SizedBox(width: 10),
                Expanded(child: Text(s.t('way.tapWhere'), style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600))),
                IconButton(onPressed: () => setState(() => _picking = false), icon: const Icon(Icons.close, color: Colors.white70)),
              ]),
            ),
          ),
        ),
      DraggableScrollableSheet(
        controller: _sheet,
        initialChildSize: .5,
        minChildSize: .16,
        maxChildSize: .9,
        snap: true,
        snapSizes: const [.16, .5, .9],
        builder: (context, scroll) => Container(
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
            boxShadow: [BoxShadow(color: Color(0x33000000), blurRadius: 30, offset: Offset(0, -8))],
          ),
          child: dest == null
              ? _choose(context, s, data, origin, scroll)
              : target == null
                  ? _outdoor(context, s, dest, scroll)
                  : _arrived
                      ? _welcome(context, s, dest, scroll)
                      : _route(context, s, data, route, origin, dest, scroll),
        ),
      ),
    ]);
  }

  Widget _handle() => Center(child: Container(margin: const EdgeInsets.only(top: 10, bottom: 6), width: 46, height: 5, decoration: BoxDecoration(color: AppColors.navy100, borderRadius: BorderRadius.circular(3))));

  // ---------------------------------------------------------------- choose a destination

  Widget _choose(BuildContext context, S s, WayData data, Origin origin, ScrollController scroll) {
    final en = !s.isArabic;
    String norm(String v) => v.toLowerCase().replaceAll(RegExp('[أإآ]'), 'ا').replaceAll('ة', 'ه').replaceAll('ى', 'ي').replaceAllMapped(RegExp('[٠-٩]'), (m) => '${'٠١٢٣٤٥٦٧٨٩'.indexOf(m[0]!)}');
    final words = norm(_query).split(RegExp(r'\s+')).where((w) => w.isNotEmpty).toList();
    final list = data.destinations.where((d) {
      final kind = d.kind == 'room' ? 'train' : d.kind;
      if (_filter == 'train' && kind != 'train') return false;
      if (_filter == 'lab' && kind != 'lab') return false;
      if (_filter == 'meet' && kind != 'meet') return false;
      if (_filter == 'services' && !const ['public', 'pray', 'wc', 'office'].contains(kind)) return false;
      final hay = norm('${d.ar} ${d.en} ${d.place?.number ?? ''}');
      return words.every(hay.contains);
    }).toList();

    return ListView(controller: scroll, padding: const EdgeInsets.fromLTRB(18, 0, 18, 24), children: [
      _handle(),
      Text(s.t('way.whereTo'), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppColors.navy900)),
      const SizedBox(height: 12),
      TextField(
        onChanged: (v) => setState(() => _query = v),
        textInputAction: TextInputAction.search,
        decoration: InputDecoration(prefixIcon: const Icon(Icons.search_rounded), hintText: s.t('way.search'), border: OutlineInputBorder(borderRadius: BorderRadius.circular(18))),
      ),
      const SizedBox(height: 10),
      _OriginRow(label: s.t('way.startFrom'), value: origin.name(en), onTap: () => _pickOrigin(context, s, data)),
      const SizedBox(height: 10),
      SizedBox(
        height: 38,
        child: ListView(scrollDirection: Axis.horizontal, children: [
          for (final f in const ['all', 'train', 'lab', 'meet', 'services'])
            Padding(
              padding: const EdgeInsetsDirectional.only(end: 8),
              child: ChoiceChip(label: Text(s.t('way.filter.$f')), selected: _filter == f, onSelected: (_) => setState(() => _filter = f)),
            ),
        ]),
      ),
      const SizedBox(height: 6),
      for (final d in list)
        ListTile(
          contentPadding: const EdgeInsets.symmetric(horizontal: 4),
          leading: _Tile(d: d),
          title: Text(d.name(en), maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
          subtitle: Text(
            '${d.place != null ? data.map.floor(d.place!.floor).name(en) : s.t('way.outside')}${d.room?.capacity != null ? ' · ${s.t('way.seats').replaceAll('{n}', '${d.room!.capacity}')}' : ''}',
            style: const TextStyle(fontSize: 12, color: AppColors.muted),
          ),
          trailing: Icon(s.isArabic ? Icons.chevron_left_rounded : Icons.chevron_right_rounded, color: AppColors.muted),
          onTap: () {
            HapticFeedback.selectionClick();
            _go(d.key);
          },
        ),
      if (list.isEmpty) Padding(padding: const EdgeInsets.all(24), child: Text(s.t('way.none'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted))),
    ]);
  }

  // ---------------------------------------------------------------- the route

  Widget _route(BuildContext context, S s, WayData data, WayRoute? route, Origin origin, Destination dest, ScrollController scroll) {
    final en = !s.isArabic;
    if (route == null) return ListView(controller: scroll, children: [_handle(), Padding(padding: const EdgeInsets.all(24), child: Text(s.t('way.noRoute'), textAlign: TextAlign.center))]);
    final step = _step.clamp(0, route.steps.length - 1).toInt();
    final last = step == route.steps.length - 1;
    final minutes = (route.seconds / 60).round().clamp(1, 99);
    final conn = route.connector;
    final destFloor = data.map.floor(dest.target!.floor).name(en);
    return Column(children: [
      Expanded(
        child: ListView(controller: scroll, padding: const EdgeInsets.fromLTRB(18, 0, 18, 12), children: [
          _handle(),
          Container(
            decoration: BoxDecoration(color: AppColors.ivory, borderRadius: BorderRadius.circular(20), border: Border.all(color: AppColors.navy100)),
            child: Column(children: [
              _Endpoint(icon: Icons.radio_button_checked, color: AppColors.navy700, label: s.t('way.from'), value: origin.name(en), onTap: () => _pickOrigin(context, s, data), trailing: Icons.swap_vert_rounded),
              const Divider(height: 1, indent: 52),
              _Endpoint(icon: Icons.place_rounded, color: AppColors.gold700, label: s.t('way.to'), value: dest.name(en), onTap: () => _go(null), trailing: Icons.close_rounded),
            ]),
          ),
          const SizedBox(height: 12),
          Wrap(spacing: 14, runSpacing: 4, crossAxisAlignment: WrapCrossAlignment.center, children: [
            Text(s.t('way.minutes').replaceAll('{n}', '$minutes'), style: TextStyle(fontSize: 24, fontWeight: FontWeight.w900, color: AppColors.navy900)),
            Row(mainAxisSize: MainAxisSize.min, children: [const Icon(Icons.directions_walk_rounded, size: 18, color: AppColors.muted), Text(s.t('way.metres').replaceAll('{n}', '${route.metres.round()}'), style: const TextStyle(color: AppColors.muted))]),
            Text('$destFloor${conn != null ? ' · ${s.t('way.via').replaceAll('{name}', en ? conn.en : conn.ar)}' : ''}', style: const TextStyle(color: AppColors.muted)),
          ]),
          const SizedBox(height: 10),
          for (var i = 0; i < route.steps.length; i++)
            Padding(
              padding: const EdgeInsets.only(bottom: 4),
              child: Material(
                color: i == step ? AppColors.navy900 : Colors.transparent,
                borderRadius: BorderRadius.circular(18),
                child: InkWell(
                  borderRadius: BorderRadius.circular(18),
                  onTap: () => setState(() {
                    _step = i;
                    _manualFloor = null;
                  }),
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Container(
                        width: 38,
                        height: 38,
                        decoration: BoxDecoration(color: i == step ? Colors.white.withValues(alpha: .1) : AppColors.ivory, borderRadius: BorderRadius.circular(12)),
                        child: Icon(_stepIcon(route.steps[i]), size: 20, color: i == step ? AppColors.gold300 : AppColors.navy800),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(
                            stepText(route.steps[i], s, data.map, dest.name(en), origin.name(en)),
                            style: TextStyle(fontWeight: FontWeight.w600, height: 1.45, color: i == step ? Colors.white : (i < step ? AppColors.muted : AppColors.navy900)),
                          ),
                        ),
                      ),
                    ]),
                  ),
                ),
              ),
            ),
        ]),
      ),
      SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(14, 6, 14, 10),
          child: Row(children: [
            IconButton.filledTonal(
              onPressed: step == 0 ? null : () => setState(() => _step = step - 1),
              icon: Icon(en ? Icons.arrow_back_rounded : Icons.arrow_forward_rounded),
              style: IconButton.styleFrom(minimumSize: const Size(54, 54)),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: FilledButton.icon(
                onPressed: () {
                  HapticFeedback.lightImpact();
                  if (last) {
                    setState(() => _arrived = true);
                  } else {
                    setState(() {
                      _step = step + 1;
                      _manualFloor = null;
                    });
                  }
                },
                style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54), backgroundColor: last ? AppColors.success : AppColors.navy900),
                icon: Icon(last ? Icons.check_rounded : (en ? Icons.arrow_forward_rounded : Icons.arrow_back_rounded)),
                label: Text(s.t(last ? 'way.arrived' : 'way.next'), style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ),
            if (data.centreLat != null && data.centreLng != null) ...[
              const SizedBox(width: 10),
              IconButton.filledTonal(
                tooltip: s.t('way.toCentre'),
                onPressed: () => launchUrl(directionsUri(data.centreLat!, data.centreLng!), mode: LaunchMode.externalApplication),
                icon: const Icon(Icons.directions_car_rounded),
                style: IconButton.styleFrom(minimumSize: const Size(54, 54)),
              ),
            ],
          ]),
        ),
      ),
    ]);
  }

  IconData _stepIcon(WayStep s) {
    switch (s.kind) {
      case 'start':
        return Icons.navigation_rounded;
      case 'arrive':
        return Icons.place_rounded;
      case 'left':
      case 'slight-left':
        return Icons.turn_left_rounded;
      case 'right':
      case 'slight-right':
        return Icons.turn_right_rounded;
      case 'u-turn':
        return Icons.u_turn_left_rounded;
      case 'stairs':
        return Icons.stairs_rounded;
      case 'lift':
        return Icons.elevator_rounded;
      default:
        return Icons.straight_rounded;
    }
  }

  Widget _welcome(BuildContext context, S s, Destination dest, ScrollController scroll) => ListView(controller: scroll, padding: const EdgeInsets.all(24), children: [
        _handle(),
        const SizedBox(height: 8),
        Center(
          child: Container(
            width: 76,
            height: 76,
            decoration: const BoxDecoration(color: Color(0xFFE7F6EF), shape: BoxShape.circle),
            child: const Icon(Icons.check_rounded, size: 40, color: AppColors.success),
          ),
        ),
        const SizedBox(height: 14),
        Text(s.t('way.welcome').replaceAll('{dest}', dest.name(!s.isArabic)), textAlign: TextAlign.center, style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800, color: AppColors.navy900)),
        const SizedBox(height: 6),
        Text(s.t('way.welcomeHint'), textAlign: TextAlign.center, style: const TextStyle(color: AppColors.muted)),
        const SizedBox(height: 18),
        FilledButton(onPressed: () => _go(null), style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(52)), child: Text(s.t('way.another'))),
      ]);

  // ---------------------------------------------------------------- a room outside the centre

  Widget _outdoor(BuildContext context, S s, Destination dest, ScrollController scroll) {
    final r = dest.room;
    return ListView(controller: scroll, padding: const EdgeInsets.fromLTRB(20, 0, 20, 24), children: [
      _handle(),
      Row(children: [
        _Tile(d: dest),
        const SizedBox(width: 12),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(dest.name(!s.isArabic), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800, color: AppColors.navy900)),
            Text(s.t('way.outsideHint'), style: const TextStyle(fontSize: 12, color: AppColors.muted)),
          ]),
        ),
      ]),
      const SizedBox(height: 16),
      if (r?.latitude != null && r?.longitude != null) ...[
        _Distance(lat: r!.latitude!, lng: r.longitude!),
        const SizedBox(height: 12),
        FilledButton.icon(
          onPressed: () => launchUrl(directionsUri(r.latitude!, r.longitude!), mode: LaunchMode.externalApplication),
          style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(54)),
          icon: const Icon(Icons.directions_car_rounded),
          label: Text(s.t('way.directions')),
        ),
      ] else
        Text(s.t('way.noLocation'), style: const TextStyle(color: AppColors.muted)),
      TextButton(onPressed: () => _go(null), child: Text(s.t('way.change'))),
    ]);
  }

  // ---------------------------------------------------------------- where am I

  Future<void> _pickOrigin(BuildContext context, S s, WayData data) async {
    final en = !s.isArabic;
    final picked = await showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      builder: (sheet) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: .75,
        maxChildSize: .92,
        builder: (_, scroll) => ListView(controller: scroll, padding: const EdgeInsets.fromLTRB(18, 0, 18, 24), children: [
          Text(s.t('way.whereAreYou'), style: TextStyle(fontSize: 19, fontWeight: FontWeight.w800, color: AppColors.navy900)),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(child: _BigChoice(icon: Icons.qr_code_scanner_rounded, title: s.t('way.scanSign'), hint: s.t('way.scanHint'), onTap: () => Navigator.pop(sheet, '__scan'))),
            const SizedBox(width: 10),
            Expanded(child: _BigChoice(icon: Icons.touch_app_rounded, title: s.t('way.tapMap'), hint: s.t('way.tapHint'), onTap: () => Navigator.pop(sheet, '__tap'))),
          ]),
          const SizedBox(height: 16),
          SectionTitle(s.t('way.entrances')),
          for (final e in [...data.entrances, ...data.signs])
            ListTile(
              leading: const Icon(Icons.door_front_door_outlined),
              title: Text(en ? e.en : e.ar, style: const TextStyle(fontWeight: FontWeight.w700)),
              subtitle: Text(data.map.floor(e.floor).name(en)),
              onTap: () => Navigator.pop(sheet, e.id),
            ),
          SectionTitle(s.t('way.nearPlace')),
          for (final p in data.map.places.where((p) => p.kind != 'wc'))
            ListTile(
              dense: true,
              leading: const Icon(Icons.place_outlined),
              title: Text(p.name(en)),
              subtitle: Text(data.map.floor(p.floor).name(en)),
              onTap: () => Navigator.pop(sheet, p.id),
            ),
        ]),
      ),
    );
    if (!mounted || picked == null) return;
    if (picked == '__tap') {
      setState(() => _picking = true);
      if (_sheet.isAttached) _sheet.animateTo(.16, duration: const Duration(milliseconds: 300), curve: Curves.easeOut);
      return;
    }
    if (picked == '__scan') {
      if (!context.mounted) return;
      final key = await Navigator.of(context).push<String>(MaterialPageRoute(fullscreenDialog: true, builder: (_) => const _SignScanner()));
      if (key != null && data.origin(key) != null) _start(key);
      return;
    }
    _start(picked);
  }
}

class _Glass extends StatelessWidget {
  const _Glass({required this.child, this.padding = EdgeInsets.zero, this.color});
  final Widget child;
  final EdgeInsets padding;
  final Color? color;
  @override
  Widget build(BuildContext context) => Material(
        color: color ?? Colors.white.withValues(alpha: .94),
        elevation: 4,
        shadowColor: Colors.black26,
        borderRadius: BorderRadius.circular(18),
        child: Padding(padding: padding, child: child),
      );
}

class _OriginRow extends StatelessWidget {
  const _OriginRow({required this.label, required this.value, required this.onTap});
  final String label, value;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => Material(
        color: AppColors.ivory,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Row(children: [
              Container(width: 34, height: 34, decoration: BoxDecoration(color: AppColors.navy900, borderRadius: BorderRadius.circular(11)), child: const Icon(Icons.my_location_rounded, size: 18, color: Colors.white)),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(label, style: const TextStyle(fontSize: 11, color: AppColors.muted, fontWeight: FontWeight.w600)),
                  Text(value, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
                ]),
              ),
              const Icon(Icons.expand_more_rounded, color: AppColors.muted),
            ]),
          ),
        ),
      );
}

class _Endpoint extends StatelessWidget {
  const _Endpoint({required this.icon, required this.color, required this.label, required this.value, required this.onTap, required this.trailing});
  final IconData icon, trailing;
  final Color color;
  final String label, value;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
          child: Row(children: [
            Icon(icon, color: color, size: 22),
            const SizedBox(width: 14),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(label, style: const TextStyle(fontSize: 11, color: AppColors.muted)),
                Text(value, maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontWeight: FontWeight.w800, color: AppColors.navy900)),
              ]),
            ),
            Icon(trailing, size: 18, color: AppColors.muted),
          ]),
        ),
      );
}

class _Tile extends StatelessWidget {
  const _Tile({required this.d});
  final Destination d;
  @override
  Widget build(BuildContext context) {
    final number = d.place?.number != null && (d.kind == 'train' || d.kind == 'lab') ? d.place!.number! : null;
    final train = d.kind == 'train';
    final icon = switch (d.kind) {
      'lab' => Icons.science_outlined,
      'meet' => Icons.groups_outlined,
      'public' => Icons.local_library_outlined,
      'office' => Icons.business_outlined,
      'pray' => Icons.mosque_outlined,
      'wc' => Icons.wc_rounded,
      _ => Icons.place_outlined,
    };
    return Container(
      width: 48,
      height: 48,
      alignment: Alignment.center,
      decoration: BoxDecoration(
        color: train ? AppColors.navy900 : (d.kind == 'lab' ? AppColors.navy100 : AppColors.gold100),
        borderRadius: BorderRadius.circular(16),
      ),
      child: number != null
          ? Text(number, style: TextStyle(fontSize: 18, fontWeight: FontWeight.w900, color: train ? Colors.white : AppColors.navy900))
          : Icon(icon, color: train ? Colors.white : AppColors.gold700),
    );
  }
}

class _BigChoice extends StatelessWidget {
  const _BigChoice({required this.icon, required this.title, required this.hint, required this.onTap});
  final IconData icon;
  final String title, hint;
  final VoidCallback onTap;
  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Ink(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(gradient: AppColors.navyGradient, borderRadius: BorderRadius.circular(20)),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Icon(icon, color: AppColors.gold300, size: 26),
            const SizedBox(height: 8),
            Text(title, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
            const SizedBox(height: 2),
            Text(hint, style: const TextStyle(color: Colors.white70, fontSize: 11, height: 1.3)),
          ]),
        ),
      );
}

/// Distance from the visitor to a room outside the centre (asks for the location only when this is shown).
class _Distance extends StatefulWidget {
  const _Distance({required this.lat, required this.lng});
  final double lat, lng;
  @override
  State<_Distance> createState() => _DistanceState();
}

class _DistanceState extends State<_Distance> {
  double? _metres;
  bool _failed = false;

  @override
  void initState() {
    super.initState();
    _locate();
  }

  Future<void> _locate() async {
    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) permission = await Geolocator.requestPermission();
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) throw Exception('denied');
      final p = await Geolocator.getCurrentPosition(locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 12)));
      if (mounted) setState(() => _metres = gpsDistance(p.latitude, p.longitude, widget.lat, widget.lng));
    } catch (_) {
      if (mounted) setState(() => _failed = true);
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final m = _metres;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(color: AppColors.ivory, borderRadius: BorderRadius.circular(18)),
      child: Row(children: [
        Icon(Icons.near_me_rounded, color: AppColors.navy800),
        const SizedBox(width: 10),
        Expanded(
          child: Text(
            m != null ? s.t('way.away').replaceAll('{km}', (m / 1000).toStringAsFixed(m < 1000 ? 2 : 1)) : (_failed ? s.t('way.noGps') : s.t('way.locating')),
            style: TextStyle(fontWeight: FontWeight.w700, color: AppColors.navy900),
          ),
        ),
      ]),
    );
  }
}

/// Scans a "you are here" sign: its QR code carries the spot (`…/map?from=<id>`).
class _SignScanner extends StatefulWidget {
  const _SignScanner();
  @override
  State<_SignScanner> createState() => _SignScannerState();
}

class _SignScannerState extends State<_SignScanner> {
  final _controller = MobileScannerController(detectionSpeed: DetectionSpeed.noDuplicates, formats: const [BarcodeFormat.qrCode]);
  bool _done = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(backgroundColor: Colors.black, foregroundColor: Colors.white, title: Text(s.t('way.scanSign'))),
      body: Stack(children: [
        MobileScanner(
          controller: _controller,
          onDetect: (capture) {
            if (_done) return;
            for (final b in capture.barcodes) {
              final key = b.rawValue == null ? null : signKey(b.rawValue!);
              if (key != null) {
                _done = true;
                HapticFeedback.mediumImpact();
                Navigator.of(context).pop(key);
                return;
              }
            }
          },
        ),
        Center(
          child: Container(
            width: 240,
            height: 240,
            decoration: BoxDecoration(border: Border.all(color: AppColors.gold300, width: 3), borderRadius: BorderRadius.circular(28)),
          ),
        ),
        Positioned(
          left: 24,
          right: 24,
          bottom: 48,
          child: Text(s.t('way.scanHint'), textAlign: TextAlign.center, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600)),
        ),
      ]),
    );
  }
}
