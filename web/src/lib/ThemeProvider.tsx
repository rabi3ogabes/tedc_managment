import { useQuery } from '@tanstack/react-query'
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
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

const FRESH_KEY = 'tedc.theme.fresh'

/** After an administrator publishes, bypass the CDN copy for a while so this browser sees the new theme at once. */
function markFresh() {
  try {
    localStorage.setItem(FRESH_KEY, String(Date.now()))
  } catch {
    /* storage unavailable */
  }
}

function freshParam(): Record<string, string> | undefined {
  try {
    const at = Number(localStorage.getItem(FRESH_KEY) ?? 0)
    return Date.now() - at < 10 * 60_000 ? { v: String(at) } : undefined
  } catch {
    return undefined
  }
}

/** Loads the published theme (cached locally to avoid a flash of the default palette) and applies it. */
export function ThemeProvider({ children }: { children: ReactNode }) {
  const [preview, setPreview] = useState<Theme | null>(null)
  const query = useQuery({
    queryKey: ['theme'],
    queryFn: async () => mergeTheme((await api.get('/public/theme', { params: freshParam() })).data.data),
    staleTime: 5 * 60_000,
    placeholderData: cached() ?? undefined,
  })

  const theme = query.data ?? mergeTheme(null)
  const active = preview ?? theme
  const { i18n } = useTranslation()

  useEffect(() => {
    applyTheme(active, i18n.language)
    document.title = i18n.language === 'en' ? active.identity.name_en : active.identity.name_ar
  }, [active, i18n.language])

  useEffect(() => {
    if (!query.data) return
    try {
      localStorage.setItem(CACHE_KEY, JSON.stringify(query.data))
    } catch {
      /* storage unavailable */
    }
  }, [query.data])

  const { refetch } = query
  const refresh = useCallback(() => {
    markFresh()
    return refetch()
  }, [refetch])
  const value = useMemo(() => ({ theme, active, setPreview, refresh }), [theme, active, refresh])
  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>
}

/** The center name in the current language, as set by administrators in the settings page. */
export function useCenterName(): string {
  const { active } = useTheme()
  const { i18n } = useTranslation()
  return i18n.language === 'en' ? active.identity.name_en : active.identity.name_ar
}

export function useTheme() {
  const ctx = useContext(ThemeContext)
  if (!ctx) throw new Error('useTheme must be used within ThemeProvider')
  return ctx
}
