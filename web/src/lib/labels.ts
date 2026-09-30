import i18n from '@/i18n'
import { api } from './api'

export type Lng = 'ar' | 'en'
export type LabelSet = { ar: Record<string, string>; en: Record<string, string> }

const CACHE = 'tedc.labels'

function flatten(node: unknown, prefix = '', out: Record<string, string> = {}): Record<string, string> {
  if (!node || typeof node !== 'object' || Array.isArray(node)) return out
  for (const [key, value] of Object.entries(node as Record<string, unknown>)) {
    const path = prefix ? `${prefix}.${key}` : key
    if (typeof value === 'string') out[path] = value
    else flatten(value, path, out)
  }
  return out
}

/** The built-in texts, captured before any administrator override is applied. Arrays (lists of steps) are not editable. */
export const baseLabels: Record<Lng, Record<string, string>> = {
  ar: flatten(i18n.getResourceBundle('ar', 'translation')),
  en: flatten(i18n.getResourceBundle('en', 'translation')),
}

const applied: Record<Lng, Set<string>> = { ar: new Set(), en: new Set() }
let current: LabelSet = { ar: {}, en: {} }

export const currentLabels = () => current

/** Puts the administrator's names on top of the built-in texts (and restores the ones that were removed). */
export function applyLabels(set: LabelSet) {
  for (const lng of ['ar', 'en'] as const) {
    for (const key of applied[lng]) {
      if (!(key in (set[lng] ?? {}))) i18n.addResource(lng, 'translation', key, baseLabels[lng][key] ?? '', { silent: true })
    }
    applied[lng] = new Set()
    for (const [key, value] of Object.entries(set[lng] ?? {})) {
      if (typeof value === 'string' && key in baseLabels[lng]) {
        i18n.addResource(lng, 'translation', key, value, { silent: true })
        applied[lng].add(key)
      }
    }
  }
  current = { ar: set.ar ?? {}, en: set.en ?? {} }
  void i18n.changeLanguage(i18n.language) // re-renders every translated component
}

/** Applies the last known labels at once (no flash of the old names), then refreshes them from the server. */
export function bootLabels() {
  try {
    const cached = JSON.parse(localStorage.getItem(CACHE) ?? 'null') as { data: LabelSet; version: string } | null
    if (cached?.data) applyLabels(cached.data)
    void api.get('/public/labels').then(({ data }) => {
      if (data.version !== cached?.version) {
        applyLabels(data.data)
        cacheLabels(data.data, data.version)
      }
    }).catch(() => undefined)
  } catch { /* storage unavailable */ }
}

export function cacheLabels(data: LabelSet, version: string) {
  try { localStorage.setItem(CACHE, JSON.stringify({ data, version })) } catch { /* storage unavailable */ }
}
