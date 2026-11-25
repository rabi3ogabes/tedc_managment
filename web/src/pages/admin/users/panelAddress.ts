/**
 * Which user the side panel of the Users page shows, and which of its views, kept in the address of the page:
 * `…?user=<id>&view=rights`. The Users page is reached two ways (`/admin/users` and Settings → Users,
 * `/admin/settings?tab=users`), so only these two parameters are touched and everything else in the address is kept.
 * The panel survives a reload, the browser's Back button closes it, and the administrator who opens the person's
 * account from it comes back to it.
 */
export const USER_VIEWS = ['rights', 'roles', 'access'] as const
export type UserView = (typeof USER_VIEWS)[number]
export type UserPanelState = { id: string; view: UserView } | null

const ID = /^[0-9a-zA-Z-]{1,64}$/

/** Reads the panel from the query string; anything that is not a plain id is ignored, and an unknown view is the first one. */
export function readUserPanel(search: string): UserPanelState {
  const params = new URLSearchParams(search)
  const id = params.get('user') ?? ''
  if (!ID.test(id)) return null
  const view = params.get('view') as UserView | null
  return { id, view: view && USER_VIEWS.includes(view) ? view : 'rights' }
}

/** The query string with the panel set (or removed with `null`); the other parameters of the page are kept. */
export function writeUserPanel(search: string, panel: UserPanelState): string {
  const params = new URLSearchParams(search)
  params.delete('user')
  params.delete('view')
  if (panel) {
    params.set('user', panel.id)
    if (panel.view !== 'rights') params.set('view', panel.view)
  }
  const out = params.toString()
  return out ? `?${out}` : ''
}
