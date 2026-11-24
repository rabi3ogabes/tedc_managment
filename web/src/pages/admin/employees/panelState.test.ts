import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { readPanel, writePanel } from './panelState.ts'

describe('the employee panel in the address of the page', () => {
  it('reads the employee and the view', () => {
    assert.deepEqual(readPanel('?employee=0193a1b2-aaaa-bbbb-cccc-1234567890ab'), { id: '0193a1b2-aaaa-bbbb-cccc-1234567890ab', view: 'passport' })
    assert.deepEqual(readPanel('?employee=abc&view=access'), { id: 'abc', view: 'access' })
    assert.deepEqual(readPanel('?employee=abc&view=anything'), { id: 'abc', view: 'passport' })
  })

  it('is closed when nothing, or something that is not an id, is given', () => {
    assert.equal(readPanel(''), null)
    assert.equal(readPanel('?view=access'), null)
    assert.equal(readPanel('?employee='), null)
    assert.equal(readPanel('?employee=../../users'), null)
    assert.equal(readPanel('?employee=%3Cscript%3E'), null)
  })

  it('writes the panel and keeps what else the page has in its address', () => {
    assert.equal(writePanel('', { id: 'abc', view: 'passport' }), '?employee=abc')
    assert.equal(writePanel('?q=noor&page=3', { id: 'abc', view: 'access' }), '?q=noor&page=3&employee=abc&view=access')
    assert.equal(writePanel('?employee=old&view=access&page=2', { id: 'new', view: 'passport' }), '?page=2&employee=new')
  })

  it('closes the panel without touching the rest', () => {
    assert.equal(writePanel('?page=2&employee=abc&view=access', null), '?page=2')
    assert.equal(writePanel('?employee=abc', null), '')
  })

  it('reads back what it wrote', () => {
    const panel = { id: '0193a1b2-aaaa-bbbb-cccc-1234567890ab', view: 'access' as const }
    assert.deepEqual(readPanel(writePanel('?page=4', panel)), panel)
  })
})
