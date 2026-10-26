import clsx from 'clsx'
import { BookOpen, CalendarClock, Pencil, Repeat, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import type { MonthEvent } from './rooms'
import type { Overview } from './types'

/** One thing that holds a room on a day: a training day (programme, group, trainer) or a booking (who asked, why, edit, cancel). */
export default function BookingCard({ event: e, overview, onChanged }: { event: MonthEvent; overview: Overview; onChanged: () => void }) {
  const { t } = useTranslation()
  const [editing, setEditing] = useState(false)
  const mine = e.booked_by_id === overview.me || !!overview.can['rooms.manage']

  const cancel = async () => {
    let series = false
    if (e.series_id && (e.series_size ?? 1) > 1) series = await dialogs.confirm(t('saber.meeting.cancelSeries'))
    else if (!(await dialogs.confirm(t('saber.meeting.cancelConfirm', { title: e.title ?? '' })))) return
    try { await api.delete(`/admin/saber/meetings/${e.id}${series ? '?series=1' : ''}`); toast(t('saber.meeting.cancelled')); onChanged() } catch (err) { toast(errorMessage(err), 'error') }
  }

  if (e.type === 'session') {
    return (
      <div className="rounded-xl border border-navy-100 bg-white p-2.5 text-xs" style={{ borderInlineStartWidth: 3, borderInlineStartColor: e.color ?? 'var(--color-navy-700)' }}>
        <div className="flex items-center gap-1.5 font-bold text-navy-900"><BookOpen className="size-3.5 text-gold-600" /><span className="truncate">{e.title}</span></div>
        <div className="mt-0.5 flex flex-wrap gap-x-2 text-slate-500">
          <span className="tabular-nums" dir="ltr">{e.start}–{e.end}</span>
          {e.group && <span>{t('saber.calendar.groupDay', { group: e.group, day: e.day ?? '—' })}</span>}
          {e.trainer && <span>{t('saber.calendar.trainer', { v: e.trainer })}</span>}
        </div>
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50/40 p-2.5 text-xs">
      <div className="flex items-start gap-1.5">
        <CalendarClock className="mt-0.5 size-3.5 shrink-0 text-amber-600" />
        <div className="min-w-0 flex-1">
          <div className="truncate font-bold text-navy-900">{e.title}</div>
          <div className="mt-0.5 flex flex-wrap gap-x-2 text-slate-500">
            <span className="tabular-nums" dir="ltr">{e.start}–{e.end}</span>
            {e.purpose && <span>{t(`saber.meeting.purposes.${e.purpose}`, { defaultValue: e.purpose })}</span>}
            {(e.series_size ?? 1) > 1 && <span className="inline-flex items-center gap-0.5 font-semibold text-amber-700"><Repeat className="size-3" />{t('saber.meeting.series', { n: e.series_size })}</span>}
          </div>
          {e.requested_by && <div className="mt-0.5 text-slate-600">{t('saber.meeting.by', { v: e.requested_by })}</div>}
          {e.reason && <div className="text-slate-600">{t('saber.meeting.why', { v: e.reason })}</div>}
          {e.booked_by && <div className="text-[10px] text-slate-400">{t('saber.meeting.bookedBy', { v: e.booked_by })}</div>}
        </div>
        {mine && (
          <div className="flex shrink-0 gap-0.5">
            <button type="button" onClick={() => setEditing(true)} title={t('saber.meeting.edit')} aria-label={t('saber.meeting.edit')} className="grid size-7 place-items-center rounded-lg text-slate-400 hover:bg-white hover:text-navy-900"><Pencil className="size-3.5" /></button>
            <button type="button" onClick={cancel} title={t('saber.meeting.cancel')} aria-label={t('saber.meeting.cancel')} className="grid size-7 place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600"><X className="size-3.5" /></button>
          </div>
        )}
      </div>
      {editing && <EditBooking event={e} onClose={() => setEditing(false)} onSaved={onChanged} />}
    </div>
  )
}

function EditBooking({ event: e, onClose, onSaved }: { event: MonthEvent; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ title: e.title ?? '', requested_by: e.requested_by ?? '', reason: e.reason ?? '', from: Number(e.start.slice(0, 2)), to: Number(e.end.slice(0, 2)), series: false })
  const [saving, setSaving] = useState(false)
  const hours = Array.from({ length: 8 }, (_, i) => 7 + i)
  const save = async () => {
    setSaving(true)
    try {
      await api.patch(`/admin/saber/meetings/${e.id}`, { title: form.title.trim(), requested_by: form.requested_by.trim() || null, reason: form.reason.trim() || null, from_hour: form.from, to_hour: form.to, series: form.series })
      toast(t('saber.meeting.updated')); onSaved(); onClose()
    } catch (err) { toast(errorMessage(err), 'error') } finally { setSaving(false) }
  }
  const input = 'h-10 w-full rounded-xl border border-navy-100 bg-white px-3 text-sm focus:border-gold-400 focus:outline-none'
  return (
    <Modal open onClose={onClose} title={t('saber.meeting.edit')}>
      <div className="space-y-3">
        <Field label={t('saber.meeting.titleLabel')}><input className={input} value={form.title} onChange={(ev) => setForm((f) => ({ ...f, title: ev.target.value }))} /></Field>
        <Field label={t('saber.meeting.requestedBy')}><input className={input} value={form.requested_by} onChange={(ev) => setForm((f) => ({ ...f, requested_by: ev.target.value }))} /></Field>
        <Field label={t('saber.meeting.reason')}><textarea rows={2} className="w-full rounded-xl border border-navy-100 p-3 text-sm" value={form.reason} onChange={(ev) => setForm((f) => ({ ...f, reason: ev.target.value }))} /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('saber.meeting.from')}><select className={input} value={form.from} onChange={(ev) => setForm((f) => ({ ...f, from: Number(ev.target.value) }))}>{hours.slice(0, -1).map((h) => <option key={h} value={h}>{String(h).padStart(2, '0')}:00</option>)}</select></Field>
          <Field label={t('saber.meeting.to')}><select className={input} value={form.to} onChange={(ev) => setForm((f) => ({ ...f, to: Number(ev.target.value) }))}>{hours.slice(1).map((h) => <option key={h} value={h} disabled={h <= form.from}>{String(h).padStart(2, '0')}:00</option>)}</select></Field>
        </div>
        {(e.series_size ?? 1) > 1 && (
          <label className={clsx('flex items-center gap-2 rounded-xl bg-ivory px-3 py-2 text-xs font-semibold text-slate-600')}>
            <input type="checkbox" checked={form.series} onChange={(ev) => setForm((f) => ({ ...f, series: ev.target.checked }))} />{t('saber.meeting.applySeries')}
          </label>
        )}
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>{t('common.cancel', { defaultValue: 'إلغاء' })}</Button>
        <Button loading={saving} disabled={!form.title.trim() || form.to <= form.from} onClick={save}>{t('common.save', { defaultValue: 'حفظ' })}</Button>
      </div>
    </Modal>
  )
}
