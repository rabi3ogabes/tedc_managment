import { allowed, holds, type Gated } from './menuRules.ts'

/**
 * «الصفحات» of one person: the administrator works with pages (shown or not, and whether the person may add, edit and
 * delete on them); the server keeps rights. These functions go between the two, with nothing drawn here, so every rule
 * is tested alone.
 *
 * What is kept for a person in one role:
 *  - `rights`: the rights they end up with (the role's, plus what was added, minus what was taken);
 *  - `hidden`: pages they are not shown although a right they keep would list them. One right can open several pages,
 *    and this is how one of them is given without the others;
 *  - `denied`: per area of rights, which of add / edit / delete they may not do. It only takes away.
 */
export type Level = 'add' | 'edit' | 'delete'
export const LEVELS: readonly Level[] = ['add', 'edit', 'delete']

/** A page of the menu: its address and what it asks for. `plain`: a page that only shows things (a dashboard), with nothing to add, edit or delete. */
export type Page = Gated & { to: string; plain?: boolean }
export type Draft = { rights: ReadonlySet<string>; hidden: ReadonlySet<string>; denied: Readonly<Record<string, readonly Level[]>> }
export type Saved = { granted: string[]; revoked: string[]; hidden_pages: string[]; denied_actions: Record<string, Level[]> }
/** What the editor needs to know about the system: the pages of the role's menu, every right, and the areas with one door. */
export type Ground = { pages: readonly Page[]; known: readonly string[]; onlyDoors: ReadonlySet<string> }

/** The area a right belongs to: `saber.day.edit` → `saber`. */
export const areaOf = (slug: string) => slug.split('.')[0]
/** The rights that open a page, in the order the page names them (none: the page is open to the whole role). */
export const doorsOf = (page: Page): string[] => page.permission?.split('|') ?? []
export const areasOf = (page: Page): string[] => [...new Set(doorsOf(page).map(areaOf))]
/** A right that changes something, as opposed to one that only lets the person look. */
const writes = (slug: string) => !slug.endsWith('.view')

/** Whether the rights alone would list the page (features are a separate switch, for everybody). */
export const opens = (page: Page, rights: ReadonlySet<string>) => allowed(page, holds([...rights]), () => true)
/** Whether the person is shown the page. */
export const shown = (page: Page, d: Draft) => opens(page, d.rights) && !d.hidden.has(page.to)
/** A page that stands in for another (`without`) and is not shown because the person gets that other one. */
export const replaced = (page: Page, d: Draft) => !!page.without && allowed(page.without, holds([...d.rights]), () => true)

/** The areas of the page in which the person holds a right that changes something. */
export const writeAreas = (page: Page, d: Draft): string[] => areasOf(page).filter((a) => [...d.rights].some((r) => areaOf(r) === a && writes(r)))
/** Whether the person may do this on the page: they hold a right that changes things there, and the action was not taken. */
export const levelOn = (page: Page, level: Level, d: Draft): boolean => shown(page, d) && writeAreas(page, d).some((a) => !(d.denied[a] ?? []).includes(level))
/**
 * Whether the page has anything to add, edit or delete for this person: they hold a right that changes things there, or
 * one could be given. A page whose areas only have rights to look (a dashboard) has no such switches.
 */
export const hasLevels = (page: Page, d: Draft, g: Ground): boolean =>
  !page.plain && (writeAreas(page, d).length > 0 || areasOf(page).some((a) => [...d.rights].some((r) => areaOf(r) === a) && g.known.some((k) => areaOf(k) === a && writes(k))))
/** The other pages whose add / edit / delete move with this one, because they share an area of rights. */
export const linked = (page: Page, g: Ground): Page[] => { const mine = areasOf(page); return mine.length ? g.pages.filter((q) => q.to !== page.to && !q.plain && areasOf(q).some((a) => mine.includes(a))) : [] }

/** The right given when a page is switched on for somebody who holds none of its rights: the one that only looks, when there is one. */
export const doorToGive = (page: Page): string | null => { const doors = doorsOf(page); return doors.find((x) => !writes(x)) ?? doors[0] ?? null }

/** Shows the page. A right is given when none of the page's rights is held; the other pages it would open stay as they were. */
export function show(page: Page, d: Draft, g: Ground): Draft {
  const hidden = new Set(d.hidden)
  hidden.delete(page.to)
  if (opens(page, d.rights)) return { ...d, hidden }
  const door = doorToGive(page)
  if (!door || replaced(page, d)) return d
  const before = new Set(g.pages.filter((q) => opens(q, d.rights)).map((q) => q.to))
  const rights = new Set(d.rights).add(door)
  // "Exactly what was ticked": a page this right also opens was not asked for, so it starts hidden.
  for (const q of g.pages) if (q.to !== page.to && !before.has(q.to) && opens(q, rights)) hidden.add(q.to)
  return clean({ rights, hidden, denied: d.denied })
}

/**
 * Hides the page. A right that opened it is taken when no page still shown uses it, so the data behind the page is
 * closed and not just the button; when another shown page needs the right, the right stays and the page is hidden.
 */
export function hide(page: Page, d: Draft, g: Ground): Draft {
  const rights = new Set(d.rights)
  const hidden = new Set(d.hidden)
  for (const door of doorsOf(page)) {
    if (!rights.has(door) || g.pages.some((q) => q.to !== page.to && doorsOf(q).includes(door) && shown(q, d))) continue
    rights.delete(door)
    // The area's actions do nothing without its only page (`saber.view` gone: `saber.program.create` goes with it).
    if (g.onlyDoors.has(door)) for (const r of [...rights]) if (areaOf(r) === areaOf(door)) rights.delete(r)
  }
  if (opens(page, rights)) hidden.add(page.to)
  return clean({ rights, hidden, denied: d.denied })
}

/**
 * Sets whether the person may add, edit or delete on the page. Taking an action only records it. Giving one to somebody
 * who can only look gives the area's managing right (or, in an area without one, its other rights) and takes the two
 * actions that were not asked for, so the page ends with exactly the one that was pressed.
 */
export function setLevel(page: Page, level: Level, on: boolean, d: Draft, g: Ground): Draft {
  if (!shown(page, d)) return d
  const denied: Record<string, Level[]> = Object.fromEntries(Object.entries(d.denied).map(([a, l]) => [a, [...l]]))
  const without = (a: string, l: Level) => { denied[a] = (denied[a] ?? []).filter((x) => x !== l) }
  const withIt = (a: string, l: Level) => { denied[a] = LEVELS.filter((x) => x === l || (denied[a] ?? []).includes(x)) }
  const held = writeAreas(page, d)
  if (!on) {
    for (const a of held) withIt(a, level)
    return clean({ ...d, denied })
  }
  if (held.length) {
    for (const a of held) without(a, level)
    return clean({ ...d, denied })
  }
  const rights = new Set(d.rights)
  for (const a of areasOf(page).filter((x) => [...d.rights].some((r) => areaOf(r) === x))) {
    const manage = `${a}.manage`
    for (const r of g.known.includes(manage) ? [manage] : g.known.filter((k) => areaOf(k) === a && writes(k))) rights.add(r)
    denied[a] = LEVELS.filter((x) => x !== level)
  }
  return clean({ rights, hidden: d.hidden, denied })
}

/** Every shown page for looking only, or every action given back. */
export function setAllLevels(on: boolean, d: Draft, g: Ground): Draft {
  if (on) return { ...d, denied: {} }
  const denied: Record<string, Level[]> = {}
  for (const page of g.pages) if (shown(page, d)) for (const a of writeAreas(page, d)) denied[a] = [...LEVELS]
  return clean({ ...d, denied: { ...d.denied, ...denied } })
}

/** Drops what no longer says anything: an action taken in an area the person holds nothing in. */
function clean(d: Draft): Draft {
  const areas = new Set([...d.rights].map(areaOf))
  const denied = Object.fromEntries(Object.entries(d.denied).filter(([a, l]) => areas.has(a) && l.length).map(([a, l]) => [a, LEVELS.filter((x) => l.includes(x))]))
  return { rights: d.rights, hidden: d.hidden, denied }
}

/** The person as saved: the role's rights with what was added and taken, their hidden pages and their limits. */
export function draftOf(roleRights: readonly string[], saved: Saved): Draft {
  const rights = new Set(roleRights)
  for (const slug of saved.granted) rights.add(slug)
  for (const slug of saved.revoked) rights.delete(slug)
  return { rights, hidden: new Set(saved.hidden_pages), denied: saved.denied_actions }
}

/** What is sent to the server for a draft. A page is only kept hidden while a right would list it. */
export function savedOf(roleRights: readonly string[], d: Draft, g: Ground): Saved {
  const own = new Set(roleRights)
  const tidy = clean(d)
  return {
    granted: [...d.rights].filter((slug) => !own.has(slug)).sort(),
    revoked: roleRights.filter((slug) => !d.rights.has(slug)).sort(),
    hidden_pages: g.pages.filter((p) => d.hidden.has(p.to) && opens(p, d.rights)).map((p) => p.to).sort(),
    denied_actions: Object.fromEntries(Object.entries(tidy.denied).sort(([a], [b]) => a.localeCompare(b)).map(([a, l]) => [a, [...l]])),
  }
}

export const sameSaved = (a: Saved, b: Saved) => JSON.stringify(normal(a)) === JSON.stringify(normal(b))
const normal = (s: Saved) => ({
  granted: [...new Set(s.granted)].sort(), revoked: [...new Set(s.revoked)].sort(), hidden: [...new Set(s.hidden_pages)].sort(),
  denied: Object.entries(s.denied_actions).filter(([, l]) => l.length).sort(([a], [b]) => a.localeCompare(b)).map(([a, l]) => [a, LEVELS.filter((x) => l.includes(x))]),
})

/** Whether the person differs from their role in anything. */
export const customised = (s: Saved) => s.granted.length + s.revoked.length + s.hidden_pages.length + Object.keys(s.denied_actions).length > 0

/**
 * What the signed-in person may not do on the page they are on, for the note under the header: the actions taken from
 * them in every area of the page they hold a right in. Empty when nothing was taken, or when another area still allows it.
 */
export function limitsAt(page: Page | undefined, rights: readonly string[], denied: Readonly<Record<string, readonly string[]>>): Level[] {
  if (!page || page.plain || rights.includes('*')) return []
  const held = areasOf(page).filter((a) => rights.some((r) => areaOf(r) === a && writes(r)))
  return held.length ? LEVELS.filter((l) => held.every((a) => (denied[a] ?? []).includes(l))) : []
}

// ── The features of a page, one by one ─────────────────────────────────────────────────────────────────────────────────

/** What a right lets the person do, told from its last word: `saber.group.create` adds, `saber.program.cancel` deletes. */
export type Kind = 'view' | 'add' | 'edit' | 'delete' | 'other'
const KINDS: Record<Exclude<Kind, 'other'>, readonly string[]> = {
  view: ['view'],
  add: ['create', 'add', 'import', 'book', 'request', 'propose', 'submit', 'nominate', 'generate', 'issue'],
  edit: ['edit', 'rename', 'assign', 'manage', 'settings', 'schedule', 'status', 'recurring', 'grade', 'moderate'],
  delete: ['delete', 'cancel', 'revoke', 'purge'],
}
export function kindOf(slug: string): Kind {
  const last = slug.split('.').pop() ?? ''
  return (Object.keys(KINDS) as Exclude<Kind, 'other'>[]).find((k) => KINDS[k].includes(last)) ?? 'other'
}

/**
 * The areas whose features are listed under this page. An area is listed once, under the first page of the menu it
 * opens (the rights of «البرامج» are under «البرامج», not again under «القاعات»), and never under a page that only shows things.
 */
export const featureAreas = (page: Page, g: Ground): string[] =>
  page.plain ? [] : areasOf(page).filter((a) => g.pages.find((q) => !q.plain && areasOf(q).includes(a))?.to === page.to)
/** Every right of those areas, in the order of the catalogue: the features an administrator gives one by one. */
export const featuresOf = (page: Page, g: Ground): string[] => { const areas = featureAreas(page, g); return g.known.filter((k) => areas.includes(areaOf(k))) }
/** A right that cannot be switched off here: it is the last one keeping the page open (the page has its own switch for that). */
export const keepsOpen = (page: Page, slug: string, d: Draft): boolean => { const held = doorsOf(page).filter((x) => d.rights.has(x)); return held.length === 1 && held[0] === slug }
/** The other pages a right opens, so the administrator sees what else it touches. */
export const alsoOpens = (page: Page, slug: string, g: Ground): Page[] => g.pages.filter((q) => q.to !== page.to && doorsOf(q).includes(slug))

/** Gives or takes one feature. A page the right would also open was not asked for, so it starts hidden (as in `show`). */
export function setFeature(page: Page, slug: string, on: boolean, d: Draft, g: Ground): Draft {
  if (!shown(page, d) || d.rights.has(slug) === on || (!on && keepsOpen(page, slug, d))) return d
  const rights = new Set(d.rights)
  const hidden = new Set(d.hidden)
  if (on) {
    const before = new Set(g.pages.filter((q) => opens(q, d.rights)).map((q) => q.to))
    rights.add(slug)
    for (const q of g.pages) if (!before.has(q.to) && opens(q, rights)) hidden.add(q.to)
  } else rights.delete(slug)
  return clean({ rights, hidden, denied: d.denied })
}

/** Every feature of the page at once: all of them, or none but what keeps the page open ("to look only"). */
export function setFeatures(page: Page, on: boolean, d: Draft, g: Ground): Draft {
  return featuresOf(page, g).reduce((draft, slug) => setFeature(page, slug, on, draft, g), d)
}
