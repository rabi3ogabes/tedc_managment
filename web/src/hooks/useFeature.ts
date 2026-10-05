import { useGet } from './useApi'

export type FeatureMap = { flags: Record<string, boolean>; unsafe_active: string[]; environment: string }

/** Which features the administrator has switched on (read once, cached for five minutes). */
export function useFeatures() {
  return useGet<{ data: FeatureMap }>('/features', undefined, { staleTime: 5 * 60_000, retry: false })
}

/** `const payments = useFeature('payments')` — false while loading, signed out, or when the flag is off. */
export function useFeature(key: string): boolean {
  return useFeatures().data?.data.flags[key] ?? false
}
