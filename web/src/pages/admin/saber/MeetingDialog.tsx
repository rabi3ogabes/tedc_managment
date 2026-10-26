import clsx from 'clsx'
import { CalendarOff, DoorOpen, Repeat } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { isoToday, type MonthRoom } from './rooms'
import type { Overview } from './types'

type Slots = { slots: { hour: number; busy: boolean; by: string | null }[]; day: { events_allowed: boolean; kind: string; label: string | null } }
const hh = (h: number) => `${String(h).padStart(2, '0')}:00`

/**
 * «حجز قاعة اجتماعات» (C2): who asks, why, the room, the day and the free hours (07:00–14:00), once or repeated daily, weekly or every two
 * weeks for whoever holds the right; holidays without events and taken hours are refused by the server with the list.
 */
export default function MeetingDialog({ overview, rooms, initial, onClose, onBooked }: {
  overview: Overview; rooms: MonthRoom[]; initial?: { roomId?: string; date?: string }; onClose: () => void; onBooked: () => void
}) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ title: '', requested_by: '', reason: '', purpose: 'meeting', room_id: initial?.roomId ?? rooms.find((r) => r.type === 'meeting')?.id ?? '', date: initial?.date ?? isoToday(), attendees: '', recurrence: 'none', count: '2' })
  const [range, setRange] = useState<[number, number] | null>(null)
  const [saving, setSaving] = useState(false)
  const canRepeat = !!overview.can['saber.meeting.recurring']
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const { data, isFetching } = useGet<{ data: Slots }>(form.room_id && form.date ? '/admin/saber/room-slots' : null, { room_id: form.room_id, date: form.date }, { staleTime: 0 })
  const slots = data?.data
  const closed = slots && !slots.day.events_allowed

  // Hours are picked as one consecutive run: a click starts it, a second click extends it, a click inside shrinks it to that hour.
  const pick = (h: number) => {
    if (!range || h < range[0] - 1 || h > range[1] + 1 || (h >= range[0] && h <= range[1] && range[0] !== range[1])) { setRange([h, h]); return }
    setRange([Math.min(range[0], h), Math.max(range[1], h)])
  }
  const runFree = range && slots ? slots.slots.filter((s) => s.hour >= range[0] && s.hour <= range[1]).every((s) => !s.busy) : false
  const meetingRooms = rooms.filter((r) => r.type === 'meeting')
  const otherRooms = rooms.filter((r) => r.type !== 'meeting')
  const ready = form.title.trim() && form.requested_by.trim() && form.reason.trim() && form.room_id && form.date && range && runFree && !closed

  const save = async () => {
    if (!ready || !range) return
    setSaving(true)
    try {
      const repeat = canRepeat && form.recurrence !== 'none'
      const { data: res } = await api.post('/admin/saber/meetings', {
        room_id: form.room_id, title: form.title.trim(), requested_by: form.requested_by.trim(), reason: form.reason.trim(), purpose: form.purpose, date: form.date,
        from_hour: range[0], to_hour: range[1] + 1, attendees: form.attendees ? Number(form.attendees) : null, recurrence: repeat ? form.recurrence : 'none', count: repeat ? Number(form.count) || 1 : 1,
      })
      toast(res.data.created > 1 ? t('saber.meeting.savedMany', { n: res.data.created }) : t('saber.meeting.saved'))
      onBooked(); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setSaving(false) }
  }
  const input = 'h-10 w-full rounded-xl border border-navy-100 bg-white px-3 text-sm focus:border-gold-400 focus:outline-none'

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><DoorOpen className="size-5 text-gold-600" />{t('saber.meeting.title')}</span>} wide>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{t('saber.meeting.hint')}</p>
      <div className="grid gap-4 md:grid-cols-2">
        <div className="space-y-3">
          <Field label={<>{t('saber.meeting.titleLabel')} <span className="text-rose-600">*</span></>}><input className={input} value={form.title} onChange={(e) => set('title', e.target.value)} /></Field>
          <Field label={<>{t('saber.meeting.requestedBy')} <span className="text-rose-600">*</span></>}><input className={input} value={form.requested_by} onChange={(e) => set('requested_by', e.target.value)} /></Field>
          <Field label={<>{t('saber.meeting.reason')} <span className="text-rose-600">*</span></>}><textarea rows={3} className="w-full rounded-xl border border-navy-100 p-3 text-sm focus:border-gold-400 focus:outline-none" value={form.reason} onChange={(e) => set('reason', e.target.value)} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('saber.meeting.purpose')}>
              <select className={input} value={form.purpose} onChange={(e) => set('purpose', e.target.value)}>
                {(['meeting', 'training', 'event', 'exam', 'other'] as const).map((k) => <option key={k} value={k}>{t(`saber.meeting.purposes.${k}`)}</option>)}
              </select>
            </Field>
            <Field label={t('saber.meeting.attendees')}><input type="number" min={1} className={input} value={form.attendees} onChange={(e) => set('attendees', e.target.value)} /></Field>
          </div>
        </div>
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-3">
            <Field label={t('saber.meeting.room')}>
              <select className={input} value={form.room_id} onChange={(e) => { set('room_id', e.target.value); setRange(null) }}>
                <option value="">—</option>
                {meetingRooms.length > 0 && <optgroup label={t('saber.rooms.types.meeting')}>{meetingRooms.map((r) => <option key={r.id} value={r.id}>{r.name}{r.tag ? ` · ${r.tag}` : ''}</option>)}</optgroup>}
                {otherRooms.length > 0 && <optgroup label={t('saber.rooms.types.training')}>{otherRooms.map((r) => <option key={r.id} value={r.id}>{r.name}{r.tag ? ` · ${r.tag}` : ''}</option>)}</optgroup>}
              </select>
            </Field>
            <Field label={t('saber.meeting.date')}><input type="date" dir="ltr" className={input} value={form.date} onChange={(e) => { set('date', e.target.value); setRange(null) }} /></Field>
          </div>
          <div className="rounded-xl border border-navy-100 bg-ivory/50 p-3">
            <div className="mb-2 flex items-center justify-between text-xs font-bold text-slate-500">
              <span>{t('saber.meeting.hours')}</span>
              {range && <span className="tabular-nums text-navy-900" dir="ltr">{hh(range[0])} – {hh(range[1] + 1)}</span>}
            </div>
            {!slots ? <p className="py-4 text-center text-xs text-slate-400">{isFetching ? '•••' : t('saber.meeting.pickRoomDate')}</p> : closed ? (
              <p className="flex items-center justify-center gap-2 py-4 text-sm font-semibold text-slate-500"><CalendarOff className="size-4" />{t('saber.meeting.closed', { label: slots.day.label ?? t(`saber.kinds.${slots.day.kind}`, { defaultValue: slots.day.kind }) })}</p>
            ) : (
              <>
                <div className="grid grid-cols-7 gap-1.5">
                  {slots.slots.map((s) => {
                    const on = !!range && s.hour >= range[0] && s.hour <= range[1]
                    return (
                      <button key={s.hour} type="button" disabled={s.busy} onClick={() => pick(s.hour)} title={s.busy ? t('saber.meeting.taken', { by: s.by ?? '—' }) : `${hh(s.hour)}–${hh(s.hour + 1)}`}
                        className={clsx('rounded-lg py-2 text-[11px] font-bold tabular-nums transition', s.busy ? 'cursor-not-allowed bg-rose-100 text-rose-400 line-through' : on ? 'bg-navy-900 text-white shadow-sm' : 'bg-white text-navy-900 ring-1 ring-inset ring-navy-100 hover:ring-gold-400')} dir="ltr">
                        {s.hour}
                      </button>
                    )
                  })}
                </div>
                <p className="mt-2 text-[11px] text-slate-400">{t('saber.meeting.hoursHint')}</p>
              </>
            )}
          </div>
          <div className={clsx('rounded-xl border p-3', canRepeat ? 'border-navy-100' : 'border-dashed border-navy-100 opacity-70')}>
            <div className="mb-2 flex items-center gap-1.5 text-xs font-bold text-slate-500"><Repeat className="size-3.5" />{t('saber.meeting.recurrence')}</div>
            <div className="grid grid-cols-2 gap-3">
              <select className={input} disabled={!canRepeat} value={form.recurrence} onChange={(e) => set('recurrence', e.target.value)}>
                {(['none', 'daily', 'weekly', 'biweekly'] as const).map((k) => <option key={k} value={k}>{t(`saber.meeting.recur.${k}`)}</option>)}
              </select>
              <input type="number" min={2} max={60} aria-label={t('saber.meeting.count')} placeholder={t('saber.meeting.count')} disabled={!canRepeat || form.recurrence === 'none'} className={input} value={form.count} onChange={(e) => set('count', e.target.value)} />
            </div>
            {!canRepeat && <p className="mt-2 text-[11px] text-slate-400">{t('saber.meeting.recurOnly')}</p>}
          </div>
        </div>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>{t('common.cancel', { defaultValue: 'إلغاء' })}</Button>
        <Button variant="gold" loading={saving} disabled={!ready} onClick={save}>{t('saber.meeting.save')}</Button>
      </div>
    </Modal>
  )
}
