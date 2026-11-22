import assert from 'node:assert/strict'
import { describe, it } from 'node:test'

/** A browser storage, small enough for the test. */
const storage = () => {
  const data = new Map<string, string>()
  return { getItem: (k: string) => data.get(k) ?? null, setItem: (k: string, v: string) => void data.set(k, v), removeItem: (k: string) => void data.delete(k) }
}

describe('the role a tab works in', () => {
  it('belongs to the tab, not to the whole browser', async () => {
    // The browser's storage: one shared by every tab, with a value an earlier version left there, and one per tab.
    const shared = storage()
    shared.setItem('tedc.activeRole', 'grant-left-by-an-earlier-version')
    const firstTab = storage()
    Object.assign(globalThis, { localStorage: shared, sessionStorage: firstTab })
    const { activeRoleStore } = await import('./activeRole.ts')

    // What an earlier version shared between the tabs is gone, and is not taken for this tab's role.
    assert.equal(shared.getItem('tedc.activeRole'), null)
    assert.equal(activeRoleStore.get(), null)

    activeRoleStore.set('supervisor-grant')
    assert.equal(activeRoleStore.get(), 'supervisor-grant')
    assert.equal(firstTab.getItem('tedc.activeRole'), 'supervisor-grant')
    assert.equal(shared.getItem('tedc.activeRole'), null, 'nothing is written where other tabs would read it')

    // Another tab of the same browser has its own storage: it does not see the first tab's role, and its own does not leak back.
    const secondTab = storage()
    Object.assign(globalThis, { sessionStorage: secondTab })
    assert.equal(activeRoleStore.get(), null)
    activeRoleStore.set('employee-grant')
    Object.assign(globalThis, { sessionStorage: firstTab })
    assert.equal(activeRoleStore.get(), 'supervisor-grant')

    activeRoleStore.set(null)
    assert.equal(activeRoleStore.get(), null)
  })
})
