import { useQuery } from '@tanstack/react-query'
import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from './api'
import { applyTheme, mergeTheme, type Theme } from './theme'

type ThemeState = {
  /** The published theme. */
  theme: Theme
  /** What is currently rendered — the draft while editing in the Brand Studio. */
  active: Theme
  setPreview: (theme: Theme | null) => void
  refresh: () => Promise<unknown>
}

const ThemeContext = createContext<ThemeState | null>(null)
const CACHE_KEY = 'tedc.theme'

function cached(): Theme | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY)
    return raw ? mergeTheme(JSON.parse(raw)) : null
  } catch {
    return null
  }
}

/** Loads the published theme (cached locally to avoid a flash of the default palette) and applies it. */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [preview, setPreview] = useState<Theme | null>(null)
  const query = useQuery({
    queryKey: ['theme'],
    queryFn: async () => mergeTheme((await api.get('/public/theme')).data.data),
    staleTime: 5 * 60_000,
    placeholderData: cached() ?? undefined,
  })

  const theme = query.data ?? mergeTheme(null)
  const active = preview ?? theme
  const { i18n } = useTranslation()

  useEffect(() => {
    applyTheme(active, i18n.language)
  }, [active, i18n.language])

  useEffect(() => {
    if (!query.data) return
    try {
      localStorage.setItem(CACHE_KEY, JSON.stringify(query.data))
    } catch {
      /* storage unavailable */
    }
  }, [query.data])

  const value = useMemo(() => ({ theme, active, setPreview, refresh: query.refetch }), [theme, active, query.refetch])
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

export function useTheme() {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider')
  return ctx
}
