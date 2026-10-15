import { AlertTriangle, CalendarPlus, CheckCircle2, Clock, Link2, MapPin, Pencil, QrCode, Send, Trash2, Users, Video, Wand2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Empty, ErrorState, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { Stepper } from './SchoolsStep'
import { STATE_TONE, type WorkshopDetail } from './plcApi'

type Mode = 'in_person' | 'online' | 'hybrid'
type TrainerRef = { trainer_id: string; name?: string | null; name_en?: string | null; role: 'lead' | 'co'; status?: string }
type Session = { id: string; sequence: number; title_ar: string; title_en?: string | null; description?: string | null; starts_at: string; ends_at: string; minutes?: number | null; status: string; mode: Mode
  room?: { id: string; name: string; name_en?: string | null; capacity?: number | null } | null; location_text?: string | null
  online: { platform?: string | null; has_link: boolean; url?: string | null; passcode?: string | null; protected: boolean }; checkin_window_minutes?: number | null; checkout_window_minutes?: number | null
  trainers: TrainerRef[]; missing: ('venue' | 'link' | 'trainer')[] }
type Overview = { sessions: Session[]; readiness: { sessions: number; complete: boolean; missing: { session: string; sequence: number; issue: string }[] }; invited: boolean
  can: { plan: boolean; schedule: boolean; change_needs_reason: boolean }; conflicts: { session_id: string; sequence: number; starts_at: string; issues: { kind: string; with?: string[]; trainer?: string | null; count?: number }[] }[]
  trainers: TrainerRef[]; program: { delivery_mode: Mode; start_date?: string | null; end_date?: string | null; capacity?: number | null } }
type Room = { id: string; name_ar: string; name_en?: string | null; capacity?: number | null }
type Form = { title_ar: string; date: string; from: string; to: string; mode: Mode; training_room_id: string; location_text: string; online_url: string; online_platform: string; online_passcode: string; trainer_ids: string[]; reason: string; force: boolean; force_reason: string; calendar_approval_reason: string }

const blank = (mode: Mode, date = ''): Form => ({ title_ar: '', date, from: '09:00', to: '12:00', mode, training_room_id: '', location_text: '', online_url: '', online_platform: '', online_passcode: '', trainer_ids: [], reason: '', force: false, force_reason: '', calendar_approval_reason: '' })
const toLocal = (iso: string) => { const d = new Date(iso); const p = (n: number) => String(n).padStart(2, '0'); return { date: `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`, time: `${p(d.getHours())}:${p(d.getMinutes())}` } }
const MODE_ICON: Record<Mode, React.ReactNode> = { in_person: <MapPin className="size-3.5" />, online: <Video className="size-3.5" />, hybrid: <Link2 className="size-3.5" /> }

/** Step 5: the sessions. Each has its time, delivery mode, venue or protected link, and trainers; conflicts are shown and alternatives offered, never applied by themselves. */
export default function ScheduleStep() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const en = i18n.language === 'en'
  const detail = useGet<{ data: WorkshopDetail }>(`/admin/plc/workshops/${id}`, undefined, { staleTime: 0 })
  const ov = useGet<{ data: Overview }>(`/admin/plc/workshops/${id}/sessions`, undefined, { staleTime: 0 })
  const rooms = useGet<{ data: Room[] }>('/admin/rooms', { status: 'active', per_page: 100 })
  const [edit, setEdit] = useState<{ session: Session | null; form: Form } | null>(null)
  const [alt, setAlt] = useState<{ slots: { starts_at: string; ends_at: string; date: string }[]; rooms: Room[] } | null>(null)
  const [error, setError] = useState<{ code?: string; message: string; details?: Record<string, unknown> } | null>(null)
  const [busy, setBusy] = useState(false)
  const [remove, setRemove] = useState<Session | null>(null)
  const [reason, setReason] = useState('')
  if (detail.isLoading || ov.isLoading) return <Spinner />
  if (detail.error || !detail.data || !ov.data) return <ErrorState error={detail.error ?? ov.error} onRetry={() => { detail.refetch(); ov.refetch() }} home="/admin/communities?tab=workshops" />
  const w = detail.data.data
  const o = ov.data.data
  const refresh = () => Promise.all([ov.refetch(), detail.refetch()])
  const name = (x: { name?: string | null; name_en?: string | null }) => (en && x.name_en ? x.name_en : x.name) ?? ''
  const payload = (f: Form) => ({
    title_ar: f.title_ar || undefined, starts_at: `${f.date}T${f.from}:00`, ends_at: `${f.date}T${f.to}:00`, mode: f.mode,
    training_room_id: f.mode !== 'online' && f.training_room_id ? f.training_room_id : null, location_text: f.mode !== 'online' ? f.location_text || null : null,
    online_url: f.mode !== 'in_person' ? f.online_url || null : null, online_platform: f.mode !== 'in_person' ? f.online_platform || null : null, online_passcode: f.mode !== 'in_person' ? f.online_passcode || null : null,
    trainer_ids: f.trainer_ids, reason: f.reason || undefined, force: f.force || undefined, force_reason: f.force_reason || undefined, calendar_approval_reason: f.calendar_approval_reason || undefined,
  })
  const save = async () => {
    if (!edit) return
    setBusy(true); setError(null)
    try {
      if (edit.session) await api.put(`/admin/plc/workshops/${id}/sessions/${edit.session.id}`, payload(edit.form)); else await api.post(`/admin/plc/workshops/${id}/sessions`, payload(edit.form))
      toast(String(t('plc.schedule.saved'))); setEdit(null); setAlt(null); await refresh()
    } catch (e) { const r = (e as { response?: { data?: { code?: string; details?: Record<string, unknown> } } }).response?.data; setError({ code: r?.code, message: errorMessage(e), details: r?.details }) } finally { setBusy(false) }
  }
  const alternatives = async () => {
    if (!edit) return
    try { const p = payload(edit.form); const { data } = await api.post(`/admin/plc/workshops/${id}/sessions/alternatives`, { starts_at: p.starts_at, ends_at: p.ends_at, training_room_id: p.training_room_id, trainer_ids: p.trainer_ids, except: edit.session?.id }); setAlt(data.data) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const schedule = async () => {
    if (!window.confirm(String(t('plc.schedule.confirmSchedule')))) return
    try { const { data } = await api.post(`/admin/plc/workshops/${id}/schedule`); toast(String(t('plc.schedule.scheduled', { n: data.data.notified }))); await refresh() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const doRemove = async () => {
    if (!remove) return
    try { await api.delete(`/admin/plc/workshops/${id}/sessions/${remove.id}`, { data: { reason: reason || undefined } }); toast(String(t('plc.schedule.removed'))); setRemove(null); setReason(''); await refresh() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const open = (s: Session | null) => {
    const f = s ? { ...blank(s.mode, toLocal(s.starts_at).date), title_ar: s.title_ar, from: toLocal(s.starts_at).time, to: toLocal(s.ends_at).time, training_room_id: s.room?.id ?? '', location_text: s.location_text ?? '', online_url: s.online.url ?? '', online_platform: s.online.platform ?? '', online_passcode: s.online.passcode ?? '', trainer_ids: s.trainers.map((x) => x.trainer_id) } : blank(o.program.delivery_mode, o.program.start_date ?? '')
    setError(null); setAlt(null); setEdit({ session: s, form: f })
  }
  const conflictOf = (sid: string) => o.conflicts.find((c) => c.session_id === sid)

  return (
    <>
      <PageHeader title={<span className="flex flex-wrap items-center gap-3">{en && w.title_en ? w.title_en : w.title_ar}<Badge color={STATE_TONE[w.state]}>{t(`plc.state.${w.state}`)}</Badge></span>} subtitle={t('plc.schedule.subtitle')}
        actions={<div className="flex flex-wrap gap-2"><Button variant="outline" to={`/admin/communities/workshops/${id}`}>{t('plc.detail.back')}</Button>{o.can.plan && <Button variant="gold" icon={<CalendarPlus className="size-4" />} onClick={() => open(null)}>{t('plc.schedule.add')}</Button>}</div>} />
      <Stepper current={4} id={id!} />
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          {!o.sessions.length ? <Card><Empty text={String(t('plc.schedule.empty'))} icon={<Clock className="size-6" />} /></Card> : o.sessions.map((s) => {
            const c = conflictOf(s.id)
            return (
              <Card key={s.id} className={s.status === 'cancelled' ? 'opacity-60' : ''}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div>
                    <div className="flex flex-wrap items-center gap-2"><span className="grid size-7 place-items-center rounded-full bg-navy-900 text-xs font-bold text-white">{s.sequence}</span><span className="font-semibold text-navy-900">{en && s.title_en ? s.title_en : s.title_ar}</span><Badge color="gray">{MODE_ICON[s.mode]} {t(`plc.mode.${s.mode}`)}</Badge>{s.status === 'cancelled' && <Badge color="red">{t('plc.schedule.cancelled')}</Badge>}</div>
                    <div className="mt-1 text-sm text-slate-600">{fmt.dateTime(s.starts_at)} → {toLocal(s.ends_at).time} · {s.minutes ? t('plc.schedule.minutes', { n: s.minutes }) : ''}</div>
                    <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                      {s.mode !== 'online' && <span className="inline-flex items-center gap-1"><MapPin className="size-3.5" />{s.room ? `${name({ name: s.room.name, name_en: s.room.name_en })} (${s.room.capacity ?? '—'})` : s.location_text || t('plc.schedule.noVenue')}</span>}
                      {s.mode !== 'in_person' && <span className="inline-flex items-center gap-1"><Video className="size-3.5" />{s.online.has_link ? `${s.online.platform || t('plc.schedule.link')}${s.online.protected ? ` · ${t('plc.schedule.protected')}` : ''}` : t('plc.schedule.noLink')}</span>}
                      <span className="inline-flex items-center gap-1"><Users className="size-3.5" />{s.trainers.length ? s.trainers.map((x) => `${name(x)} (${t(`plc.trainers.role.${x.role}`, { defaultValue: x.role })})`).join('، ') : t('plc.schedule.noTrainer')}</span>
                    </div>
                    {!!s.missing.length && <div className="mt-2 flex flex-wrap gap-1">{s.missing.map((m) => <Badge key={m} color="amber">{t(`plc.schedule.missing.${m}`)}</Badge>)}</div>}
                    {c && <div className="mt-2 space-y-0.5 text-xs text-red-700">{c.issues.map((i, k) => <div key={k} className="flex items-center gap-1"><AlertTriangle className="size-3.5" />{t(`plc.schedule.conflict.${i.kind}`, { n: i.count, trainer: i.trainer })}{i.with?.length ? `: ${i.with.join('، ')}` : ''}</div>)}</div>}
                  </div>
                  {o.can.plan && s.status !== 'cancelled' && <div className="flex gap-1">{o.invited && <Button size="sm" variant="gold" icon={<QrCode className="size-3.5" />} to={`/admin/communities/workshops/${id}/sessions/${s.id}/attendance`}>{t('plc.attendance.open')}</Button>}<Button size="sm" variant="outline" icon={<Pencil className="size-3.5" />} onClick={() => open(s)}>{t('common.edit')}</Button><Button size="sm" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => { setRemove(s); setReason('') }}>{t('common.remove', { defaultValue: 'إزالة' })}</Button></div>}
                </div>
              </Card>
            )
          })}
        </div>
        <div className="space-y-4">
          <Card>
            <CardTitle subtitle={t('plc.schedule.readinessHint')}>{t('plc.schedule.readiness')}</CardTitle>
            <div className="flex items-center gap-2 text-sm">{o.readiness.complete ? <CheckCircle2 className="size-5 text-emerald-600" /> : <AlertTriangle className="size-5 text-amber-600" />}<span>{t('plc.schedule.sessionsN', { n: o.readiness.sessions })}</span></div>
            {!!o.readiness.missing.length && <ul className="mt-2 space-y-0.5 text-xs text-amber-800">{o.readiness.missing.map((m, k) => <li key={k}>{t('plc.schedule.sessionN', { n: m.sequence })}: {t(`plc.schedule.missing.${m.issue}`)}</li>)}</ul>}
            {o.invited ? <p className="mt-3 rounded-xl bg-blue-50 px-3 py-2 text-xs text-blue-900">{t('plc.schedule.invitedNote')}</p> : w.state === 'approved' ? (
              <Button className="mt-3 w-full" variant="gold" icon={<Send className="size-4" />} disabled={!o.can.schedule} onClick={schedule}>{t('plc.schedule.scheduleNow')}</Button>
            ) : <p className="mt-3 text-xs text-slate-500">{t('plc.schedule.approveFirst')}</p>}
          </Card>
          <Card>
            <CardTitle>{t('plc.schedule.conflicts')}</CardTitle>
            {!o.conflicts.length ? <p className="text-sm text-emerald-700">{t('plc.schedule.noConflicts')}</p> : <ul className="space-y-1 text-xs">{o.conflicts.map((c) => <li key={c.session_id}><b>{t('plc.schedule.sessionN', { n: c.sequence })}</b> · {c.issues.map((i) => t(`plc.schedule.conflict.${i.kind}`, { n: i.count, trainer: i.trainer })).join(' · ')}</li>)}</ul>}
          </Card>
          {o.invited && <Card><CardTitle subtitle={t('plc.attendance.reportHint')}>{t('plc.attendance.report')}</CardTitle><Button variant="outline" size="sm" to={`/admin/communities/workshops/${id}/attendance`}>{t('plc.attendance.openReport')}</Button></Card>}
          <Card><CardTitle>{t('plc.schedule.program')}</CardTitle><dl className="text-sm"><dt className="text-xs text-slate-500">{t('plc.mode.' + o.program.delivery_mode)}</dt><dd className="font-semibold text-navy-900">{o.program.start_date ? `${fmt.date(o.program.start_date)} → ${fmt.date(o.program.end_date ?? o.program.start_date)}` : '—'}</dd></dl><p className="mt-1 text-xs text-slate-500">{t('plc.schedule.datesNote')}</p></Card>
        </div>
      </div>

      <Modal open={!!edit} onClose={() => setEdit(null)} title={edit?.session ? t('plc.schedule.editSession') : t('plc.schedule.add')} wide>
        {edit && (
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-4">
              <Field label={t('plc.schedule.title')} className="sm:col-span-2"><input className="input" value={edit.form.title_ar} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, title_ar: e.target.value } })} placeholder={String(t('plc.schedule.titlePlaceholder'))} /></Field>
              <Field label={t('plc.schedule.date')}><input type="date" className="input" value={edit.form.date} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, date: e.target.value } })} /></Field>
              <Field label={t('plc.schedule.time')}><div className="flex items-center gap-1"><input type="time" className="input" value={edit.form.from} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, from: e.target.value } })} /><span>–</span><input type="time" className="input" value={edit.form.to} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, to: e.target.value } })} /></div></Field>
            </div>
            <div className="flex flex-wrap gap-2">{(['in_person', 'online', 'hybrid'] as Mode[]).map((m) => <button key={m} type="button" onClick={() => setEdit({ ...edit, form: { ...edit.form, mode: m } })} className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm ${edit.form.mode === m ? 'border-gold-500 bg-gold-50 text-navy-900' : 'border-slate-200 text-slate-600'}`}>{MODE_ICON[m]}{t(`plc.mode.${m}`)}</button>)}</div>
            {edit.form.mode !== 'online' && (
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('plc.schedule.room')} hint={t('plc.schedule.roomHint')}><select className="input" value={edit.form.training_room_id} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, training_room_id: e.target.value } })}><option value="">{t('plc.schedule.noRoom')}</option>{(rooms.data?.data ?? []).map((r) => <option key={r.id} value={r.id}>{en && r.name_en ? r.name_en : r.name_ar} ({r.capacity ?? '—'})</option>)}</select></Field>
                <Field label={t('plc.schedule.venue')} hint={t('plc.schedule.venueHint')}><input className="input" value={edit.form.location_text} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, location_text: e.target.value } })} placeholder={w.host_school ? name(w.host_school) : ''} /></Field>
              </div>
            )}
            {edit.form.mode !== 'in_person' && (
              <div className="grid gap-3 sm:grid-cols-3">
                <Field label={t('plc.schedule.linkUrl')} className="sm:col-span-2" hint={t('plc.schedule.linkHint')}><input className="input" dir="ltr" value={edit.form.online_url} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, online_url: e.target.value } })} placeholder="https://" /></Field>
                <Field label={t('plc.schedule.passcode')}><input className="input" dir="ltr" value={edit.form.online_passcode} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, online_passcode: e.target.value } })} /></Field>
                <Field label={t('plc.schedule.platform')}><input className="input" value={edit.form.online_platform} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, online_platform: e.target.value } })} placeholder="Teams" /></Field>
              </div>
            )}
            <Field label={t('plc.schedule.trainers')} hint={o.trainers.length ? undefined : t('plc.schedule.noConfirmedTrainers')}>
              <div className="flex flex-wrap gap-2">{o.trainers.map((x) => { const on = edit.form.trainer_ids.includes(x.trainer_id); return <label key={x.trainer_id} className={`inline-flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm ${on ? 'border-gold-500 bg-gold-50' : 'border-slate-200'}`}><input type="checkbox" className="accent-gold-600" checked={on} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, trainer_ids: e.target.checked ? [...edit.form.trainer_ids, x.trainer_id] : edit.form.trainer_ids.filter((i) => i !== x.trainer_id) } })} />{name(x)} <span className="text-xs text-slate-400">{t(`plc.trainers.role.${x.role}`, { defaultValue: x.role })}</span></label> })}</div>
            </Field>
            {o.can.change_needs_reason && <Field label={t('plc.schedule.changeReason')} hint={t('plc.schedule.changeReasonHint')}><input className="input" value={edit.form.reason} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, reason: e.target.value } })} /></Field>}
            {error && (
              <div className="rounded-2xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <div className="flex items-start gap-2"><AlertTriangle className="mt-0.5 size-4 shrink-0" /><span>{error.message}</span></div>
                {error.code === 'trainer_conflict' && <label className="mt-2 flex items-center gap-2 text-xs"><input type="checkbox" className="accent-gold-600" checked={edit.form.force} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, force: e.target.checked } })} />{t('plc.schedule.forceTrainer')}</label>}
                {error.code === 'trainer_conflict' && edit.form.force && <input className="input mt-2" placeholder={String(t('plc.schedule.forceReason'))} value={edit.form.force_reason} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, force_reason: e.target.value } })} />}
                {error.code === 'calendar_closed' && <input className="input mt-2" placeholder={String(t('plc.schedule.calendarReason'))} value={edit.form.calendar_approval_reason} onChange={(e) => setEdit({ ...edit, form: { ...edit.form, calendar_approval_reason: e.target.value } })} />}
                <Button className="mt-2" size="sm" variant="outline" icon={<Wand2 className="size-3.5" />} onClick={alternatives}>{t('plc.schedule.suggest')}</Button>
              </div>
            )}
            {alt && (
              <div className="rounded-2xl border border-gold-300 bg-gold-50 p-3 text-sm">
                <div className="mb-1 font-semibold text-navy-900">{t('plc.schedule.alternatives')}</div>
                <p className="mb-2 text-xs text-slate-600">{t('plc.schedule.alternativesHint')}</p>
                <div className="flex flex-wrap gap-1.5">{alt.slots.map((s) => <button key={s.starts_at} type="button" className="rounded-full border border-slate-300 bg-white px-3 py-1 text-xs hover:border-gold-500" onClick={() => setEdit({ ...edit, form: { ...edit.form, date: toLocal(s.starts_at).date, from: toLocal(s.starts_at).time, to: toLocal(s.ends_at).time } })}>{fmt.dateTime(s.starts_at)}</button>)}{!alt.slots.length && <span className="text-xs text-slate-500">{t('plc.schedule.noSlots')}</span>}</div>
                {!!alt.rooms.length && <div className="mt-2 flex flex-wrap gap-1.5">{alt.rooms.map((r) => <button key={r.id} type="button" className="rounded-full border border-slate-300 bg-white px-3 py-1 text-xs hover:border-gold-500" onClick={() => setEdit({ ...edit, form: { ...edit.form, training_room_id: r.id } })}><MapPin className="me-1 inline size-3" />{en && r.name_en ? r.name_en : r.name_ar} ({r.capacity ?? '—'})</button>)}</div>}
              </div>
            )}
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setEdit(null)}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} onClick={save}>{t('common.save')}</Button></div>
          </div>
        )}
      </Modal>
      <Modal open={!!remove} onClose={() => setRemove(null)} title={t('plc.schedule.removeTitle')}>
        <p className="text-sm text-slate-600">{o.invited ? t('plc.schedule.removeInvited') : t('plc.schedule.removeDraft')}</p>
        {o.invited && <Field label={t('plc.schedule.changeReason')} className="mt-3"><input className="input" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>}
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setRemove(null)}>{t('common.cancel')}</Button><Button variant="primary" disabled={o.invited && !reason.trim()} onClick={doRemove}>{t('plc.schedule.removeConfirm')}</Button></div>
      </Modal>
    </>
  )
}
