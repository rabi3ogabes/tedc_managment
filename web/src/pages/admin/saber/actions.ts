import { createContext, useContext } from 'react'
import type { Group, OffdayTarget, Program } from './types'

/** What a group or a day can ask the workbench to open (dialogs live once, on the page). */
export type SaberActions = {
  plan: (program: Program, group: Group) => void
  cancelGroup: (program: Program, group: Group) => void
  requestOffday: (target: OffdayTarget) => void
  details: (programId: string) => void
  refresh: () => void
}

export const SaberActionsContext = createContext<SaberActions | null>(null)

export function useSaberActions(): SaberActions {
  const actions = useContext(SaberActionsContext)
  if (!actions) throw new Error('useSaberActions outside the SABER+ workbench')
  return actions
}
