/**
 * Where things stand in the 3D drawing of the centre: rooms on their floor slabs, and seats inside a room.
 * Plain arithmetic with no drawing library, so it can be tested on its own.
 */

/** Room footprint in metres (x across, z from the board at the back to the open front) and the walkway between rooms. */
export const ROOM = { w: 9, d: 7.6, gap: 2.4, wallHeight: 1.6, floorGap: 3.4 } as const

export type RoomSlot = { id: string; floor: string | null }
export type PlacedRoom = { id: string; x: number; z: number; floor: string }
export type FloorSlab = { key: string; x: number; z: number; w: number; d: number }
export type Bounds = { minX: number; maxX: number; minZ: number; maxZ: number }
export type Placement = { rooms: PlacedRoom[]; floors: FloorSlab[]; bounds: Bounds }

/**
 * Rooms per row for a floor: up to three stay in one row, more wrap into a block. Floors that stand side by side get
 * blocks about as wide as they are deep; floors that stand one behind the other get wider blocks.
 */
export function columnsFor(count: number, stacked = false): number {
  if (count <= 3) return Math.max(count, 1)
  return Math.min(6, Math.ceil(Math.sqrt(count * (stacked ? 1.7 : 1))))
}

/**
 * Lays the rooms out floor by floor (in the order the floors first appear), each floor on its own slab. The slabs stand
 * side by side for a wide stage, or one behind the other (`stacked`, the first floor nearest the viewer) for a tall one.
 * With `mirror` the first floor and the first room of a row stand on the right, the way an Arabic reader starts.
 */
export function placeRooms(rooms: RoomSlot[], mirror = false, stacked = false): Placement {
  const groups = new Map<string, RoomSlot[]>()
  for (const r of rooms) {
    const key = (r.floor ?? '').trim()
    groups.set(key, [...(groups.get(key) ?? []), r])
  }
  const stepX = ROOM.w + ROOM.gap
  const stepZ = ROOM.d + ROOM.gap
  const pad = ROOM.gap * 0.75
  const placed: PlacedRoom[] = []
  const floors: FloorSlab[] = []
  let edge = 0   // the leading edge of the next slab, along x (side by side) or along z towards the back (stacked)
  for (const [key, list] of groups) {
    const cols = columnsFor(list.length, stacked)
    const rows = Math.ceil(list.length / cols)
    const w = cols * ROOM.w + (cols - 1) * ROOM.gap + pad * 2
    const d = rows * ROOM.d + (rows - 1) * ROOM.gap + pad * 2
    const cx = stacked ? 0 : edge + w / 2
    const cz = stacked ? -(edge + d / 2) : 0
    list.forEach((r, i) => {
      const col = i % cols
      const row = Math.floor(i / cols)
      // The last row may be short: centre it under the full rows.
      const inRow = row === rows - 1 ? list.length - row * cols : cols
      placed.push({ id: r.id, x: cx + (col - (inRow - 1) / 2) * stepX, z: cz + (row - (rows - 1) / 2) * stepZ, floor: key })
    })
    floors.push({ key, x: cx, z: cz, w, d })
    edge += (stacked ? d : w) + ROOM.floorGap
  }
  // Centre the whole drawing on the origin, then mirror it for a right-to-left reader.
  const span = Math.max(edge - ROOM.floorGap, stacked ? ROOM.d : ROOM.w)
  const sign = mirror ? -1 : 1
  if (stacked) {
    placed.forEach((p) => { p.x *= sign; p.z += span / 2 })
    floors.forEach((f) => { f.z += span / 2 })
    const halfW = Math.max(...floors.map((f) => f.w / 2), ROOM.w / 2)
    return { rooms: placed, floors, bounds: { minX: -halfW, maxX: halfW, minZ: -span / 2, maxZ: span / 2 } }
  }
  placed.forEach((p) => { p.x = (p.x - span / 2) * sign })
  floors.forEach((f) => { f.x = (f.x - span / 2) * sign })
  const halfD = Math.max(...floors.map((f) => f.d / 2), ROOM.d / 2)
  return { rooms: placed, floors, bounds: { minX: -span / 2, maxX: span / 2, minZ: -halfD, maxZ: halfD } }
}

export type SeatingLayout = { rows: number; cols: number; blocked?: string[] }
/** A seat in a room, in the room's own coordinates (origin at the centre of the floor, the board at -z). `key` is "row,col". */
export type Seat = { key: string; row: number; col: number; x: number; z: number }

/** Most seats drawn in one room; a bigger group still counts fully in the numbers. */
export const MAX_SEATS = 48

/**
 * The seats of a room: the designed seating plan when there is one, otherwise a tidy grid big enough for `count` people.
 * Row 0 is the front row, nearest the board.
 */
export function seatGrid(count: number, plan?: SeatingLayout | null): Seat[] {
  let rows: number
  let cols: number
  let blocked = new Set<string>()
  if (plan && plan.rows > 0 && plan.cols > 0) {
    rows = Math.min(plan.rows, 8)
    cols = Math.min(plan.cols, 10)
    blocked = new Set(plan.blocked ?? [])
  } else {
    const n = Math.max(1, Math.min(count, MAX_SEATS))
    cols = Math.min(8, Math.max(2, Math.ceil(Math.sqrt(n * 1.5))))
    rows = Math.ceil(n / cols)
  }
  // The seats sit between the trainer's strip in front of the board and the back of the room.
  const areaW = ROOM.w - 1.8
  const areaD = ROOM.d - 3.3
  const stepX = Math.min(1.15, areaW / cols)
  const stepZ = Math.min(1.2, areaD / rows)
  const frontZ = -ROOM.d / 2 + 2.75
  const seats: Seat[] = []
  for (let row = 0; row < rows; row++) {
    for (let col = 0; col < cols; col++) {
      const key = `${row},${col}`
      if (blocked.has(key)) continue
      if (!plan && seats.length >= Math.max(1, Math.min(count, MAX_SEATS))) break
      seats.push({ key, row, col, x: (col - (cols - 1) / 2) * stepX, z: frontZ + row * stepZ })
    }
  }
  return seats
}

export type Sitter = { id: string; seat: string | null }

/**
 * Gives every present trainee a seat and keeps it from one refresh to the next, so nobody jumps when someone else arrives.
 * A trainee with a designed seat takes it; the others fill the free seats from the front, in the order they arrived.
 * Returns trainee id → seat index; a trainee beyond the last seat is left out (the room is full).
 */
export function assignSeats(previous: ReadonlyMap<string, number>, present: Sitter[], seats: Seat[]): Map<string, number> {
  const byKey = new Map(seats.map((s, i) => [s.key, i]))
  const taken = new Set<number>()
  const next = new Map<string, number>()
  const here = new Set(present.map((p) => p.id))
  // 1. designed seats
  for (const p of present) {
    const i = p.seat ? byKey.get(p.seat) : undefined
    if (i !== undefined && !taken.has(i)) { next.set(p.id, i); taken.add(i) }
  }
  // 2. the seat a trainee already had
  for (const [id, i] of previous) {
    if (here.has(id) && !next.has(id) && i < seats.length && !taken.has(i)) { next.set(id, i); taken.add(i) }
  }
  // 3. newcomers, front row first
  let free = 0
  for (const p of present) {
    if (next.has(p.id)) continue
    while (free < seats.length && taken.has(free)) free++
    if (free >= seats.length) break
    next.set(p.id, free)
    taken.add(free)
  }
  return next
}

/** A steady colour slot for a person, so the same trainee keeps the same colour all day. */
export function colourSlot(id: string, slots: number): number {
  let h = 0
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) >>> 0
  return h % slots
}

/** Arabic names whose first word does not stand alone («عبد الله», «أبو بكر», «أم كلثوم»). */
const JOINED = new Set(['عبد', 'عبدال', 'أبو', 'ابو', 'أم', 'ام', 'بن', 'ابن', 'بنت', 'آل', 'ال'])
/** Titles that are not the name itself. */
const TITLES = /^(د|أ|م|السيد|السيدة|الدكتور|الدكتورة|الأستاذ|الأستاذة|dr|mr|mrs|ms|prof|eng)\.?$/i

/** The name as it fits above a small figure: the given name (two words when the first cannot stand alone), without a title. */
export function shortName(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  while (parts.length > 1 && TITLES.test(parts[0])) parts.shift()
  return parts.slice(0, JOINED.has(parts[0] ?? '') ? 2 : 1).join(' ')
}
