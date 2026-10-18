import clsx from 'clsx'
import { AlertTriangle, CalendarClock, CalendarPlus, CheckCircle2, DoorOpen, Info, Plus, RefreshCcw, Trash2, Wand2 } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Field, Spinner } from '@/components/ui'
import { Switch } from '../notifications/shared'
import { api, errorMessage } from '@/lib/api'

type Level = 'error' | 'warn' | 'info'
type Flag = { code: string; level: Level; ar: string; en: string }
export type PlanSession = {
  sequence: number; title_ar: string; title_en: string; date: string; weekday: number; starts_at: string; ends_at: string; start_time: string; end_time: string; hours: number
  mode: 'in_person' | 'online'; training_room_id: string | null; room: { id: string; name_ar: string; name_en: string } | null; trainer_id: string | null; flags: Flag[]; status: 'ok' | 'warn' | 'error'
}
type PlanRoom = { id: string; name_ar: string; name_en: string; capacity: number; fits: boolean; free_count: number; dates: number; free_all: boolean }
type Summary = {
  count: number; hours: number; first: string | null; last: string | null; pattern: string; weekday: number; every_weeks: number; errors: number; warnings: number; day_hours: number; day_start: string; day_end: string; weekend: number[]
  room: { mode: 'fixed' | 'mixed' | 'none' | 'remote'; room_id: string | null; name_ar: string | null; name_en: string | null }
}
type Plan = { sessions: PlanSession[]; rooms: PlanRoom[]; notes: Flag[]; summary: Summary }
type Recurrence = 'consecutive' | 'weekly' | 'biweekly' | 'custom'
type RoomMode = 'fixed' | 'per_session' | 'none'
export type PlannerInputs = { start_date: string; total_hours: number; capacity: number; delivery_mode: string; trainer_id?: string | null }
export type PlannerState = { enabled: boolean; sessions: PlanSession[]; errors: number; blocking: number; closedDays: number; first: string | null; last: string | null }

const LEVEL_TONE: Record<Level, 'red' | 'amber' | 'blue'> = { error: 'red', warn: 'amber', info: 'blue' }

/**
 * The sessions of a new programme's first group, generated from what the form already says: the start date, the hours, the
 * capacity and how the sessions recur (consecutive days, one fixed weekday every week or every two weeks, or chosen dates).
 * A room is fixed for the whole series when one is free on every date. Every session stays editable and is re-checked as it
 * changes; closed days, the day window and busy rooms or trainers are shown on the row they affect.
 */
export default function SchedulePlanner({ inputs, weekend, canApprove, state, onChange, approval, onApproval }: {
  inputs: PlannerInputs; weekend: number[]; canApprove: boolean; state: PlannerState; onChange: (s: PlannerState) => void; approval: string; onApproval: (v: string) => void
}) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [recurrence, setRecurrence] = useState<Recurrence>('weekly')
  const [weekday, setWeekday] = useState<number | null>(null)
  const [count, setCount] = useState<string>('')
  const [startTime, setStartTime] = useState('')
  const [roomMode, setRoomMode] = useState<RoomMode>('fixed')
  const [roomId, setRoomId] = useState('')
  const [plan, setPlan] = useState<Plan | null>(null)
  const [dirty, setDirty] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const ticket = useRef(0)
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const sessionsRef = useRef(state.sessions)
  sessionsRef.current = state.sessions
  const day = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString(en ? 'en-GB' : 'ar-QA', { weekday: 'long' })
  const weekdayName = (n: number) => new Date(2026, 0, 4 + n, 12).toLocaleDateString(en ? 'en-GB' : 'ar-QA', { weekday: 'long' })
  const effectiveWeekday = weekday ?? (inputs.start_date ? new Date(`${inputs.start_date}T12:00:00`).getDay() : 1)
  const name = (o: { name_ar: string; name_en?: string | null }) => (en && o.name_en ? o.name_en : o.name_ar)

  const publish = useCallback((sessions: PlanSession[], enabled: boolean) => {
    const errors = sessions.filter((s) => s.status === 'error').length
    const closed = sessions.filter((s) => s.flags.some((f) => f.code === 'closed_day')).length
    const other = sessions.filter((s) => s.flags.some((f) => f.level === 'error' && f.code !== 'closed_day')).length
    onChange({ enabled, sessions, errors, blocking: other, closedDays: closed, first: sessions[0]?.date ?? null, last: sessions.length ? sessions[sessions.length - 1].date : null })
  }, [onChange])

  const body = useCallback((extra: Record<string, unknown> = {}) => ({
    total_hours: inputs.total_hours, capacity: inputs.capacity, delivery_mode: inputs.delivery_mode, trainer_id: inputs.trainer_id || undefined, start_time: startTime || undefined, ...extra,
  }), [inputs.total_hours, inputs.capacity, inputs.delivery_mode, inputs.trainer_id, startTime])

  const request = useCallback(async (payload: Record<string, unknown>, keepEdits: boolean) => {
    const mine = ++ticket.current
    setBusy(true); setError(null)
    try {
      const { data } = await api.post<{ data: Plan }>('/admin/programs/schedule-plan', payload)
      if (mine !== ticket.current) return
      setPlan(data.data)
      publish(data.data.sessions, true)
      setDirty(keepEdits)
    } catch (e) {
      if (mine === ticket.current) setError(errorMessage(e))
    } finally {
      if (mine === ticket.current) setBusy(false)
    }
  }, [publish])

  const generate = useCallback((override: Partial<{ recurrence: Recurrence; weekday: number | null; count: string; roomMode: RoomMode; roomId: string; dates: string[] }> = {}) => {
    if (!inputs.start_date || !(inputs.total_hours > 0)) return
    const rec = override.recurrence ?? recurrence
    const wd = override.weekday !== undefined ? override.weekday : weekday
    const c = override.count ?? count
    const dates = override.dates
    request(body({
      start_date: inputs.start_date, pattern: rec === 'consecutive' ? 'consecutive' : rec === 'custom' ? 'custom' : 'weekly', every_weeks: rec === 'biweekly' ? 2 : 1,
      weekday: rec === 'weekly' || rec === 'biweekly' ? (wd ?? new Date(`${inputs.start_date}T12:00:00`).getDay()) : undefined, sessions_count: c ? Number(c) : undefined, dates,
      room_mode: override.roomMode ?? roomMode, room_id: (override.roomId ?? roomId) || undefined,
    }), false)
  }, [inputs.start_date, inputs.total_hours, recurrence, weekday, count, roomMode, roomId, body, request])

  // Regenerate while the person has not edited the rows by hand: every change of the form's facts or of the controls produces the plan again.
  const signature = useMemo(() => JSON.stringify([inputs.start_date, inputs.total_hours, inputs.capacity, inputs.delivery_mode, inputs.trainer_id, recurrence, weekday, count, startTime, roomMode, roomId]), [inputs.start_date, inputs.total_hours, inputs.capacity, inputs.delivery_mode, inputs.trainer_id, recurrence, weekday, count, startTime, roomMode, roomId])
  useEffect(() => {
    if (!state.enabled || dirty) return
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => generate(), 450)
    return () => { if (timer.current) clearTimeout(timer.current) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature, state.enabled, dirty])

  // The person edits a row: the plan is re-checked (not regenerated) so their changes stay.
  const edit = (sessions: PlanSession[]) => {
    setDirty(true)
    publish(sessions, true)
    if (timer.current) clearTimeout(timer.current)
    timer.current = setTimeout(() => request(body({ sessions: sessionsRef.current.map((s) => ({ starts_at: s.starts_at, ends_at: s.ends_at, training_room_id: s.training_room_id, title_ar: s.title_ar, title_en: s.title_en, mode: s.mode })), room_id: roomId || undefined }), true), 450)
  }
  const patchRow = (i: number, p: Partial<PlanSession>) => edit(state.sessions.map((s, j) => (j === i ? { ...s, ...p } : s)))
  const rebuild = (s: PlanSession, date: string, from: string, to: string): PlanSession => ({ ...s, date, start_time: from, end_time: to, starts_at: `${date} ${from}:00`, ends_at: `${date} ${to}:00`, weekday: new Date(`${date}T12:00:00`).getDay() })
  const addSession = () => {
    const last = state.sessions[state.sessions.length - 1]
    const next = new Date(`${(last?.date ?? inputs.start_date) || new Date().toISOString().slice(0, 10)}T12:00:00`)
    next.setDate(next.getDate() + (last ? 7 : 0))
    const date = next.toISOString().slice(0, 10)
    const from = last?.start_time ?? plan?.summary.day_start ?? '08:00'
    const to = last?.end_time ?? '11:00'
    const n = state.sessions.length + 1
    edit([...state.sessions, { ...rebuild(last ?? ({} as PlanSession), date, from, to), sequence: n, title_ar: `الجلسة ${n}`, title_en: `Session ${n}`, flags: [], status: 'ok', mode: last?.mode ?? 'in_person', training_room_id: last?.training_room_id ?? null, room: last?.room ?? null, trainer_id: last?.trainer_id ?? null, hours: last?.hours ?? 3 }])
  }
  const applyRoomToAll = (id: string) => { setRoomId(id); edit(state.sessions.map((s) => ({ ...s, training_room_id: id || null }))) }
  const regenerate = () => { setDirty(false); generate() }
  const pick = (rec: Recurrence) => {
    setRecurrence(rec)
    if (rec === 'custom') { setDirty(true) } else { setDirty(false) }
  }

  if (!state.enabled) {
    return (
      <Card>
        <div className="flex items-center gap-4">
          <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300"><CalendarClock className="size-5" /></span>
          <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{t('admin.programs.plan.title')}</div><p className="text-xs text-slate-500">{t('admin.programs.plan.offHint')}</p></div>
          <Switch checked={false} label={t('admin.programs.plan.title')} onChange={(v) => publish(state.sessions, v)} />
        </div>
      </Card>
    )
  }

  const s = plan?.summary
  const roomLine = s?.room.mode === 'fixed' ? t('admin.programs.plan.roomFixed', { room: name({ name_ar: s.room.name_ar ?? '', name_en: s.room.name_en }) })
    : s?.room.mode === 'mixed' ? t('admin.programs.plan.roomMixed') : s?.room.mode === 'remote' ? t('admin.programs.plan.roomRemote') : t('admin.programs.plan.roomNone')
  const recLabel = (r: Recurrence) => t(`admin.programs.plan.rec.${r}`)
  const weekendDays = s?.weekend ?? weekend

  return (
    <Card>
      <CardTitle subtitle={t('admin.programs.plan.subtitle')} action={<Switch checked label={t('admin.programs.plan.title')} onChange={(v) => publish(state.sessions, v)} />}>
        <span className="inline-flex items-center gap-2"><CalendarClock className="size-5 text-gold-600" />{t('admin.programs.plan.title')}<Badge color="navy">{t('admin.programs.plan.group1')}</Badge></span>
      </CardTitle>

      {!inputs.start_date || !(inputs.total_hours > 0) ? (
        <div className="flex items-center gap-2 rounded-xl bg-ivory p-4 text-sm text-slate-600"><Info className="size-4 text-gold-600" />{t('admin.programs.plan.needDate')}</div>
      ) : (
        <div className="space-y-5">
          {/* How the sessions recur */}
          <div>
            <span className="label">{t('admin.programs.plan.recurrence')}</span>
            <div className="flex flex-wrap gap-1.5">
              {(['weekly', 'biweekly', 'consecutive', 'custom'] as Recurrence[]).map((r) => <button key={r} type="button" onClick={() => pick(r)} className={clsx('rounded-full px-3.5 py-1.5 text-sm font-semibold transition', recurrence === r ? 'bg-navy-900 text-white shadow' : 'bg-slate-100 text-slate-600 hover:bg-slate-200')}>{recLabel(r)}</button>)}
            </div>
            {(recurrence === 'weekly' || recurrence === 'biweekly') && (
              <div className="mt-3 flex flex-wrap items-center gap-1.5">
                <span className="text-xs font-semibold text-slate-500">{t('admin.programs.plan.fixedDay')}</span>
                {[0, 1, 2, 3, 4, 5, 6].map((d) => {
                  const closed = weekendDays.includes(d)
                  return <button key={d} type="button" disabled={closed} onClick={() => setWeekday(d)} title={closed ? String(t('admin.programs.plan.weekendDay')) : undefined} className={clsx('rounded-full px-3 py-1 text-xs font-semibold transition', closed ? 'cursor-not-allowed bg-slate-50 text-slate-300 line-through' : effectiveWeekday === d ? 'bg-gold-500 text-navy-950' : 'bg-slate-100 text-slate-600 hover:bg-slate-200')}>{weekdayName(d)}</button>
                })}
              </div>
            )}
            {recurrence === 'custom' && <p className="mt-2 text-xs text-slate-500">{t('admin.programs.plan.customHint')}</p>}
          </div>

          <div className="grid gap-4 sm:grid-cols-4">
            <Field label={t('admin.programs.plan.count')} hint={t('admin.programs.plan.countHint', { n: Math.max(1, Math.ceil(inputs.total_hours / (s?.day_hours ?? 5))) })}><input type="number" min={1} max={60} className="input" placeholder={String(Math.max(1, Math.ceil(inputs.total_hours / (s?.day_hours ?? 5))))} value={count} onChange={(e) => setCount(e.target.value)} disabled={recurrence === 'custom'} /></Field>
            <Field label={t('admin.programs.plan.startTime')}><input type="time" className="input" value={startTime || s?.day_start || '08:00'} onChange={(e) => setStartTime(e.target.value)} /></Field>
            <Field label={t('admin.programs.plan.room')} className="sm:col-span-2">
              <div className="flex flex-wrap gap-1.5">
                {(['fixed', 'per_session', 'none'] as RoomMode[]).map((m) => <button key={m} type="button" disabled={inputs.delivery_mode === 'online'} onClick={() => { setRoomMode(m); if (recurrence === 'custom') generate({ roomMode: m, dates: state.sessions.map((x) => x.date), recurrence: 'custom' }) }} className={clsx('rounded-full px-3 py-1.5 text-xs font-semibold transition disabled:opacity-40', roomMode === m ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200')}>{t(`admin.programs.plan.roomMode.${m}`)}</button>)}
              </div>
            </Field>
          </div>

          {inputs.delivery_mode !== 'online' && roomMode !== 'none' && !!plan?.rooms.length && (
            <div>
              <span className="label"><DoorOpen className="me-1 inline size-3.5" />{t('admin.programs.plan.preferred')}</span>
              <div className="flex flex-wrap gap-1.5">
                <button type="button" onClick={() => setRoomId('')} className={clsx('rounded-xl border px-3 py-1.5 text-xs font-semibold', !roomId ? 'border-gold-400 bg-gold-50 text-navy-900' : 'border-navy-100 text-slate-600 hover:border-gold-300')}>{t('admin.programs.plan.autoRoom')}</button>
                {plan.rooms.slice(0, 12).map((r) => (
                  <button key={r.id} type="button" onClick={() => (recurrence === 'custom' ? applyRoomToAll(r.id) : setRoomId(r.id))} className={clsx('rounded-xl border px-3 py-1.5 text-start text-xs transition', roomId === r.id ? 'border-gold-400 bg-gold-50' : 'border-navy-100 hover:border-gold-300', !r.fits && 'opacity-60')}>
                    <span className="block font-semibold text-navy-900">{name(r)}</span>
                    <span className={clsx('block', r.free_all ? 'text-emerald-700' : r.free_count ? 'text-amber-700' : 'text-red-600')}>{r.free_all ? t('admin.programs.plan.freeAll', { n: r.dates }) : t('admin.programs.plan.freeSome', { n: r.free_count, of: r.dates })}{!r.fits ? ` · ${t('admin.programs.plan.tooSmall')}` : ''}</span>
                  </button>
                ))}
              </div>
            </div>
          )}

          {/* The plan */}
          {busy && !plan ? <div className="py-6"><Spinner /></div> : error ? (
            <div className="flex items-center gap-2 rounded-xl bg-red-50 p-3 text-sm text-danger"><AlertTriangle className="size-4" />{error}</div>
          ) : plan && (
            <div className="space-y-3">
              <div className={clsx('flex flex-wrap items-center gap-x-4 gap-y-1 rounded-2xl border p-4 text-sm', s && s.errors ? 'border-red-200 bg-red-50/50' : 'border-gold-200 bg-gold-50/50')}>
                <span className="inline-flex items-center gap-1.5 font-bold text-navy-900">{s && s.errors ? <AlertTriangle className="size-4 text-red-600" /> : <CheckCircle2 className="size-4 text-emerald-600" />}{t('admin.programs.plan.summary', { n: s?.count ?? 0, h: s?.hours ?? 0 })}</span>
                {s?.first && s.last && <span className="text-slate-600">{s.first} → {s.last}</span>}
                {s && (s.pattern === 'weekly') && <span className="text-slate-600">{t(s.every_weeks > 1 ? 'admin.programs.plan.everyOther' : 'admin.programs.plan.everyDay', { day: weekdayName(s.weekday) })}</span>}
                <span className="inline-flex items-center gap-1 text-slate-700"><DoorOpen className="size-4 text-gold-600" />{roomLine}</span>
                {busy && <RefreshCcw className="size-4 animate-spin text-slate-400" />}
              </div>
              {plan.notes.map((n, i) => <p key={i} className={clsx('flex items-start gap-2 text-xs', n.level === 'warn' ? 'text-amber-800' : 'text-slate-600')}>{n.level === 'warn' ? <AlertTriangle className="mt-0.5 size-3.5 shrink-0" /> : <Info className="mt-0.5 size-3.5 shrink-0 text-gold-600" />}{en ? n.en : n.ar}</p>)}

              {dirty && recurrence !== 'custom' && (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-gold-200 bg-gold-50/70 px-3 py-2 text-xs text-slate-700">
                  <Wand2 className="size-4 text-gold-700" />{t('admin.programs.plan.edited')}
                  <Button size="sm" variant="outline" icon={<RefreshCcw className="size-3.5" />} onClick={regenerate}>{t('admin.programs.plan.regenerate')}</Button>
                </div>
              )}

              <div className="space-y-2">
                {state.sessions.map((row, i) => (
                  <div key={i} className={clsx('rounded-2xl border p-3 transition', row.status === 'error' ? 'border-red-200 bg-red-50/40' : row.status === 'warn' ? 'border-amber-200 bg-amber-50/40' : 'border-navy-100 bg-white')}>
                    <div className="grid items-end gap-3 sm:grid-cols-[2.5rem_1.4fr_1.2fr_1.4fr_2rem]">
                      <span className="grid size-9 place-items-center rounded-xl bg-navy-900 font-mono text-sm font-bold text-gold-300">{i + 1}</span>
                      <label className="block text-xs text-slate-500">{day(row.date)}<input type="date" className="input mt-1" value={row.date} onChange={(e) => e.target.value && patchRow(i, rebuild(row, e.target.value, row.start_time, row.end_time))} /></label>
                      <div className="flex items-end gap-1.5 text-xs text-slate-500"><label className="block flex-1">{t('admin.programs.plan.from')}<input type="time" className="input mt-1" value={row.start_time} onChange={(e) => e.target.value && patchRow(i, rebuild(row, row.date, e.target.value, row.end_time))} /></label><label className="block flex-1">{t('admin.programs.plan.to')}<input type="time" className="input mt-1" value={row.end_time} onChange={(e) => e.target.value && patchRow(i, rebuild(row, row.date, row.start_time, e.target.value))} /></label></div>
                      {inputs.delivery_mode === 'online' ? <div className="text-xs text-slate-500">{t('admin.programs.plan.online')}</div> : (
                        <label className="block text-xs text-slate-500">{t('admin.programs.plan.room')}
                          <select className="input mt-1" value={row.training_room_id ?? ''} onChange={(e) => { const r = plan.rooms.find((x) => x.id === e.target.value); patchRow(i, { training_room_id: e.target.value || null, room: r ? { id: r.id, name_ar: r.name_ar, name_en: r.name_en } : null }) }}>
                            <option value="">—</option>{plan.rooms.map((r) => <option key={r.id} value={r.id}>{name(r)}{r.free_all ? ' ✓' : ` (${r.free_count}/${r.dates})`}</option>)}
                          </select>
                        </label>
                      )}
                      <button type="button" aria-label={String(t('common.delete'))} className="mb-1 text-slate-400 hover:text-danger" onClick={() => edit(state.sessions.filter((_, j) => j !== i).map((x, j) => ({ ...x, sequence: j + 1, title_ar: `الجلسة ${j + 1}`, title_en: `Session ${j + 1}` })))}><Trash2 className="size-4" /></button>
                    </div>
                    {row.flags.length > 0 && <div className="mt-2 flex flex-wrap gap-1.5">{row.flags.map((f, k) => <Badge key={k} color={LEVEL_TONE[f.level]} className="font-normal">{en ? f.en : f.ar}</Badge>)}</div>}
                  </div>
                ))}
              </div>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <Button variant="outline" size="sm" icon={<Plus className="size-4" />} onClick={addSession}>{t('admin.programs.plan.addSession')}</Button>
                <span className="text-xs text-slate-400"><CalendarPlus className="me-1 inline size-3.5" />{t('admin.programs.plan.hoursNote', { h: s?.day_hours ?? 5, from: s?.day_start ?? '08:00', to: s?.day_end ?? '13:00' })}</span>
              </div>
              {state.closedDays > 0 && (
                <Field label={t('admin.programs.plan.approval')} hint={canApprove ? t('admin.programs.plan.approvalHint') : t('admin.programs.plan.approvalDenied')}>
                  <input className="input" value={approval} disabled={!canApprove} onChange={(e) => onApproval(e.target.value)} placeholder={String(t('admin.programs.plan.approvalPlaceholder'))} />
                </Field>
              )}
            </div>
          )}
        </div>
      )}
    </Card>
  )
}
