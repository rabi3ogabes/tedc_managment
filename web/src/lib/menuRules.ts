/**
 * The rules that decide which buttons a role gets, kept apart from the catalogue of pages so they can be tested alone.
 * The side menu, the search and the administrator's «ما يراه» window all go through them.
 */

/** What a button asks for: one of several rights (`a|b`), a feature that is switched on (`x|y`), both, or nothing. */
export type Gated = { permission?: string; feature?: string }

/** `holds(['saber.view'])('saber.view')`: whether a list of rights carries one ('*' carries all). */
export const holds = (permissions: readonly string[]) => (slug: string) => permissions.includes('*') || permissions.includes(slug)

/** A button is shown when its feature is on and the role holds one of the rights it names (or it names none). */
export function allowed(item: Gated, can: (slug: string) => boolean, featureOn: (feature?: string) => boolean): boolean {
  return featureOn(item.feature) && (!item.permission || item.permission.split('|').some((x) => can(x)))
}

/** The sections with only the buttons the role may use; a section left empty is dropped. */
export function visibleGroups<I extends Gated, G extends { items: I[] }>(groups: G[], can: (slug: string) => boolean, featureOn: (feature?: string) => boolean): G[] {
  return groups.map((g) => ({ ...g, items: g.items.filter((i) => allowed(i, can, featureOn)) })).filter((g) => g.items.length > 0)
}

/** Whether a role's home is the portal. */
export const landsOnPortal = (landing: string | null | undefined) => (landing ?? '').startsWith('/portal')

/**
 * The areas whose only door is their "view" right, from the rights the pages of the menu ask for. `saber.view` is the one
 * right that opens «سابر+», so an action of the area (`saber.program.create`) does nothing for a role that cannot open
 * the page, and the roles screen gives the two together (pageRightOf). An area with another door is left alone:
 * `analytics.executive` opens its own page, and adding `analytics.view` to it would open pages nobody chose.
 */
export function onlyDoors(asked: readonly (string | undefined)[]): Set<string> {
  const doors: Record<string, Set<string>> = {}
  for (const right of asked.flatMap((p) => p?.split('|') ?? [])) (doors[right.split('.')[0]] ??= new Set<string>()).add(right)
  return new Set(Object.entries(doors).filter(([area, rights]) => rights.size === 1 && rights.has(`${area}.view`)).map(([area]) => `${area}.view`))
}

/** The right that opens the page of the area a right belongs to, when the area has one: `saber.day.edit` → `saber.view`. */
export function pageRightOf(slug: string, pages: ReadonlySet<string>): string | null {
  const page = `${slug.split('.')[0]}.view`
  return page !== slug && pages.has(page) ? page : null
}
