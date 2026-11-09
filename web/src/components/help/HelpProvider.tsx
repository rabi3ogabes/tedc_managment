import { createContext, useCallback, useContext, useMemo, useState, type ReactNode } from 'react'
import HelpDrawer, { type HelpTarget } from './HelpDrawer'

type HelpApi = { open: (target?: HelpTarget) => void }
const HelpContext = createContext<HelpApi | null>(null)

/** The one help window of a layout, opened from the «?» in the top bar, from an ⓘ beside a page title, or from an ⓘ beside any section. */
export function HelpProvider({ portal, children }: { portal: boolean; children: ReactNode }) {
  const [state, setState] = useState<{ open: boolean; target: HelpTarget }>({ open: false, target: {} })
  const open = useCallback((target: HelpTarget = {}) => setState({ open: true, target }), [])
  const close = useCallback(() => setState((s) => ({ ...s, open: false })), [])
  const api = useMemo(() => ({ open }), [open])
  return (
    <HelpContext.Provider value={api}>
      {children}
      <HelpDrawer open={state.open} target={state.target} onClose={close} portal={portal} />
    </HelpContext.Provider>
  )
}

/** Null outside a layout that has the help window (a public page, a full-screen flow): the ⓘ buttons then simply do not appear. */
export function useHelp(): HelpApi | null {
  return useContext(HelpContext)
}
