import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { isStaleCodeError } from './staleCode.ts'

describe('isStaleCodeError', () => {
  it('recognises a page file that a new deployment removed, in every browser', () => {
    for (const m of [
      'Failed to fetch dynamically imported module: https://x/assets/Page-abc.js',
      'Importing a module script failed.',
      'error loading dynamically imported module',
      'Unable to preload CSS for /assets/Page-abc.css',
      'can\'t access property "default", e._result is undefined',
      "Cannot read properties of undefined (reading 'default')",
    ]) {
      assert.equal(isStaleCodeError(new Error(m)), true, m)
    }
  })
  it('leaves real bugs alone', () => {
    assert.equal(isStaleCodeError(new Error("Cannot read properties of undefined (reading 'name')")), false)
    assert.equal(isStaleCodeError(new Error('Network Error')), false)
    assert.equal(isStaleCodeError(undefined), false)
  })
})
