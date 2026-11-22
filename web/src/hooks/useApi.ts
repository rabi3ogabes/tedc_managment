import { useMutation, useQuery, useQueryClient, type UseQueryOptions } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '@/lib/api'
import { useActiveRoleId } from '@/lib/auth'
import { queryKeyFor } from '@/lib/queryKey'

/**
 * GET helper keyed by URL + params + locale (content is localized server-side) and, for personal data, the role the person is
 * working in: the same address answers differently in each role, so each role keeps its own copy (see queryKeyFor).
 */
export function useGet<T>(url: string | null, params?: Record<string, unknown>, options?: Partial<UseQueryOptions<T>>) {
  const { i18n } = useTranslation()
  const roleId = useActiveRoleId()
  return useQuery<T>({
    queryKey: queryKeyFor(url, params, i18n.language, roleId),
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
