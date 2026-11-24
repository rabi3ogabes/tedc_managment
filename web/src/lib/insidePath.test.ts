import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { insidePath } from './insidePath.ts'

const back = '/admin/users'

describe('an address to return to', () => {
  it('keeps a path of this site, with its query', () => {
    assert.equal(insidePath('/admin/employees?employee=abc&view=access', back), '/admin/employees?employee=abc&view=access')
    assert.equal(insidePath('/admin/settings?tab=users', back), '/admin/settings?tab=users')
    assert.equal(insidePath('/', back), '/')
  })

  it('refuses another site', () => {
    assert.equal(insidePath('https://example.com/admin', back), back)
    assert.equal(insidePath('//example.com/admin', back), back)
    assert.equal(insidePath('/\\example.com', back), back)
    assert.equal(insidePath('/admin\\..\\x', back), back)
  })

  it('refuses a script, a relative path and what is not text', () => {
    assert.equal(insidePath('javascript:alert(1)', back), back)
    assert.equal(insidePath('admin/users', back), back)
    assert.equal(insidePath('', back), back)
    assert.equal(insidePath(undefined, back), back)
    assert.equal(insidePath({ to: '/admin' }, back), back)
    assert.equal(insidePath('/admin/\nusers', back), back)
  })
})
