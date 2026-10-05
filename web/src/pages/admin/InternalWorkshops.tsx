import { Check, Plus, UserPlus, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Workshop = { id: string; code: string; title_ar: string; title_en: string; total_hours: number; capacity: number; start_date: string | null; end_date: string | null; approval_status: string; school: { name_ar: string; name_en: string } | null; registrations_count: number | null }
const blank = { title_ar: '', title_en: '', total_hours: 3, capacity: 20, start_date: '', end_date: '', objectives: '' }
const TONE: Record<string, 'gold' | 'green' | 'red'> = { pending: 'gold', approved: 'green', rejected: 'red' }

/** School internal workshops: the school submits and runs them; the centre approves or rejects. */
export default function InternalWorkshops() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const { data, isLoading, refetch } = useGet<{ data: Workshop[] }>('/admin/internal-workshops', undefined, { staleTime: 0 })
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState(blank)
  const [busy, setBusy] = useState(false)
  const [reject, setReject] = useState<Workshop | null>(null)
  const [note, setNote] = useState('')
  const [reg, setReg] = useState<Workshop | null>(null)
  const [ids, setIds] = useState('')

  const submit = async () => {
    setBusy(true)
    try {
      await api.post('/admin/internal-workshops', { ...form, objectives: form.objectives.split('\n').map((x) => x.trim()).filter(Boolean), end_date: form.end_date || form.start_date })
      toast(t('workshops.submitted')); setCreating(false); setForm(blank); await refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const decide = async (w: Workshop, decision: 'approved' | 'rejected', n?: string) => {
    try { await api.post(`/admin/internal-workshops/${w.id}/decision`, { decision, note: n }); toast(t('workshops.decided')); setReject(null); setNote(''); await refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const register = async () => {
    if (!reg) return
    try {
      const { data: r } = await api.post(`/admin/internal-workshops/${reg.id}/register`, { employee_nos: ids.split(/\s+/).filter(Boolean) })
      toast(t('workshops.registered', { n: r.data.registered, m: r.data.skipped })); setReg(null); setIds(''); await refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  if (isLoading || !data) return <Spinner />
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('workshops.title')} subtitle={t('workshops.subtitle')} actions={can('workshops.internal') && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('workshops.new')}</Button>} />
      {data.data.length === 0 ? <Card><Empty text={t('workshops.empty')} /></Card> : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {data.data.map((w) => (
            <Card key={w.id} className="space-y-3">
              <div className="flex items-start justify-between gap-2"><div className="min-w-0"><h3 className="truncate font-bold text-navy-900">{ar ? w.title_ar : w.title_en}</h3><p className="font-mono text-xs text-slate-400" dir="ltr">{w.code}</p></div><Badge color={TONE[w.approval_status] ?? 'navy'}>{t(`workshops.approval.${w.approval_status}`)}</Badge></div>
              {w.school && <p className="text-sm text-slate-600">{ar ? w.school.name_ar : w.school.name_en}</p>}
              <p className="text-sm text-slate-500">{fmt.date(w.start_date)} · {fmt.number(w.total_hours, 1)} {t('workshops.hours')} · {fmt.number(w.registrations_count ?? 0)}/{fmt.number(w.capacity)}</p>
              <div className="flex flex-wrap gap-2">
                {w.approval_status === 'pending' && can('workshops.approve') && <><Button size="sm" variant="gold" icon={<Check className="size-4" />} onClick={() => void decide(w, 'approved')}>{t('workshops.approve')}</Button><Button size="sm" variant="outline" icon={<X className="size-4" />} onClick={() => setReject(w)}>{t('workshops.reject')}</Button></>}
                {w.approval_status === 'approved' && can('workshops.internal') && <Button size="sm" variant="outline" icon={<UserPlus className="size-4" />} onClick={() => setReg(w)}>{t('workshops.register')}</Button>}
              </div>
            </Card>
          ))}
        </div>
      )}

      <Modal open={creating} onClose={() => setCreating(false)} title={t('workshops.new')} wide>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('plans.itemTitleAr')}><input className="input" value={form.title_ar} onChange={(e) => setForm({ ...form, title_ar: e.target.value })} /></Field>
            <Field label={t('plans.itemTitleEn')}><input className="input" dir="ltr" value={form.title_en} onChange={(e) => setForm({ ...form, title_en: e.target.value })} /></Field>
            <Field label={t('workshops.hours')}><input type="number" min={0.5} step={0.5} className="input" value={form.total_hours} onChange={(e) => setForm({ ...form, total_hours: Number(e.target.value) })} /></Field>
            <Field label={t('workshops.capacity')}><input type="number" min={1} className="input" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: Number(e.target.value) })} /></Field>
            <Field label={t('workshops.start')}><input type="date" className="input" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} /></Field>
            <Field label={t('workshops.end')}><input type="date" className="input" value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} /></Field>
          </div>
          <Field label={t('workshops.objectives')}><textarea className="input min-h-24" value={form.objectives} onChange={(e) => setForm({ ...form, objectives: e.target.value })} /></Field>
          <Button variant="gold" loading={busy} disabled={!form.title_ar.trim() || !form.title_en.trim() || !form.start_date} onClick={() => void submit()}>{t('workshops.submit')}</Button>
        </div>
      </Modal>
      <Modal open={!!reject} onClose={() => setReject(null)} title={t('workshops.reject')}>
        <div className="space-y-4"><Field label={t('workshops.note')}><textarea className="input min-h-20" value={note} onChange={(e) => setNote(e.target.value)} /></Field><Button variant="gold" disabled={!note.trim()} onClick={() => reject && void decide(reject, 'rejected', note)}>{t('workshops.reject')}</Button></div>
      </Modal>
      <Modal open={!!reg} onClose={() => setReg(null)} title={t('workshops.register')}>
        <div className="space-y-4"><Field label={t('workshops.registerHint')}><textarea className="input min-h-32 font-mono text-xs" dir="ltr" value={ids} onChange={(e) => setIds(e.target.value)} /></Field><Button variant="gold" disabled={!ids.trim()} onClick={() => void register()}>{t('workshops.register')}</Button></div>
      </Modal>
    </div>
  )
}
