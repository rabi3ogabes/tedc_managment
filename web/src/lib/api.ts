import axios, { AxiosError, type InternalAxiosRequestConfig } from 'axios'
import i18n from '@/i18n'

export const API_URL = import.meta.env.VITE_API_URL ?? '/api/v1'

const TOKEN_KEY = 'tedc.session'

export type StoredSession = { access_token: string; refresh_token: string; expires_at: number }

export const sessionStore = {
  get(): StoredSession | null {
    try {
      const raw = localStorage.getItem(TOKEN_KEY)
      return raw ? (JSON.parse(raw) as StoredSession) : null
    } catch {
      return null
    }
  },
  set(session: StoredSession | null) {
    try {
      if (session) localStorage.setItem(TOKEN_KEY, JSON.stringify(session))
      else localStorage.removeItem(TOKEN_KEY)
    } catch {
      /* storage unavailable */
    }
  },
}

export const api = axios.create({ baseURL: API_URL, headers: { Accept: 'application/json' } })

api.interceptors.request.use((config) => {
  const session = sessionStore.get()
  if (session) config.headers.Authorization = `Bearer ${session.access_token}`
  config.headers['X-Locale'] = i18n.language === 'en' ? 'en' : 'ar'
  return config
})

let refreshing: Promise<string | null> | null = null

async function refreshToken(): Promise<string | null> {
  const session = sessionStore.get()
  if (!session?.refresh_token) return null
  try {
    const { data } = await axios.post(`${API_URL}/auth/refresh`, { refresh_token: session.refresh_token })
    sessionStore.set({ access_token: data.access_token, refresh_token: data.refresh_token, expires_at: Date.now() + data.expires_in * 1000 })
    return data.access_token as string
  } catch {
    sessionStore.set(null)
    window.dispatchEvent(new Event('tedc:logout'))
    return null
  }
}

api.interceptors.response.use(undefined, async (error: AxiosError) => {
  const original = error.config as (InternalAxiosRequestConfig & { _retried?: boolean }) | undefined
  if (error.response?.status === 401 && original && !original._retried && sessionStore.get()) {
    original._retried = true
    refreshing ??= refreshToken().finally(() => (refreshing = null))
    const token = await refreshing
    if (token) {
      original.headers.Authorization = `Bearer ${token}`
      return api(original)
    }
  }
  return Promise.reject(error)
})

export function errorMessage(error: unknown): string {
  const e = error as AxiosError<{ message?: string; errors?: Record<string, string[]> }>
  const data = e?.response?.data
  if (data?.errors) return Object.values(data.errors).flat()[0] ?? data.message ?? ''
  return data?.message ?? (e as Error)?.message ?? 'Error'
}

/** Downloads an authenticated binary endpoint (PDF, Excel) and opens / saves it. */
export async function downloadFile(url: string, filename: string, open = false) {
  const response = await api.get(url.replace(/^https?:\/\/[^/]+\/api\/v1/, ''), { responseType: 'blob' })
  const href = URL.createObjectURL(response.data as Blob)
  if (open) {
    window.open(href, '_blank', 'noopener')
  } else {
    const a = document.createElement('a')
    a.href = href
    a.download = filename
    a.click()
  }
  setTimeout(() => URL.revokeObjectURL(href), 60_000)
}
