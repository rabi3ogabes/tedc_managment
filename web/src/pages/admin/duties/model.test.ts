import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { attendancePercent, filterGroups, focusOf, meFirst, nextUp, showsQr, todayKey, type DutyGroup, type DutySession } from './model.ts'

const session = (id: string, when: DutySession['when'], starts_at: string, extra: Partial<DutySession> = {}): DutySession => ({
  id, sequence: 1, title_ar: 'جلسة', title_en: 'Session', starts_at, ends_at: starts_at, status: 'scheduled', mode: 'in_person', when, focus: false, place: null, present: 0, expected: 0, ...extra,
})
const group = (id: string, capacities: DutyGroup['capacities'], phase: DutyGroup['phase'], sessions: DutySession[] = []): DutyGroup => ({
  id, capacities, phase, program: { id: `p-${id}`, code: id, title_ar: id, title_en: id, status: 'in_progress', delivery_mode: 'in_person' },
  group: { id, title_ar: id, title_en: id, status: 'running', only: true }, from: null, to: null, supervisor: null, trainers: [], rooms: [], trainees: 0, sessions, forms: null,
})

describe('«برامجي»: the session to act on', () => {
  it('is the one the server marked', () => {
    const g = group('a', ['trainer'], 'running', [session('s1', 'past', '2026-11-24T08:00'), session('s2', 'now', '2026-11-25T08:00', { focus: true })])
    assert.equal(focusOf(g)?.id, 's2')
    assert.equal(focusOf(group('b', ['trainer'], 'upcoming')), null)
  })
})

describe('«برامجي»: attendance', () => {
  it('is a whole percentage, and nobody expected is zero', () => {
    assert.equal(attendancePercent(2, 3), 67)
    assert.equal(attendancePercent(0, 0), 0)
    assert.equal(attendancePercent(30, 25), 100)
  })

  it('shows the attendance code for a session of today held in a room', () => {
    assert.equal(showsQr({ when: 'now', mode: 'in_person' }), true)
    assert.equal(showsQr({ when: 'today', mode: null }), true)
    assert.equal(showsQr({ when: 'later', mode: 'in_person' }), false)
    assert.equal(showsQr({ when: 'past', mode: 'in_person' }), false)
    assert.equal(showsQr({ when: 'now', mode: 'online' }), false)
  })
})

describe('«برامجي»: the filters', () => {
  const groups = [group('a', ['supervisor'], 'running'), group('b', ['trainer'], 'upcoming'), group('c', ['supervisor', 'trainer'], 'ended')]

  it('keeps everything when nothing is chosen', () => {
    assert.equal(filterGroups(groups, { capacity: 'all', phase: 'all' }).length, 3)
  })

  it('keeps the groups of one capacity, counting a person who is both in each', () => {
    assert.deepEqual(filterGroups(groups, { capacity: 'supervisor', phase: 'all' }).map((g) => g.id), ['a', 'c'])
    assert.deepEqual(filterGroups(groups, { capacity: 'trainer', phase: 'all' }).map((g) => g.id), ['b', 'c'])
  })

  it('combines the two filters', () => {
    assert.deepEqual(filterGroups(groups, { capacity: 'trainer', phase: 'ended' }).map((g) => g.id), ['c'])
    assert.deepEqual(filterGroups(groups, { capacity: 'supervisor', phase: 'upcoming' }), [])
  })
})

describe('«برامجي»: what to put on the dashboard', () => {
  it('is the session happening now, whichever group it is in', () => {
    const groups = [
      group('a', ['trainer'], 'running', [session('later', 'later', '2026-11-26T08:00')]),
      group('b', ['supervisor'], 'running', [session('now', 'now', '2026-11-24T08:00')]),
    ]
    assert.equal(nextUp(groups)?.session.id, 'now')
    assert.equal(nextUp(groups)?.group.id, 'b')
  })

  it('is otherwise the soonest session that has not ended', () => {
    const groups = [
      group('a', ['trainer'], 'upcoming', [session('old', 'past', '2026-11-01T08:00'), session('far', 'later', '2026-12-10T08:00')]),
      group('b', ['trainer'], 'upcoming', [session('near', 'later', '2026-11-27T08:00')]),
    ]
    assert.equal(nextUp(groups)?.session.id, 'near')
  })

  it('is nothing when every session is over', () => {
    assert.equal(nextUp([group('a', ['trainer'], 'ended', [session('old', 'past', '2026-11-01T08:00')])]), null)
    assert.equal(nextUp([]), null)
  })
})

describe('«برامجي»: counting today\'s sessions', () => {
  it('picks the wording Arabic uses for none, one, two, a few and many', () => {
    assert.deepEqual([0, 1, 2, 3, 10, 11, 40].map(todayKey), ['today0', 'today1', 'today2', 'todayFew', 'todayFew', 'todayMany', 'todayMany'])
  })
})

describe('«برامجي»: names', () => {
  it('puts the reader first and keeps the order of the others', () => {
    assert.deepEqual(meFirst([{ n: 'a', me: false }, { n: 'b', me: true }, { n: 'c', me: false }]).map((p) => p.n), ['b', 'a', 'c'])
  })
})
