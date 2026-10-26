import clsx from 'clsx'
import { CalendarClock, CalendarOff, CheckCircle2, Clock3, Send, Sparkles, Trash2, XCircle } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { OffdayRequest, OffdayTarget, Overview } from './types'

/** «طلب حجز في يوم إجازة» (B3): opened when a day is refused because the calendar closes it. */
export function OffdayRequestDialog({ target, overview, onClose, onDone }: { target: OffdayTarget; overview: Overview; onClose: () => void; onDone: (opened: boolean) => void }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState<'send' | 'instant' | null>(null)
  const canInstant = !!overview.can['calendar.approve']

  const send = async (instant: boolean) => {
    setBusy(instant ? 'instant' : 'send')
    try {
      await api.post('/admin/saber/offday-requests', { date: target.date, group_id: target.groupId, day_idx: target.idx, reason: reason.trim(), instant })
      toast(t(instant ? 'saber.offday.opened' : 'saber.offday.sent'))
      onDone(instant); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><CalendarOff className="size-5 text-amber-600" />{t('saber.offday.requestTitle')}</span>}>
      <div className="mb-4 grid grid-cols-2 gap-2 rounded-xl bg-ivory p-3 text-sm">
        <div><div className="text-[11px] font-bold text-slate-400">{t('saber.day.date')}</div><div className="font-bold text-navy-900">{fmt.date(target.date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</div></div>
        <div><div className="text-[11px] font-bold text-slate-400">{t('saber.plan.group')}</div><div className="truncate font-bold text-navy-900">{target.groupLabel} · {t('saber.offday.day', { n: target.idx })}</div></div>
        <div className="col-span-2 truncate text-xs text-slate-500">{target.programTitle}</div>
      </div>
      <p className="mb-3 text-sm text-slate-500">{t('saber.offday.requestHint')}</p>
      <Field label={t('saber.offday.reason')}>
        <textarea autoFocus rows={3} value={reason} onChange={(e) => setReason(e.target.value)} className="w-full rounded-xl border border-navy-100 p-3 text-sm focus:border-gold-400 focus:outline-none" />
      </Field>
      <div className="mt-5 flex flex-wrap justify-end gap-2">
        {canInstant && <Button variant="gold" icon={<Sparkles className="size-4" />} loading={busy === 'instant'} disabled={reason.trim().length < 3} onClick={() => send(true)}>{t('saber.offday.instant')}</Button>}
        <Button variant={canInstant ? 'outline' : 'primary'} icon={<Send className="size-4" />} loading={busy === 'send'} disabled={reason.trim().length < 3} onClick={() => send(false)}>{t('saber.offday.send')}</Button>
      </div>
    </Modal>
  )
}

const STATUS_STYLE = { pending: 'bg-amber-50 text-amber-700 ring-amber-600/20', approved: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', rejected: 'bg-rose-50 text-rose-700 ring-rose-600/20' }
const STATUS_ICON = { pending: Clock3, approved: CheckCircle2, rejected: XCircle }

/** «طلبات أيام الإجازة»: the approver decides, everyone else follows their own requests. */
export function OffdayInbox({ onClose, onChanged }: { onClose: () => void; onChanged: () => void }) {
  const { t } = useTranslation()
  const [filter, setFilter] = useState<'pending' | 'all'>('pending')
  const { data, refetch } = useGet<{ data: OffdayRequest[]; meta: { approver: boolean; pending: number } }>('/admin/saber/offday-requests', { status: filter === 'pending' ? 'pending' : undefined }, { staleTime: 0 })
  const approver = !!data?.meta.approver

  const decide = async (r: OffdayRequest, approve: boolean) => {
    const note = approve ? '' : await dialogs.prompt(t('saber.offday.note'))
    if (note === null) return
    try {
      await api.post(`/admin/saber/offday-requests/${r.id}/decide`, { approve, note: note || null })
      toast(t(approve ? 'saber.offday.approved' : 'saber.offday.rejected')); refetch(); onChanged()
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><CalendarClock className="size-5 text-gold-600" />{t('saber.offday.title')}</span>} wide>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{approver ? t('saber.offday.hintApprover') : t('saber.offday.hintMine')}</p>
      <Tabs tabs={[{ id: 'pending', label: `${t('saber.offday.status.pending')}${data ? ` (${data.meta.pending})` : ''}` }, { id: 'all', label: t('common.all', { defaultValue: 'الكل' }) }]} value={filter} onChange={setFilter} />
      <ul className="mt-4 max-h-[26rem] space-y-2 overflow-y-auto">
        {!data ? <li className="h-20 animate-pulse rounded-xl bg-ivory" /> : data.data.length === 0 ? <li className="rounded-xl border border-dashed border-navy-100 py-8 text-center text-sm text-slate-400">{t('saber.offday.none')}</li> : data.data.map((r) => {
          const Icon = STATUS_ICON[r.status]
          return (
            <li key={r.id} className="rounded-xl border border-navy-100 bg-white p-3">
              <div className="flex flex-wrap items-start gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-bold text-navy-900">{fmt.date(r.date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</span>
                    <span className={clsx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset', STATUS_STYLE[r.status])}><Icon className="size-3" />{t(`saber.offday.status.${r.status}`)}</span>
                  </div>
                  <div className="mt-0.5 truncate text-xs text-slate-500">
                    {[r.program?.title, r.group && (r.group.title || t('saber.group.n', { n: r.group.number })), r.day_idx && t('saber.offday.day', { n: r.day_idx })].filter(Boolean).join(' · ')}
                  </div>
                  <p className="mt-1.5 text-sm text-slate-700">{r.reason}</p>
                  <div className="mt-1 text-[11px] text-slate-400">
                    {[r.requested_by && t('saber.offday.by', { name: r.requested_by }), r.decided_by && t('saber.offday.decidedBy', { name: r.decided_by }), r.decision_note].filter(Boolean).join(' · ')}
                  </div>
                </div>
                {approver && r.status === 'pending' && (
                  <div className="flex gap-1.5">
                    <Button size="sm" variant="primary" icon={<CheckCircle2 className="size-4" />} onClick={() => decide(r, true)}>{t('saber.offday.approve')}</Button>
                    <Button size="sm" variant="outline" icon={<XCircle className="size-4" />} onClick={() => decide(r, false)}>{t('saber.offday.reject')}</Button>
                  </div>
                )}
              </div>
            </li>
          )
        })}
      </ul>
    </Modal>
  )
}

type Rule = { id: string; weekday: number; title_ar: string | null; title_en: string | null; allow_events: boolean }

/** «الإجازات الأسبوعية» (B4): weekdays off every week, on top of the weekend, each with a label and whether events may still happen. */
export function WeeklyHolidays({ canManage, onClose, onChanged }: { canManage: boolean; onClose: () => void; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const { data, refetch } = useGet<{ data: { rules: Rule[]; weekend: number[] } }>('/admin/saber/weekly-rules', undefined, { staleTime: 0 })
  const [form, setForm] = useState({ weekday: '', title: '', allow_events: false })
  const days = t('saber.weekdays', { returnObjects: true }) as string[]
  const taken = new Set([...(data?.data.weekend ?? []), ...(data?.data.rules.map((r) => r.weekday) ?? [])])

  const save = async (body: Record<string, unknown>) => {
    try {
      const res = await api.post('/admin/saber/weekly-rules', body)
      toast(t('saber.weekly.saved'))
      if (res.data.meta?.sessions_on_that_day) toast(t('saber.weekly.warn', { n: res.data.meta.sessions_on_that_day }), 'info')
      refetch(); onChanged()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const remove = async (r: Rule) => {
    try { await api.delete(`/admin/saber/weekly-rules/${r.id}`); toast(t('saber.weekly.removed')); refetch(); onChanged() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const label = (r: Rule) => (i18n.language === 'en' ? r.title_en || r.title_ar : r.title_ar || r.title_en) ?? ''

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><CalendarOff className="size-5 text-gold-600" />{t('saber.weekly.title')}</span>}>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{t('saber.weekly.hint')}</p>
      <div className="mb-3 flex flex-wrap items-center gap-1.5 text-xs">
        <span className="font-bold text-slate-500">{t('saber.weekly.weekend')}:</span>
        {(data?.data.weekend ?? []).map((d) => <span key={d} className="rounded-full bg-slate-100 px-2.5 py-0.5 font-semibold text-slate-600">{days[d]}</span>)}
      </div>
      <ul className="space-y-2">
        {data && data.data.rules.length === 0 && <li className="rounded-xl border border-dashed border-navy-100 py-5 text-center text-sm text-slate-400">{t('saber.weekly.none')}</li>}
        {data?.data.rules.map((r) => (
          <li key={r.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-navy-100 px-3 py-2.5">
            <span className="w-20 font-bold text-navy-900">{days[r.weekday]}</span>
            <input defaultValue={label(r)} disabled={!canManage} aria-label={t('saber.weekly.label')} onBlur={(e) => e.target.value !== label(r) && save({ weekday: r.weekday, title_ar: e.target.value || null, allow_events: r.allow_events })}
              className="h-9 min-w-32 flex-1 rounded-lg border border-transparent px-2 text-sm hover:border-navy-100 focus:border-gold-400 focus:outline-none" />
            <label className="flex items-center gap-1.5 text-xs font-semibold text-slate-600">
              <input type="checkbox" checked={r.allow_events} disabled={!canManage} onChange={(e) => save({ weekday: r.weekday, title_ar: r.title_ar, allow_events: e.target.checked })} />{t('saber.weekly.allowEvents')}
            </label>
            {canManage && <button type="button" onClick={() => remove(r)} aria-label={t('saber.weekly.removed')} className="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600"><Trash2 className="size-4" /></button>}
          </li>
        ))}
      </ul>
      {canManage && (
        <div className="mt-4 flex flex-wrap items-end gap-2 rounded-xl bg-ivory p-3">
          <Field label={t('saber.plan.weekday')} className="w-32">
            <select value={form.weekday} onChange={(e) => setForm((f) => ({ ...f, weekday: e.target.value }))} className="h-10 w-full rounded-xl border border-navy-100 bg-white px-2 text-sm">
              <option value="">—</option>{days.map((d, i) => !taken.has(i) && <option key={i} value={i}>{d}</option>)}
            </select>
          </Field>
          <Field label={t('saber.weekly.label')} className="min-w-40 flex-1">
            <input value={form.title} onChange={(e) => setForm((f) => ({ ...f, title: e.target.value }))} className="h-10 w-full rounded-xl border border-navy-100 bg-white px-3 text-sm" />
          </Field>
          <label className="flex h-10 items-center gap-1.5 text-xs font-semibold text-slate-600"><input type="checkbox" checked={form.allow_events} onChange={(e) => setForm((f) => ({ ...f, allow_events: e.target.checked }))} />{t('saber.weekly.allowEvents')}</label>
          <Button disabled={form.weekday === ''} onClick={() => { save({ weekday: Number(form.weekday), title_ar: form.title || null, allow_events: form.allow_events }); setForm({ weekday: '', title: '', allow_events: false }) }}>{t('saber.weekly.add')}</Button>
        </div>
      )}
    </Modal>
  )
}
