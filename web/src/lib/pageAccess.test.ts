import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { draftOf, hide, levelOn, limitsAt, linked, savedOf, sameSaved, setAllLevels, setLevel, show, shown, type Draft, type Ground, type Page, type Saved } from './pageAccess.ts'

const page = (to: string, permission?: string, more: Partial<Page> = {}): Page => ({ to, permission, ...more })
const home = page('/portal')
const saber = page('/admin/saber', 'saber.view')
const absence = page('/admin/absence', 'attendance.manage|attendance.devices')
const attempts = page('/admin/attendance-attempts', 'attendance.manage')
const communication = page('/admin/communication', 'announcements.manage|announcements.publish|notifications.schedule')
const chats = page('/admin/chats', 'announcements.manage')
const programs = page('/admin/programs', 'programs.view')
const rooms = page('/admin/rooms', 'programs.view')
const hub = page('/admin/communities', 'plc.view')
const talk = page('/admin/discussions', undefined, { without: { permission: 'plc.view' } })
const reports = page('/portal/reports')
const ground: Ground = {
  pages: [home, saber, absence, attempts, communication, chats, programs, rooms, hub, talk, reports],
  known: ['saber.view', 'saber.program.create', 'saber.day.edit', 'attendance.manage', 'attendance.devices', 'announcements.manage', 'announcements.publish', 'notifications.schedule', 'programs.view', 'programs.manage', 'plc.view', 'plc.reports', 'leaves.manage'],
  onlyDoors: new Set(['saber.view', 'programs.view', 'plc.view']),
}
const none: Saved = { granted: [], revoked: [], hidden_pages: [], denied_actions: {} }
// The supervisor of the live site after the administrator trimmed him: سابر+, attendance and announcements.
const role = ['saber.view', 'saber.program.create', 'saber.day.edit', 'attendance.manage', 'announcements.manage', 'leaves.manage']
const start = (saved: Saved = none): Draft => draftOf(role, saved)
const names = (d: Draft) => ground.pages.filter((p) => shown(p, d)).map((p) => p.to)

describe('the pages a person is shown', () => {
  it('starts from the rights: every page a right opens, and the pages open to the whole role', () => {
    assert.deepEqual(names(start()), ['/portal', '/admin/saber', '/admin/absence', '/admin/attendance-attempts', '/admin/communication', '/admin/chats', '/admin/discussions', '/portal/reports'])
  })

  it('hides one of the pages a right opens and keeps the right for the others', () => {
    const d = hide(attempts, start(), ground)
    assert.equal(shown(attempts, d), false)
    assert.equal(shown(absence, d), true)
    assert.equal(d.rights.has('attendance.manage'), true)
    assert.deepEqual(savedOf(role, d, ground), { ...none, hidden_pages: ['/admin/attendance-attempts'] })
  })

  it('takes the right itself when the last page that uses it is hidden', () => {
    const d = hide(absence, hide(attempts, start(), ground), ground)
    assert.equal(d.rights.has('attendance.manage'), false)
    // Nothing is left to hide: the right is gone, so neither page would be listed anyway.
    assert.deepEqual(savedOf(role, d, ground), { ...none, revoked: ['attendance.manage'] })
  })

  it('takes the whole area with its only page', () => {
    const d = hide(saber, start(), ground)
    assert.deepEqual(savedOf(role, d, ground).revoked, ['saber.day.edit', 'saber.program.create', 'saber.view'])
    assert.equal(d.rights.has('leaves.manage'), true)
  })

  it('hides a page that asks for no right by its address alone', () => {
    const d = hide(reports, start(), ground)
    assert.deepEqual(savedOf(role, d, ground), { ...none, hidden_pages: ['/portal/reports'] })
    assert.deepEqual(savedOf(role, show(reports, d, ground), ground), none)
  })

  it('gives one page of a right without the others it would open', () => {
    const d = show(rooms, start(), ground)
    assert.equal(d.rights.has('programs.view'), true)
    assert.equal(shown(rooms, d), true)
    assert.equal(shown(programs, d), false, '«البرامج» was not asked for')
    assert.deepEqual(savedOf(role, d, ground), { ...none, granted: ['programs.view'], hidden_pages: ['/admin/programs'] })
    // Asking for the second page afterwards only lifts its hiding.
    assert.deepEqual(savedOf(role, show(programs, d, ground), ground), { ...none, granted: ['programs.view'] })
  })

  it('gives the right that only looks when a page names several', () => {
    const withView = page('/admin/kits', 'kits.manage|kits.view')
    const d = show(withView, start(), { ...ground, pages: [...ground.pages, withView] })
    assert.equal(d.rights.has('kits.view'), true)
    assert.equal(d.rights.has('kits.manage'), false)
  })

  it('leaves a stand-in page to whoever is not shown the page it stands in for', () => {
    const d = show(hub, start(), ground)
    assert.equal(shown(hub, d), true)
    assert.equal(shown(talk, d), false)
    assert.deepEqual(show(talk, d, ground), d, 'cannot be shown beside the hub')
    assert.equal(shown(talk, hide(hub, d, ground)), true)
  })

  it('puts a person back exactly as they were saved', () => {
    const saved: Saved = { granted: ['programs.view'], revoked: ['leaves.manage'], hidden_pages: ['/admin/programs'], denied_actions: { saber: ['delete'] } }
    assert.equal(sameSaved(savedOf(role, start(saved), ground), saved), true)
    assert.equal(sameSaved(savedOf(role, start(), ground), saved), false)
  })
})

describe('adding, editing and deleting on a page', () => {
  it('is all allowed for somebody who holds a right that changes things, until one is taken', () => {
    const d = start()
    assert.deepEqual(['add', 'edit', 'delete'].map((l) => levelOn(saber, l as 'add', d)), [true, true, true])
    const noDelete = setLevel(saber, 'delete', false, d, ground)
    assert.deepEqual(['add', 'edit', 'delete'].map((l) => levelOn(saber, l as 'add', noDelete)), [true, true, false])
    assert.deepEqual(savedOf(role, noDelete, ground), { ...none, denied_actions: { saber: ['delete'] } })
    assert.deepEqual(savedOf(role, setLevel(saber, 'delete', true, noDelete, ground), ground), none)
  })

  it('moves together on pages that share an area of rights', () => {
    const d = setLevel(chats, 'add', false, start(), ground)
    assert.equal(levelOn(communication, 'add', d), false)
    assert.deepEqual(linked(chats, ground).map((p) => p.to), ['/admin/communication'])
    assert.deepEqual(linked(reports, ground), [])
  })

  it('is off for somebody who can only look, and giving one action gives exactly that one', () => {
    const viewer = show(programs, start(), ground)
    assert.equal(levelOn(programs, 'edit', viewer), false)
    const editor = setLevel(programs, 'edit', true, viewer, ground)
    assert.equal(editor.rights.has('programs.manage'), true)
    assert.deepEqual(['add', 'edit', 'delete'].map((l) => levelOn(programs, l as 'add', editor)), [false, true, false])
    assert.deepEqual(savedOf(role, editor, ground).denied_actions, { programs: ['add', 'delete'] })
  })

  it('gives the other rights of an area that has no managing right', () => {
    const viewer = draftOf(['saber.view'], none)
    const d = setLevel(saber, 'add', true, viewer, ground)
    assert.deepEqual([...d.rights].sort(), ['saber.day.edit', 'saber.program.create', 'saber.view'])
    assert.deepEqual(d.denied, { saber: ['edit', 'delete'] })
  })

  it('makes every shown page for looking only, and gives everything back', () => {
    const d = setAllLevels(false, hide(chats, start(), ground), ground)
    assert.deepEqual(savedOf(role, d, ground).denied_actions, { announcements: ['add', 'edit', 'delete'], attendance: ['add', 'edit', 'delete'], saber: ['add', 'edit', 'delete'] })
    assert.deepEqual(savedOf(role, setAllLevels(true, d, ground), ground).denied_actions, {})
  })

  it('forgets a limit when the area is no longer held', () => {
    const d = hide(saber, setLevel(saber, 'delete', false, start(), ground), ground)
    assert.deepEqual(savedOf(role, d, ground).denied_actions, {})
  })
})

describe('what the person is told on the page', () => {
  it('names the actions taken from them, and nothing when another area of the page still allows it', () => {
    assert.deepEqual(limitsAt(chats, ['announcements.manage'], { announcements: ['add', 'delete'] }), ['add', 'delete'])
    assert.deepEqual(limitsAt(communication, ['announcements.manage', 'notifications.schedule'], { announcements: ['add'] }), [])
    assert.deepEqual(limitsAt(communication, ['announcements.manage'], { announcements: ['add'] }), ['add'])
    assert.deepEqual(limitsAt(chats, ['*'], { announcements: ['add'] }), [])
    assert.deepEqual(limitsAt(reports, ['announcements.manage'], { announcements: ['add'] }), [])
    assert.deepEqual(limitsAt(undefined, [], {}), [])
    // A page whose work is done by assignment, not by a right, says nothing.
    assert.deepEqual(limitsAt({ ...chats, plain: true }, ['announcements.manage'], { announcements: ['add'] }), [])
  })
})
