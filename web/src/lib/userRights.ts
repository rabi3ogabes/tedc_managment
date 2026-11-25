/**
 * Rights of one person in one of their roles. The server keeps two short lists on the grant of the role: what was added
 * for the person on top of the role (`granted`) and what was taken from them out of it (`revoked`). The screen works
 * the other way round, with the set of rights the person should end up with, and these functions go between the two.
 */
export type Overrides = { granted: string[]; revoked: string[] }

/** How one right stands for the person: the role gives it, it was added for them, it was taken from them, or neither. */
export type RightState = 'role' | 'added' | 'taken' | 'none'

/** What the person may do: the role's rights, plus what was added, minus what was taken. */
export function effectiveRights(role: readonly string[], overrides: Overrides): Set<string> {
  const out = new Set(role)
  for (const slug of overrides.granted) out.add(slug)
  for (const slug of overrides.revoked) out.delete(slug)
  return out
}

/** The two lists that turn the role's rights into the set the administrator ticked, each in a steady order. */
export function overridesFor(role: readonly string[], wanted: ReadonlySet<string>): Overrides {
  const own = new Set(role)
  return {
    granted: [...wanted].filter((slug) => !own.has(slug)).sort(),
    revoked: role.filter((slug) => !wanted.has(slug)).sort(),
  }
}

export function rightState(slug: string, role: ReadonlySet<string>, wanted: ReadonlySet<string>): RightState {
  if (role.has(slug)) return wanted.has(slug) ? 'role' : 'taken'
  return wanted.has(slug) ? 'added' : 'none'
}

/** Whether two pairs of lists say the same thing, whatever their order. */
export function sameOverrides(a: Overrides, b: Overrides): boolean {
  const key = (o: Overrides) => `${[...new Set(o.granted)].sort().join(',')}|${[...new Set(o.revoked)].sort().join(',')}`
  return key(a) === key(b)
}

/** Whether the person differs from their role at all. */
export const isCustomised = (o: Overrides): boolean => o.granted.length + o.revoked.length > 0
