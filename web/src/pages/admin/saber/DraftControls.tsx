import clsx from 'clsx'
import { Eye, FilePlus2, MessageSquareWarning, Send, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import DiffView, { type Diff } from './DiffView'
import type { Overview, Program } from './types'

/** The draft state of a programme in SABER+: its status, the reviewer's note, and send / view / discard. */
export function DraftBar({ program, overview, onChanged }: { program: Program; overview: Overview; onChanged: (removed?: boolean) => void }) {
  const { t } = useTranslation()
  const [viewing, setViewing] = useState(false)
  const [busy, setBusy] = useState(false)
  const d = program.draft
  if (!d) return null
  const returned = d.status === 'draft' && !!d.review_note
  const status = returned ? 'returned' : d.status
  const canDiscard = d.mine || !!overview.can['saber.publish']

  const submit = async () => {
    setBusy(true)
    try { await api.post(`/admin/saber/programs/${program.id}/draft/submit`); toast(t('saber.draft.submitted_done')); onChanged() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const discard = async () => {
    if (!(await dialogs.confirm(t('saber.draft.discardConfirm')))) return
    try { const r = await api.delete(`/admin/saber/programs/${program.id}/draft`); toast(r.data?.data?.removed ? t('saber.draft.removed') : t('saber.draft.discarded')); onChanged(!!r.data?.data?.removed) } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <div className={clsx('flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2', status === 'submitted' ? 'border-sky-200 bg-sky-50/60' : returned ? 'border-amber-200 bg-amber-50/60' : 'border-gold-200 bg-gold-50/50')}>
      <span className={clsx('rounded-full px-2.5 py-0.5 text-[11px] font-bold', status === 'submitted' ? 'bg-sky-100 text-sky-800' : returned ? 'bg-amber-100 text-amber-800' : 'bg-gold-100 text-gold-800')}>{t(`saber.draft.status.${status}`)}</span>
      {program.status === 'draft' && <span className="text-[11px] font-semibold text-sky-700">{t('saber.draft.newProgram')}</span>}
      {d.counts && <span className="text-[11px] text-slate-500">{t('saber.draft.changes', { groups: d.counts.groups, days: d.counts.days })}</span>}
      {d.editor && <span className="text-[11px] text-slate-400">{t('saber.draft.by', { name: d.editor })} · {fmt.dateTime(d.updated_at)}</span>}
      {returned && <span className="flex w-full items-center gap-1.5 text-xs font-semibold text-amber-800"><MessageSquareWarning className="size-3.5" />{t('saber.draft.note', { note: d.review_note })}</span>}
      <span className="ms-auto flex flex-wrap gap-1.5">
        <Button size="sm" variant="ghost" icon={<Eye className="size-4" />} onClick={() => setViewing(true)}>{t('saber.draft.view')}</Button>
        {d.status === 'draft' && <Button size="sm" variant="primary" icon={<Send className="size-4" />} loading={busy} onClick={submit}>{t('saber.draft.submit')}</Button>}
        {canDiscard && <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={discard}>{t('saber.draft.discard')}</Button>}
      </span>
      {viewing && <DraftDiffModal programId={program.id} title={program.title} onClose={() => setViewing(false)} />}
    </div>
  )
}

function DraftDiffModal({ programId, title, onClose }: { programId: string; title: string; onClose: () => void }) {
  const { t } = useTranslation()
  const { data } = useGet<{ data: { diff: Diff } }>(`/admin/saber/programs/${programId}/draft`, undefined, { staleTime: 0 })
  return (
    <Modal open onClose={onClose} title={`${t('saber.draft.view')} · ${title}`} wide>
      {!data ? <div className="h-40 animate-pulse rounded-xl bg-ivory" /> : <div className="max-h-[65vh] overflow-y-auto pe-1"><DiffView diff={data.data.diff} /></div>}
    </Modal>
  )
}

/** «برنامج جديد» in SABER+: a draft programme in the Programmes page, planned here and published after review. */
export function NewProgramDialog({ overview, onClose, onCreated }: { overview: Overview; onClose: () => void; onCreated: (id: string) => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState({ title: '', delivery_mode: 'in_person', total_hours: '15', training_days: '', groups: '1', kit_ready: '', start_date: '', coordinator_id: '', objective: '', capacity: '25' })
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof f, v: string) => setF((x) => ({ ...x, [k]: v }))
  const create = async () => {
    setBusy(true)
    try {
      const { data } = await api.post('/admin/saber/programs', {
        title: f.title.trim(), delivery_mode: f.delivery_mode, total_hours: Number(f.total_hours), training_days: f.training_days ? Number(f.training_days) : null, groups: Number(f.groups) || 1,
        kit_ready: f.kit_ready === '' ? null : f.kit_ready === '1', start_date: f.start_date || null, coordinator_id: f.coordinator_id || null, objective: f.objective.trim() || null, capacity: Number(f.capacity) || 25,
      })
      toast(t('saber.newProg.created')); onCreated(data.data.id); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const input = 'h-10 w-full rounded-xl border border-navy-100 bg-white px-3 text-sm focus:border-gold-400 focus:outline-none'
  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><FilePlus2 className="size-5 text-gold-600" />{t('saber.newProg.title')}</span>} wide>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{t('saber.newProg.hint')}</p>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={<>{t('saber.newProg.name')} <span className="text-rose-600">*</span></>} className="sm:col-span-2"><input className={input} value={f.title} onChange={(e) => set('title', e.target.value)} /></Field>
        <Field label={t('saber.newProg.mode')}><select className={input} value={f.delivery_mode} onChange={(e) => set('delivery_mode', e.target.value)}>{['in_person', 'online', 'hybrid'].map((m) => <option key={m} value={m}>{t(`modes.${m}`)}</option>)}</select></Field>
        <Field label={t('saber.newProg.hours')}><input type="number" min={1} className={input} value={f.total_hours} onChange={(e) => set('total_hours', e.target.value)} /></Field>
        <Field label={t('saber.newProg.days')}><input type="number" min={1} className={input} value={f.training_days} onChange={(e) => set('training_days', e.target.value)} /></Field>
        <Field label={t('saber.newProg.groups')}><input type="number" min={1} max={30} className={input} value={f.groups} onChange={(e) => set('groups', e.target.value)} /></Field>
        <Field label={t('saber.newProg.kit')}><select className={input} value={f.kit_ready} onChange={(e) => set('kit_ready', e.target.value)}><option value="">—</option><option value="1">{t('saber.newProg.kitReady')}</option><option value="0">{t('saber.newProg.kitNot')}</option></select></Field>
        <Field label={t('saber.newProg.start')}><input type="date" dir="ltr" className={input} value={f.start_date} onChange={(e) => set('start_date', e.target.value)} /></Field>
        <Field label={t('saber.newProg.supervisor')}><select className={input} value={f.coordinator_id} onChange={(e) => set('coordinator_id', e.target.value)}><option value="">—</option>{overview.supervisors.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select></Field>
        <Field label={t('saber.newProg.capacity')}><input type="number" min={1} className={input} value={f.capacity} onChange={(e) => set('capacity', e.target.value)} /></Field>
        <Field label={t('saber.newProg.objective')} className="sm:col-span-2"><textarea rows={2} className="w-full rounded-xl border border-navy-100 p-3 text-sm focus:border-gold-400 focus:outline-none" value={f.objective} onChange={(e) => set('objective', e.target.value)} /></Field>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>{t('common.cancel', { defaultValue: 'إلغاء' })}</Button>
        <Button variant="gold" loading={busy} disabled={!f.title.trim() || !Number(f.total_hours)} onClick={create}>{t('saber.newProg.create')}</Button>
      </div>
    </Modal>
  )
}
