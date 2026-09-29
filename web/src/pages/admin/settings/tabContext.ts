import { createContext, useContext, useEffect } from 'react'

export type SettingsTabState = {
  /** True while this tab is the visible one. Hidden tabs stay mounted to keep their state. */
  active: boolean
  /** Report unsaved changes so closing the tab asks first. */
  setDirty: (dirty: boolean) => void
}

export const SettingsTabContext = createContext<SettingsTabState>({ active: true, setDirty: () => {} })

/** For pages that live inside a settings tab (they also work standalone with the defaults). */
export function useSettingsTab() {
  return useContext(SettingsTabContext)
}

/** Marks the current tab dirty while `dirty` is true. */
export function useTabDirty(dirty: boolean) {
  const { setDirty } = useSettingsTab()
  useEffect(() => {
    setDirty(dirty)
    return () => setDirty(false)
  }, [dirty, setDirty])
}
