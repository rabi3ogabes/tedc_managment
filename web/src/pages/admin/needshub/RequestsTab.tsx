import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'

type Req = { id: string; title: string | null; entity_name: string | null; need_degree: number; objectives: string[] | null; employee_ids: string[] | null; employees_count?: number | null; preferred_window: string | null; status: string; review_note: string | null }
const TONE: Record<string, 'gold' | 'green' | 'red'> = { submitted: 'gold', accepted: 'green', merged: 'green', rejected: 'red' }

/** Direct managers ask for programs for their own staff; planners review and send accepted ones to the plan. */
export default function RequestsTab() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const list = useGet<{ data: Req[] }>('/admin/institutional-requests', undefined, { staleTime: 0 })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ title: '', need_degree: 3, objectives: '', preferred_window: '', employees: '' })
  const [review, setReview] = useState<Req | null>(null)
  const [note, setNote] = useState('')
  const lines = (s: string) => s.split('\n').map((x) => x.trim()).filter(Boolean)
  const run = async (fn: () => Promise<unknown>) => { try { await fn(); toast(t('needsHub.cycle.saved')); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') } }

  if (list.isLoading || !list.data) return <Spinner />
  return (
    <div className="space-y-4">
      {can('needs.request') && <div className="flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('needsHub.request.new')}</Button></div>}
      <Card padded={false}>{list.data.data.length === 0 ? <Empty text={t('needsHub.cycle.none')} /> : (
        <ul className="divide-y divide-navy-50">{list.data.data.map((r) => (
          <li key={r.id} className="flex flex-wrap items-start gap-3 px-5 py-4">
            <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{r.title}</div><div className="text-xs text-slate-500">{r.entity_name} · ★{r.need_degree} · {r.employees_count ?? (r.employee_ids ?? []).length} · {r.preferred_window}</div>{r.review_note && <p className="mt-1 text-xs text-amber-700">{r.review_note}</p>}</div>
            <Badge color={TONE[r.status] ?? 'gold'}>{t(`needsHub.proposal.status.${r.status}`)}</Badge>
            {can('needs.cycles') && r.status === 'submitted' && <Button size="sm" variant="outline" onClick={() => setReview(r)}>{t('needsHub.proposal.accept')} / {t('needsHub.proposal.reject')}</Button>}
          </li>))}</ul>
      )}</Card>
      <Modal open={open} onClose={() => setOpen(false)} title={t('needsHub.request.new')} wide>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('needsHub.request.title')}><input className="input" value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Field><Field label={t('needsHub.request.degree')}><input type="number" min={1} max={5} className="input" value={f.need_degree} onChange={(e) => setF({ ...f, need_degree: Number(e.target.value) })} /></Field></div>
          <Field label={t('needsHub.request.objectives')}><textarea className="input min-h-20" value={f.objectives} onChange={(e) => setF({ ...f, objectives: e.target.value })} /></Field>
          <Field label={t('needsHub.request.employees')}><textarea className="input min-h-20 font-mono text-xs" dir="ltr" value={f.employees} onChange={(e) => setF({ ...f, employees: e.target.value })} /></Field>
          <Field label={t('needsHub.request.window')}><input className="input" value={f.preferred_window} onChange={(e) => setF({ ...f, preferred_window: e.target.value })} /></Field>
          <Button variant="gold" disabled={!f.title.trim()} onClick={() => void run(async () => { await api.post('/admin/institutional-requests', { title: f.title, need_degree: f.need_degree, objectives: lines(f.objectives), employee_nos: lines(f.employees), preferred_window: f.preferred_window || undefined }); setOpen(false) })}>{t('needsHub.request.submit')}</Button>
        </div>
      </Modal>
      <Modal open={!!review} onClose={() => setReview(null)} title={review?.title ?? ''}>
        <div className="space-y-4"><Field label={t('needsHub.proposal.note')}><textarea className="input min-h-20" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          <div className="flex gap-2"><Button variant="gold" onClick={() => review && void run(async () => { await api.post(`/admin/institutional-requests/${review.id}/review`, { decision: 'accepted', note: note || undefined }); setReview(null); setNote('') })}>{t('needsHub.proposal.accept')}</Button><Button variant="outline" disabled={!note.trim()} onClick={() => review && void run(async () => { await api.post(`/admin/institutional-requests/${review.id}/review`, { decision: 'rejected', note }); setReview(null); setNote('') })}>{t('needsHub.proposal.reject')}</Button></div></div>
      </Modal>
    </div>
  )
}
