import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Row = { key: string; label_ar: string; label_en: string; employees: number; evaluated: number; avg_gap: number; priority: number; uncovered: boolean; licence_relevant: boolean; explanation_ar: string; explanation_en: string }
const GROUPS = ['skill', 'school', 'job_title', 'region']

/** Ranked gaps with the reason for each, a heat bar per row, gaps no program covers, and "send to plan". */
export default function GapsTab() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const [by, setBy] = useState('skill')
  const [sel, setSel] = useState<string[]>([])
  const [planId, setPlanId] = useState('')
  const gaps = useGet<{ data: { rows: Row[]; uncovered: Row[] } }>('/admin/gaps', { group_by: by }, { staleTime: 0 })
  const plans = useGet<{ data: { id: string; year: number; version: number; status: string }[] }>(can('plans.manage') ? '/admin/plans' : null)
  const max = Math.max(1, ...(gaps.data?.data.rows.map((r) => r.priority) ?? [1]))

  const send = async () => {
    try { const { data } = await api.post('/admin/gaps/to-plan', { plan_id: planId, skill_ids: sel }); toast(t('needsHub.gaps.sent', { n: data.data.added })); setSel([]) } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-semibold text-slate-600">{t('needsHub.gaps.groupBy')}</span>
        {GROUPS.map((g) => <button key={g} type="button" aria-pressed={by === g} onClick={() => { setBy(g); setSel([]) }} className={by === g ? 'rounded-full bg-navy-900 px-3 py-1.5 text-sm font-bold text-white' : 'rounded-full border border-navy-100 bg-white px-3 py-1.5 text-sm text-slate-600'}>{t(`needsHub.gaps.by.${g}`)}</button>)}
      </div>
      {gaps.isLoading || !gaps.data ? <Spinner /> : gaps.data.data.rows.length === 0 ? <Card><Empty text={t('needsHub.gaps.none')} /></Card> : (
        <Card padded={false}>
          <ul className="divide-y divide-navy-50">{gaps.data.data.rows.map((r) => (
            <li key={r.key} className="space-y-1.5 px-5 py-4">
              <div className="flex flex-wrap items-center gap-3">
                {by === 'skill' && can('plans.manage') && <input type="checkbox" aria-label={r.label_en} checked={sel.includes(r.key)} onChange={(e) => setSel(e.target.checked ? [...sel, r.key] : sel.filter((x) => x !== r.key))} />}
                <span className="min-w-0 flex-1 font-bold text-navy-900">{ar ? r.label_ar : r.label_en}</span>
                {r.licence_relevant && <Badge color="gold">{t('competencies.licence', { defaultValue: t('needsHub.comp.licence') })}</Badge>}
                {r.uncovered && <Badge color="red">{t('needsHub.gaps.uncovered')}</Badge>}
                <span className="text-sm tabular-nums text-slate-600">{fmt.number(r.employees)}/{fmt.number(r.evaluated)} · {t('needsHub.gaps.avg')} {fmt.number(r.avg_gap, 1)}</span>
                <span className="w-16 text-end text-lg font-extrabold tabular-nums text-navy-900" title={t('needsHub.gaps.priority')}>{fmt.number(r.priority, 1)}</span>
              </div>
              <div className="h-1.5 overflow-hidden rounded-full bg-navy-50"><div className="h-full rounded-full bg-gold-500" style={{ width: `${Math.max(3, (r.priority / max) * 100)}%` }} /></div>
              <p className="text-xs text-slate-500">{ar ? r.explanation_ar : r.explanation_en}</p>
            </li>))}</ul>
        </Card>
      )}
      {sel.length > 0 && (
        <div className="sticky bottom-4 flex flex-wrap items-center gap-3 rounded-2xl bg-navy-900 p-4 text-white shadow-lg">
          <select className="input w-60 text-navy-900" value={planId} onChange={(e) => setPlanId(e.target.value)}><option value="">{t('needsHub.gaps.pickPlan')}</option>{plans.data?.data.filter((p) => p.status === 'draft' || p.status === 'approved' || p.status === 'active').map((p) => <option key={p.id} value={p.id}>{p.year} v{p.version}</option>)}</select>
          <Button variant="gold" disabled={!planId} onClick={() => void send()}>{t('needsHub.gaps.toPlan')} ({sel.length})</Button>
        </div>
      )}
    </div>
  )
}
