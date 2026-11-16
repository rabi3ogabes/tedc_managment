import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { daysBetween, nearestLit, neighbour, readTypedDate, relativeDay, shiftIso, type DayCell, type DayWindow } from './days.ts'

const TODAY = '2026-11-16'
const cell = (date: string, programs = 0, off = false): DayCell => ({ date, weekday: new Date(`${date}T00:00:00Z`).getUTCDay(), programs, sessions: programs, off, kind: off ? 'weekend' : 'workday', title: null })
// A row from Thursday 12 to Monday 23 November 2026 without its two weekends.
const win: DayWindow = {
  around: TODAY, today: TODAY, prev: '2026-11-11', next: '2026-11-24',
  days: [cell('2026-11-12'), cell('2026-11-15', 2), cell('2026-11-16'), cell('2026-11-17', 3), cell('2026-11-18'), cell('2026-11-19', 1), cell('2026-11-22'), cell('2026-11-23')],
}

describe('programmes by day: a date typed in the search box', () => {
  it('reads the day-first forms people write here', () => {
    assert.deepEqual(readTypedDate('16/11/2026', TODAY), { iso: '2026-11-16', complete: true })
    assert.deepEqual(readTypedDate(' 6-1-2027 ', TODAY), { iso: '2027-01-06', complete: true })
    assert.deepEqual(readTypedDate('16.11.2026', TODAY), { iso: '2026-11-16', complete: true })
    assert.deepEqual(readTypedDate('2026-11-16', TODAY), { iso: '2026-11-16', complete: true })
  })

  it('reads the digits of an Arabic keyboard', () => {
    assert.deepEqual(readTypedDate('١٦/١١/٢٠٢٦', TODAY), { iso: '2026-11-16', complete: true })
    assert.deepEqual(readTypedDate('۱۶/۱۱/۲۰۲۶', TODAY), { iso: '2026-11-16', complete: true })
  })

  it('takes day and month alone as the nearest such day, and does not call it complete', () => {
    assert.deepEqual(readTypedDate('17/11', TODAY), { iso: '2026-11-17', complete: false })
    assert.deepEqual(readTypedDate('1/9', TODAY), { iso: '2026-09-01', complete: false })
    // February is nine months behind in November: the one ahead is meant.
    assert.deepEqual(readTypedDate('5/2', TODAY), { iso: '2027-02-05', complete: false })
    assert.deepEqual(readTypedDate('29/2', '2027-06-01'), { iso: '2028-02-29', complete: false })
  })

  it('leaves alone what is not a date', () => {
    for (const text of ['', 'LDR-12', 'القيادة', '16', '16/', '16/11/26', '31/04/2026', '29/02/2027', '00/11/2026', '16/13/2026', '16/11/1999', '2026-11', '12-3']) {
      assert.equal(readTypedDate(text, TODAY), null, text)
    }
  })
})

describe('programmes by day: moving along the row', () => {
  it('goes to the shown day before or after, over the weekend', () => {
    assert.equal(neighbour(win, '2026-11-16', 1), '2026-11-17')
    assert.equal(neighbour(win, '2026-11-16', -1), '2026-11-15')
    assert.equal(neighbour(win, '2026-11-19', 1), '2026-11-22')
    assert.equal(neighbour(win, '2026-11-15', -1), '2026-11-12')
  })

  it('leaves the row through the day the server named', () => {
    assert.equal(neighbour(win, '2026-11-23', 1), '2026-11-24')
    assert.equal(neighbour(win, '2026-11-12', -1), '2026-11-11')
    assert.equal(neighbour({ ...win, next: null }, '2026-11-23', 1), null)
  })

  it('steps from a day that is not in the row (a day off picked from the calendar)', () => {
    assert.equal(neighbour(win, '2026-11-20', 1), '2026-11-22')
    assert.equal(neighbour(win, '2026-11-20', -1), '2026-11-19')
  })

  it('finds the nearest days with training around a day that has none', () => {
    const { before, after } = nearestLit(win, '2026-11-18')
    assert.equal(before?.date, '2026-11-17')
    assert.equal(after?.date, '2026-11-19')
    assert.deepEqual(nearestLit(win, '2026-11-22'), { before: win.days[5], after: null })
    assert.equal(nearestLit(win, '2026-11-12').before, null)
  })

  it('names the days around today', () => {
    assert.equal(relativeDay('2026-11-16', TODAY), 'today')
    assert.equal(relativeDay('2026-11-17', TODAY), 'tomorrow')
    assert.equal(relativeDay('2026-11-15', TODAY), 'yesterday')
    assert.equal(relativeDay('2026-11-18', TODAY), null)
  })

  it('counts and shifts whole days across a month and a year', () => {
    assert.equal(daysBetween('2026-11-16', '2026-12-01'), 15)
    assert.equal(daysBetween('2026-11-16', '2026-11-10'), -6)
    assert.equal(shiftIso('2026-11-30', 1), '2026-12-01')
    assert.equal(shiftIso('2027-01-01', -1), '2026-12-31')
  })
})
