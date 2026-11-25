import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { effectiveRights, isCustomised, overridesFor, rightState, sameOverrides } from './userRights.ts'

const role = ['employees.view', 'saber.view', 'surveys.view']

describe('rights of one person in a role', () => {
  it('are the role\'s rights when nothing was added or taken', () => {
    assert.deepEqual([...effectiveRights(role, { granted: [], revoked: [] })].sort(), [...role].sort())
    assert.equal(isCustomised({ granted: [], revoked: [] }), false)
  })

  it('gain what was added and lose what was taken', () => {
    const rights = effectiveRights(role, { granted: ['certificates.view'], revoked: ['saber.view'] })
    assert.equal(rights.has('certificates.view'), true)
    assert.equal(rights.has('saber.view'), false)
    assert.equal(rights.has('employees.view'), true)
    assert.equal(isCustomised({ granted: ['certificates.view'], revoked: [] }), true)
  })

  it('turn the ticked set back into the two lists the server keeps', () => {
    const wanted = new Set(['employees.view', 'surveys.view', 'kits.view', 'certificates.view'])
    assert.deepEqual(overridesFor(role, wanted), { granted: ['certificates.view', 'kits.view'], revoked: ['saber.view'] })
    // Ticking exactly the role's rights is no change at all.
    assert.deepEqual(overridesFor(role, new Set(role)), { granted: [], revoked: [] })
  })

  it('go there and back unchanged', () => {
    const saved = { granted: ['kits.view'], revoked: ['employees.view'] }
    assert.deepEqual(overridesFor(role, effectiveRights(role, saved)), saved)
  })

  it('name how each right stands', () => {
    const own = new Set(role)
    const wanted = new Set(['employees.view', 'surveys.view', 'kits.view'])
    assert.equal(rightState('employees.view', own, wanted), 'role')
    assert.equal(rightState('saber.view', own, wanted), 'taken')
    assert.equal(rightState('kits.view', own, wanted), 'added')
    assert.equal(rightState('audit.view', own, wanted), 'none')
  })

  it('compare two changes whatever their order', () => {
    assert.equal(sameOverrides({ granted: ['a', 'b'], revoked: ['c'] }, { granted: ['b', 'a'], revoked: ['c'] }), true)
    assert.equal(sameOverrides({ granted: ['a'], revoked: [] }, { granted: [], revoked: ['a'] }), false)
    assert.equal(sameOverrides({ granted: [], revoked: [] }, { granted: [], revoked: [] }), true)
  })
})
