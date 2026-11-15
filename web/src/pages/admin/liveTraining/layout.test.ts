import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { assignSeats, colourSlot, columnsFor, MAX_SEATS, placeRooms, ROOM, seatGrid, shortName } from './layout.ts'

const room = (id: string, floor: string | null = 'G') => ({ id, floor })

describe('live training: where the rooms stand', () => {
  it('keeps up to three rooms in one row and wraps more into a block', () => {
    assert.equal(columnsFor(1), 1)
    assert.equal(columnsFor(3), 3)
    assert.equal(columnsFor(4), 2)
    assert.equal(columnsFor(8), 3)
    assert.equal(columnsFor(20), 5)
  })

  it('never lets two rooms overlap and centres the drawing', () => {
    const rooms = [...Array.from({ length: 8 }, (_, i) => room(`g${i}`, 'الأرضي')), ...Array.from({ length: 12 }, (_, i) => room(`f${i}`, 'الأول'))]
    const { rooms: placed, floors, bounds } = placeRooms(rooms)
    assert.equal(placed.length, 20)
    for (let a = 0; a < placed.length; a++) {
      for (let b = a + 1; b < placed.length; b++) {
        const apart = Math.abs(placed[a].x - placed[b].x) >= ROOM.w - 1e-9 || Math.abs(placed[a].z - placed[b].z) >= ROOM.d - 1e-9
        assert.ok(apart, `${placed[a].id} overlaps ${placed[b].id}`)
      }
    }
    assert.equal(floors.length, 2)
    assert.ok(Math.abs(bounds.minZ + bounds.maxZ) < 1e-9, 'centred front to back')
    assert.ok(Math.abs(bounds.minX + bounds.maxX) < 1e-9, 'centred side to side')
    // every room stands on the slab of its floor
    for (const p of placed) {
      const slab = floors.find((f) => f.key === p.floor)!
      assert.ok(Math.abs(p.x - slab.x) <= slab.w / 2 - ROOM.w / 2 + 1e-9 && Math.abs(p.z - slab.z) <= slab.d / 2 - ROOM.d / 2 + 1e-9)
    }
  })

  it('starts each row, and the first floor, from the right for an Arabic reader', () => {
    const rooms = [room('a'), room('b'), room('c')]
    const ltr = placeRooms(rooms).rooms
    const rtl = placeRooms(rooms, true).rooms
    assert.ok(ltr[0].x < ltr[2].x)
    assert.ok(rtl[0].x > rtl[2].x)
    const two = [room('g1', 'G'), room('g2', 'G'), room('f1', '1')]
    assert.ok(placeRooms(two).floors[0].x < placeRooms(two).floors[1].x, 'the ground floor first, on the left')
    assert.ok(placeRooms(two, true).floors[0].x > placeRooms(two, true).floors[1].x, 'and on the right in Arabic')
  })

  it('sets the floors side by side, wider than deep', () => {
    const rooms = [...Array.from({ length: 8 }, (_, i) => room(`g${i}`, 'G')), ...Array.from({ length: 12 }, (_, i) => room(`f${i}`, '1'))]
    const { bounds } = placeRooms(rooms)
    assert.ok(bounds.maxX - bounds.minX > bounds.maxZ - bounds.minZ)
  })

  it('stacks the floors one behind the other for a tall stage, the first floor in front', () => {
    const rooms = [...Array.from({ length: 5 }, (_, i) => room(`g${i}`, 'G')), ...Array.from({ length: 5 }, (_, i) => room(`f${i}`, '1'))]
    const { rooms: placed, floors, bounds } = placeRooms(rooms, false, true)
    assert.ok(floors[0].z > floors[1].z, 'the ground floor is nearer the viewer')
    assert.equal(floors[0].x, 0)
    assert.ok(Math.abs(bounds.minZ + bounds.maxZ) < 1e-9 && Math.abs(bounds.minX + bounds.maxX) < 1e-9)
    for (let a = 0; a < placed.length; a++) {
      for (let b = a + 1; b < placed.length; b++) {
        assert.ok(Math.abs(placed[a].x - placed[b].x) >= ROOM.w - 1e-9 || Math.abs(placed[a].z - placed[b].z) >= ROOM.d - 1e-9)
      }
    }
    for (const p of placed) {
      const slab = floors.find((f) => f.key === p.floor)!
      assert.ok(Math.abs(p.x - slab.x) <= slab.w / 2 - ROOM.w / 2 + 1e-9 && Math.abs(p.z - slab.z) <= slab.d / 2 - ROOM.d / 2 + 1e-9)
    }
  })

  it('puts rooms without a floor on a slab of their own', () => {
    const { floors } = placeRooms([room('a', null), room('b', '')])
    assert.equal(floors.length, 1)
  })
})

describe('live training: seats in a room', () => {
  it('makes a seat for everyone expected, inside the walls, front row first', () => {
    for (const n of [1, 7, 24, 30, 48]) {
      const seats = seatGrid(n)
      assert.equal(seats.length, n)
      for (const s of seats) {
        assert.ok(Math.abs(s.x) < ROOM.w / 2 - 0.4 && s.z > -ROOM.d / 2 + 2 && s.z < ROOM.d / 2 - 0.3, `seat ${s.key} of ${n} is inside the room`)
      }
      assert.equal(seats[0].row, 0)
    }
  })

  it('draws at most a room-full however large the group', () => {
    assert.equal(seatGrid(300).length, MAX_SEATS)
  })

  it('follows the designed seating plan and skips blocked seats', () => {
    const seats = seatGrid(99, { rows: 2, cols: 3, blocked: ['0,1'] })
    assert.deepEqual(seats.map((s) => s.key), ['0,0', '0,2', '1,0', '1,1', '1,2'])
  })
})

describe('live training: who sits where', () => {
  const seats = seatGrid(6)

  it('seats newcomers from the front in the order they arrived', () => {
    const m = assignSeats(new Map(), [{ id: 'a', seat: null }, { id: 'b', seat: null }], seats)
    assert.deepEqual([...m], [['a', 0], ['b', 1]])
  })

  it('nobody jumps when a colleague arrives or leaves', () => {
    const first = assignSeats(new Map(), [{ id: 'a', seat: null }, { id: 'b', seat: null }, { id: 'c', seat: null }], seats)
    const afterLeave = assignSeats(first, [{ id: 'a', seat: null }, { id: 'c', seat: null }, { id: 'd', seat: null }], seats)
    assert.equal(afterLeave.get('a'), 0)
    assert.equal(afterLeave.get('c'), 2, 'c keeps the seat')
    assert.equal(afterLeave.get('d'), 1, 'the newcomer takes the freed seat')
    assert.equal(afterLeave.has('b'), false)
  })

  it('a designed seat wins, and a full room leaves the extra trainee standing outside the drawing', () => {
    const plan = seatGrid(0, { rows: 1, cols: 2 })
    const m = assignSeats(new Map(), [{ id: 'a', seat: null }, { id: 'b', seat: '0,0' }, { id: 'c', seat: null }], plan)
    assert.equal(m.get('b'), 0)
    assert.equal(m.get('a'), 1)
    assert.equal(m.has('c'), false)
  })
})

describe('live training: small helpers', () => {
  it('gives a person the same colour every time', () => {
    assert.equal(colourSlot('abc', 6), colourSlot('abc', 6))
    assert.ok(colourSlot('abc', 6) >= 0 && colourSlot('abc', 6) < 6)
  })

  it('shortens a name to the given name, keeping names that need two words whole and dropping titles', () => {
    assert.equal(shortName('أحمد بن علي الكواري'), 'أحمد')
    assert.equal(shortName('عبد الله محمد المري'), 'عبد الله')
    assert.equal(shortName('أ. نورة السليطي'), 'نورة')
    assert.equal(shortName('Dr. Helen Carter'), 'Helen')
    assert.equal(shortName('  Noura  '), 'Noura')
    assert.equal(shortName(''), '')
  })
})
