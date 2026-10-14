import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { FaceMonitor, type FaceBox, type FaceEvent } from './faceMonitor.ts'

const centred: FaceBox = { x: 0.35, y: 0.25, w: 0.3, h: 0.4 }
const aside: FaceBox = { x: 0.82, y: 0.3, w: 0.16, h: 0.3 }

/** Feeds one sample a second: `plan` lists the faces seen at each second. */
function run(plan: FaceBox[][], opts: Partial<ConstructorParameters<typeof FaceMonitor>[0]> = {}) {
  const events: { type: FaceEvent; at: number }[] = []
  const m = new FaceMonitor({ absentMs: 10_000, emit: (type, at) => events.push({ type, at }), ...opts })
  plan.forEach((faces, i) => m.feed({ at: i * 1000, faces }))
  return events
}

const times = <T>(n: number, v: T) => Array.from({ length: n }, () => v)

describe('face presence: the debounce rules', () => {
  it('reports no face only after the configured seconds without one, once per absence', () => {
    assert.deepEqual(run(times(10, [])), [], 'nine seconds without a face is not yet an event')
    assert.deepEqual(run(times(11, [])).map((e) => e.type), ['face_absent'])
    // A long absence is one event, not one a second.
    assert.deepEqual(run(times(60, [])).map((e) => e.type), ['face_absent'])
    // Back at the screen, then away again: a second event.
    assert.deepEqual(run([...times(11, []), [centred], ...times(11, [])]).map((e) => e.type), ['face_absent', 'face_absent'])
  })

  it('a face that flickers out for a moment is not an absence', () => {
    const plan = Array.from({ length: 60 }, (_, i) => (i % 4 === 0 ? [] : [centred]))
    assert.deepEqual(run(plan), [])
  })

  it('reports more than one face after three seconds, once per episode', () => {
    assert.deepEqual(run(times(3, [centred, aside])), [], 'two seconds of two faces is not yet an event')
    assert.deepEqual(run(times(4, [centred, aside])).map((e) => e.type), ['multiple_faces'])
    assert.deepEqual(run(times(30, [centred, aside])).map((e) => e.type), ['multiple_faces'])
  })

  it('reports looking away after five seconds outside the central region, or turned aside', () => {
    assert.deepEqual(run(times(5, [aside])), [])
    assert.deepEqual(run(times(6, [aside])).map((e) => e.type), ['looking_away'])
    const turned: FaceBox = { ...centred, yaw: 0.9 }
    assert.deepEqual(run(times(6, [turned])).map((e) => e.type), ['looking_away'])
    assert.deepEqual(run(times(30, [centred])), [], 'a centred face looking at the screen raises nothing')
  })

  it('a slow device never floods the server: one event per episode whatever the rate', () => {
    const events: FaceEvent[] = []
    const m = new FaceMonitor({ absentMs: 10_000, emit: (t) => events.push(t) })
    for (let t = 0; t <= 120_000; t += 250) m.feed({ at: t, faces: [] })
    assert.deepEqual(events, ['face_absent'])
  })

  it('a gap in sampling (the tab was hidden) does not count as time without a face', () => {
    const events: FaceEvent[] = []
    const m = new FaceMonitor({ absentMs: 10_000, emit: (t) => events.push(t) })
    m.feed({ at: 0, faces: [] })
    m.feed({ at: 60_000, faces: [] })
    assert.deepEqual(events, [])
  })
})
