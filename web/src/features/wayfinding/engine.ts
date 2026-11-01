/**
 * Indoor wayfinding (docs/wayfinding.md): the shortest walk between two points of the centre, on one floor or across floors by the
 * stairs or a lift, kept to the middle of the corridors, and the spoken-style steps that go with it. Pure code over the generated map
 * (centre-map.json), so it runs instantly on the visitor's phone, works with a weak signal inside the building, and is tested with
 * `npm test`. The mobile app carries the same algorithm (mobile/lib/features/wayfinding/engine.dart).
 */
export type FloorId = 'ground' | 'first'
export type Pt = [number, number]
export type PlaceKind = 'train' | 'lab' | 'meet' | 'public' | 'office' | 'pray' | 'wc'
export type Place = { id: string; floor: FloorId; kind: PlaceKind; number: string | null; ar: string; en: string; rect: [number, number, number, number]; door: Pt; outline?: Pt[] }
export type Shape = { floor: FloorId; kind: 'core' | 'void'; rect?: [number, number, number, number]; points?: Pt[]; chamfer?: number }
export type Connector = { id: string; kind: 'stairs' | 'lift'; ar: string; en: string; rect: Record<FloorId, [number, number, number, number]>; at: Record<FloorId, Pt> }
export type Entrance = { id: string; floor: FloorId; ar: string; en: string; at: Pt }
export type CentreMap = {
  version: number; size: { w: number; h: number }; cell: number; cols: number; rows: number; px_per_m: number
  floors: { id: FloorId; level: number; ar: string; en: string; image: string; grid: string; outline?: string }[]
  places: Place[]; connectors: Connector[]; entrances: Entrance[]; shapes: Shape[]
}
export type Spot = { floor: FloorId; at: Pt }
/** A destination: where its door is, and optionally its outline (for "on your left") and id (not named as a landmark on the way). */
export type Target = Spot & { rect?: [number, number, number, number]; id?: string }
export type Leg = { floor: FloorId; points: Pt[] }
export type StepKind = 'start' | 'straight' | 'slight-left' | 'slight-right' | 'left' | 'right' | 'u-turn' | 'stairs' | 'lift' | 'arrive'
export type Step = {
  kind: StepKind
  floor: FloorId
  /** where the step begins (the turn, the stairs, the door) */
  at: Pt
  /** metres walked in this step */
  metres: number
  /** a place next to the turn, to recognise it */
  near?: string
  /** for stairs / lift: the floor reached and whether it is up */
  to?: FloorId
  up?: boolean
  connector?: string
  /** for arrive: which hand the door is on */
  side?: 'left' | 'right' | 'ahead'
}
export type Route = { legs: Leg[]; steps: Step[]; metres: number; seconds: number; connector: Connector | null }

const SQRT2 = Math.SQRT2
/** How strongly the walk keeps away from walls (so it follows the middle of a corridor rather than hugging a corner). */
const CENTRE_PULL = 0.9
/** Extra cost of changing floor, in pixels of walking: a flight of stairs, and a lift (the wait). */
const FLOOR_COST = { stairs: 140, lift: 260 }

type FloorGrid = { walk: Uint8Array; cost: Float32Array }

export function decodeGrid(b64: string, cols: number, rows: number): Uint8Array {
  const bin = typeof atob === 'function' ? atob(b64) : Buffer.from(b64, 'base64').toString('binary')
  const out = new Uint8Array(cols * rows)
  for (let i = 0; i < out.length; i++) out[i] = (bin.charCodeAt(i >> 3) >> (7 - (i & 7))) & 1
  return out
}

/** Distance (in cells) from each walkable cell to the nearest wall, turned into a per-cell cost that is lowest mid-corridor. */
function costField(walk: Uint8Array, cols: number, rows: number): Float32Array {
  const dist = new Int32Array(walk.length).fill(-1)
  const queue = new Int32Array(walk.length)
  let head = 0, tail = 0
  for (let i = 0; i < walk.length; i++) if (!walk[i]) { dist[i] = 0; queue[tail++] = i }
  // The map's edge counts as a wall too.
  for (let c = 0; c < cols; c++) for (const r of [0, rows - 1]) { const i = r * cols + c; if (dist[i] < 0) { dist[i] = 1; queue[tail++] = i } }
  for (let r = 0; r < rows; r++) for (const c of [0, cols - 1]) { const i = r * cols + c; if (dist[i] < 0) { dist[i] = 1; queue[tail++] = i } }
  while (head < tail) {
    const i = queue[head++]
    const c = i % cols, r = (i - c) / cols
    for (const [dc, dr] of [[1, 0], [-1, 0], [0, 1], [0, -1]]) {
      const nc = c + dc, nr = r + dr
      if (nc < 0 || nr < 0 || nc >= cols || nr >= rows) continue
      const j = nr * cols + nc
      if (dist[j] < 0) { dist[j] = dist[i] + 1; queue[tail++] = j }
    }
  }
  const cost = new Float32Array(walk.length)
  for (let i = 0; i < walk.length; i++) cost[i] = walk[i] ? 1 + CENTRE_PULL / Math.max(1, dist[i]) : Infinity
  return cost
}

/** A small binary heap of [priority, cell]. */
class Heap {
  private p: number[] = []
  private v: number[] = []
  get size() { return this.v.length }
  push(priority: number, value: number) {
    const p = this.p, v = this.v
    let i = v.length
    p.push(priority); v.push(value)
    while (i > 0) {
      const parent = (i - 1) >> 1
      if (p[parent] <= priority) break
      p[i] = p[parent]; v[i] = v[parent]; i = parent
    }
    p[i] = priority; v[i] = value
  }
  pop(): [number, number] {
    const p = this.p, v = this.v
    const top: [number, number] = [p[0], v[0]]
    const lp = p.pop()!, lv = v.pop()!
    if (v.length) {
      let i = 0
      const n = v.length
      for (;;) {
        const l = 2 * i + 1, r = l + 1
        let m = i, mp = lp
        if (l < n && p[l] < mp) { m = l; mp = p[l] }
        if (r < n && p[r] < mp) { m = r; mp = p[r] }
        if (m === i) break
        p[i] = p[m]; v[i] = v[m]; i = m
      }
      p[i] = lp; v[i] = lv
    }
    return top
  }
}

const NEIGHBOURS: [number, number, number][] = [[1, 0, 1], [-1, 0, 1], [0, 1, 1], [0, -1, 1], [1, 1, SQRT2], [1, -1, SQRT2], [-1, 1, SQRT2], [-1, -1, SQRT2]]

export function createEngine(map: CentreMap) {
  const { cols, rows, cell } = map
  const grids = new Map<FloorId, FloorGrid>()
  for (const f of map.floors) {
    const walk = decodeGrid(f.grid, cols, rows)
    grids.set(f.id, { walk, cost: costField(walk, cols, rows) })
  }
  const level = (f: FloorId) => map.floors.find((x) => x.id === f)?.level ?? 0
  const toCell = ([x, y]: Pt) => Math.min(rows - 1, Math.max(0, Math.floor(y / cell))) * cols + Math.min(cols - 1, Math.max(0, Math.floor(x / cell)))
  const toPt = (i: number): Pt => { const c = i % cols; return [c * cell + cell / 2, ((i - c) / cols) * cell + cell / 2] }

  /** The walkable cell nearest a point (a tap on a room, a sign slightly off the corridor). */
  function snapCell(floor: FloorId, at: Pt): number {
    const g = grids.get(floor)!
    const start = toCell(at)
    if (g.walk[start]) return start
    const sc = start % cols, sr = (start - sc) / cols
    for (let radius = 1; radius < Math.max(cols, rows); radius++) {
      let best = -1, bestD = Infinity
      for (let dr = -radius; dr <= radius; dr++) for (let dc = -radius; dc <= radius; dc++) {
        if (Math.max(Math.abs(dr), Math.abs(dc)) !== radius) continue
        const c = sc + dc, r = sr + dr
        if (c < 0 || r < 0 || c >= cols || r >= rows || !g.walk[r * cols + c]) continue
        const d = dc * dc + dr * dr
        if (d < bestD) { bestD = d; best = r * cols + c }
      }
      if (best >= 0) return best
    }
    return start
  }

  /** Dijkstra from one cell; stops early when `target` is settled. Distances are in pixels. */
  function search(floor: FloorId, from: number, target = -1) {
    const g = grids.get(floor)!
    const dist = new Float64Array(g.walk.length).fill(Infinity)
    const prev = new Int32Array(g.walk.length).fill(-1)
    const heap = new Heap()
    dist[from] = 0
    heap.push(0, from)
    while (heap.size) {
      const [d, i] = heap.pop()
      if (d > dist[i]) continue
      if (i === target) break
      const c = i % cols, r = (i - c) / cols
      for (const [dc, dr, len] of NEIGHBOURS) {
        const nc = c + dc, nr = r + dr
        if (nc < 0 || nr < 0 || nc >= cols || nr >= rows) continue
        const j = nr * cols + nc
        if (!g.walk[j]) continue
        if (dc && dr && (!g.walk[r * cols + nc] || !g.walk[nr * cols + c])) continue   // never cut a wall's corner
        const nd = d + len * cell * (g.cost[i] + g.cost[j]) / 2
        if (nd < dist[j]) { dist[j] = nd; prev[j] = i; heap.push(nd, j) }
      }
    }
    return { dist, prev }
  }

  function trace(prev: Int32Array, from: number, to: number, reverse: boolean): Pt[] {
    const cells: number[] = []
    for (let i = to; i >= 0; i = prev[i]) { cells.push(i); if (i === from) break }
    if (!reverse) cells.reverse()
    return smooth(cells.map(toPt))
  }

  /** The walk from one point to another. Null when no walk exists (never for the generated map, which is checked when built). */
  function route(from: Spot, to: Target, options: { avoidStairs?: boolean } = {}): Route | null {
    const a = snapCell(from.floor, from.at)
    const b = snapCell(to.floor, to.at)
    let legs: Leg[]
    let connector: Connector | null = null
    if (from.floor === to.floor) {
      const s = search(from.floor, a, b)
      if (!Number.isFinite(s.dist[b])) return null
      legs = [{ floor: from.floor, points: withEnds(trace(s.prev, a, b, false), from.at, to.at) }]
    } else {
      const sa = search(from.floor, a)
      const sb = search(to.floor, b)
      let best = Infinity
      for (const c of map.connectors) {
        if (options.avoidStairs && c.kind === 'stairs') continue
        const ca = snapCell(from.floor, c.at[from.floor]), cb = snapCell(to.floor, c.at[to.floor])
        const total = sa.dist[ca] + sb.dist[cb] + FLOOR_COST[c.kind] * Math.abs(level(to.floor) - level(from.floor))
        if (total < best) { best = total; connector = c }
      }
      if (!connector || !Number.isFinite(best)) return null
      const ca = snapCell(from.floor, connector.at[from.floor]), cb = snapCell(to.floor, connector.at[to.floor])
      legs = [
        { floor: from.floor, points: withEnds(trace(sa.prev, a, ca, false), from.at, null) },
        { floor: to.floor, points: withEnds(trace(sb.prev, b, cb, true), null, to.at) },
      ]
    }
    const steps = describe(legs, connector, to)
    const metres = steps.reduce((s, x) => s + x.metres, 0)
    return { legs, steps, metres, seconds: Math.round(metres / 1.2 + (connector ? (connector.kind === 'lift' ? 45 : 20) : 0)), connector }
  }

  /** Starts and ends the drawn line exactly at the given points when they sit on the floor (a tap on a corridor). */
  function withEnds(points: Pt[], start: Pt | null, end: Pt | null): Pt[] {
    return points.length ? points : [start ?? end ?? [0, 0]]
  }

  /** The places near a point, to name a turn ("at the library"). */
  function nearby(floor: FloorId, at: Pt, skip: string | undefined, within = 48): string | undefined {
    let best: string | undefined, bestD = within * within
    for (const p of map.places) {
      if (p.floor !== floor || p.id === skip || p.kind === 'wc') continue
      const d = (p.door[0] - at[0]) ** 2 + (p.door[1] - at[1]) ** 2
      if (d < bestD) { bestD = d; best = p.id }
    }
    return best
  }

  function describe(legs: Leg[], connector: Connector | null, to: Target): Step[] {
    const steps: Step[] = []
    const m = (px: number) => px / map.px_per_m
    const rect = to.rect ?? map.places.find((p) => p.id === to.id)?.rect
    legs.forEach((leg, li) => {
      const pts = leg.points
      if (li === 0) steps.push({ kind: 'start', floor: leg.floor, at: pts[0], metres: 0 })
      else steps.push({ kind: connector!.kind, floor: legs[li - 1].floor, at: legs[li - 1].points[legs[li - 1].points.length - 1], metres: 0, to: leg.floor, up: level(leg.floor) > level(legs[li - 1].floor), connector: connector!.id })
      const seg = (i: number, j: number) => Math.hypot(pts[j][0] - pts[i][0], pts[j][1] - pts[i][1])
      // Bends a few metres apart are one turn (a corner walked as two diagonals, a jog round a pillar): their angles are added up.
      let k = 1
      while (k < pts.length) {
        steps[steps.length - 1].metres += m(seg(k - 1, k))
        if (k === pts.length - 1) break
        let j = k
        let total = angle(pts[k - 1], pts[k], pts[k + 1])
        while (j + 1 < pts.length - 1 && seg(j, j + 1) < 40) { total += angle(pts[j], pts[j + 1], pts[j + 2]); j++ }
        const abs = Math.abs(total)
        // A gentle bend is just the corridor curving: "keep going" covers it, so it is not a step of its own.
        if (abs >= 40) {
          const at = pts[k + Math.floor((j - k) / 2)]
          const kind: StepKind = abs > 150 ? 'u-turn' : abs < 62 ? (total > 0 ? 'slight-right' : 'slight-left') : total > 0 ? 'right' : 'left'
          steps.push({ kind, floor: leg.floor, at, metres: 0, near: nearby(leg.floor, at, to.id) })
        }
        for (let q = k + 1; q <= j; q++) steps[steps.length - 1].metres += m(seg(q - 1, q))
        k = j + 1
      }
    })
    const last = legs[legs.length - 1].points
    let side: Step['side'] = 'ahead'
    if (rect && last.length > 1) {
      const p = last[last.length - 2], q = last[last.length - 1]
      const cx = rect[0] + rect[2] / 2, cy = rect[1] + rect[3] / 2
      const t = angle(p, q, [cx, cy])
      side = Math.abs(t) < 35 ? 'ahead' : t > 0 ? 'right' : 'left'
    }
    steps.push({ kind: 'arrive', floor: to.floor, at: last[last.length - 1], metres: 0, side })
    // A bend followed by only a step or two before the next instruction is folded into the step before it, so the visitor is never
    // asked to turn twice in a breath.
    const out: Step[] = []
    for (const s of steps) {
      const prev = out[out.length - 1]
      if (prev && (s.kind === 'slight-left' || s.kind === 'slight-right') && s.metres < 2.5) { prev.metres += s.metres; continue }
      out.push(s)
    }
    return out
  }

  return { map, route, snap: (floor: FloorId, at: Pt): Pt => toPt(snapCell(floor, at)), level }
}

/** Signed turn in degrees at b, walking a → b → c (positive is a right turn on the plan, whose y axis points down). */
export function angle(a: Pt, b: Pt, c: Pt): number {
  const h1 = Math.atan2(b[1] - a[1], b[0] - a[0])
  const h2 = Math.atan2(c[1] - b[1], c[0] - b[0])
  let d = ((h2 - h1) * 180) / Math.PI
  while (d > 180) d -= 360
  while (d < -180) d += 360
  return d
}

/** Simplifies a cell-by-cell walk into a few straight stretches (Ramer–Douglas–Peucker), keeping it on the corridor. */
export function smooth(points: Pt[], epsilon = 5): Pt[] {
  if (points.length < 3) return points
  const keep = new Uint8Array(points.length)
  keep[0] = keep[points.length - 1] = 1
  const stack: [number, number][] = [[0, points.length - 1]]
  while (stack.length) {
    const [s, e] = stack.pop()!
    const [ax, ay] = points[s], [bx, by] = points[e]
    const len = Math.hypot(bx - ax, by - ay) || 1
    let far = -1, farD = epsilon
    for (let i = s + 1; i < e; i++) {
      const d = Math.abs((bx - ax) * (ay - points[i][1]) - (ax - points[i][0]) * (by - ay)) / len
      if (d > farD) { farD = d; far = i }
    }
    if (far > 0) { keep[far] = 1; stack.push([s, far], [far, e]) }
  }
  return points.filter((_, i) => keep[i])
}

/** Every point a visitor can start from or go to by name: places, entrances, stairs and lifts. */
export function spotOf(map: CentreMap, id: string): (Target & { label: { ar: string; en: string } }) | null {
  const p = map.places.find((x) => x.id === id)
  if (p) return { floor: p.floor, at: p.door, rect: p.rect, id: p.id, label: { ar: p.ar, en: p.en } }
  const e = map.entrances.find((x) => x.id === id)
  if (e) return { floor: e.floor, at: e.at, label: { ar: e.ar, en: e.en } }
  const [cid, floor] = id.split('@')
  const c = map.connectors.find((x) => x.id === cid)
  if (c && (floor === 'ground' || floor === 'first')) return { floor, at: c.at[floor], label: { ar: c.ar, en: c.en } }
  return null
}

/** Length of a polyline in pixels. */
export function length(points: Pt[]): number {
  let l = 0
  for (let i = 1; i < points.length; i++) l += Math.hypot(points[i][0] - points[i - 1][0], points[i][1] - points[i - 1][1])
  return l
}

/** The walkable floor of a level as one path: runs of floor cells, merged down the rows into rectangles. */
export function corridorPath(map: CentreMap, floor: FloorId): string {
  const f = map.floors.find((x) => x.id === floor)!
  const grid = decodeGrid(f.grid, map.cols, map.rows)
  const { cols, rows, cell } = map
  const open = new Map<string, { y: number; c0: number; c1: number }>()
  const rects: [number, number, number, number][] = []
  for (let r = 0; r <= rows; r++) {
    const runs: [number, number][] = []
    if (r < rows) for (let c = 0; c < cols; c++) if (grid[r * cols + c]) { const s = c; while (c < cols && grid[r * cols + c]) c++; runs.push([s, c]) }
    const seen = new Set<string>()
    for (const [c0, c1] of runs) { const k = `${c0}:${c1}`; seen.add(k); if (!open.has(k)) open.set(k, { y: r, c0, c1 }) }
    for (const [k, o] of open) if (!seen.has(k)) { rects.push([o.c0 * cell, o.y * cell, (o.c1 - o.c0) * cell, (r - o.y) * cell]); open.delete(k) }
  }
  return rects.map(([x, y, rw, rh]) => `M${x} ${y}h${rw}v${rh}h${-rw}z`).join('')
}
