import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { showsOwnTraining } from './portalHome.ts'

describe('the home page of the portal: whose content', () => {
  it('shows a supervisor who is also a trainee their own training only as a trainee', () => {
    const held = ['supervisor', 'employee']
    assert.equal(showsOwnTraining('supervisor', held), false)
    assert.equal(showsOwnTraining('employee', held), true)
  })

  it('shows a trainee their own training', () => {
    assert.equal(showsOwnTraining('employee', ['employee']), true)
  })

  it('keeps it for somebody who has no trainee role to switch to', () => {
    assert.equal(showsOwnTraining('supervisor', ['supervisor']), true)
    assert.equal(showsOwnTraining('school_admin', ['school_admin', 'academic_deputy']), true)
    assert.equal(showsOwnTraining(null, []), true)
  })

  it('follows the same rule for any other role held beside the trainee role', () => {
    assert.equal(showsOwnTraining('school_admin', ['school_admin', 'employee']), false)
    assert.equal(showsOwnTraining('trainer', ['employee', 'trainer']), false)
  })
})
