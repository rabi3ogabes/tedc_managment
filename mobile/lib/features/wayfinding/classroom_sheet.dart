import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../core/format.dart';
import '../../core/l10n/strings.dart';
import '../../core/theme/app_theme.dart';
import 'centre_fix.dart';
import 'engine.dart';
import 'floor_plan.dart';
import 'wayfinding_data.dart';
import 'wayfinding_screen.dart' show SignScanner;

/// The classroom popup (docs/wayfinding.md): the map icon next to a session opens a sheet that shows where the room is in the
/// centre, reads the phone's location against the centre, draws the walk from the entrance and says whether the person arrives in time.

/// The session the popup is opened for.
class ClassroomTarget {
  const ClassroomTarget({
    required this.roomId,
    this.placeId = '',
    this.program = '',
    this.title = '',
    this.startsAt,
    this.endsAt,
  });

  /// The session's room record, and the place of the plans the server worked out for it (from the room, or from the room named as text).
  final String roomId, placeId, program, title;
  final DateTime? startsAt, endsAt;

  /// Whether the session says where it is at all.
  bool get hasPlace => roomId.isNotEmpty || placeId.isNotEmpty;

  /// The destination of the guide: the room by its own name when it stands on the plans, else the place.
  Destination? find(WayData data) =>
      (roomId.isEmpty ? null : data.destination('room:$roomId')) ??
      (placeId.isEmpty ? null : data.destination(placeId));
}

/// The map icon of a session. Shown only for a session at the centre (not online, not cancelled, not over) whose room stands on the
/// centre's plans; a room elsewhere gets [fallback] (the full-screen guide's outdoor directions) or nothing.
class ClassroomMapButton extends ConsumerWidget {
  const ClassroomMapButton({
    super.key,
    required this.target,
    required this.mode,
    this.cancelled = false,
    this.wide = false,
    this.fallback,
  });

  final ClassroomTarget target;
  final String mode;
  final bool cancelled;

  /// A full-width labelled button (the session screen) instead of the round icon.
  final bool wide;
  final Widget? fallback;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final ends = target.endsAt;
    if (mode == 'online' ||
        cancelled ||
        !target.hasPlace ||
        (ends != null && ends.isBefore(DateTime.now()))) {
      return const SizedBox.shrink();
    }
    final data = ref.watch(wayDataProvider).value;
    if (data == null) return const SizedBox.shrink();
    if (target.find(data)?.target == null) {
      return fallback ?? const SizedBox.shrink();
    }
    final s = context.s;
    if (wide) {
      return OutlinedButton.icon(
        key: const ValueKey('classroom-map'),
        onPressed: () => showClassroomSheet(context, target),
        style: OutlinedButton.styleFrom(minimumSize: const Size.fromHeight(52)),
        icon: const Icon(Icons.map_outlined),
        label: Text(s.t('way.toRoom')),
      );
    }
    return IconButton.filled(
      key: const ValueKey('classroom-map'),
      tooltip: s.t('way.sheet.open'),
      visualDensity: VisualDensity.compact,
      style: IconButton.styleFrom(
        backgroundColor: AppColors.navy900,
        foregroundColor: AppColors.gold300,
      ),
      icon: const Icon(Icons.map_outlined, size: 20),
      onPressed: () => showClassroomSheet(context, target),
    );
  }
}

/// Opens the classroom popup.
Future<void> showClassroomSheet(BuildContext context, ClassroomTarget target) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useRootNavigator: true,
    useSafeArea: true,
    showDragHandle: true,
    backgroundColor: const Color(0xFFF7F5F0),
    shape: const RoundedRectangleBorder(
      borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
    ),
    builder: (_) => ClassroomSheet(target: target),
  );
}

class ClassroomSheet extends ConsumerStatefulWidget {
  const ClassroomSheet({super.key, required this.target});

  final ClassroomTarget target;

  @override
  ConsumerState<ClassroomSheet> createState() => _ClassroomSheetState();
}

class _ClassroomSheetState extends ConsumerState<ClassroomSheet> {
  String? _fromKey;
  bool _avoidStairs = false;
  String? _floor;
  Position? _fix;
  bool _locating = true;
  bool _noGps = false;
  StreamSubscription<Position>? _gps;
  Timer? _tick;
  Timer? _giveUp;

  @override
  void initState() {
    super.initState();
    // The minutes before the session move on while the sheet is open.
    _tick = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) setState(() {});
    });
    unawaited(_locate());
  }

  @override
  void dispose() {
    _gps?.cancel();
    _tick?.cancel();
    _giveUp?.cancel();
    super.dispose();
  }

  /// Reads the phone's position, now and as it changes: arriving at the centre updates the sheet by itself.
  Future<void> _locate() async {
    try {
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied ||
          permission == LocationPermission.deniedForever) {
        throw Exception('denied');
      }
      final last = await Geolocator.getLastKnownPosition();
      if (!mounted) return;
      if (last != null) setState(() => _fix = last);
      _giveUp = Timer(const Duration(seconds: 15), () {
        if (mounted && _fix == null) {
          setState(() {
            _locating = false;
            _noGps = true;
          });
        }
      });
      _gps =
          Geolocator.getPositionStream(
            locationSettings: const LocationSettings(
              accuracy: LocationAccuracy.high,
              distanceFilter: 15,
            ),
          ).listen(
            (p) {
              if (mounted) {
                setState(() {
                  _fix = p;
                  _locating = false;
                  _noGps = false;
                });
              }
            },
            onError: (_) {
              if (mounted && _fix == null) {
                setState(() {
                  _locating = false;
                  _noGps = true;
                });
              }
            },
          );
    } catch (_) {
      if (mounted) {
        setState(() {
          _locating = false;
          _noGps = true;
        });
      }
    }
  }

  Future<void> _scan(WayData data) async {
    final key = await Navigator.of(context).push<String>(
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => const SignScanner(),
      ),
    );
    if (key != null && mounted && data.origin(key) != null) {
      HapticFeedback.mediumImpact();
      setState(() {
        _fromKey = key;
        _floor = null;
      });
    }
  }

  void _pick(String key) {
    HapticFeedback.selectionClick();
    setState(() {
      _fromKey = key;
      _floor = null;
    });
  }

  /// The full-screen guide takes over from the chosen start, step by step.
  void _startGuide(Origin origin, String to) {
    final router = GoRouter.of(context);
    Navigator.of(context).pop();
    router.push(
      Uri(
        path: '/map',
        queryParameters: {'to': to, 'from': origin.key},
      ).toString(),
    );
  }

  @override
  Widget build(BuildContext context) {
    final s = S.of(context);
    final value = ref.watch(wayDataProvider);
    final height = MediaQuery.of(context).size.height * .9;
    return SizedBox(
      height: height,
      child: value.when(
        loading: () => const Center(child: CircularProgressIndicator()),
        error: (_, _) => Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                s.t('way.sheet.failed'),
                style: const TextStyle(color: AppColors.muted),
              ),
              TextButton(
                onPressed: () => ref.invalidate(wayDataProvider),
                child: Text(s.t('way.sheet.retry')),
              ),
            ],
          ),
        ),
        data: (data) => _content(context, s, data),
      ),
    );
  }

  Widget _content(BuildContext context, S s, WayData data) {
    final en = !s.isArabic;
    final fmt = Fmt(s.languageCode);
    final dest = widget.target.find(data);
    final target = dest?.target;
    if (dest == null || target == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Text(s.t('way.outsideHint'), textAlign: TextAlign.center),
        ),
      );
    }
    final origin = data.origin(_fromKey) ?? data.defaultOrigin;
    final route = data.engine.route(
      origin.floor,
      origin.at,
      target,
      avoidStairs: _avoidStairs,
    );

    // Where the phone is against the centre.
    final fix = _fix;
    final lat = data.centreLat, lng = data.centreLng;
    final metres = fix != null && lat != null && lng != null
        ? gpsDistance(fix.latitude, fix.longitude, lat, lng)
        : null;
    final proximity = metres == null
        ? null
        : proximityOf(metres, accuracy: fix!.accuracy);

    // Will the person be in their seat when it starts?
    final starts = widget.target.startsAt, ends = widget.target.endsAt;
    final now = DateTime.now();
    final minutesToStart = starts == null
        ? null
        : (starts.difference(now).inSeconds / 60).ceil();
    final over = ends != null && ends.isBefore(now);
    final travel = metres != null && proximity != CentreProximity.at
        ? driveMinutes(metres) + 3
        : 0;
    final needed = travel + (route == null ? 0 : walkMinutes(route.seconds));
    final verdict = minutesToStart == null || over
        ? null
        : verdictFor(minutesToStart: minutesToStart, minutesNeeded: needed);

    // The floors the walk crosses, the start's first; the destination's floor is always there.
    final floors = <String>{if (route != null) ...route.legs.map((l) => l.floor), target.floor}.toList();
    final floor = floors.contains(_floor) ? _floor! : floors.first;

    return Column(
      children: [
        Expanded(
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
            children: [
              _header(s, fmt, data, dest, starts, ends),
              const SizedBox(height: 14),
              _where(s, data, metres, proximity),
              if (verdict != null)
                _verdict(s, verdict, minutesToStart!, needed),
              const SizedBox(height: 12),
              _plan(s, data, dest, origin, route, floor, floors),
              const SizedBox(height: 12),
              Text(
                s.t('way.sheet.from'),
                style: TextStyle(
                  fontWeight: FontWeight.w800,
                  color: AppColors.navy900,
                ),
              ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                crossAxisAlignment: WrapCrossAlignment.center,
                children: [
                  for (final e in data.entrances)
                    ChoiceChip(
                      label: Text(en ? e.en : e.ar),
                      selected: origin.key == e.id,
                      onSelected: (_) => _pick(e.id),
                    ),
                  if (data.entrances.every((e) => e.id != origin.key))
                    ChoiceChip(
                      label: Text(origin.name(en)),
                      selected: true,
                      onSelected: (_) {},
                    ),
                  ActionChip(
                    avatar: const Icon(Icons.qr_code_scanner_rounded, size: 18),
                    label: Text(s.t('way.sheet.scan')),
                    onPressed: () => _scan(data),
                  ),
                  FilterChip(
                    avatar: const Icon(Icons.accessible_rounded, size: 18),
                    label: Text(s.t('way.sheet.stepFree')),
                    selected: _avoidStairs,
                    onSelected: (v) => setState(() {
                      _avoidStairs = v;
                      _floor = null;
                    }),
                  ),
                ],
              ),
              const SizedBox(height: 14),
              if (route == null)
                _note(
                  Icons.alt_route_rounded,
                  s.t('way.sheet.noRoute'),
                  AppColors.warning,
                )
              else
                _summary(s, data, route, dest, origin),
            ],
          ),
        ),
        Padding(
          padding: EdgeInsets.fromLTRB(
            20,
            8,
            20,
            16 + MediaQuery.of(context).padding.bottom,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              FilledButton.icon(
                key: const ValueKey('classroom-start'),
                onPressed: route == null
                    ? null
                    : () => _startGuide(origin, dest.key),
                style: FilledButton.styleFrom(
                  minimumSize: const Size.fromHeight(54),
                ),
                icon: const Icon(Icons.navigation_rounded),
                label: Text(s.t('way.sheet.start')),
              ),
              if (proximity != null &&
                  proximity != CentreProximity.at &&
                  lat != null &&
                  lng != null) ...[
                const SizedBox(height: 8),
                OutlinedButton.icon(
                  onPressed: () => launchUrl(
                    directionsUri(lat, lng),
                    mode: LaunchMode.externalApplication,
                  ),
                  style: OutlinedButton.styleFrom(
                    minimumSize: const Size.fromHeight(48),
                  ),
                  icon: const Icon(Icons.directions_car_outlined),
                  label: Text(s.t('way.sheet.directions')),
                ),
              ],
            ],
          ),
        ),
      ],
    );
  }

  /// The room, its floor and when the session is.
  Widget _header(
    S s,
    Fmt fmt,
    WayData data,
    Destination dest,
    DateTime? starts,
    DateTime? ends,
  ) {
    final en = !s.isArabic;
    final floorName = data.map.floor(dest.target!.floor).name(en);
    final now = DateTime.now();
    final running =
        starts != null &&
        ends != null &&
        !now.isBefore(starts) &&
        now.isBefore(ends);
    final today =
        starts != null &&
        starts.year == now.year &&
        starts.month == now.month &&
        starts.day == now.day;
    final when = running
        ? s.t('way.sheet.running')
        : starts == null
        ? null
        : s
              .t('way.sheet.starts')
              .replaceAll(
                '{time}',
                today
                    ? fmt.time(starts)
                    : '${fmt.weekdayDate(starts)} · ${fmt.time(starts)}',
              );
    final subtitle = widget.target.program.isNotEmpty
        ? widget.target.program
        : widget.target.title;
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Container(
          width: 54,
          height: 54,
          decoration: BoxDecoration(
            color: AppColors.navy900,
            borderRadius: BorderRadius.circular(18),
          ),
          child: Icon(
            Icons.meeting_room_outlined,
            color: AppColors.gold300,
            size: 28,
          ),
        ),
        const SizedBox(width: 14),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                s.t('way.sheet.title'),
                style: const TextStyle(
                  color: AppColors.muted,
                  fontSize: 12,
                  fontWeight: FontWeight.w600,
                ),
              ),
              Text(
                dest.name(en),
                style: TextStyle(
                  fontSize: 22,
                  fontWeight: FontWeight.w800,
                  color: AppColors.navy900,
                  height: 1.2,
                ),
              ),
              if (subtitle.isNotEmpty)
                Text(
                  subtitle,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(color: AppColors.muted, fontSize: 13),
                ),
              const SizedBox(height: 8),
              Wrap(
                spacing: 8,
                runSpacing: 6,
                children: [
                  _pill(Icons.layers_outlined, floorName),
                  if (when != null)
                    _pill(
                      running
                          ? Icons.play_circle_outline
                          : Icons.schedule_rounded,
                      when,
                    ),
                ],
              ),
            ],
          ),
        ),
      ],
    );
  }

  Widget _pill(IconData icon, String text) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(20),
      border: Border.all(color: AppColors.navy100),
    ),
    child: Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Icon(icon, size: 15, color: AppColors.gold700),
        const SizedBox(width: 5),
        Text(
          text,
          style: TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: AppColors.navy900,
          ),
        ),
      ],
    ),
  );

  /// What the phone's location says: reading it, not allowed, far away, close, at the centre.
  Widget _where(S s, WayData data, double? metres, CentreProximity? proximity) {
    if (proximity == null) {
      if (_noGps) {
        return _card(
          icon: Icons.location_off_outlined,
          color: AppColors.warning,
          text: s.t('way.sheet.noGps'),
          action: TextButton(
            onPressed: () => Geolocator.openAppSettings(),
            child: Text(s.t('way.sheet.allow')),
          ),
        );
      }
      if (_locating) {
        return _card(
          icon: Icons.my_location_rounded,
          color: AppColors.gold700,
          text: s.t('way.sheet.locating'),
          spinner: true,
        );
      }
      return const SizedBox.shrink();
    }
    final m = metres!;
    switch (proximity) {
      case CentreProximity.far:
        final km = (m / 1000).toStringAsFixed(m < 10000 ? 1 : 0);
        return _card(
          icon: Icons.directions_car_outlined,
          color: AppColors.navy800,
          text: s
              .t('way.sheet.far')
              .replaceAll('{km}', km)
              .replaceAll('{n}', '${driveMinutes(m)}'),
        );
      case CentreProximity.near:
        return _card(
          icon: Icons.near_me_rounded,
          color: AppColors.navy800,
          text: s.t('way.sheet.near').replaceAll('{m}', '${m.round()}'),
        );
      case CentreProximity.at:
        return _card(
          icon: Icons.where_to_vote_outlined,
          color: AppColors.success,
          text: s.t('way.sheet.at'),
        );
    }
  }

  Widget _card({
    required IconData icon,
    required Color color,
    required String text,
    bool spinner = false,
    Widget? action,
  }) => Container(
    width: double.infinity,
    margin: const EdgeInsets.only(bottom: 8),
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: AppColors.navy100),
    ),
    child: Row(
      children: [
        if (spinner)
          const SizedBox(
            width: 20,
            height: 20,
            child: CircularProgressIndicator(strokeWidth: 2),
          )
        else
          Icon(icon, color: color),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            text,
            style: TextStyle(
              fontWeight: FontWeight.w700,
              color: AppColors.navy900,
              height: 1.4,
            ),
          ),
        ),
        ?action,
      ],
    ),
  );

  Widget _verdict(S s, ArrivalVerdict verdict, int minutesToStart, int needed) {
    final spare = minutesToStart - needed;
    final (
      IconData icon,
      Color color,
      Color bg,
      String text,
    ) = switch (verdict) {
      ArrivalVerdict.started => (
        Icons.directions_run_rounded,
        AppColors.danger,
        const Color(0xFFFDECEA),
        s.t('way.sheet.v.started').replaceAll('{n}', '${-minutesToStart}'),
      ),
      ArrivalVerdict.comfortable => (
        Icons.check_circle_outline_rounded,
        AppColors.success,
        const Color(0xFFE7F6EF),
        s.t('way.sheet.v.comfortable').replaceAll('{n}', '$spare'),
      ),
      ArrivalVerdict.soon => (
        Icons.timer_outlined,
        AppColors.warning,
        const Color(0xFFFFF4DC),
        s.t('way.sheet.v.soon').replaceAll('{n}', '$spare'),
      ),
      ArrivalVerdict.late => (
        Icons.warning_amber_rounded,
        AppColors.danger,
        const Color(0xFFFDECEA),
        s.t('way.sheet.v.late').replaceAll('{n}', '${-spare}'),
      ),
    };
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(18),
      ),
      child: Row(
        children: [
          Icon(icon, color: color),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              text,
              style: TextStyle(
                fontWeight: FontWeight.w800,
                color: color,
                height: 1.4,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _note(IconData icon, String text, Color color) => Container(
    width: double.infinity,
    padding: const EdgeInsets.all(12),
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(18),
      border: Border.all(color: AppColors.navy100),
    ),
    child: Row(
      children: [
        Icon(icon, color: color),
        const SizedBox(width: 12),
        Expanded(
          child: Text(
            text,
            style: const TextStyle(fontWeight: FontWeight.w600, height: 1.4),
          ),
        ),
      ],
    ),
  );

  /// The plan of the floor, with the walk drawn on it and a switch between the floors the walk crosses.
  Widget _plan(
    S s,
    WayData data,
    Destination dest,
    Origin origin,
    WayRoute? route,
    String floor,
    List<String> floors,
  ) {
    final en = !s.isArabic;
    final target = dest.target!;
    final leg = route?.legs.where((l) => l.floor == floor).firstOrNull?.points;
    return ClipRRect(
      borderRadius: BorderRadius.circular(22),
      child: Container(
        height: 250,
        decoration: BoxDecoration(
          color: const Color(0xFFF3F1EC),
          border: Border.all(color: AppColors.navy100),
          borderRadius: BorderRadius.circular(22),
        ),
        child: Stack(
          children: [
            Positioned.fill(
              child: FloorPlanView(
                key: ValueKey('plan-$floor'),
                map: data.map,
                floor: floor,
                route: leg,
                you: origin.floor == floor ? origin.at : null,
                target: target.floor == floor ? target.at : null,
                highlight: target.floor == floor ? target.id : null,
                focus: leg != null && leg.isNotEmpty
                    ? leg
                    : [if (target.floor == floor) target.at else origin.at],
              ),
            ),
            if (floors.length > 1)
              PositionedDirectional(
                top: 10,
                start: 10,
                child: Container(
                  padding: const EdgeInsets.all(4),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: .92),
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      for (final f in floors)
                        GestureDetector(
                          onTap: () {
                            HapticFeedback.selectionClick();
                            setState(() => _floor = f);
                          },
                          child: AnimatedContainer(
                            duration: const Duration(milliseconds: 200),
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 8,
                            ),
                            decoration: BoxDecoration(
                              color: floor == f
                                  ? AppColors.navy900
                                  : Colors.transparent,
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: Text(
                              data.map.floor(f).name(en),
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w800,
                                color: floor == f
                                    ? Colors.white
                                    : AppColors.navy900,
                              ),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  /// The walk in numbers and the first steps in words.
  Widget _summary(
    S s,
    WayData data,
    WayRoute route,
    Destination dest,
    Origin origin,
  ) {
    final en = !s.isArabic;
    final connector = route.connector;
    final steps = route.steps.take(2).toList();
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Wrap(
          spacing: 8,
          runSpacing: 6,
          children: [
            _pill(
              Icons.straighten_rounded,
              s.t('way.metres').replaceAll('{n}', '${route.metres.round()}'),
            ),
            _pill(
              Icons.directions_walk_rounded,
              s
                  .t('way.minutes')
                  .replaceAll(
                    '{n}',
                    '${math.max(1, (route.seconds / 60).ceil())}',
                  ),
            ),
            if (connector != null)
              _pill(
                Icons.stairs_outlined,
                s
                    .t('way.via')
                    .replaceAll('{name}', en ? connector.en : connector.ar),
              ),
          ],
        ),
        const SizedBox(height: 12),
        Text(
          s.t('way.sheet.firstSteps'),
          style: TextStyle(
            fontWeight: FontWeight.w800,
            color: AppColors.navy900,
          ),
        ),
        const SizedBox(height: 6),
        for (final step in steps)
          Padding(
            padding: const EdgeInsets.only(bottom: 6),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Padding(
                  padding: const EdgeInsets.only(top: 2),
                  child: Icon(Icons.circle, size: 8, color: AppColors.gold500),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    stepText(step, s, data.map, dest.name(en), origin.name(en)),
                    style: const TextStyle(height: 1.5),
                  ),
                ),
              ],
            ),
          ),
      ],
    );
  }
}
