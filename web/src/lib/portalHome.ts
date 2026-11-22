/** The role of somebody who is trained: «موظف» in the menu of roles, «المتدرب» in the Ministry's roles document. */
export const TRAINEE_ROLE = 'employee'

/**
 * Whether the home page of the portal shows the person's own training (hours, active and finished programmes, the next session,
 * what is waiting for them, the programmes recommended to them).
 *
 * The home page belongs to the role the person is working in. Somebody who is both a supervisor and a trainee sees the
 * supervision dashboard as «مشرف» and their own training as «موظف», not both under each. Their own training is never lost:
 * a person who does not hold the trainee role at all keeps it on their home page, since they have no other place to switch to.
 */
export function showsOwnTraining(activeSlug: string | null | undefined, heldSlugs: readonly string[]): boolean {
  return activeSlug === TRAINEE_ROLE || !heldSlugs.includes(TRAINEE_ROLE)
}
