import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { allowed, holds, landsOnPortal, onlyDoors, pageRightOf, visibleGroups } from './menuRules.ts'

const on = () => true
const flags = (set: string[]) => (f?: string) => !f || f.split('|').some((x) => set.includes(x))

describe('which buttons a role gets', () => {
  it('shows a page to whoever holds the right it asks for, whatever the role is called', () => {
    const saber = { to: '/admin/saber', permission: 'saber.view' }
    assert.equal(allowed(saber, holds(['employees.view']), on), false)
    // The administrator ticks «سابر+» for the role in Settings → Roles & permissions: the button follows.
    assert.equal(allowed(saber, holds(['employees.view', 'saber.view']), on), true)
  })

  it('accepts any one of several rights, and everything for the system administrator', () => {
    const absence = { permission: 'attendance.manage|attendance.devices' }
    assert.equal(allowed(absence, holds(['attendance.devices']), on), true)
    assert.equal(allowed(absence, holds(['kits.view']), on), false)
    assert.equal(allowed(absence, holds(['*']), on), true)
  })

  it('keeps a page with no right of its own, and hides one whose feature is off', () => {
    assert.equal(allowed({}, holds([]), on), true)
    assert.equal(allowed({ permission: 'surveys.view', feature: 'survey_studio_v2' }, holds(['surveys.view']), flags([])), false)
    assert.equal(allowed({ permission: 'surveys.view', feature: 'survey_studio_v2' }, holds(['surveys.view']), flags(['survey_studio_v2'])), true)
    assert.equal(allowed({ feature: 'plc|forums' }, holds([]), flags(['forums'])), true)
  })

  it('shows a stand-in only to whoever is not shown the button it stands in for', () => {
    // «مجتمعات التعلم المهنية» needs its right and its feature; «المجتمعات والمنتديات» is for everybody else.
    const hub = { permission: 'plc.view', feature: 'plc' }
    const discussions = { feature: 'plc|forums', without: hub }
    const plc = flags(['plc'])
    assert.equal(allowed(hub, holds([]), plc), false)
    assert.equal(allowed(discussions, holds([]), plc), true)
    assert.equal(allowed(hub, holds(['plc.view']), plc), true)
    assert.equal(allowed(discussions, holds(['plc.view']), plc), false)
    // The feature of the hub is off but forums are on: a holder of the right still reaches the discussions.
    assert.equal(allowed(hub, holds(['plc.view']), flags(['forums'])), false)
    assert.equal(allowed(discussions, holds(['plc.view']), flags(['forums'])), true)
    // Neither feature: nothing.
    assert.equal(allowed(discussions, holds([]), flags([])), false)
  })

  it('drops a section that is left empty', () => {
    const groups: { title: string; items: { to: string; permission?: string }[] }[] = [
      { title: 'portal', items: [{ to: '/portal' }] },
      { title: 'work', items: [{ to: '/admin/saber', permission: 'saber.view' }, { to: '/admin/kits', permission: 'kits.view' }] },
    ]
    assert.deepEqual(visibleGroups(groups, holds([]), on).map((g) => g.title), ['portal'])
    assert.deepEqual(visibleGroups(groups, holds(['saber.view']), on)[1].items.map((i) => i.to), ['/admin/saber'])
  })

  it('knows a role whose home is the portal', () => {
    assert.equal(landsOnPortal('/portal'), true)
    assert.equal(landsOnPortal('/admin/programs'), false)
    assert.equal(landsOnPortal(null), false)
  })
})

describe('the right that opens the page of an area', () => {
  const pages = onlyDoors(['saber.view', 'programs.view', 'programs.view|rooms.book', 'kits.view', 'kits.manage', 'analytics.view', 'analytics.executive', 'attendance.manage', undefined])

  it('pairs an area whose only door is its view right', () => {
    assert.deepEqual([...pages].sort(), ['programs.view', 'saber.view'])
    assert.equal(pageRightOf('saber.program.create', pages), 'saber.view')
    assert.equal(pageRightOf('programs.manage', pages), 'programs.view')
  })

  it('leaves an area with another door alone, so nothing is opened that nobody chose', () => {
    assert.equal(pageRightOf('analytics.executive', pages), null)
    assert.equal(pageRightOf('kits.review', pages), null)
    assert.equal(pageRightOf('attendance.manage', pages), null)
  })

  it('never pairs the page right with itself', () => {
    assert.equal(pageRightOf('saber.view', pages), null)
  })
})
