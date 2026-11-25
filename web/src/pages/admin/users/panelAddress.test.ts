import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { readUserPanel, writeUserPanel } from './panelAddress.ts'

describe('the user panel in the address of the page', () => {
  it('reads the user and the view, the pages first', () => {
    assert.deepEqual(readUserPanel('?tab=users&user=0193a1b2-aaaa-bbbb-cccc-1234567890ab'), { id: '0193a1b2-aaaa-bbbb-cccc-1234567890ab', view: 'pages' })
    assert.deepEqual(readUserPanel('?user=abc&view=rights'), { id: 'abc', view: 'rights' })
    assert.deepEqual(readUserPanel('?user=abc&view=access'), { id: 'abc', view: 'access' })
    assert.deepEqual(readUserPanel('?user=abc&view=roles'), { id: 'abc', view: 'roles' })
    assert.deepEqual(readUserPanel('?user=abc&view=anything'), { id: 'abc', view: 'pages' })
  })

  it('is closed when nothing, or something that is not an id, is given', () => {
    assert.equal(readUserPanel('?tab=users'), null)
    assert.equal(readUserPanel('?user='), null)
    assert.equal(readUserPanel('?user=../../roles'), null)
    assert.equal(readUserPanel('?user=%3Cscript%3E'), null)
  })

  it('keeps the tab of the settings page and whatever else is in the address', () => {
    assert.equal(writeUserPanel('?tab=users', { id: 'abc', view: 'pages' }), '?tab=users&user=abc')
    assert.equal(writeUserPanel('?tab=users', { id: 'abc', view: 'rights' }), '?tab=users&user=abc&view=rights')
    assert.equal(writeUserPanel('?tab=users&user=old&view=access', { id: 'new', view: 'roles' }), '?tab=users&user=new&view=roles')
    assert.equal(writeUserPanel('', { id: 'abc', view: 'access' }), '?user=abc&view=access')
  })

  it('closes the panel without touching the rest', () => {
    assert.equal(writeUserPanel('?tab=users&user=abc&view=access', null), '?tab=users')
    assert.equal(writeUserPanel('?user=abc', null), '')
  })

  it('reads back what it wrote', () => {
    const panel = { id: '0193a1b2-aaaa-bbbb-cccc-1234567890ab', view: 'access' as const }
    assert.deepEqual(readUserPanel(writeUserPanel('?tab=users', panel)), panel)
  })
})
