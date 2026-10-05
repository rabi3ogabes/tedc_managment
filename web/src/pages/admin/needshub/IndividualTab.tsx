import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'

type Need = { id: string; employee: { name: string | null; school: string | null } | null; skill: { name_ar: string; name_en: string } | null; source: string; gap: number; priority_score: number; status: string; explanation_ar: string | null; explanation_en: string | null }
const TONE: Record<string, 'gold' | 'green' | 'red' | 'navy'> = { pending_manager: 'gold', approved: 'green', auto_approved: 'green', rejected: 'red', fulfilled: 'navy' }

/** The manager's inbox: staff needs with the reason behind each one, approved or rejected in bulk. */
export default function IndividualTab() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const [status, setStatus] = useState('pending_manager')
  const list = useGet<{ data: Need[]; auto_approve_days: number }>('/admin/individual-needs', { status }, { staleTime: 0 })
  const [sel, setSel] = useState<string[]>([])
  const [note, setNote] = useState('')
  const [days, setDays] = useState<number | null>(null)
  const decide = async (decision: 'approved' | 'rejected') => {
    try { await api.post('/admin/individual-needs/decide', { ids: sel, decision, note: note || undefined }); toast(t('needsHub.individual.decided')); setSel([]); setNote(''); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (list.isLoading || !list.data) return <Spinner />
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <select className="input w-52" value={status} onChange={(e) => { setStatus(e.target.value); setSel([]) }}>{['pending_manager', 'approved,auto_approved', 'rejected'].map((s) => <option key={s} value={s}>{t(`needsHub.individual.status.${s.split(',')[0]}`)}</option>)}</select>
        {can('needs.cycles') && <Field label={t('needsHub.individual.autoDays')} className="ms-auto"><div className="flex gap-2"><input type="number" min={0} max={90} className="input w-24" value={days ?? list.data.auto_approve_days} onChange={(e) => setDays(Number(e.target.value))} /><Button size="sm" variant="outline" onClick={() => void api.put('/admin/individual-needs/settings', { auto_approve_days: days ?? list.data!.auto_approve_days }).then(() => toast(t('needsHub.cycle.saved')))}>{t('groups.save')}</Button></div></Field>}
      </div>
      <Card padded={false}>{list.data.data.length === 0 ? <Empty text={t('needsHub.individual.empty')} /> : (
        <ul className="divide-y divide-navy-50">{list.data.data.map((n) => (
          <li key={n.id} className="flex items-start gap-3 px-5 py-4">
            {n.status === 'pending_manager' && <input type="checkbox" className="mt-1" aria-label={n.employee?.name ?? ''} checked={sel.includes(n.id)} onChange={(e) => setSel(e.target.checked ? [...sel, n.id] : sel.filter((x) => x !== n.id))} />}
            <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{n.employee?.name} <span className="font-normal text-slate-500">· {n.employee?.school}</span></div><div className="text-sm text-slate-700">{ar ? n.skill?.name_ar : n.skill?.name_en} · {t('needsHub.individual.gap')} {n.gap}</div><p className="mt-1 text-xs text-slate-500">{ar ? n.explanation_ar : n.explanation_en}</p></div>
            <Badge color="slate">{t(`needsHub.individual.source.${n.source}`)}</Badge><Badge color={TONE[n.status] ?? 'navy'}>{t(`needsHub.individual.status.${n.status}`)}</Badge>
          </li>))}</ul>
      )}</Card>
      {sel.length > 0 && (
        <div className="sticky bottom-4 flex flex-wrap items-center gap-3 rounded-2xl bg-navy-900 p-4 text-white shadow-lg">
          <span className="text-sm font-bold">{sel.length} {t('needsHub.individual.selected')}</span>
          <input className="input min-w-0 flex-1 text-navy-900" placeholder={t('needsHub.individual.note')} value={note} onChange={(e) => setNote(e.target.value)} />
          <Button variant="gold" onClick={() => void decide('approved')}>{t('needsHub.individual.approve')}</Button><Button variant="outline" disabled={!note.trim()} onClick={() => void decide('rejected')}>{t('needsHub.individual.reject')}</Button>
        </div>
      )}
    </div>
  )
}
