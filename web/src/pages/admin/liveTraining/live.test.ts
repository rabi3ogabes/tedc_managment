import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { countdown, isFresh, lastBoundary, needsAttention, phaseOf, pollEvery, roomsOnTheClock, signalsFor, timing, trainersCheckIn } from './live.ts'
import type { BoardRoom, SessionBrief } from './types.ts'

const DAY = '2026-11-15'
const iso = (hm: string) => `${DAY}T${hm}:00+03:00`
const ms = (hm: string) => Date.parse(iso(hm))

const session = (id: string, from: string, to: string, over: Partial<SessionBrief> = {}): SessionBrief => ({
  id, title: null, sequence: 1, mode: 'in_person', state: 'upcoming', starts_at: iso(from), ends_at: iso(to), location: null,
  program: { id: `p-${id}`, code: null, title: 'برنامج' }, group: null,
  trainers: [{ id: 't1', name: 'مدرب', role: 'lead', photo: null, source: null, rating: null, programs_count: 3, checked_in: true }],
  supervisor: null, kit: null, counts: { expected: 20, present: 16, late: 1, absent: 0, left: 0, percent: 80 }, seating: null, present: [], ...over,
})

const room = (id: string, sessions: SessionBrief[], capacity = 30): BoardRoom => ({
  id, name: id, code: id, floor: 'G', office: null, building: null, capacity, room_type: 'training', state: 'idle', focus_session_id: null, sessions,
})

describe('live training: the board on the clock', () => {
  it('reads a session as coming, running or over at the minute it changes', () => {
    const s = session('a', '09:00', '11:00')
    assert.equal(phaseOf(s, ms('08:59')), 'upcoming')
    assert.equal(phaseOf(s, ms('09:00')), 'live')
    assert.equal(phaseOf(s, ms('10:59')), 'live')
    assert.equal(phaseOf(s, ms('11:00')), 'ended')
  })

  it('lights a room when its session starts, without waiting for the server', () => {
    const rooms = [room('R-1', [session('a', '09:00', '11:00')])]
    assert.equal(roomsOnTheClock(rooms, ms('07:30'))[0].state, 'later')
    assert.equal(roomsOnTheClock(rooms, ms('08:00'))[0].state, 'soon')
    const lit = roomsOnTheClock(rooms, ms('09:00'))[0]
    assert.equal(lit.state, 'live')
    assert.equal(lit.sessions[0].state, 'live')
    assert.equal(lit.focus_session_id, 'a')
    assert.equal(roomsOnTheClock(rooms, ms('11:00'))[0].state, 'ended')
  })

  it('shows the running session, else the next one, else the last one', () => {
    const rooms = [room('R-1', [session('a', '08:00', '10:00'), session('b', '12:00', '14:00')])]
    assert.equal(roomsOnTheClock(rooms, ms('09:00'))[0].focus_session_id, 'a')
    const between = roomsOnTheClock(rooms, ms('10:30'))[0]
    assert.equal(between.focus_session_id, 'b')
    assert.equal(between.state, 'later')
    assert.equal(roomsOnTheClock(rooms, ms('11:15'))[0].state, 'soon')
    const after = roomsOnTheClock(rooms, ms('15:00'))[0]
    assert.equal(after.focus_session_id, 'b')
    assert.equal(after.state, 'ended')
  })

  it('leaves a room without sessions dark and keeps a session object that did not change', () => {
    const kept = session('a', '09:00', '11:00', { state: 'live' })
    const [dark, lit] = roomsOnTheClock([room('R-1', []), room('R-2', [kept])], ms('10:00'))
    assert.equal(dark.state, 'idle')
    assert.equal(dark.focus_session_id, null)
    assert.equal(lit.sessions[0], kept)
  })

  it('moves its boundary only when something changes state', () => {
    const rooms = [room('R-1', [session('a', '09:00', '11:00')])]
    assert.equal(lastBoundary(rooms, [], ms('07:00')), 0)
    assert.equal(lastBoundary(rooms, [], ms('08:30')), ms('08:00'))
    assert.equal(lastBoundary(rooms, [], ms('09:00')), ms('09:00'))
    assert.equal(lastBoundary(rooms, [], ms('10:59')), ms('09:00'))
    assert.equal(lastBoundary(rooms, [session('x', '10:00', '10:30')], ms('10:45')), ms('10:30'))
    // The rooms worked out at the boundary are the rooms of every moment until the next one.
    const now = ms('10:20')
    assert.deepEqual(roomsOnTheClock(rooms, lastBoundary(rooms, [], now)), roomsOnTheClock(rooms, now))
  })
})

describe('live training: the running clock', () => {
  it('measures what is behind and what is left', () => {
    const s = session('a', '09:00', '11:00')
    assert.deepEqual(timing(s, ms('08:30')), { phase: 'upcoming', share: 0, leftMs: 30 * 60_000 })
    assert.deepEqual(timing(s, ms('09:30')), { phase: 'live', share: 0.25, leftMs: 90 * 60_000 })
    assert.deepEqual(timing(s, ms('12:00')), { phase: 'ended', share: 1, leftMs: 0 })
  })

  it('writes the time left as a clock', () => {
    assert.equal(countdown(0), '00:00')
    assert.equal(countdown(-5000), '00:00')
    assert.equal(countdown(65_000), '01:05')
    assert.equal(countdown(59 * 60_000 + 59_000), '59:59')
    assert.equal(countdown(3_600_000), '1:00:00')
    assert.equal(countdown(5_049_000), '1:24:09')
  })

  it('counts somebody as new for a few seconds only', () => {
    const now = ms('09:10')
    assert.equal(isFresh(new Date(now - 4_000).toISOString(), now), true)
    assert.equal(isFresh(new Date(now + 2_000).toISOString(), now), true)
    assert.equal(isFresh(new Date(now - 25_000).toISOString(), now), false)
    assert.equal(isFresh(null, now), false)
  })
})

describe('live training: what deserves a look', () => {
  const r = room('R-1', [], 30)
  const keys = (s: SessionBrief | null, at: string, checkIns = true) => signalsFor(r, s, ms(at), checkIns).map((x) => x.key)

  it('says nothing for a quiet room, a dark room or a session that is over', () => {
    assert.deepEqual(keys(session('a', '09:00', '11:00'), '09:30'), [])
    assert.deepEqual(keys(null, '09:30'), [])
    assert.deepEqual(keys(session('a', '09:00', '11:00', { trainers: [] }), '12:00'), [])
  })

  it('raises a session without a trainer, louder once it is running', () => {
    const s = session('a', '09:00', '11:00', { trainers: [] })
    assert.deepEqual(signalsFor(r, s, ms('07:00')), [])
    assert.deepEqual(signalsFor(r, s, ms('08:30')), [{ key: 'noTrainer', tone: 'warn' }])
    assert.deepEqual(signalsFor(r, s, ms('09:30'))[0], { key: 'noTrainer', tone: 'alert' })
  })

  it('raises a trainer who has not checked in, after a few minutes and only where trainers check in', () => {
    const s = session('a', '09:00', '11:00', { trainers: [{ id: 't1', name: 'مدرب', role: 'lead', photo: null, source: null, rating: null, programs_count: 0, checked_in: false }] })
    assert.deepEqual(keys(s, '09:03'), [])
    assert.deepEqual(keys(s, '09:06'), ['trainerAbsent'])
    assert.deepEqual(keys(s, '09:06', false), [])
  })

  it('tells an empty room from a thin one', () => {
    const empty = session('a', '09:00', '11:00', { counts: { expected: 20, present: 0, late: 0, absent: 0, left: 0, percent: 0 } })
    assert.deepEqual(keys(empty, '09:05'), [])
    assert.deepEqual(keys(empty, '09:12'), ['nobodyHere'])
    const thin = session('b', '09:00', '11:00', { counts: { expected: 20, present: 7, late: 0, absent: 0, left: 0, percent: 35 } })
    assert.deepEqual(keys(thin, '09:12'), [])
    assert.deepEqual(signalsFor(r, thin, ms('09:20')), [{ key: 'lowAttendance', tone: 'warn', n: 35 }])
  })

  it('raises more trainees than seats', () => {
    const s = session('a', '09:00', '11:00', { counts: { expected: 34, present: 30, late: 0, absent: 0, left: 0, percent: 88 } })
    assert.deepEqual(signalsFor(r, s, ms('09:30')), [{ key: 'overCapacity', tone: 'warn', n: 4 }])
    assert.deepEqual(signalsFor({ capacity: 0 }, s, ms('09:30')), [])
  })

  it('announces a start and an end that are minutes away, after anything that is wrong', () => {
    assert.deepEqual(signalsFor(r, session('a', '09:00', '11:00'), ms('08:50')), [{ key: 'startingSoon', tone: 'info', n: 10 }])
    assert.deepEqual(signalsFor(r, session('a', '09:00', '11:00'), ms('10:53')), [{ key: 'endingSoon', tone: 'info', n: 7 }])
    const thin = session('b', '09:00', '11:00', { counts: { expected: 20, present: 7, late: 0, absent: 0, left: 0, percent: 35 } })
    assert.deepEqual(keys(thin, '10:55'), ['lowAttendance', 'endingSoon'])
  })

  it('asks for attention only when something is wrong', () => {
    assert.equal(needsAttention([{ key: 'endingSoon', tone: 'info', n: 3 }]), false)
    assert.equal(needsAttention([{ key: 'lowAttendance', tone: 'warn', n: 30 }]), true)
    assert.equal(needsAttention([]), false)
  })

  it('knows whether trainers check in on this day', () => {
    const off = { id: 't1', name: 'م', role: 'lead', photo: null, source: null, rating: null, programs_count: 0, checked_in: false }
    assert.equal(trainersCheckIn([room('R-1', [session('a', '09:00', '11:00', { trainers: [off] })])], []), false)
    assert.equal(trainersCheckIn([room('R-1', [session('a', '09:00', '11:00', { trainers: [off] })])], [session('b', '09:00', '11:00')]), true)
  })
})

describe('live training: how often to ask again', () => {
  const rooms = [room('R-1', [session('a', '09:00', '11:00')])]

  it('asks every five seconds around a session and slower away from one', () => {
    assert.equal(pollEvery(rooms, [], ms('07:00')), 15_000)
    assert.equal(pollEvery(rooms, [], ms('08:50')), 5_000)
    assert.equal(pollEvery(rooms, [], ms('10:00')), 5_000)
    assert.equal(pollEvery(rooms, [], ms('11:03')), 5_000)
    assert.equal(pollEvery(rooms, [], ms('11:30')), 30_000)
    assert.equal(pollEvery([room('R-1', [])], [], ms('10:00')), 30_000)
    assert.equal(pollEvery([room('R-1', [])], [session('x', '09:00', '11:00')], ms('10:00')), 5_000)
  })
})
