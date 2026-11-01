import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { angle, createEngine, length, smooth, spotOf, type CentreMap, type FloorId } from './engine.ts'

const map = JSON.parse(readFileSync(new URL('./centre-map.json', import.meta.url), 'utf8')) as CentreMap
const engine = createEngine(map)
const backend = readFileSync(new URL('../../../../backend/resources/wayfinding/centre-map.json', import.meta.url), 'utf8')

describe('the centre map', () => {
  it('is the same file for the website and for the app', () => {
    assert.equal(backend.trim(), readFileSync(new URL('./centre-map.json', import.meta.url), 'utf8').trim())
  })

  it('has both floors, every training room 1–20 and the computer labs, each with a door on its floor', () => {
    assert.deepEqual(map.floors.map((f) => f.id), ['ground', 'first'])
    const rooms = map.places.filter((p) => p.kind === 'train').map((p) => Number(p.number)).sort((a, b) => a - b)
    assert.deepEqual(rooms, Array.from({ length: 20 }, (_, i) => i + 1))
    for (const n of ['1', '2', '3']) assert.ok(map.places.some((p) => p.kind === 'lab' && p.number === n), `computer lab ${n}`)
    for (const p of map.places) {
      assert.ok(p.door[0] > 0 && p.door[0] < map.size.w && p.door[1] > 0 && p.door[1] < map.size.h, p.id)
      assert.ok(p.id.startsWith(p.floor === 'ground' ? 'g-' : 'f-'), p.id)
    }
  })
})

describe('routes', () => {
  it('reach every place from every entrance, by the stairs and, avoiding stairs, by a lift', () => {
    for (const e of map.entrances) {
      for (const p of map.places) {
        for (const avoidStairs of [false, true]) {
          const r = engine.route({ floor: e.floor, at: e.at }, { floor: p.floor, at: p.door, rect: p.rect, id: p.id }, { avoidStairs })
          assert.ok(r, `${e.id} → ${p.id} (${avoidStairs ? 'lift' : 'any'})`)
          assert.equal(r.steps[0].kind, 'start')
          assert.equal(r.steps.at(-1)!.kind, 'arrive')
          assert.ok(r.metres > 0 && r.metres < 400, `${p.id}: ${r.metres} m`)
          if (p.floor !== e.floor) {
            assert.equal(r.legs.length, 2)
            assert.ok(r.connector)
            if (avoidStairs) assert.equal(r.connector.kind, 'lift')
            const change = r.steps.find((s) => s.kind === 'stairs' || s.kind === 'lift')!
            assert.equal(change.to, 'first')
            assert.equal(change.up, true)
          } else assert.equal(r.legs.length, 1)
        }
      }
    }
  })

  it('go down as well as up, and are as long one way as the other', () => {
    const a = spotOf(map, 'f-room-14')!, b = spotOf(map, 'g-room-1')!
    const down = engine.route(a, b)!
    const up = engine.route(b, a)!
    assert.equal(down.steps.find((s) => s.kind === 'stairs' || s.kind === 'lift')!.up, false)
    assert.ok(Math.abs(down.metres - up.metres) < 6)
  })

  it('stay on the corridors: every drawn stretch crosses walkable floor only', () => {
    const grids = Object.fromEntries(map.floors.map((f) => [f.id, f.grid])) as Record<FloorId, string>
    const walk = (floor: FloorId, x: number, y: number) => {
      const bin = Buffer.from(grids[floor], 'base64')
      const i = Math.floor(y / map.cell) * map.cols + Math.floor(x / map.cell)
      return (bin[i >> 3] >> (7 - (i & 7))) & 1
    }
    for (const [from, to] of [['g-room-8', 'f-room-19'], ['g-library', 'f-knowledge-oasis'], ['g-room-5', 'g-room-2'], ['f-room-10', 'f-room-17']]) {
      const r = engine.route(spotOf(map, from)!, spotOf(map, to)!)!
      for (const leg of r.legs) {
        for (let i = 1; i < leg.points.length; i++) {
          const [ax, ay] = leg.points[i - 1], [bx, by] = leg.points[i]
          const n = Math.ceil(Math.hypot(bx - ax, by - ay) / 2)
          let off = 0
          for (let k = 0; k <= n; k++) off += walk(leg.floor, ax + ((bx - ax) * k) / n, ay + ((by - ay) * k) / n) ? 0 : 1
          assert.ok(off <= 2, `${from} → ${to}: a stretch leaves the corridor`)
        }
      }
    }
  })

  it('name the turns and say which hand the door is on', () => {
    const r = engine.route(spotOf(map, 'entrance-west')!, spotOf(map, 'g-room-2')!)!
    assert.ok(r.steps.some((s) => s.kind === 'left' || s.kind === 'right'))
    assert.ok(['left', 'right', 'ahead'].includes(r.steps.at(-1)!.side!))
    assert.ok(r.seconds > 0)
  })

  it('are quick enough to answer as the visitor taps', () => {
    const t = performance.now()
    for (let i = 0; i < 10; i++) engine.route(spotOf(map, 'entrance-east')!, spotOf(map, 'f-room-20')!)
    assert.ok((performance.now() - t) / 10 < 120, 'a cross-floor route takes under 120 ms')
  })
})

describe('geometry helpers', () => {
  it('tells right from left on a plan whose y axis points down', () => {
    assert.ok(angle([0, 0], [10, 0], [10, 10]) > 80)
    assert.ok(angle([0, 0], [10, 0], [10, -10]) < -80)
    assert.deepEqual(smooth([[0, 0], [5, 0.5], [10, 0], [10, 10]]), [[0, 0], [10, 0], [10, 10]])
    assert.equal(length([[0, 0], [3, 4]]), 5)
  })
})
