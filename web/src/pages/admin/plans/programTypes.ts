import { useGet } from '@/hooks/useApi'

export type ProgramType = { id: string; name_ar: string; name_en: string; hint_ar: string; hint_en: string }
export type Issue = { code: string; item_ids: string[]; message: { ar: string; en: string } }
export type Compliance = {
  caps: { seats: { used: number; max: number | null }; hours: { used: number; max: number | null } }
  by_type: Record<string, number>
  by_category: { id: string | null; name_ar: string | null; name_en: string | null; count: number; mandatory: boolean }[]
  mandatory: { id: string; name_ar: string; name_en: string; covered: boolean }[]
  allowed_types: string[]
  violations: Issue[]
  warnings: Issue[]
  excluded_needs: { need_ids: string[]; title: string; program_type: string | null; seats: number; reason: string }[]
  deferred: number
  exception: { reason: string; by_name?: string; at: string; stage: string } | null
}

/** The five programme types of the RFP (ترخيص، تمكين، تمهين، تخصيص، تخيير), from the API so the names stay in one place. */
export function useProgramTypes() {
  const res = useGet<{ data: ProgramType[] }>('/admin/program-types', undefined, { staleTime: 3_600_000 })
  return res.data?.data ?? []
}
