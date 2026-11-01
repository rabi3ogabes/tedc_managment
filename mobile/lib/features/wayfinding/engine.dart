import 'dart:convert';
import 'dart:math' as math;
import 'dart:typed_data';

/// Indoor wayfinding (docs/wayfinding.md): the shortest walk between two points of the centre, on one floor or across floors
/// by the stairs or a lift, kept to the middle of the corridors, and the steps that go with it. The same algorithm as the
/// website's (web/src/features/wayfinding/engine.ts), run on the phone so routes are instant and work with a weak signal.
class Pt {
  const Pt(this.x, this.y);
  final double x, y;

  static Pt of(Object? v) {
    final l = v is List ? v : const [0, 0];
    return Pt((l[0] as num).toDouble(), (l[1] as num).toDouble());
  }

  double distanceTo(Pt o) => math.sqrt((x - o.x) * (x - o.x) + (y - o.y) * (y - o.y));

  @override
  bool operator ==(Object other) => other is Pt && other.x == x && other.y == y;

  @override
  int get hashCode => Object.hash(x, y);
}

class Rect4 {
  const Rect4(this.x, this.y, this.w, this.h);
  final double x, y, w, h;
  static Rect4 of(Object? v) {
    final l = (v as List).map((e) => (e as num).toDouble()).toList();
    return Rect4(l[0], l[1], l[2], l[3]);
  }

  Pt get centre => Pt(x + w / 2, y + h / 2);
  bool contains(Pt p) => p.x >= x && p.x <= x + w && p.y >= y && p.y <= y + h;
}

class Place {
  Place.fromJson(Map<String, dynamic> j)
      : id = j['id'] as String,
        floor = j['floor'] as String,
        kind = j['kind'] as String,
        number = j['number'] as String?,
        ar = j['ar'] as String,
        en = j['en'] as String,
        rect = Rect4.of(j['rect']),
        door = Pt.of(j['door']),
        outline = (j['outline'] as List?)?.map(Pt.of).toList();
  final String id, floor, kind, ar, en;
  final String? number;
  final Rect4 rect;
  final Pt door;
  final List<Pt>? outline;

  String name(bool en_) => en_ ? en : ar;

  bool contains(Pt p) {
    final o = outline;
    if (o == null) return rect.contains(p);
    var inside = false;
    for (var i = 0, j = o.length - 1; i < o.length; j = i++) {
      if ((o[i].y > p.y) != (o[j].y > p.y) && p.x < (o[j].x - o[i].x) * (p.y - o[i].y) / (o[j].y - o[i].y) + o[i].x) inside = !inside;
    }
    return inside;
  }
}

class Connector {
  Connector.fromJson(Map<String, dynamic> j)
      : id = j['id'] as String,
        kind = j['kind'] as String,
        ar = j['ar'] as String,
        en = j['en'] as String,
        rect = (j['rect'] as Map).map((k, v) => MapEntry(k as String, Rect4.of(v))),
        at = (j['at'] as Map).map((k, v) => MapEntry(k as String, Pt.of(v)));
  final String id, kind, ar, en;
  final Map<String, Rect4> rect;
  final Map<String, Pt> at;
}

class Entrance {
  Entrance.fromJson(Map<String, dynamic> j)
      : id = j['id'] as String,
        floor = j['floor'] as String,
        ar = j['ar'] as String? ?? '',
        en = (j['en'] as String?)?.isNotEmpty == true ? j['en'] as String : (j['ar'] as String? ?? ''),
        at = Pt.of(j['at']);
  final String id, floor, ar, en;
  final Pt at;
}

class Shape {
  Shape.fromJson(Map<String, dynamic> j)
      : floor = j['floor'] as String,
        kind = j['kind'] as String,
        rect = j['rect'] == null ? null : Rect4.of(j['rect']),
        points = (j['points'] as List?)?.map(Pt.of).toList(),
        chamfer = (j['chamfer'] as num?)?.toDouble() ?? 0;
  final String floor, kind;
  final Rect4? rect;
  final List<Pt>? points;
  final double chamfer;
}

class FloorInfo {
  FloorInfo.fromJson(Map<String, dynamic> j)
      : id = j['id'] as String,
        level = (j['level'] as num).toInt(),
        ar = j['ar'] as String,
        en = j['en'] as String,
        grid = j['grid'] as String,
        outline = j['outline'] as String? ?? '';
  final String id, ar, en, grid, outline;
  final int level;
  String name(bool en_) => en_ ? en : ar;
}

class CentreMap {
  CentreMap.fromJson(Map<String, dynamic> j)
      : width = ((j['size'] as Map)['w'] as num).toDouble(),
        height = ((j['size'] as Map)['h'] as num).toDouble(),
        cell = (j['cell'] as num).toDouble(),
        cols = (j['cols'] as num).toInt(),
        rows = (j['rows'] as num).toInt(),
        pxPerM = (j['px_per_m'] as num).toDouble(),
        floors = (j['floors'] as List).map((e) => FloorInfo.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        places = (j['places'] as List).map((e) => Place.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        connectors = (j['connectors'] as List).map((e) => Connector.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        entrances = (j['entrances'] as List? ?? const []).map((e) => Entrance.fromJson(Map<String, dynamic>.from(e as Map))).toList(),
        shapes = (j['shapes'] as List? ?? const []).map((e) => Shape.fromJson(Map<String, dynamic>.from(e as Map))).toList();
  final double width, height, cell, pxPerM;
  final int cols, rows;
  final List<FloorInfo> floors;
  final List<Place> places;
  final List<Connector> connectors;
  final List<Entrance> entrances;
  final List<Shape> shapes;

  FloorInfo floor(String id) => floors.firstWhere((f) => f.id == id, orElse: () => floors.first);
}

class Leg {
  const Leg(this.floor, this.points);
  final String floor;
  final List<Pt> points;
}

/// start · straight · slight-left · slight-right · left · right · u-turn · stairs · lift · arrive
class WayStep {
  WayStep(this.kind, this.floor, this.at, {this.near, this.to, this.up, this.connector, this.side});
  final String kind;
  final String floor;
  final Pt at;
  double metres = 0;
  final String? near;
  final String? to;
  final bool? up;
  final String? connector;
  final String? side;
}

class WayRoute {
  const WayRoute(this.legs, this.steps, this.metres, this.seconds, this.connector);
  final List<Leg> legs;
  final List<WayStep> steps;
  final double metres;
  final int seconds;
  final Connector? connector;
}

/// A destination: its door, and optionally its outline (for "on your left") and id (never named as a landmark on the way).
class Target {
  const Target(this.floor, this.at, {this.rect, this.id});
  final String floor;
  final Pt at;
  final Rect4? rect;
  final String? id;
}

const _centrePull = 0.9;
const _floorCost = {'stairs': 140.0, 'lift': 260.0};
const _neighbours = [
  [1, 0, 1.0], [-1, 0, 1.0], [0, 1, 1.0], [0, -1, 1.0],
  [1, 1, math.sqrt2], [1, -1, math.sqrt2], [-1, 1, math.sqrt2], [-1, -1, math.sqrt2],
];

Uint8List decodeGrid(String b64, int cols, int rows) {
  final bytes = base64.decode(b64);
  final out = Uint8List(cols * rows);
  for (var i = 0; i < out.length; i++) {
    out[i] = (bytes[i >> 3] >> (7 - (i & 7))) & 1;
  }
  return out;
}

class _Grid {
  _Grid(this.walk, this.cost);
  final Uint8List walk;
  final Float64List cost;
}

class _Search {
  _Search(this.dist, this.prev);
  final Float64List dist;
  final Int32List prev;
}

class WayEngine {
  WayEngine(this.map) {
    for (final f in map.floors) {
      final walk = decodeGrid(f.grid, map.cols, map.rows);
      _grids[f.id] = _Grid(walk, _costField(walk));
    }
  }

  final CentreMap map;
  final _grids = <String, _Grid>{};

  int get _cols => map.cols;
  int get _rows => map.rows;
  int _level(String floor) => map.floor(floor).level;
  int _toCell(Pt p) => (p.y / map.cell).floor().clamp(0, _rows - 1) * _cols + (p.x / map.cell).floor().clamp(0, _cols - 1);
  Pt _toPt(int i) {
    final c = i % _cols;
    return Pt(c * map.cell + map.cell / 2, ((i - c) ~/ _cols) * map.cell + map.cell / 2);
  }

  Float64List _costField(Uint8List walk) {
    final dist = Int32List(walk.length)..fillRange(0, walk.length, -1);
    final queue = Int32List(walk.length);
    var head = 0, tail = 0;
    for (var i = 0; i < walk.length; i++) {
      if (walk[i] == 0) {
        dist[i] = 0;
        queue[tail++] = i;
      }
    }
    void edge(int i) {
      if (dist[i] < 0) {
        dist[i] = 1;
        queue[tail++] = i;
      }
    }

    for (var c = 0; c < _cols; c++) {
      edge(c);
      edge((_rows - 1) * _cols + c);
    }
    for (var r = 0; r < _rows; r++) {
      edge(r * _cols);
      edge(r * _cols + _cols - 1);
    }
    while (head < tail) {
      final i = queue[head++];
      final c = i % _cols, r = i ~/ _cols;
      for (final d in const [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
        final nc = c + d[0], nr = r + d[1];
        if (nc < 0 || nr < 0 || nc >= _cols || nr >= _rows) continue;
        final j = nr * _cols + nc;
        if (dist[j] < 0) {
          dist[j] = dist[i] + 1;
          queue[tail++] = j;
        }
      }
    }
    final cost = Float64List(walk.length);
    for (var i = 0; i < walk.length; i++) {
      cost[i] = walk[i] == 1 ? 1 + _centrePull / math.max(1, dist[i]) : double.infinity;
    }
    return cost;
  }

  /// The walkable cell nearest a point (a tap on a room, a sign slightly off the corridor).
  int _snapCell(String floor, Pt at) {
    final g = _grids[floor]!;
    final start = _toCell(at);
    if (g.walk[start] == 1) return start;
    final sc = start % _cols, sr = start ~/ _cols;
    for (var radius = 1; radius < math.max(_cols, _rows); radius++) {
      var best = -1;
      var bestD = 1 << 30;
      for (var dr = -radius; dr <= radius; dr++) {
        for (var dc = -radius; dc <= radius; dc++) {
          if (math.max(dr.abs(), dc.abs()) != radius) continue;
          final c = sc + dc, r = sr + dr;
          if (c < 0 || r < 0 || c >= _cols || r >= _rows || g.walk[r * _cols + c] == 0) continue;
          final d = dc * dc + dr * dr;
          if (d < bestD) {
            bestD = d;
            best = r * _cols + c;
          }
        }
      }
      if (best >= 0) return best;
    }
    return start;
  }

  Pt snap(String floor, Pt at) => _toPt(_snapCell(floor, at));

  _Search _search(String floor, int from, [int target = -1]) {
    final g = _grids[floor]!;
    final dist = Float64List(g.walk.length)..fillRange(0, g.walk.length, double.infinity);
    final prev = Int32List(g.walk.length)..fillRange(0, g.walk.length, -1);
    final heap = _Heap();
    dist[from] = 0;
    heap.push(0, from);
    while (heap.isNotEmpty) {
      final top = heap.pop();
      final d = top.$1, i = top.$2;
      if (d > dist[i]) continue;
      if (i == target) break;
      final c = i % _cols, r = i ~/ _cols;
      for (final n in _neighbours) {
        final dc = n[0] as int, dr = n[1] as int, len = n[2] as double;
        final nc = c + dc, nr = r + dr;
        if (nc < 0 || nr < 0 || nc >= _cols || nr >= _rows) continue;
        final j = nr * _cols + nc;
        if (g.walk[j] == 0) continue;
        if (dc != 0 && dr != 0 && (g.walk[r * _cols + nc] == 0 || g.walk[nr * _cols + c] == 0)) continue; // never cut a wall's corner
        final nd = d + len * map.cell * (g.cost[i] + g.cost[j]) / 2;
        if (nd < dist[j]) {
          dist[j] = nd;
          prev[j] = i;
          heap.push(nd, j);
        }
      }
    }
    return _Search(dist, prev);
  }

  List<Pt> _trace(Int32List prev, int from, int to, bool reverse) {
    final cells = <int>[];
    for (var i = to; i >= 0; i = prev[i]) {
      cells.add(i);
      if (i == from) break;
    }
    final ordered = reverse ? cells : cells.reversed.toList();
    return smooth(ordered.map(_toPt).toList());
  }

  /// The walk from one point to another; null when no walk exists (never for the generated map, which is checked when built).
  WayRoute? route(String fromFloor, Pt from, Target to, {bool avoidStairs = false}) {
    final a = _snapCell(fromFloor, from);
    final b = _snapCell(to.floor, to.at);
    List<Leg> legs;
    Connector? connector;
    if (fromFloor == to.floor) {
      final s = _search(fromFloor, a, b);
      if (!s.dist[b].isFinite) return null;
      legs = [Leg(fromFloor, _trace(s.prev, a, b, false))];
    } else {
      final sa = _search(fromFloor, a);
      final sb = _search(to.floor, b);
      var best = double.infinity;
      for (final c in map.connectors) {
        if (avoidStairs && c.kind == 'stairs') continue;
        final ca = _snapCell(fromFloor, c.at[fromFloor]!), cb = _snapCell(to.floor, c.at[to.floor]!);
        final total = sa.dist[ca] + sb.dist[cb] + _floorCost[c.kind]! * (_level(to.floor) - _level(fromFloor)).abs();
        if (total < best) {
          best = total;
          connector = c;
        }
      }
      if (connector == null || !best.isFinite) return null;
      final ca = _snapCell(fromFloor, connector.at[fromFloor]!), cb = _snapCell(to.floor, connector.at[to.floor]!);
      legs = [Leg(fromFloor, _trace(sa.prev, a, ca, false)), Leg(to.floor, _trace(sb.prev, b, cb, true))];
    }
    final steps = _describe(legs, connector, to);
    final metres = steps.fold<double>(0, (s, x) => s + x.metres);
    final wait = connector == null ? 0 : (connector.kind == 'lift' ? 45 : 20);
    return WayRoute(legs, steps, metres, (metres / 1.2).round() + wait, connector);
  }

  String? _nearby(String floor, Pt at, String? skip, [double within = 48]) {
    String? best;
    var bestD = within * within;
    for (final p in map.places) {
      if (p.floor != floor || p.id == skip || p.kind == 'wc') continue;
      final d = (p.door.x - at.x) * (p.door.x - at.x) + (p.door.y - at.y) * (p.door.y - at.y);
      if (d < bestD) {
        bestD = d;
        best = p.id;
      }
    }
    return best;
  }

  List<WayStep> _describe(List<Leg> legs, Connector? connector, Target to) {
    final steps = <WayStep>[];
    double m(double px) => px / map.pxPerM;
    Rect4? rect = to.rect;
    if (rect == null && to.id != null) {
      for (final p in map.places) {
        if (p.id == to.id) rect = p.rect;
      }
    }
    for (var li = 0; li < legs.length; li++) {
      final leg = legs[li];
      final pts = leg.points;
      if (li == 0) {
        steps.add(WayStep('start', leg.floor, pts.first));
      } else {
        final before = legs[li - 1];
        steps.add(WayStep(connector!.kind, before.floor, before.points.last, to: leg.floor, up: _level(leg.floor) > _level(before.floor), connector: connector.id));
      }
      double seg(int i, int j) => pts[i].distanceTo(pts[j]);
      // Bends a few metres apart are one turn (a corner walked as two diagonals): their angles are added up.
      var k = 1;
      while (k < pts.length) {
        steps.last.metres += m(seg(k - 1, k));
        if (k == pts.length - 1) break;
        var j = k;
        var total = angle(pts[k - 1], pts[k], pts[k + 1]);
        while (j + 1 < pts.length - 1 && seg(j, j + 1) < 40) {
          total += angle(pts[j], pts[j + 1], pts[j + 2]);
          j++;
        }
        final abs = total.abs();
        // A gentle bend is just the corridor curving: "keep going" covers it.
        if (abs >= 40) {
          final at = pts[k + (j - k) ~/ 2];
          final kind = abs > 150 ? 'u-turn' : abs < 62 ? (total > 0 ? 'slight-right' : 'slight-left') : (total > 0 ? 'right' : 'left');
          steps.add(WayStep(kind, leg.floor, at, near: _nearby(leg.floor, at, to.id)));
        }
        for (var q = k + 1; q <= j; q++) {
          steps.last.metres += m(seg(q - 1, q));
        }
        k = j + 1;
      }
    }
    final last = legs.last.points;
    var side = 'ahead';
    if (rect != null && last.length > 1) {
      final t = angle(last[last.length - 2], last.last, rect.centre);
      side = t.abs() < 35 ? 'ahead' : t > 0 ? 'right' : 'left';
    }
    steps.add(WayStep('arrive', to.floor, last.last, side: side));
    // A bend followed by only a step or two is folded into the step before it.
    final out = <WayStep>[];
    for (final s in steps) {
      if (out.isNotEmpty && (s.kind == 'slight-left' || s.kind == 'slight-right') && s.metres < 2.5) {
        out.last.metres += s.metres;
        continue;
      }
      out.add(s);
    }
    return out;
  }
}

/// Signed turn in degrees at b, walking a → b → c (positive is a right turn on the plan, whose y axis points down).
double angle(Pt a, Pt b, Pt c) {
  final h1 = math.atan2(b.y - a.y, b.x - a.x);
  final h2 = math.atan2(c.y - b.y, c.x - b.x);
  var d = (h2 - h1) * 180 / math.pi;
  while (d > 180) {
    d -= 360;
  }
  while (d < -180) {
    d += 360;
  }
  return d;
}

/// Simplifies a cell-by-cell walk into a few straight stretches (Ramer–Douglas–Peucker).
List<Pt> smooth(List<Pt> points, [double epsilon = 5]) {
  if (points.length < 3) return points;
  final keep = List<bool>.filled(points.length, false);
  keep[0] = keep[points.length - 1] = true;
  final stack = <List<int>>[[0, points.length - 1]];
  while (stack.isNotEmpty) {
    final se = stack.removeLast();
    final s = se[0], e = se[1];
    final a = points[s], b = points[e];
    final len = math.max(1e-9, a.distanceTo(b));
    var far = -1;
    var farD = epsilon;
    for (var i = s + 1; i < e; i++) {
      final d = ((b.x - a.x) * (a.y - points[i].y) - (a.x - points[i].x) * (b.y - a.y)).abs() / len;
      if (d > farD) {
        farD = d;
        far = i;
      }
    }
    if (far > 0) {
      keep[far] = true;
      stack
        ..add([s, far])
        ..add([far, e]);
    }
  }
  return [for (var i = 0; i < points.length; i++) if (keep[i]) points[i]];
}

class _Heap {
  final _p = <double>[];
  final _v = <int>[];
  bool get isNotEmpty => _v.isNotEmpty;

  void push(double priority, int value) {
    var i = _v.length;
    _p.add(priority);
    _v.add(value);
    while (i > 0) {
      final parent = (i - 1) >> 1;
      if (_p[parent] <= priority) break;
      _p[i] = _p[parent];
      _v[i] = _v[parent];
      i = parent;
    }
    _p[i] = priority;
    _v[i] = value;
  }

  (double, int) pop() {
    final top = (_p[0], _v[0]);
    final lp = _p.removeLast(), lv = _v.removeLast();
    if (_v.isNotEmpty) {
      var i = 0;
      final n = _v.length;
      while (true) {
        final l = 2 * i + 1, r = l + 1;
        var m = i;
        var mp = lp;
        if (l < n && _p[l] < mp) {
          m = l;
          mp = _p[l];
        }
        if (r < n && _p[r] < mp) {
          m = r;
          mp = _p[r];
        }
        if (m == i) break;
        _p[i] = _p[m];
        _v[i] = _v[m];
        i = m;
      }
      _p[i] = lp;
      _v[i] = lv;
    }
    return top;
  }
}
