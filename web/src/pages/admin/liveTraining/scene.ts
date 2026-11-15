import {
  AdditiveBlending, Box3, BoxGeometry, BufferGeometry, CanvasTexture, CapsuleGeometry, CircleGeometry, Color, DirectionalLight, EdgesGeometry, Group, HemisphereLight,
  InstancedMesh, LineBasicMaterial, LineSegments, Material, Matrix4, Mesh, MeshBasicMaterial, MeshLambertMaterial, Object3D, PerspectiveCamera, Quaternion, Raycaster,
  RingGeometry, Scene, SphereGeometry, SRGBColorSpace, Vector2, Vector3, WebGLRenderer,
} from 'three'
import { OrbitControls } from 'three/addons/controls/OrbitControls.js'
import { CSS2DObject, CSS2DRenderer } from 'three/addons/renderers/CSS2DRenderer.js'
import { assignSeats, colourSlot, placeRooms, ROOM, seatGrid, shortName, type Placement, type Seat } from './layout'
import type { RoomState, SceneRoom, SceneText } from './types'

/**
 * The centre drawn in 3D: every classroom on its floor slab, lit when a session runs and dark when it holds no training,
 * with the trainer at the board, the supervisor by the door, and a seated figure for every trainee who checked in.
 * No React in here: the page hands it data with `setData` and listens to `onSelect` / `onHover`.
 */

/** How bright a room is drawn in each state: lights on, warming up, after hours, waiting, switched off. */
const LEVEL: Record<RoomState, number> = { live: 1, soon: 0.6, ended: 0.36, later: 0.3, idle: 0.07 }

const PALETTE = {
  slab: 0x2b1520, slabEdge: 0xa29475,
  floor: [0x251822, 0xf3ecdf], wall: [0x2f1f29, 0xfdf9f2], cap: [0x3a1522, 0x8a1538], desk: [0x33252c, 0xcdb88f], chair: [0x2b2028, 0x8f8573],
  board: [0x3b2c35, 0xffffff], lamp: [0x4d3d46, 0xffe2a0], door: [0x3a1522, 0x8a1538],
  trainer: 0x8a1538, supervisor: 0xa29475, head: 0xf1dfc4, gold: 0xe9d3a0,
  trainees: [0x9d3a58, 0x2f6f73, 0x4a5d7a, 0x6d3f6b, 0x7a7f4f, 0xb07a3a],
} as const

/** A stage narrower than this (width / height) stacks the floors one behind the other instead of side by side. */
const STACK_BELOW = 1.6

const mix = (pair: readonly [number, number] | readonly number[], t: number) => new Color(pair[0]).lerp(new Color(pair[1]), t)
const easeOutBack = (p: number) => 1 + 2.7 * Math.pow(p - 1, 3) + 1.7 * Math.pow(p - 1, 2)
const easeInOut = (p: number) => (p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2)

const STYLE = `
.lt-labels{position:absolute;inset:0;z-index:0;pointer-events:none;overflow:hidden;font-family:inherit;direction:ltr}
.lt-labels>div{left:0;top:0}
.lt-tag{padding:3px 9px 4px;border-radius:14px;background:rgba(255,255,255,.95);color:#3b0a18;box-shadow:0 8px 22px -12px rgba(0,0,0,.8);font-size:11.5px;line-height:1.45;text-align:center;white-space:nowrap;transition:opacity .25s}
.lt-tag .lt-head{display:flex;align-items:center;justify-content:center;gap:6px}
.lt-tag b{font-weight:700}
.lt-tag .lt-sub,.lt-tag .lt-status{display:none;max-width:190px;margin-inline:auto;overflow:hidden;text-overflow:ellipsis;font-size:11px}
.lt-tag .lt-sub{opacity:.8}
.lt-tag .lt-status{font-weight:600}
.lt-tag.lt-open{padding:6px 12px 7px;z-index:3}
.lt-tag.lt-open b{font-size:13px}
.lt-tag.lt-open .lt-sub,.lt-tag.lt-open .lt-status{display:block}
.lt-tag .lt-count{padding:0 7px;border-radius:999px;background:#8a1538;color:#fff;font-size:10.5px;font-weight:700;font-variant-numeric:tabular-nums}
.lt-tag .lt-dot{flex:none;width:7px;height:7px;border-radius:50%;background:#12a36a}
.lt-tag.lt-live .lt-dot{animation:lt-pulse 1.6s ease-in-out infinite}
.lt-tag.lt-soon .lt-dot{background:#e0a030}
.lt-tag.lt-later .lt-dot,.lt-tag.lt-ended .lt-dot{background:#9a8c93}
.lt-tag.lt-later,.lt-tag.lt-ended{background:rgba(238,230,232,.9)}
.lt-tag.lt-idle{background:rgba(38,24,32,.86);color:#b6a5ad;box-shadow:none;border:1px solid rgba(255,255,255,.09)}
.lt-tag.lt-idle b{font-weight:600}
.lt-tag.lt-dim{opacity:.38}
.lt-name{padding:0 6px 1px;border-radius:999px;background:rgba(24,10,17,.86);color:#fff;border:1px solid rgba(255,255,255,.16);font-size:10.5px;font-weight:600;line-height:1.5;white-space:nowrap}
.lt-name.lt-late{border-color:#f0b84a}
.lt-name.lt-new{background:#e9d3a0;color:#2a0d18;border-color:#fff;animation:lt-pop .45s cubic-bezier(.2,1.4,.4,1)}
.lt-role{padding:2px 9px 3px;border-radius:999px;font-size:11px;font-weight:700;line-height:1.5;white-space:nowrap;box-shadow:0 6px 16px -8px rgba(0,0,0,.7)}
.lt-role.lt-trainer{background:#8a1538;color:#fff}
.lt-role.lt-supervisor{background:#a29475;color:#21101a}
.lt-role.lt-away{opacity:.6}
.lt-floor{color:rgba(255,255,255,.5);font-size:13px;font-weight:700;white-space:nowrap}
@keyframes lt-pulse{0%,100%{box-shadow:0 0 0 0 rgba(18,163,106,.55)}50%{box-shadow:0 0 0 6px rgba(18,163,106,0)}}
@keyframes lt-pop{from{transform:scale(.4);opacity:0}to{transform:scale(1);opacity:1}}
@media (prefers-reduced-motion:reduce){.lt-tag.lt-live .lt-dot,.lt-name.lt-new{animation:none}}
`

export type SceneOptions = {
  text: SceneText
  mirror: boolean
  reducedMotion: boolean
  onSelect: (roomId: string) => void
  onHover: (roomId: string | null, clientX: number, clientY: number) => void
}

type Shared = {
  box: Map<string, BoxGeometry>
  capsuleSeated: CapsuleGeometry; capsuleStanding: CapsuleGeometry; head: SphereGeometry; lamp: SphereGeometry; pool: CircleGeometry; ring: RingGeometry
  glow: CanvasTexture
  headMat: MeshLambertMaterial; bodyMat: MeshLambertMaterial
}

const label = (className: string) => {
  const el = document.createElement('div')
  el.className = className
  el.dir = document.documentElement.dir || 'ltr'   // the label layer itself is laid out left to right so positions hold in Arabic pages
  return el
}

/** One classroom of the drawing. */
class RoomView {
  readonly group = new Group()
  readonly box = new Box3()
  data: SceneRoom
  private readonly shared: Shared
  private readonly text: SceneText
  private fixed = new Group()
  private fixedSig = ''
  private own: Material[] = []
  private seats: Seat[] = []
  private seatOf = new Map<string, number>()
  private sessionKey = ''
  private bodies: InstancedMesh | null = null
  private heads: InstancedMesh | null = null
  private readonly dropping = new Map<number, number>()
  private readonly rings: { mesh: Mesh; t0: number }[] = []
  private readonly tag: CSS2DObject
  private readonly tagEl = { head: label('lt-head'), name: document.createElement('b'), sub: label('lt-sub'), dot: label('lt-dot'), status: label('lt-status'), count: label('lt-count') }
  private readonly names = new Map<string, { obj: CSS2DObject; until: number }>()
  private roles: CSS2DObject[] = []
  private readonly frame: LineSegments
  private readonly trainerFigures: Group[] = []
  private pool: Mesh | null = null
  private focused = false
  private level = 0

  constructor(data: SceneRoom, shared: Shared, text: SceneText) {
    this.data = data
    this.shared = shared
    this.text = text
    this.group.add(this.fixed)

    const el = label('lt-tag')
    this.tagEl.head.append(this.tagEl.dot, this.tagEl.name, this.tagEl.count)
    el.append(this.tagEl.head, this.tagEl.sub, this.tagEl.status)
    this.tag = new CSS2DObject(el)
    this.tag.position.set(0, ROOM.wallHeight + 1.55, -ROOM.d / 2)
    this.tag.center.set(0.5, 1)
    this.group.add(this.tag)

    const outline = new EdgesGeometry(new BoxGeometry(ROOM.w + 0.7, 0.02, ROOM.d + 0.7))
    this.frame = new LineSegments(outline, new LineBasicMaterial({ color: PALETTE.gold, transparent: true, opacity: 0 }))
    this.frame.position.y = 0.02
    this.group.add(this.frame)
  }

  place(x: number, z: number) {
    this.group.position.set(x, 0, z)
    this.box.set(new Vector3(x - ROOM.w / 2, 0, z - ROOM.d / 2), new Vector3(x + ROOM.w / 2, ROOM.wallHeight + 0.8, z + ROOM.d / 2))
  }

  /** Redraws what changed. `now` is the scene clock in ms; newcomers of a session already on show drop into their seats. */
  update(d: SceneRoom, now: number, animate: boolean) {
    this.data = d
    const sameSession = d.sessionKey === this.sessionKey
    this.sessionKey = d.sessionKey
    const seatCount = d.seating ? 0 : d.state === 'idle' ? Math.min(Math.max(d.capacity, 6), 20) : Math.max(d.expected, d.present.length, 4)
    const sig = JSON.stringify([d.state, d.seating, seatCount, d.trainers.map((t) => [t.id, t.name, t.here]), d.supervisor])
    const rebuilt = sig !== this.fixedSig
    if (rebuilt) {
      this.fixedSig = sig
      this.seats = seatGrid(seatCount, d.seating)
      this.rebuild(d)
    }
    if (!sameSession) this.dropping.clear()

    // The tag above the room.
    const t = this.tagEl
    this.tag.element.className = `lt-tag lt-${d.state}${this.dimmed ? ' lt-dim' : ''}${this.open ? ' lt-open' : ''}`
    t.name.textContent = d.name
    t.sub.textContent = d.title ?? ''
    t.status.textContent = d.status
    t.count.textContent = `${d.presentCount} / ${d.expected}`
    t.count.style.display = d.state === 'idle' ? 'none' : ''
    t.dot.style.display = d.state === 'idle' ? 'none' : ''

    // Who sits where.
    const before = sameSession ? this.seatOf : new Map<string, number>()
    const next = assignSeats(before, d.present, this.seats)
    const arrived: string[] = []
    for (const p of d.present) {
      const seat = next.get(p.id)
      if (seat === undefined) continue
      if (!before.has(p.id)) {
        if (sameSession && animate && !rebuilt) { this.dropping.set(seat, now); this.ring(seat, now); arrived.push(p.id) }
      }
      this.sit(seat, p.id, 1, 0)
    }
    const taken = new Set(next.values())
    for (const seat of before.values()) if (!taken.has(seat)) this.clearSeat(seat)
    this.seatOf = next
    this.flush()

    // Name labels: one per seated trainee, shown when the room is in focus or for a moment after arriving.
    const present = new Map(d.present.map((p) => [p.id, p]))
    for (const [id, n] of this.names) if (!next.has(id)) { this.group.remove(n.obj); n.obj.element.remove(); this.names.delete(id) }
    for (const [id, seat] of next) {
      const p = present.get(id)!
      let n = this.names.get(id)
      if (!n) {
        n = { obj: new CSS2DObject(label('lt-name')), until: 0 }
        n.obj.center.set(0.5, 1)
        this.names.set(id, n)
        this.group.add(n.obj)
      }
      const s = this.seats[seat]
      n.obj.position.set(s.x, 1.52, s.z)
      n.obj.element.textContent = shortName(p.name)
      if (arrived.includes(id)) n.until = now + 7000
      n.obj.element.className = `lt-name${p.late ? ' lt-late' : ''}${n.until > now ? ' lt-new' : ''}`
    }
    this.showNames(now)
  }

  private open = false
  /** The tag shows the programme and the state while the room is under the pointer or in focus; otherwise only its name and count. */
  setOpen(on: boolean) {
    this.open = on
    this.tag.element.classList.toggle('lt-open', on)
  }

  private dimmed = false
  /** Another room is in focus: this one's tag steps back. */
  setDimmed(on: boolean) {
    this.dimmed = on
    this.tag.element.classList.toggle('lt-dim', on)
  }

  setFocused(on: boolean, now: number) {
    this.focused = on
    this.roles.forEach((r) => { r.visible = on })
    this.showNames(now)
  }

  setHighlight(strength: number) {
    (this.frame.material as LineBasicMaterial).opacity = strength
  }

  private showNames(now: number) {
    for (const n of this.names.values()) {
      const fresh = n.until > now
      n.obj.visible = this.focused || fresh
      if (!fresh) n.obj.element.classList.remove('lt-new')
    }
  }

  /** Animations of this room; returns true while something is still moving. */
  tick(now: number, reduced: boolean): boolean {
    let moving = false
    if (this.dropping.size) {
      for (const [seat, t0] of this.dropping) {
        const p = reduced ? 1 : Math.min(1, (now - t0) / 720)
        const id = [...this.seatOf].find(([, s]) => s === seat)?.[0]
        if (id) this.sit(seat, id, Math.max(0.01, easeOutBack(p)), (1 - p) * (1 - p) * 1.7)
        if (p >= 1) this.dropping.delete(seat)
      }
      this.flush()
      moving = true
    }
    for (let i = this.rings.length - 1; i >= 0; i--) {
      const r = this.rings[i]
      const p = (now - r.t0) / 1200
      if (p >= 1 || reduced) { this.group.remove(r.mesh); (r.mesh.material as Material).dispose(); this.rings.splice(i, 1); continue }
      r.mesh.scale.setScalar(1 + p * 3.4);
      (r.mesh.material as MeshBasicMaterial).opacity = 0.9 * (1 - p)
      moving = true
    }
    if (this.names.size) {
      let expired = false
      for (const n of this.names.values()) if (n.until && n.until <= now) { n.until = 0; expired = true }
      if (expired) this.showNames(now)
    }
    if (this.data.state === 'live' && !reduced) {
      const wave = Math.sin(now / 900 + this.group.position.x)
      if (this.pool) (this.pool.material as MeshBasicMaterial).opacity = 0.5 + wave * 0.06
      this.trainerFigures.forEach((f, i) => { f.rotation.y = Math.sin(now / 1400 + i * 2) * 0.22 })
      moving = true
    }
    return moving
  }

  private readonly m4 = new Matrix4()
  private readonly q = new Quaternion()
  private readonly v = new Vector3()
  private readonly s = new Vector3()

  private sit(seat: number, id: string, scale: number, lift: number) {
    if (!this.bodies || !this.heads || seat >= this.seats.length) return
    const p = this.seats[seat]
    this.s.setScalar(scale)
    this.bodies.setMatrixAt(seat, this.m4.compose(this.v.set(p.x, 0.78 + lift, p.z), this.q, this.s))
    this.heads.setMatrixAt(seat, this.m4.compose(this.v.set(p.x, 0.78 + 0.47 * scale + lift, p.z - 0.02), this.q, this.s))
    const tone = 0.5 + 0.5 * Math.max(this.level, 0.25)
    this.bodies.setColorAt(seat, new Color(PALETTE.trainees[colourSlot(id, PALETTE.trainees.length)]).multiplyScalar(tone))
    this.heads.setColorAt(seat, new Color(PALETTE.head).multiplyScalar(tone))
  }

  private clearSeat(seat: number) {
    if (!this.bodies || !this.heads || seat >= this.seats.length) return
    this.m4.makeScale(0, 0, 0)
    this.bodies.setMatrixAt(seat, this.m4)
    this.heads.setMatrixAt(seat, this.m4)
  }

  private flush() {
    for (const mesh of [this.bodies, this.heads]) {
      if (!mesh) continue
      mesh.instanceMatrix.needsUpdate = true
      if (mesh.instanceColor) mesh.instanceColor.needsUpdate = true
    }
  }

  private ring(seat: number, now: number) {
    const p = this.seats[seat]
    const mesh = new Mesh(this.shared.ring, new MeshBasicMaterial({ color: PALETTE.gold, transparent: true, opacity: 0.9, blending: AdditiveBlending, depthWrite: false }))
    mesh.rotation.x = -Math.PI / 2
    mesh.position.set(p.x, 0.04, p.z)
    this.group.add(mesh)
    this.rings.push({ mesh, t0: now })
  }

  private geo(w: number, h: number, d: number): BoxGeometry {
    const key = `${w}|${h}|${d}`
    let g = this.shared.box.get(key)
    if (!g) { g = new BoxGeometry(w, h, d); this.shared.box.set(key, g) }
    return g
  }

  private lambert(pair: readonly number[], level: number): MeshLambertMaterial {
    const m = new MeshLambertMaterial({ color: mix(pair, level) })
    this.own.push(m)
    return m
  }

  private part(w: number, h: number, d: number, mat: Material, x: number, y: number, z: number): Mesh {
    const mesh = new Mesh(this.geo(w, h, d), mat)
    mesh.position.set(x, y, z)
    this.fixed.add(mesh)
    return mesh
  }

  /** Everything that does not move: floor, walls, door, board, lamps, furniture, the trainer and the supervisor. */
  private rebuild(d: SceneRoom) {
    this.fixed.clear()
    this.own.forEach((m) => m.dispose())
    this.own = []
    this.roles.forEach((r) => { this.group.remove(r); r.element.remove() })
    this.roles = []
    this.trainerFigures.length = 0
    this.dropping.clear()
    this.pool = null

    const L = (this.level = LEVEL[d.state])
    const W = ROOM.w
    const D = ROOM.d
    const H = ROOM.wallHeight
    const T = 0.16
    const floor = this.lambert(PALETTE.floor, L)
    const wall = this.lambert(PALETTE.wall, L)
    const cap = this.lambert(PALETTE.cap, L)

    this.part(W, 0.2, D, floor, 0, -0.1, 0)
    // back wall and the two side walls; the front stays open so the room can be seen into
    this.part(W, H, T, wall, 0, H / 2, -D / 2 + T / 2)
    this.part(W + 0.04, 0.07, T + 0.04, cap, 0, H + 0.035, -D / 2 + T / 2)
    this.part(T, H, D, wall, -W / 2 + T / 2, H / 2, 0)
    this.part(T + 0.04, 0.07, D, cap, -W / 2 + T / 2, H + 0.035, 0)
    // the door side: a wall with a doorway near the front and the door leaf standing open
    const doorW = 1.25
    const doorZ = D / 2 - 1.5
    const backLen = doorZ - doorW / 2 + D / 2
    const frontLen = D / 2 - (doorZ + doorW / 2)
    this.part(T, H, backLen, wall, W / 2 - T / 2, H / 2, -D / 2 + backLen / 2)
    this.part(T + 0.04, 0.07, backLen, cap, W / 2 - T / 2, H + 0.035, -D / 2 + backLen / 2)
    this.part(T, H, frontLen, wall, W / 2 - T / 2, H / 2, D / 2 - frontLen / 2)
    this.part(T + 0.04, 0.07, frontLen, cap, W / 2 - T / 2, H + 0.035, D / 2 - frontLen / 2)
    const leaf = this.part(0.06, H - 0.1, doorW, this.lambert(PALETTE.door, Math.max(L, 0.25)), W / 2 + 0.42, (H - 0.1) / 2, doorZ - doorW / 2 - 0.36)
    leaf.rotation.y = -0.85
    // the low kerb at the open front
    this.part(W, 0.14, T, cap, 0, 0.07, D / 2 - T / 2)

    // the board: it glows while a session runs
    const boardMat = d.state === 'live' ? new MeshBasicMaterial({ color: 0xffffff }) : this.lambert(PALETTE.board, L)
    if (d.state === 'live') this.own.push(boardMat)
    this.part(3.62, 1.13, 0.04, cap, 0, 0.98, -D / 2 + T + 0.02)
    this.part(3.4, 0.93, 0.05, boardMat, 0, 0.98, -D / 2 + T + 0.045)
    // the trainer's desk
    this.part(1.5, 0.06, 0.6, this.lambert(PALETTE.desk, L), W / 2 - 1.9, 0.74, -D / 2 + 1.25)
    this.part(1.4, 0.7, 0.05, this.lambert(PALETTE.desk, L * 0.85), W / 2 - 1.9, 0.36, -D / 2 + 1.5)

    // the lamps and the pool of light they throw
    const lampMat = new MeshBasicMaterial({ color: mix(PALETTE.lamp, L > 0.5 ? 1 : L) })
    this.own.push(lampMat)
    for (const x of [-W / 4, W / 4]) {
      const lamp = new Mesh(this.shared.lamp, lampMat)
      lamp.position.set(x, H + 0.75, 0.2)
      this.fixed.add(lamp)
      this.part(0.02, 0.5, 0.02, cap, x, H + 1.1, 0.2)
    }
    if (L > 0.5) {
      const poolMat = new MeshBasicMaterial({ map: this.shared.glow, transparent: true, opacity: d.state === 'live' ? 0.5 : 0.22, blending: AdditiveBlending, depthWrite: false })
      this.own.push(poolMat)
      this.pool = new Mesh(this.shared.pool, poolMat)
      this.pool.rotation.x = -Math.PI / 2
      this.pool.position.y = 0.012
      this.fixed.add(this.pool)
    }

    // seats: a chair and a small desk each, drawn as three instanced shapes
    const n = this.seats.length
    if (n > 0) {
      const chairMat = this.lambert(PALETTE.chair, L)
      const deskMat = this.lambert(PALETTE.desk, L)
      const seat = new InstancedMesh(this.geo(0.46, 0.06, 0.46), chairMat, n)
      const back = new InstancedMesh(this.geo(0.46, 0.5, 0.05), chairMat, n)
      const desk = new InstancedMesh(this.geo(0.66, 0.05, 0.36), deskMat, n)
      const leg = new InstancedMesh(this.geo(0.05, 0.66, 0.05), deskMat, n)
      const m = new Matrix4()
      this.seats.forEach((s, i) => {
        seat.setMatrixAt(i, m.makeTranslation(s.x, 0.43, s.z))
        back.setMatrixAt(i, m.makeTranslation(s.x, 0.7, s.z + 0.24))
        desk.setMatrixAt(i, m.makeTranslation(s.x, 0.7, s.z - 0.44))
        leg.setMatrixAt(i, m.makeTranslation(s.x, 0.34, s.z - 0.5))
      })
      for (const mesh of [seat, back, desk, leg]) { mesh.frustumCulled = false; this.fixed.add(mesh) }

      this.bodies = new InstancedMesh(this.shared.capsuleSeated, this.shared.bodyMat, n)
      this.heads = new InstancedMesh(this.shared.head, this.shared.headMat, n)
      for (const mesh of [this.bodies, this.heads]) {
        mesh.frustumCulled = false
        for (let i = 0; i < n; i++) { mesh.setMatrixAt(i, m.makeScale(0, 0, 0)); mesh.setColorAt(i, new Color(0xffffff)) }
        this.fixed.add(mesh)
      }
    } else {
      this.bodies = this.heads = null
    }

    // the trainers stand at the board, facing the room
    d.trainers.slice(0, 3).forEach((t, i) => {
      const figure = this.figure(PALETTE.trainer, t.here || d.state === 'live' ? 1 : 0.4, Math.max(L, 0.3))
      figure.position.set(-1.9 + i * 1.05, 0, -D / 2 + 1.25)
      this.fixed.add(figure)
      this.trainerFigures.push(figure)
      this.role(`${this.text.trainer}: ${t.name}`, `lt-trainer${t.here || d.state === 'live' ? '' : ' lt-away'}`, figure.position.x, 2.05, figure.position.z)
    })
    // the supervisor stands inside the door
    if (d.supervisor) {
      const figure = this.figure(PALETTE.supervisor, 1, Math.max(L, 0.3))
      figure.position.set(W / 2 - 0.95, 0, doorZ + 0.1)
      figure.rotation.y = -Math.PI / 2.6
      this.fixed.add(figure)
      this.role(`${this.text.supervisor}: ${d.supervisor}`, 'lt-supervisor', figure.position.x, 2.05, figure.position.z)
    }
  }

  private role(text: string, cls: string, x: number, y: number, z: number) {
    const el = label(`lt-role ${cls}`)
    el.textContent = text
    const obj = new CSS2DObject(el)
    obj.center.set(0.5, 1)
    obj.position.set(x, y, z)
    obj.visible = this.focused
    this.group.add(obj)
    this.roles.push(obj)
  }

  /** A standing figure: a robe and a head. */
  private figure(colour: number, opacity: number, level: number): Group {
    const g = new Group()
    const tone = 0.5 + 0.5 * level
    const body = new MeshLambertMaterial({ color: new Color(colour).multiplyScalar(tone), transparent: opacity < 1, opacity })
    const head = new MeshLambertMaterial({ color: new Color(PALETTE.head).multiplyScalar(tone), transparent: opacity < 1, opacity })
    this.own.push(body, head)
    const b = new Mesh(this.shared.capsuleStanding, body)
    b.position.y = 0.72
    const h = new Mesh(this.shared.head, head)
    h.position.y = 1.56
    h.scale.setScalar(1.12)
    g.add(b, h)
    return g
  }

  dispose() {
    this.own.forEach((m) => m.dispose())
    this.rings.forEach((r) => (r.mesh.material as Material).dispose())
    this.frame.geometry.dispose();
    (this.frame.material as Material).dispose()
    for (const o of [this.tag, ...this.roles, ...[...this.names.values()].map((n) => n.obj)]) o.element.remove()
  }
}

export class LiveScene {
  private readonly container: HTMLElement
  private readonly opts: SceneOptions
  private readonly renderer: WebGLRenderer
  private readonly labels: CSS2DRenderer
  private readonly scene = new Scene()
  private readonly camera = new PerspectiveCamera(34, 1, 0.5, 600)
  private readonly controls: OrbitControls
  private readonly shared: Shared
  private readonly views = new Map<string, RoomView>()
  private readonly slabs = new Group()
  private readonly floorLabels: CSS2DObject[] = []
  private readonly styleEl: HTMLStyleElement
  private readonly observer: ResizeObserver
  private readonly raycaster = new Raycaster()
  private placement: Placement | null = null
  private latest: SceneRoom[] = []
  private placementSig = ''
  private selected: string | null = null
  private hovered: string | null = null
  private userMoved = false
  private tween: { fromP: Vector3; toP: Vector3; fromT: Vector3; toT: Vector3; t0: number; ms: number } | null = null
  private raf = 0
  private dirty = true
  private disposed = false
  private first = true
  private down: { x: number; y: number } | null = null

  constructor(container: HTMLElement, opts: SceneOptions) {
    this.container = container
    this.opts = opts
    this.renderer = new WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' })
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2))
    this.renderer.outputColorSpace = SRGBColorSpace
    this.renderer.setClearColor(0x000000, 0)
    Object.assign(this.renderer.domElement.style, { position: 'absolute', inset: '0', width: '100%', height: '100%', display: 'block', touchAction: 'none', outline: 'none' })
    container.appendChild(this.renderer.domElement)

    this.styleEl = document.createElement('style')
    this.styleEl.textContent = STYLE
    container.appendChild(this.styleEl)
    this.labels = new CSS2DRenderer()
    this.labels.domElement.className = 'lt-labels'
    container.appendChild(this.labels.domElement)

    this.scene.add(new HemisphereLight(0xfff3df, 0x2a1622, 2.1))
    const sun = new DirectionalLight(0xffffff, 1.9)
    sun.position.set(-14, 30, 22)
    this.scene.add(sun)
    this.scene.add(this.slabs)

    this.shared = {
      box: new Map(),
      capsuleSeated: new CapsuleGeometry(0.2, 0.3, 4, 12), capsuleStanding: new CapsuleGeometry(0.25, 0.9, 4, 14), head: new SphereGeometry(0.16, 16, 12),
      lamp: new SphereGeometry(0.17, 14, 10), pool: new CircleGeometry(Math.min(ROOM.w, ROOM.d) * 0.62, 40), ring: new RingGeometry(0.24, 0.31, 36),
      glow: glowTexture(), headMat: new MeshLambertMaterial({ color: 0xffffff }), bodyMat: new MeshLambertMaterial({ color: 0xffffff }),
    }

    this.camera.position.set(0, 40, 44)
    this.renderer.domElement.style.cursor = 'grab'
    this.controls = new OrbitControls(this.camera, this.renderer.domElement)
    this.controls.enableDamping = true
    this.controls.dampingFactor = 0.08
    this.controls.minPolarAngle = 0.25
    this.controls.maxPolarAngle = 1.28
    this.controls.minDistance = 7
    this.controls.zoomSpeed = 0.8
    this.controls.panSpeed = 0.8
    this.controls.screenSpacePanning = false
    this.controls.addEventListener('start', () => { this.tween = null; this.userMoved = true })
    this.controls.addEventListener('change', () => { this.dirty = true })

    const canvas = this.renderer.domElement
    canvas.addEventListener('pointermove', this.onMove)
    canvas.addEventListener('pointerdown', this.onDown)
    canvas.addEventListener('pointerup', this.onUp)
    canvas.addEventListener('pointerleave', this.onLeave)

    this.observer = new ResizeObserver(() => this.resize())
    this.observer.observe(container)
    this.resize()
    this.raf = requestAnimationFrame(this.frame)
  }

  /** New data from the page. People already seated stay put; newcomers of the same session drop into their seats. */
  setData(rooms: SceneRoom[]) {
    if (this.disposed) return
    const now = performance.now()
    this.latest = rooms
    const stacked = this.camera.aspect < STACK_BELOW
    const sig = `${stacked ? 'S' : 'W'}|${rooms.map((r) => `${r.id}:${r.floor ?? ''}`).join('|')}`
    const relaid = sig !== this.placementSig
    if (relaid) {
      this.placementSig = sig
      this.placement = placeRooms(rooms.map((r) => ({ id: r.id, floor: r.floor })), this.opts.mirror, stacked)
      this.buildSlabs()
      const keep = new Set(rooms.map((r) => r.id))
      for (const [id, v] of this.views) if (!keep.has(id)) { this.scene.remove(v.group); v.dispose(); this.views.delete(id) }
      if (this.selected && !keep.has(this.selected)) this.selected = null
    }
    for (const r of rooms) {
      let v = this.views.get(r.id)
      if (!v) {
        v = new RoomView(r, this.shared, this.opts.text)
        this.views.set(r.id, v)
        this.scene.add(v.group)
      }
      const at = this.placement!.rooms.find((p) => p.id === r.id)!
      v.place(at.x, at.z)
      v.update(r, now, !this.first)
    }
    this.applySelection(now)
    if (this.first || (relaid && !this.selected)) this.frameAll(this.first ? 0 : 600)
    this.first = false
    this.dirty = true
  }

  /** Puts one room in focus (the camera flies to it and names appear), or shows the whole centre again with `null`. */
  select(roomId: string | null) {
    if (this.disposed || roomId === this.selected) return
    this.selected = roomId && this.views.has(roomId) ? roomId : null
    this.applySelection(performance.now())
    if (this.selected) this.focusRoom(this.views.get(this.selected)!)
    else this.frameAll()
    this.dirty = true
  }

  /** Back to the view of the whole centre. */
  resetView() {
    this.userMoved = false
    if (this.selected) this.focusRoom(this.views.get(this.selected)!)
    else this.frameAll()
  }

  zoom(factor: number) {
    const offset = this.camera.position.clone().sub(this.controls.target).multiplyScalar(factor)
    const len = Math.min(Math.max(offset.length(), this.controls.minDistance), this.controls.maxDistance)
    this.flyTo(this.controls.target.clone().add(offset.setLength(len)), this.controls.target.clone(), 260)
    this.userMoved = true
  }

  dispose() {
    this.disposed = true
    cancelAnimationFrame(this.raf)
    this.observer.disconnect()
    const canvas = this.renderer.domElement
    canvas.removeEventListener('pointermove', this.onMove)
    canvas.removeEventListener('pointerdown', this.onDown)
    canvas.removeEventListener('pointerup', this.onUp)
    canvas.removeEventListener('pointerleave', this.onLeave)
    this.controls.dispose()
    this.views.forEach((v) => v.dispose())
    this.scene.traverse((o: Object3D) => {
      const mesh = o as Mesh
      if (mesh.geometry) (mesh.geometry as BufferGeometry).dispose()
      const mat = mesh.material as Material | Material[] | undefined
      if (Array.isArray(mat)) mat.forEach((m) => m.dispose())
      else mat?.dispose()
    })
    this.shared.glow.dispose()
    this.renderer.dispose()
    canvas.remove()
    this.labels.domElement.remove()
    this.styleEl.remove()
  }

  // ── drawing ───────────────────────────────────────────────────────────────────────────────

  private buildSlabs() {
    this.slabs.clear()
    this.floorLabels.forEach((l) => l.element.remove())
    this.floorLabels.length = 0
    const p = this.placement!
    for (const f of p.floors) {
      const slab = new Mesh(new BoxGeometry(f.w, 0.3, f.d), new MeshLambertMaterial({ color: PALETTE.slab }))
      slab.position.set(f.x, -0.36, f.z)
      const edge = new LineSegments(new EdgesGeometry(slab.geometry), new LineBasicMaterial({ color: PALETTE.slabEdge, transparent: true, opacity: 0.35 }))
      edge.position.copy(slab.position)
      this.slabs.add(slab, edge)
      if (f.key) {
        const el = label('lt-floor')
        el.textContent = this.opts.text.floor(f.key)
        const obj = new CSS2DObject(el)
        const stacked = this.placementSig.startsWith('S')
        const side = this.opts.mirror ? 1 : -1
        if (stacked) {
          obj.center.set(this.opts.mirror ? 0 : 1, 0.5)
          obj.position.set(f.x + side * (f.w / 2 + 0.9), -0.2, f.z + f.d / 4)
        } else {
          obj.center.set(this.opts.mirror ? 1 : 0, 0)
          obj.position.set(f.x + side * (f.w / 2 - 0.5), -0.2, f.z + f.d / 2 + 0.4)
        }
        this.slabs.add(obj)
        this.floorLabels.push(obj)
      }
    }
    const radius = Math.hypot(p.bounds.maxX - p.bounds.minX, p.bounds.maxZ - p.bounds.minZ) * 0.75
    const haze = new Mesh(new CircleGeometry(radius, 48), new MeshBasicMaterial({ map: this.shared.glow, transparent: true, opacity: 0.1, depthWrite: false }))
    haze.rotation.x = -Math.PI / 2
    haze.position.y = -0.55
    this.slabs.add(haze)
  }

  private applySelection(now: number) {
    for (const [id, v] of this.views) {
      v.setFocused(id === this.selected, now)
      v.setDimmed(this.selected !== null && id !== this.selected)
      v.setHighlight(id === this.selected ? 0.95 : id === this.hovered ? 0.55 : 0)
      v.setOpen(id === this.selected || id === this.hovered)
    }
  }

  // ── camera ────────────────────────────────────────────────────────────────────────────────

  private frameAll(ms = 900) {
    const b = this.placement?.bounds ?? { minX: -ROOM.w, maxX: ROOM.w, minZ: -ROOM.d, maxZ: ROOM.d }
    const target = new Vector3(0, 0, 0)
    const dir = this.direction(0.9, 0.22)
    // the tags float above the back wall of each room, so the box reaches a little higher than the walls
    const dist = this.fit(target, dir, new Box3(new Vector3(b.minX, -0.4, b.minZ), new Vector3(b.maxX, ROOM.wallHeight + 2.6, b.maxZ)), 0.92, 0.74, 0.86)
    this.controls.maxDistance = dist * 1.8
    this.flyTo(target.clone().add(dir.multiplyScalar(dist)), target, ms)
  }

  private focusRoom(v: RoomView) {
    const { x, z } = v.group.position
    const target = new Vector3(x, 0.9, z + 0.2)
    const dir = this.direction(0.84, 0.16)
    // the floor of the room fills the stage; the tag above the back wall keeps a little air under the controls
    const floor = this.fit(target, dir, new Box3(new Vector3(x - ROOM.w / 2, 0, z - ROOM.d / 2), new Vector3(x + ROOM.w / 2, 0.1, z + ROOM.d / 2)), 0.9, 0.9, 0.8)
    const tag = this.fit(target, dir, new Box3(new Vector3(x - 1, ROOM.wallHeight + 1.5, z - ROOM.d / 2), new Vector3(x + 1, ROOM.wallHeight + 1.9, z - ROOM.d / 2)), 1, 0.72, 1)
    const dist = Math.max(floor, tag)
    this.flyTo(target.clone().add(dir.multiplyScalar(dist)), target, 950)
  }

  /**
   * The nearest distance along `dir` from which the whole box is on screen, leaving `side`, `top` and `bottom` of the half
   * screen free at most (1 = up to the edge). Found by halving, with the real projection, so it holds for any screen shape.
   */
  private fit(target: Vector3, dir: Vector3, box: Box3, side: number, top: number, bottom: number): number {
    const probe = this.camera.clone()
    const corners: Vector3[] = []
    for (const x of [box.min.x, box.max.x]) for (const y of [box.min.y, box.max.y]) for (const z of [box.min.z, box.max.z]) corners.push(new Vector3(x, y, z))
    const inside = (dist: number) => {
      probe.position.copy(target).addScaledVector(dir, dist)
      probe.lookAt(target)
      probe.updateMatrixWorld(true)
      return corners.every((c) => { const p = c.clone().project(probe); return p.z < 1 && Math.abs(p.x) <= side && p.y <= top && p.y >= -bottom })
    }
    let lo = 6
    let hi = 600
    for (let i = 0; i < 22; i++) { const mid = (lo + hi) / 2; if (inside(mid)) hi = mid; else lo = mid }
    return hi
  }

  private direction(elevation: number, azimuth: number): Vector3 {
    const a = this.opts.mirror ? azimuth : -azimuth
    return new Vector3(Math.sin(a) * Math.cos(elevation), Math.sin(elevation), Math.cos(a) * Math.cos(elevation))
  }

  private flyTo(position: Vector3, target: Vector3, ms: number) {
    if (this.opts.reducedMotion || ms <= 0) {
      this.camera.position.copy(position)
      this.controls.target.copy(target)
      this.controls.update()
      this.tween = null
    } else {
      this.tween = { fromP: this.camera.position.clone(), toP: position, fromT: this.controls.target.clone(), toT: target, t0: performance.now(), ms }
    }
    this.dirty = true
  }

  private resize() {
    const w = Math.max(this.container.clientWidth, 2)
    const h = Math.max(this.container.clientHeight, 2)
    this.renderer.setSize(w, h, false)
    this.labels.setSize(w, h)
    this.camera.aspect = w / h
    this.camera.updateProjectionMatrix()
    // A stage that turns from wide to tall (or back) is laid out again: floors side by side, or one behind the other.
    if (!this.first && (this.camera.aspect < STACK_BELOW) !== this.placementSig.startsWith('S')) this.setData(this.latest)
    else if (!this.first && !this.userMoved && !this.selected) this.frameAll(0)
    this.dirty = true
  }

  private readonly frame = (now: number) => {
    if (this.disposed) return
    this.raf = requestAnimationFrame(this.frame)
    if (document.hidden) return
    let moving = false
    if (this.tween) {
      const p = Math.min(1, (now - this.tween.t0) / this.tween.ms)
      const e = easeInOut(p)
      this.camera.position.lerpVectors(this.tween.fromP, this.tween.toP, e)
      this.controls.target.lerpVectors(this.tween.fromT, this.tween.toT, e)
      if (p >= 1) this.tween = null
      moving = true
    }
    for (const v of this.views.values()) if (v.tick(now, this.opts.reducedMotion)) moving = true
    this.controls.update()
    if (!moving && !this.dirty) return
    this.dirty = false
    this.renderer.render(this.scene, this.camera)
    this.labels.render(this.scene, this.camera)
  }

  // ── pointing at rooms ─────────────────────────────────────────────────────────────────────

  private pick(e: PointerEvent): string | null {
    const r = this.renderer.domElement.getBoundingClientRect()
    this.raycaster.setFromCamera(new Vector2(((e.clientX - r.left) / r.width) * 2 - 1, -((e.clientY - r.top) / r.height) * 2 + 1), this.camera)
    const hit = new Vector3()
    let best: string | null = null
    let near = Infinity
    for (const [id, v] of this.views) {
      if (!this.raycaster.ray.intersectBox(v.box, hit)) continue
      const d = hit.distanceToSquared(this.raycaster.ray.origin)
      if (d < near) { near = d; best = id }
    }
    return best
  }

  private readonly onMove = (e: PointerEvent) => {
    if (this.down && Math.hypot(e.clientX - this.down.x, e.clientY - this.down.y) > 6) { this.setHover(null, e); return }
    this.setHover(this.pick(e), e)
  }

  private setHover(id: string | null, e: PointerEvent) {
    if (id !== this.hovered) {
      this.hovered = id
      this.renderer.domElement.style.cursor = id ? 'pointer' : 'grab'
      this.applySelection(performance.now())
      this.dirty = true
    }
    this.opts.onHover(id, e.clientX, e.clientY)
  }

  private readonly onDown = (e: PointerEvent) => { this.down = { x: e.clientX, y: e.clientY } }

  private readonly onUp = (e: PointerEvent) => {
    const start = this.down
    this.down = null
    if (!start || Math.hypot(e.clientX - start.x, e.clientY - start.y) > 6) return
    const id = this.pick(e)
    if (id) this.opts.onSelect(id)
  }

  private readonly onLeave = (e: PointerEvent) => { this.down = null; this.setHover(null, e) }
}

/** A soft round glow, used for the light a lamp throws on the floor. */
function glowTexture(): CanvasTexture {
  const c = document.createElement('canvas')
  c.width = c.height = 128
  const g = c.getContext('2d')!
  const grad = g.createRadialGradient(64, 64, 4, 64, 64, 64)
  grad.addColorStop(0, 'rgba(255,236,190,1)')
  grad.addColorStop(0.45, 'rgba(255,225,160,0.45)')
  grad.addColorStop(1, 'rgba(255,220,150,0)')
  g.fillStyle = grad
  g.fillRect(0, 0, 128, 128)
  const t = new CanvasTexture(c)
  t.colorSpace = SRGBColorSpace
  return t
}
