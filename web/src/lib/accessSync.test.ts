import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { accessChanged, dueForSync, SYNC_GAP } from './accessSync.ts'
import type { RoleGrant } from './types.ts'

const grant = (id: string, slug: string, extra: Partial<RoleGrant> = {}): RoleGrant => ({
  id, slug, name: slug, name_ar: slug, name_en: slug, scope_type: 'ministry', scope_id: null, scope_label_ar: 'الوزارة', scope_label_en: 'Ministry',
  landing_route: '/portal', expires_at: null, active: false, ...extra,
})
const supervisor = grant('g1', 'supervisor', { active: true })
const employee = grant('g2', 'employee')
const me = (permissions: string[], roles = [supervisor, employee], active: RoleGrant | null = supervisor) => ({ roles, active_role: active, permissions })

describe('whether the rights of an open page changed', () => {
  it('is quiet when the same rights come back in another order', () => {
    assert.equal(accessChanged(me(['saber.view', 'kits.view']), me(['kits.view', 'saber.view'], [employee, supervisor])), false)
  })

  it('sees a right given to the role in Settings → Roles & permissions', () => {
    assert.equal(accessChanged(me(['employees.view']), me(['employees.view', 'saber.view'])), true)
  })

  it('sees a right taken away', () => {
    assert.equal(accessChanged(me(['employees.view', 'saber.view']), me(['employees.view'])), true)
  })

  it('sees a role granted, revoked, or moved to another home', () => {
    assert.equal(accessChanged(me([]), me([], [supervisor])), true)
    assert.equal(accessChanged(me([]), me([], [supervisor, employee, grant('g3', 'trainer')])), true)
    assert.equal(accessChanged(me([]), me([], [{ ...supervisor, landing_route: '/admin' }, employee])), true)
  })

  it('sees the working role change on the server (the role the tab worked in was revoked)', () => {
    assert.equal(accessChanged(me([]), me([], [supervisor, employee], employee)), true)
  })
})

describe('how often an open page asks', () => {
  it('asks again only after the gap, so looking at the tab many times costs one request', () => {
    assert.equal(dueForSync(1_000, 1_000 + SYNC_GAP - 1), false)
    assert.equal(dueForSync(1_000, 1_000 + SYNC_GAP), true)
  })
})
