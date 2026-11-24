/**
 * Which employee the side panel of the employees list shows, and which of its views, kept in the address of the page:
 * `/admin/employees?employee=<id>&view=access`. So the panel survives a reload, can be sent to a colleague as a link, the
 * browser's Back button closes it, and the administrator who opens the person's account from it comes back to it.
 */
export type PanelView = 'passport' | 'access'

export type PanelState = { id: string; view: PanelView } | null

const ID = /^[0-9a-zA-Z-]{1,64}$/

/** Reads the panel from the query string; anything that is not a plain id is ignored. */
export function readPanel(search: string): PanelState {
  const params = new URLSearchParams(search)
  const id = params.get('employee') ?? ''
  if (!ID.test(id)) return null
  return { id, view: params.get('view') === 'access' ? 'access' : 'passport' }
}

/** The query string with the panel set (or removed with `null`); the other parameters of the page are kept. */
export function writePanel(search: string, panel: PanelState): string {
  const params = new URLSearchParams(search)
  params.delete('employee')
  params.delete('view')
  if (panel) {
    params.set('employee', panel.id)
    if (panel.view === 'access') params.set('view', 'access')
  }
  const out = params.toString()
  return out ? `?${out}` : ''
}
