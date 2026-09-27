import { useMutation, useQuery, useQueryClient, type UseQueryOptions } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '@/lib/api'

/** GET helper keyed by URL + params + locale (content is localized server-side). */
export function useGet<T>(url: string | null, params?: Record<string, unknown>, options?: Partial<UseQueryOptions<T>>) {
  const { i18n } = useTranslation()
  return useQuery<T>({
    queryKey: [url, params, i18n.language],
    queryFn: async () => (await api.get(url as string, { params })).data as T,
    enabled: !!url,
    ...options,
  })
}

/** Mutation helper that invalidates the given query URL prefixes on success. */
export function useSend<TBody = unknown, TResult = unknown>(method: 'post' | 'put' | 'patch' | 'delete', url: string | ((body: TBody) => string), invalidate: string[] = []) {
  const qc = useQueryClient()
  return useMutation<TResult, unknown, TBody>({
    mutationFn: async (body: TBody) => {
      const target = typeof url === 'function' ? url(body) : url
      const response = method === 'delete' ? await api.delete(target) : await api[method](target, body)
      return response.data as TResult
    },
    onSuccess: () => {
      invalidate.forEach((prefix) => qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0] ?? '').startsWith(prefix) }))
    },
  })
}
