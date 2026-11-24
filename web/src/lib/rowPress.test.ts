import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { opensRow } from './rowPress.ts'

describe('a press on a row', () => {
  const on = (hit: boolean) => ({ closest: () => (hit ? {} : null) })

  it('opens the row when it lands on the row itself', () => {
    assert.equal(opensRow(on(false), ''), true)
  })

  it('leaves links, buttons and fields to do their own work', () => {
    assert.equal(opensRow(on(true), ''), false)
  })

  it('does nothing while text of the row is selected, so a name or a number can be copied', () => {
    assert.equal(opensRow(on(false), '10293847'), false)
    assert.equal(opensRow(on(false), '   '), true)
  })

  it('copes with a press that has no element behind it', () => {
    assert.equal(opensRow(null, ''), true)
  })
})
