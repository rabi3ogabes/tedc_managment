import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { queryKeyFor } from './queryKey.ts'

const SUPERVISOR = '01a2017a-4c9a-7b85-a196-5c5d6b65ac5f'
const EMPLOYEE = '01a2017a-4c99-7af1-9994-9fc27e34fdb5'

describe('what a GET is remembered under', () => {
  it('keeps the two roles of one person apart', () => {
    const asSupervisor = queryKeyFor('/dashboard', undefined, 'ar', SUPERVISOR)
    const asEmployee = queryKeyFor('/dashboard', undefined, 'ar', EMPLOYEE)
    assert.notDeepEqual(asSupervisor, asEmployee)
    assert.deepEqual(asSupervisor, ['/dashboard', undefined, 'ar', SUPERVISOR])
    assert.deepEqual(queryKeyFor('/me/home', { page: 2 }, 'en', EMPLOYEE), ['/me/home', { page: 2 }, 'en', EMPLOYEE])
  })

  it('still starts with the address, so a change can refresh every copy of it', () => {
    // invalidateQueries({ queryKey: ['/me/menu-layout'] }) and the prefix checks on queryKey[0] rely on this.
    assert.equal(queryKeyFor('/me/menu-layout', undefined, 'ar', SUPERVISOR)[0], '/me/menu-layout')
  })

  it('leaves public data under the key it is fetched ahead and remembered with', () => {
    assert.deepEqual(queryKeyFor('/public/home', undefined, 'ar', SUPERVISOR), ['/public/home', undefined, 'ar'])
    assert.deepEqual(queryKeyFor('/public/programs/LDR-1', undefined, 'en', EMPLOYEE), ['/public/programs/LDR-1', undefined, 'en'])
  })

  it('has no role to add before anyone has signed in, or for a query that is switched off', () => {
    assert.deepEqual(queryKeyFor('/me/home', undefined, 'ar', null), ['/me/home', undefined, 'ar'])
    assert.deepEqual(queryKeyFor(null, undefined, 'ar', SUPERVISOR), [null, undefined, 'ar'])
  })
})
