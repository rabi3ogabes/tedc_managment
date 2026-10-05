import { CalendarPlus, Copy, GraduationCap, Globe, GlobeLock, Pencil, Plus, Trash2, UserPlus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Program } from '@/lib/types'

export type GroupTrainer = { id: string; trainer_id: string; name: string | null; role: string; status: string }
export type Group = {
  id: string; code: string; title: string; delivery_mode: string; start_date: string | null; end_date: string | null; capacity: number; seats_taken: number; status: string; status_reason: string | null
  allowed_transitions: string[]; is_emergency: boolean; published: boolean; sessions_count?: number; trainers?: GroupTrainer[]; program?: { id: string; title: string; code: string } | null; postponed_to: string | null
}
const MODES = ['in_person', 'online', 'blended', 'self_paced']
export const STATUS_TONE: Record<string, 'navy' | 'gold' | 'green' | 'red' | 'slate'> = { planned: 'slate', registration_open: 'gold', ongoing: 'green', incomplete: 'red', postponed: 'navy', cancelled: 'red', completed: 'green' }

/** Status change dialog shared by the program tab and the status board. */
export function StatusDialog({ group, to, onClose, onDone }: { group: Group | null; to?: string; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [status, setStatus] = useState('')
  const [reason, setReason] = useState('')
  const [postponedTo, setPostponedTo] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (group) { setStatus(to ?? group.allowed_transitions[0] ?? ''); setReason(''); setPostponedTo('') } }, [group, to])
  if (!group) return null
  const needsReason = ['postponed', 'cancelled', 'incomplete'].includes(status)
  const go = async () => {
    setBusy(true)
    try { await api.post(`/admin/groups/${group.id}/status`, { status, reason: reason || undefined, postponed_to: postponedTo || undefined }); toast(t('groups.saved')); onClose(); onDone() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open onClose={onClose} title={`${t('groups.changeStatus')} — ${group.code}`}>
      <div className="space-y-4">
        <Field label={t('groups.changeStatus')}><select className="input" value={status} onChange={(e) => setStatus(e.target.value)}>{group.allowed_transitions.map((s) => <option key={s} value={s}>{t(`groups.status.${s}`)}</option>)}</select></Field>
        {needsReason && <Field label={t('groups.reason')}><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>}
        {status === 'postponed' && <Field label={t('groups.postponedTo')}><input type="date" className="input" value={postponedTo} onChange={(e) => setPostponedTo(e.target.value)} /></Field>}
        <div className="flex gap-2"><Button variant="gold" loading={busy} disabled={!status || (needsReason && !reason.trim())} onClick={() => void go()}>{t('groups.save')}</Button><Button variant="outline" onClick={onClose}>{t('groups.cancel')}</Button></div>
      </div>
    </Modal>
  )
}

const blank = { start_date: '', end_date: '', capacity: 25, delivery_mode: 'in_person', weekdays: [0, 1, 2, 3, 4] as number[], starts: '08:00', ends: '13:00', count: 5, is_emergency: false, emergency_reason: '' }

/** Program → Groups: every run of the program with its own dates, seats, status and trainers (proposal → form → approval). */
export default function GroupsTab({ program }: { program: Program }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const list = useGet<{ data: Group[] }>(`/admin/programs/${program.id}/groups`, undefined, { staleTime: 0 })
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState(blank)
  const [busy, setBusy] = useState(false)
  const [statusFor, setStatusFor] = useState<Group | null>(null)
  const [proposeFor, setProposeFor] = useState<Group | null>(null)
  const [decideFor, setDecideFor] = useState<GroupTrainer | null>(null)
  const manage = can('groups.manage')

  const create = async () => {
    setBusy(true)
    try {
      const { data } = await api.post(`/admin/programs/${program.id}/groups`, {
        start_date: form.start_date, end_date: form.end_date || form.start_date, capacity: form.capacity, delivery_mode: form.delivery_mode, is_emergency: form.is_emergency, emergency_reason: form.is_emergency ? form.emergency_reason : undefined,
        sessions: { start_date: form.start_date, weekdays: form.weekdays, starts: form.starts, ends: form.ends, count: form.count },
      })
      const skipped = (data.data.skipped ?? []).length
      toast(skipped ? `${t('groups.saved')} — ${t('groups.skipped', { n: skipped })}` : t('groups.saved')); setCreating(false); setForm(blank); await list.refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const act = async (fn: () => Promise<unknown>, ok: string) => { try { await fn(); toast(ok); await list.refetch() } catch (e) { toast(errorMessage(e), 'error') } }

  if (list.isLoading || !list.data) return <Spinner />
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3"><div><h3 className="font-bold text-navy-900">{t('groups.title')}</h3><p className="text-sm text-slate-500">{t('groups.hint')}</p></div>
        {manage && <div className="flex gap-2"><KitDevelopers programId={program.id} /><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('groups.new')}</Button></div>}</div>

      {list.data.data.length === 0 ? <Card><Empty text={t('groups.empty')} /></Card> : list.data.data.map((g) => (
        <Card key={g.id} className="space-y-3">
          <div className="flex flex-wrap items-center gap-3">
            <span className="font-mono text-xs text-slate-400" dir="ltr">{g.code}</span>
            <h4 className="min-w-0 flex-1 truncate font-bold text-navy-900">{g.title}</h4>
            <Badge color={STATUS_TONE[g.status] ?? 'navy'}>{t(`groups.status.${g.status}`)}</Badge>
            {g.is_emergency && <Badge color="red">{t('groups.emergency')}</Badge>}
            <Badge color={g.published ? 'green' : 'slate'}>{g.published ? t('groups.published') : t('groups.draft')}</Badge>
          </div>
          <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-600">
            <span>{fmt.date(g.start_date)} – {fmt.date(g.end_date)}</span><span>{fmt.number(g.seats_taken)} / {fmt.number(g.capacity)} {t('groups.seats')}</span><span>{fmt.number(g.sessions_count ?? 0)} {t('groups.sessions')}</span><span>{t(`groups.modes.${g.delivery_mode}`)}</span>
            {g.status_reason && <span className="text-slate-500">{g.status_reason}</span>}
          </div>
          <div className="rounded-xl bg-ivory p-3">
            <div className="mb-2 flex items-center justify-between"><span className="flex items-center gap-1.5 text-xs font-bold text-slate-500"><GraduationCap className="size-4" />{t('groups.trainers.title')}</span>{manage && <button type="button" onClick={() => setProposeFor(g)} className="flex items-center gap-1 text-xs font-bold text-gold-700 hover:underline"><UserPlus className="size-3.5" />{t('groups.trainers.propose')}</button>}</div>
            {(g.trainers ?? []).length === 0 ? <p className="text-xs text-slate-400">{t('groups.trainers.none')}</p> : (
              <ul className="space-y-1.5">{g.trainers!.map((tr) => (
                <li key={tr.id} className="flex flex-wrap items-center gap-2 text-sm"><span className="font-semibold text-navy-900">{tr.name}</span><span className="text-xs text-slate-400">{t(`groups.trainers.${tr.role}`)}</span><Badge color={tr.status === 'approved' ? 'green' : tr.status === 'rejected' ? 'red' : 'gold'}>{t(`groups.trainers.${tr.status}`)}</Badge>
                  {tr.status === 'proposed' && can('trainers.approve') && <button type="button" onClick={() => setDecideFor(tr)} className="text-xs font-bold text-navy-700 underline">{t('groups.trainers.approve')} / {t('groups.trainers.reject')}</button>}</li>
              ))}</ul>
            )}
          </div>
          {manage && (
            <div className="flex flex-wrap gap-2">
              {g.allowed_transitions.length > 0 && can('groups.status') && <Button size="sm" variant="outline" icon={<Pencil className="size-4" />} onClick={() => setStatusFor(g)}>{t('groups.changeStatus')}</Button>}
              <Button size="sm" variant="outline" icon={g.published ? <GlobeLock className="size-4" /> : <Globe className="size-4" />} onClick={() => void act(() => api.post(`/admin/groups/${g.id}/${g.published ? 'unpublish' : 'publish'}`), t('groups.saved'))}>{g.published ? t('groups.unpublish') : t('groups.publish')}</Button>
              <Button size="sm" variant="outline" icon={<Copy className="size-4" />} onClick={() => { const d = window.prompt(t('groups.start'), g.end_date ?? ''); if (d) void act(() => api.post(`/admin/groups/${g.id}/clone`, { start_date: d }), t('groups.saved')) }}>{t('groups.clone')}</Button>
              {g.seats_taken === 0 && <button type="button" aria-label={t('groups.delete')} onClick={() => { if (window.confirm(t('groups.confirmDelete', { name: g.title }))) void act(() => api.delete(`/admin/groups/${g.id}`), t('groups.deleted')) }} className="grid size-9 place-items-center rounded-xl text-slate-400 hover:bg-red-50 hover:text-danger"><Trash2 className="size-4" /></button>}
            </div>
          )}
        </Card>
      ))}

      <Modal open={creating} onClose={() => setCreating(false)} title={t('groups.new')} wide>
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Field label={t('groups.start')}><input type="date" className="input" value={form.start_date} onChange={(e) => setForm({ ...form, start_date: e.target.value })} /></Field>
            <Field label={t('groups.end')}><input type="date" className="input" value={form.end_date} onChange={(e) => setForm({ ...form, end_date: e.target.value })} /></Field>
            <Field label={t('groups.capacity')}><input type="number" min={1} className="input" value={form.capacity} onChange={(e) => setForm({ ...form, capacity: Number(e.target.value) })} /></Field>
            <Field label={t('groups.mode')}><select className="input" value={form.delivery_mode} onChange={(e) => setForm({ ...form, delivery_mode: e.target.value })}>{MODES.map((m) => <option key={m} value={m}>{t(`groups.modes.${m}`)}</option>)}</select></Field>
          </div>
          <Field label={t('groups.weekdays')}>
            <div className="flex flex-wrap gap-2">{(t('groups.days', { returnObjects: true }) as string[]).map((d, i) => { const on = form.weekdays.includes(i); return <button key={d} type="button" aria-pressed={on} onClick={() => setForm({ ...form, weekdays: on ? form.weekdays.filter((x) => x !== i) : [...form.weekdays, i].sort() })} className={on ? 'rounded-full bg-navy-900 px-3 py-1.5 text-sm font-bold text-white' : 'rounded-full border border-navy-100 bg-white px-3 py-1.5 text-sm text-slate-600'}>{d}</button> })}</div>
          </Field>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label={t('groups.starts')}><input type="time" className="input" value={form.starts} onChange={(e) => setForm({ ...form, starts: e.target.value })} /></Field>
            <Field label={t('groups.ends')}><input type="time" className="input" value={form.ends} onChange={(e) => setForm({ ...form, ends: e.target.value })} /></Field>
            <Field label={t('groups.count')}><input type="number" min={1} max={120} className="input" value={form.count} onChange={(e) => setForm({ ...form, count: Number(e.target.value) })} /></Field>
          </div>
          <label className="flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" checked={form.is_emergency} onChange={(e) => setForm({ ...form, is_emergency: e.target.checked })} />{t('groups.emergency')}</label>
          {form.is_emergency && <Field label={t('groups.emergencyReason')}><textarea className="input min-h-16" value={form.emergency_reason} onChange={(e) => setForm({ ...form, emergency_reason: e.target.value })} /></Field>}
          <div className="flex gap-2"><Button variant="gold" icon={<CalendarPlus className="size-4" />} loading={busy} disabled={!form.start_date || form.weekdays.length === 0 || (form.is_emergency && !form.emergency_reason.trim())} onClick={() => void create()}>{t('groups.create')}</Button><Button variant="outline" onClick={() => setCreating(false)}>{t('groups.cancel')}</Button></div>
        </div>
      </Modal>

      <StatusDialog group={statusFor} onClose={() => setStatusFor(null)} onDone={() => void list.refetch()} />
      <ProposeDialog group={proposeFor} locale={i18n.language} onClose={() => setProposeFor(null)} onDone={() => void list.refetch()} />
      <DecideDialog assignment={decideFor} onClose={() => setDecideFor(null)} onDone={() => void list.refetch()} />
    </div>
  )
}

function ProposeDialog({ group, onClose, onDone }: { group: Group | null; locale: string; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [trainerId, setTrainerId] = useState('')
  const [role, setRole] = useState('lead')
  const [hours, setHours] = useState(0)
  const [busy, setBusy] = useState(false)
  const found = useGet<{ data: { id: string; name: string }[] }>(group ? '/admin/trainers' : null, { q, per_page: 15, status: 'active' }, { staleTime: 30_000 })
  useEffect(() => { if (group) { setQ(''); setTrainerId(''); setRole('lead'); setHours(0) } }, [group])
  if (!group) return null
  const go = async () => {
    setBusy(true)
    try { await api.post(`/admin/groups/${group.id}/trainers`, { trainer_id: trainerId, role, hours }); toast(t('groups.trainers.proposedOk')); onClose(); onDone() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open onClose={onClose} title={`${t('groups.trainers.propose')} — ${group.code}`}>
      <div className="space-y-4">
        <Field label={t('groups.trainers.pickTrainer')}>
          <input className="input mb-2" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('groups.kit.search')} />
          <select className="input" size={6} value={trainerId} onChange={(e) => setTrainerId(e.target.value)}>{found.data?.data.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
        </Field>
        <div className="grid grid-cols-2 gap-4">
          <Field label={t('groups.trainers.role')}><select className="input" value={role} onChange={(e) => setRole(e.target.value)}><option value="lead">{t('groups.trainers.lead')}</option><option value="assistant">{t('groups.trainers.assistant')}</option></select></Field>
          <Field label={t('groups.trainers.hours')}><input type="number" min={0} className="input" value={hours} onChange={(e) => setHours(Number(e.target.value))} /></Field>
        </div>
        <div className="flex gap-2"><Button variant="gold" loading={busy} disabled={!trainerId} onClick={() => void go()}>{t('groups.trainers.propose')}</Button><Button variant="outline" onClick={onClose}>{t('groups.cancel')}</Button></div>
      </div>
    </Modal>
  )
}

function DecideDialog({ assignment, onClose, onDone }: { assignment: GroupTrainer | null; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [ref, setRef] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  useEffect(() => { setRef(''); setNote('') }, [assignment])
  if (!assignment) return null
  const go = async (decision: 'approved' | 'rejected') => {
    setBusy(true)
    try { await api.post(`/admin/group-trainers/${assignment.id}/decision`, { decision, external_approval_ref: ref || undefined, note: note || undefined }); toast(t('groups.trainers.decided')); onClose(); onDone() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open onClose={onClose} title={assignment.name ?? ''}>
      <div className="space-y-4">
        <Field label={t('groups.trainers.ref')} hint={t('groups.trainers.refHint')}><input className="input" dir="ltr" value={ref} onChange={(e) => setRef(e.target.value)} /></Field>
        <Field label={t('groups.trainers.note')}><textarea className="input min-h-16" value={note} onChange={(e) => setNote(e.target.value)} /></Field>
        <div className="flex gap-2"><Button variant="gold" loading={busy} disabled={!ref.trim()} onClick={() => void go('approved')}>{t('groups.trainers.approve')}</Button><Button variant="outline" loading={busy} disabled={!note.trim()} onClick={() => void go('rejected')}>{t('groups.trainers.reject')}</Button></div>
      </div>
    </Modal>
  )
}

function KitDevelopers({ programId }: { programId: string }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const [picked, setPicked] = useState<{ id: string; name: string }[]>([])
  const [due, setDue] = useState('')
  const [busy, setBusy] = useState(false)
  const found = useGet<{ data: { id: string; name: string; email: string }[] }>(open && q.trim().length >= 2 ? '/admin/staff-lookup' : null, { q: q.trim() }, { retry: false })
  const go = async () => {
    setBusy(true)
    try { await api.post(`/admin/programs/${programId}/kit-developers`, { user_ids: picked.map((p) => p.id), due_at: due || undefined }); toast(t('groups.kit.assigned')); setOpen(false); setPicked([]) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>{t('groups.kit.title')}</Button>
      <Modal open={open} onClose={() => setOpen(false)} title={t('groups.kit.title')}>
        <div className="space-y-4">
          <p className="text-sm text-slate-500">{t('groups.kit.hint')}</p>
          <div className="flex flex-wrap gap-2">{picked.map((p) => <button key={p.id} type="button" onClick={() => setPicked(picked.filter((x) => x.id !== p.id))} className="rounded-full bg-navy-900 px-3 py-1 text-xs font-bold text-white">{p.name} ×</button>)}</div>
          <input className="input" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('groups.kit.search')} />
          {found.data && <ul className="max-h-48 overflow-auto rounded-xl border border-navy-100">{found.data.data.map((p) => <li key={p.id}><button type="button" className="flex w-full flex-col px-3 py-2 text-start hover:bg-ivory" onClick={() => { if (!picked.some((x) => x.id === p.id)) setPicked([...picked, { id: p.id, name: p.name }]); setQ('') }}><span className="text-sm font-semibold">{p.name}</span><span className="text-xs text-slate-400">{p.email}</span></button></li>)}</ul>}
          <Field label={t('groups.kit.due')}><input type="date" className="input" value={due} onChange={(e) => setDue(e.target.value)} /></Field>
          <Button variant="gold" loading={busy} disabled={picked.length === 0} onClick={() => void go()}>{t('groups.kit.assign')}</Button>
        </div>
      </Modal>
    </>
  )
}
