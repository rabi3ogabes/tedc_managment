import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/l10n/strings.dart';
import '../../core/providers.dart';
import 'engine.dart';

/// A training room as the centre's map knows it (docs/wayfinding.md): its place on the plans, its door, or — for a room that is
/// not at the centre — its coordinates for the walk outdoors.
class WayRoom {
  WayRoom.fromJson(Map<String, dynamic> j)
      : id = j['id'] as String,
        nameAr = j['name_ar'] as String? ?? '',
        nameEn = (j['name_en'] as String?)?.isNotEmpty == true ? j['name_en'] as String : (j['name_ar'] as String? ?? ''),
        capacity = (j['capacity'] as num?)?.toInt(),
        place = j['place'] as String?,
        floor = j['floor'] as String?,
        door = j['door'] == null ? null : Pt.of(j['door']),
        latitude = (j['latitude'] as num?)?.toDouble(),
        longitude = (j['longitude'] as num?)?.toDouble();
  final String id, nameAr, nameEn;
  final int? capacity;
  final String? place, floor;
  final Pt? door;
  final double? latitude, longitude;
}

/// Something a visitor can go to: a room by its own name, or a place of the plans (the library, a prayer room…).
class Destination {
  Destination({required this.key, required this.ar, required this.en, required this.kind, this.place, this.room, this.target});
  final String key, ar, en, kind;
  final Place? place;
  final WayRoom? room;
  final Target? target;
  String name(bool en_) => en_ ? en : ar;
}

/// Where the visitor starts: an entrance, a sign, a place, the stairs, or a tapped point.
class Origin {
  const Origin(this.key, this.floor, this.at, this.ar, this.en);
  final String key, floor, ar, en;
  final Pt at;
  String name(bool en_) => en_ ? en : ar;
}

class WayData {
  WayData(Map<String, dynamic> j)
      : map = CentreMap.fromJson({...Map<String, dynamic>.from(j['map'] as Map), 'entrances': j['entrances'] ?? const []}),
        signs = (j['signs'] as List? ?? const []).map((e) => Entrance.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        rooms = (j['rooms'] as List? ?? const []).map((e) => WayRoom.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        centreLat = ((j['centre'] as Map?)?['lat'] as num?)?.toDouble(),
        centreLng = ((j['centre'] as Map?)?['lng'] as num?)?.toDouble() {
    engine = WayEngine(map);
  }

  final CentreMap map;
  final List<Entrance> signs;
  final List<WayRoom> rooms;
  final double? centreLat, centreLng;
  late final WayEngine engine;

  List<Entrance> get entrances => map.entrances;

  late final List<Destination> destinations = () {
    final out = <Destination>[];
    final used = <String>{};
    for (final r in rooms) {
      final place = r.place == null ? null : map.places.where((p) => p.id == r.place).firstOrNull;
      if (place != null && r.door != null && r.floor != null) {
        used.add(place.id);
        out.add(Destination(key: 'room:${r.id}', ar: r.nameAr, en: r.nameEn, kind: place.kind, place: place, room: r, target: Target(r.floor!, r.door!, rect: place.rect, id: place.id)));
      } else if (r.latitude != null && r.longitude != null) {
        out.add(Destination(key: 'room:${r.id}', ar: r.nameAr, en: r.nameEn, kind: 'room', room: r));
      }
    }
    for (final p in map.places) {
      if (used.contains(p.id)) continue;
      out.add(Destination(key: p.id, ar: p.ar, en: p.en, kind: p.kind, place: p, target: Target(p.floor, p.door, rect: p.rect, id: p.id)));
    }
    int rank(Destination d) => d.room != null ? 0 : const ['train', 'lab', 'meet', 'public', 'pray', 'office', 'wc'].indexOf(d.kind) + 1;
    out.sort((a, b) {
      final r = rank(a).compareTo(rank(b));
      if (r != 0) return r;
      final na = int.tryParse(a.place?.number ?? '') ?? 99, nb = int.tryParse(b.place?.number ?? '') ?? 99;
      return na != nb ? na.compareTo(nb) : a.ar.compareTo(b.ar);
    });
    return out;
  }();

  Destination? destination(String? key) => key == null ? null : destinations.where((d) => d.key == key).firstOrNull;

  /// A starting point by key: "entrance-west", a sign, "g-room-5", "stairs-west@first", or a tapped "ground:420,610".
  Origin? origin(String? key) {
    if (key == null) return null;
    final tapped = RegExp(r'^(ground|first):(\d+),(\d+)$').firstMatch(key);
    if (tapped != null) {
      final floor = tapped.group(1)!;
      return Origin(key, floor, engine.snap(floor, Pt(double.parse(tapped.group(2)!), double.parse(tapped.group(3)!))), 'موقعك على الخريطة', 'Your spot on the map');
    }
    for (final e in [...entrances, ...signs]) {
      if (e.id == key) return Origin(key, e.floor, e.at, e.ar, e.en);
    }
    for (final p in map.places) {
      if (p.id == key) return Origin(key, p.floor, p.door, p.ar, p.en);
    }
    final parts = key.split('@');
    for (final c in map.connectors) {
      if (c.id == parts.first && parts.length == 2 && c.at.containsKey(parts[1])) return Origin(key, parts[1], c.at[parts[1]]!, c.ar, c.en);
    }
    return null;
  }

  Origin get defaultOrigin => origin(entrances.isNotEmpty ? entrances.first.id : null) ?? Origin('start', map.floors.first.id, map.places.first.door, '', '');
}

/// The centre's map from the server, kept on the phone for the next visit (the plans themselves never change between visits).
final wayDataProvider = FutureProvider.autoDispose<WayData>((ref) async {
  final raw = await ref.watch(getProvider('/public/wayfinding').future);
  return WayData(Map<String, dynamic>.from((raw as Map)['data'] as Map));
});

/// The starting point a "you are here" sign's QR code carries: `…/map?from=<id>` (or the bare id).
String? signKey(String raw) {
  final uri = Uri.tryParse(raw);
  if (uri != null && uri.hasScheme) return uri.queryParameters['from'];
  return RegExp(r'^[a-zA-Z0-9@:,-]{2,60}$').hasMatch(raw) ? raw : null;
}

/// One step as a sentence in the visitor's language.
String stepText(WayStep s, S t, CentreMap map, String dest, String from) {
  final en = !t.isArabic;
  final metres = math.max(1, s.metres.round());
  final walk = s.metres >= 0.5 ? t.t('way.step.walk').replaceAll('{m}', '$metres') : '';
  final near = s.near == null ? null : map.places.where((p) => p.id == s.near).firstOrNull;
  final nearText = near == null ? '' : t.t('way.step.near').replaceAll('{place}', near.name(en));
  String fill(String key) => t.t(key).replaceAll('{walk}', walk).replaceAll('{near}', nearText).replaceAll('{dest}', dest).replaceAll('{from}', from).replaceAll(RegExp(r'\s+'), ' ').trim();
  switch (s.kind) {
    case 'start':
      return fill('way.step.start');
    case 'arrive':
      return fill('way.step.arrive_${s.side ?? 'ahead'}');
    case 'stairs':
    case 'lift':
      final c = map.connectors.where((x) => x.id == s.connector).firstOrNull;
      return fill('way.step.${s.kind}_${s.up == true ? 'up' : 'down'}').replaceAll('{name}', c == null ? '' : (en ? c.en : c.ar)).replaceAll('{floor}', map.floor(s.to ?? '').name(en));
    default:
      return fill('way.step.${s.kind}');
  }
}

/// Metres between two GPS positions (haversine).
double gpsDistance(double lat1, double lng1, double lat2, double lng2) {
  const r = 6371000.0, rad = math.pi / 180;
  final dLat = (lat2 - lat1) * rad, dLng = (lng2 - lng1) * rad;
  final x = math.pow(math.sin(dLat / 2), 2) + math.cos(lat1 * rad) * math.cos(lat2 * rad) * math.pow(math.sin(dLng / 2), 2);
  return 2 * r * math.asin(math.sqrt(x));
}

/// Directions in the phone's maps app, to a point (only the destination is in the address).
Uri directionsUri(double lat, double lng) => Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$lat,$lng&travelmode=driving');
