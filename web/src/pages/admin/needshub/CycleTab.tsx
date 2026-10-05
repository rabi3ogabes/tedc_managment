import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Cycle = { id: string; year: number; title_ar: string; title_en: string; status: string; is_open: boolean; closes_at: string | null }
type Proposal = { id: string; program_title_ar: string; program_title_en: string | null; entity_name: string | null; groups_count: number; days: number; hours: number; importance: number; status: string; justification: string | null; kit_availability: string; review_note: string | null }
const STATUS_TONE: Record<string, 'gold' | 'green' | 'red' | 'navy' | 'slate'> = { submitted: 'gold', under_review: 'navy', accepted: 'green', merged: 'green', rejected: 'red', draft: 'slate', open: 'green', closed: 'slate', analysed: 'navy' }
const blankProposal = { program_title_ar: '', program_title_en: '', groups_count: 1, days: 1, hours: 6, axes: '', kit_availability: 'none', importance: 3, justification: '', target_description: '', trainers: '' }

/** The yearly cycle: planners open and close it and review proposals; department heads submit proposals while it is open. */
export default function CycleTab() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const manage = can('needs.cycles')
  const cycles = useGet<{ data: Cycle[] }>('/admin/needs-cycles', undefined, { staleTime: 0 })
  const [selected, setSelected] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ year: new Date().getFullYear() + 1, title_ar: '', title_en: '', closes_at: '' })
  const id = selected ?? cycles.data?.data[0]?.id ?? null
  const proposals = useGet<{ data: Proposal[] }>(id ? `/admin/needs-cycles/${id}/proposals` : null, undefined, { staleTime: 0 })
  const [propose, setPropose] = useState(false)
  const [p, setP] = useState(blankProposal)
  const [review, setReview] = useState<Proposal | null>(null)
  const [note, setNote] = useState('')
  const [target, setTarget] = useState('')
  const [busy, setBusy] = useState(false)
  const current = cycles.data?.data.find((c) => c.id === id)

  const act = async (fn: () => Promise<unknown>, ok = t('needsHub.cycle.saved')) => { setBusy(true); try { await fn(); toast(ok); await Promise.all([cycles.refetch(), proposals.refetch()]) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) } }
  const lines = (s: string) => s.split('\n').map((x) => x.trim()).filter(Boolean)
  const submit = () => act(async () => {
    await api.post(`/admin/needs-cycles/${id}/proposals`, { ...p, axes: lines(p.axes), trainer_nominations: lines(p.trainers).map((name) => ({ type: 'internal', name })), trainers: undefined })
    setPropose(false); setP(blankProposal)
  })
  const decide = (decision: string) => review && act(async () => {
    await api.post(`/admin/proposals/${review.id}/review`, { decision, note: note || undefined, existing_program_code: decision === 'merged' ? target || undefined : undefined })
    setReview(null); setNote(''); setTarget('')
  })

  if (cycles.isLoading || !cycles.data) return <Spinner />
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-2">
        {cycles.data.data.map((c) => <button key={c.id} type="button" aria-pressed={c.id === id} onClick={() => setSelected(c.id)} className={c.id === id ? 'flex items-center gap-2 rounded-xl border border-navy-900 bg-navy-900 px-4 py-2 text-sm font-bold text-white' : 'flex items-center gap-2 rounded-xl border border-navy-100 bg-white px-4 py-2 text-sm font-bold text-navy-900'}>{c.year}<Badge color={STATUS_TONE[c.status]}>{t(`needsHub.cycle.status.${c.status}`)}</Badge></button>)}
        {manage && <Button variant="outline" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('needsHub.cycle.new')}</Button>}
      </div>
      {!current ? <Card><Empty text={t('needsHub.cycle.none')} /></Card> : (
        <>
          <Card className="flex flex-wrap items-center gap-3">
            <div className="min-w-0 flex-1"><h3 className="font-bold text-navy-900">{ar ? current.title_ar : current.title_en}</h3><p className="text-sm text-slate-500">{t('needsHub.cycle.closes')}: {fmt.date(current.closes_at)}</p></div>
            {manage && current.status === 'draft' && <Button variant="gold" loading={busy} onClick={() => void act(() => api.post(`/admin/needs-cycles/${current.id}/open`))}>{t('needsHub.cycle.open')}</Button>}
            {manage && current.status === 'open' && <Button variant="outline" loading={busy} onClick={() => void act(() => api.post(`/admin/needs-cycles/${current.id}/close`))}>{t('needsHub.cycle.close')}</Button>}
            {current.is_open && can('needs.propose') && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setPropose(true)}>{t('needsHub.cycle.submitProposal')}</Button>}
          </Card>
          <Card padded={false}>
            <div className="border-b border-navy-50 px-5 py-3 font-bold text-navy-900">{t('needsHub.cycle.proposals')}</div>
            {!proposals.data || proposals.data.data.length === 0 ? <Empty text={t('needsHub.cycle.none')} /> : (
              <ul className="divide-y divide-navy-50">{proposals.data.data.map((x) => (
                <li key={x.id} className="flex flex-wrap items-start gap-3 px-5 py-4">
                  <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{ar ? x.program_title_ar : (x.program_title_en || x.program_title_ar)}</div><div className="text-xs text-slate-500">{x.entity_name} · {x.groups_count} × {x.days}d · {fmt.number(x.hours, 1)}h · ★{x.importance}</div>{x.justification && <p className="mt-1 text-sm text-slate-600">{x.justification}</p>}{x.review_note && <p className="mt-1 text-xs text-amber-700">{x.review_note}</p>}</div>
                  <Badge color={STATUS_TONE[x.status]}>{t(`needsHub.proposal.status.${x.status}`)}</Badge>
                  {manage && ['submitted', 'under_review'].includes(x.status) && <Button size="sm" variant="outline" onClick={() => setReview(x)}>{t('needsHub.proposal.accept')} / {t('needsHub.proposal.reject')}</Button>}
                </li>))}</ul>
            )}
          </Card>
        </>
      )}

      <Modal open={creating} onClose={() => setCreating(false)} title={t('needsHub.cycle.new')}>
        <div className="space-y-4">
          <Field label={t('plans.year')}><input type="number" className="input" value={form.year} onChange={(e) => setForm({ ...form, year: Number(e.target.value) })} /></Field>
          <Field label={t('plans.titleAr')}><input className="input" value={form.title_ar} onChange={(e) => setForm({ ...form, title_ar: e.target.value })} /></Field>
          <Field label={t('plans.titleEn')}><input className="input" dir="ltr" value={form.title_en} onChange={(e) => setForm({ ...form, title_en: e.target.value })} /></Field>
          <Field label={t('needsHub.cycle.closes')}><input type="date" className="input" value={form.closes_at} onChange={(e) => setForm({ ...form, closes_at: e.target.value })} /></Field>
          <Button variant="gold" loading={busy} disabled={!form.title_ar.trim() || !form.title_en.trim()} onClick={() => void act(async () => { const { data } = await api.post('/admin/needs-cycles', { ...form, closes_at: form.closes_at ? `${form.closes_at} 23:59:00` : undefined }); setSelected(data.data.id); setCreating(false) })}>{t('plans.create')}</Button>
        </div>
      </Modal>

      <Modal open={propose} onClose={() => setPropose(false)} title={t('needsHub.cycle.submitProposal')} wide>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('needsHub.proposal.titleAr')}><input className="input" value={p.program_title_ar} onChange={(e) => setP({ ...p, program_title_ar: e.target.value })} /></Field><Field label={t('needsHub.proposal.titleEn')}><input className="input" dir="ltr" value={p.program_title_en} onChange={(e) => setP({ ...p, program_title_en: e.target.value })} /></Field></div>
          <div className="grid gap-4 sm:grid-cols-4">
            <Field label={t('needsHub.proposal.groups')}><input type="number" min={1} className="input" value={p.groups_count} onChange={(e) => setP({ ...p, groups_count: Number(e.target.value) })} /></Field>
            <Field label={t('needsHub.proposal.days')}><input type="number" min={1} className="input" value={p.days} onChange={(e) => setP({ ...p, days: Number(e.target.value) })} /></Field>
            <Field label={t('needsHub.proposal.hours')}><input type="number" min={0} className="input" value={p.hours} onChange={(e) => setP({ ...p, hours: Number(e.target.value) })} /></Field>
            <Field label={t('needsHub.proposal.importance')}><input type="number" min={1} max={5} className="input" value={p.importance} onChange={(e) => setP({ ...p, importance: Number(e.target.value) })} /></Field>
          </div>
          <Field label={t('needsHub.proposal.kit')}><select className="input" value={p.kit_availability} onChange={(e) => setP({ ...p, kit_availability: e.target.value })}>{['available', 'partial', 'none'].map((k) => <option key={k} value={k}>{t(`needsHub.proposal.kits.${k}`)}</option>)}</select></Field>
          <div className="grid gap-4 sm:grid-cols-2"><Field label={t('needsHub.proposal.axes')}><textarea className="input min-h-20" value={p.axes} onChange={(e) => setP({ ...p, axes: e.target.value })} /></Field><Field label={t('needsHub.proposal.trainers')}><textarea className="input min-h-20" value={p.trainers} onChange={(e) => setP({ ...p, trainers: e.target.value })} /></Field></div>
          <Field label={t('needsHub.proposal.target')}><input className="input" value={p.target_description} onChange={(e) => setP({ ...p, target_description: e.target.value })} /></Field>
          <Field label={t('needsHub.proposal.justification')}><textarea className="input min-h-20" value={p.justification} onChange={(e) => setP({ ...p, justification: e.target.value })} /></Field>
          <Button variant="gold" loading={busy} disabled={!p.program_title_ar.trim()} onClick={() => void submit()}>{t('needsHub.proposal.submit')}</Button>
        </div>
      </Modal>

      <Modal open={!!review} onClose={() => setReview(null)} title={review ? (ar ? review.program_title_ar : review.program_title_en ?? '') : ''}>
        <div className="space-y-4">
          <Field label={t('needsHub.proposal.note')}><textarea className="input min-h-20" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
          <Field label={t('needsHub.proposal.mergeTarget')}><input className="input" dir="ltr" value={target} onChange={(e) => setTarget(e.target.value)} /></Field>
          <div className="flex flex-wrap gap-2"><Button variant="gold" loading={busy} onClick={() => void decide('accepted')}>{t('needsHub.proposal.accept')}</Button><Button variant="outline" disabled={!target.trim()} onClick={() => void decide('merged')}>{t('needsHub.proposal.merge')}</Button><Button variant="outline" disabled={!note.trim()} onClick={() => void decide('rejected')}>{t('needsHub.proposal.reject')}</Button></div>
        </div>
      </Modal>
    </div>
  )
}
