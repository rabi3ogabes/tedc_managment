import clsx from 'clsx'
import { CalendarCheck2, CalendarOff, CheckCircle2, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import ProgramSearch from './ProgramSearch'
import type { Group, Notice, Overview, PlanRow, Program } from './types'

const todayIso = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60_000).toISOString().slice(0, 10)

/**
 * «خطة الأيام القادمة» (B1): a weekly or fortnightly pattern previewed against the calendar and the bookings, then — if wanted — applied to
 * the group, replacing its days.
 */
export default function PlanDialog({ overview, initial, onClose, onApplied }: {
  overview: Overview; initial: { program: Program; group: Group } | null; onClose: () => void; onApplied: (notices: Notice[]) => void
}) {
  const { t } = useTranslation()
  const [program, setProgram] = useState<Program | null>(initial?.program ?? null)
  const [groupId, setGroupId] = useState(initial?.group.id ?? '')
  const [form, setForm] = useState({ weekday: '0', every: '1', count: String(initial?.program.days ?? 1), from: todayIso(), room_id: '', trainer_id: '' })
  const [rows, setRows] = useState<PlanRow[] | null>(null)
  const [meta, setMeta] = useState<{ max: number; saved: number } | null>(null)
  const [chosen, setChosen] = useState<Set<string>>(new Set())
  const [busy, setBusy] = useState(false)
  const set = (k: keyof typeof form, v: string) => { setForm((f) => ({ ...f, [k]: v })); setRows(null) }
  const max = Math.max(1, program?.days ?? 1)
  const canApply = !!overview.can['saber.day.edit']

  const preview = async () => {
    if (!program || !groupId) return
    setBusy(true)
    try {
      const { data } = await api.post(`/admin/saber/programs/${program.id}/groups/${groupId}/plan/preview`, {
        weekday: Number(form.weekday), every: Number(form.every), count: Math.min(Number(form.count) || 1, max * 4), from: form.from,
        room_id: form.room_id || null, trainer_id: form.trainer_id || null,
      })
      setRows(data.data); setMeta(data.meta)
      setChosen(new Set((data.data as PlanRow[]).filter((r) => r.status === 'free').map((r) => r.date)))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  const apply = async () => {
    if (!groupId || chosen.size === 0) { toast(t('saber.plan.noneChosen'), 'error'); return }
    if (meta && meta.saved > 0 && !(await dialogs.confirm(t('saber.plan.replace', { saved: meta.saved, n: chosen.size })))) return
    setBusy(true)
    try {
      const { data } = await api.post(`/admin/saber/programs/${program!.id}/groups/${groupId}/plan/apply`, { dates: [...chosen], room_id: form.room_id || null, trainer_id: form.trainer_id || null })
      onApplied(data.notices ?? []); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  const stats = rows ? { free: rows.filter((r) => r.status === 'free').length, busy: rows.filter((r) => r.status === 'busy').length, off: rows.filter((r) => r.status === 'off').length } : null
  const select = 'h-10 w-full rounded-xl border border-navy-100 bg-white px-2 text-sm'

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><CalendarCheck2 className="size-5 text-gold-600" />{t('saber.plan.title')}</span>} wide>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{t('saber.plan.hint')}</p>
      <div className="grid gap-4 md:grid-cols-2">
        <div className="space-y-3">
          <Field label={t('saber.plan.program')}>
            {initial ? <div className="rounded-xl bg-ivory px-3 py-2 text-sm font-bold text-navy-900">{initial.program.title}</div>
              : <ProgramSearch value={program} placeholder={t('saber.plan.pickProgram')} filter={(p) => !p.cancelled} onPick={(p) => { setProgram(p); setGroupId(p.groups.find((g) => !g.cancelled)?.id ?? ''); set('count', String(p.days)) }} />}
          </Field>
          <Field label={t('saber.plan.group')}>
            <select className={select} value={groupId} disabled={!program} onChange={(e) => { setGroupId(e.target.value); setRows(null) }}>
              <option value="">{program ? t('saber.plan.pickGroup') : t('saber.plan.pickProgram')}</option>
              {program?.groups.filter((g) => !g.cancelled).map((g) => <option key={g.id} value={g.id}>{g.title || t('saber.group.n', { n: g.number })}</option>)}
            </select>
          </Field>
        </div>
        <div className="grid grid-cols-2 gap-3">
          <Field label={t('saber.plan.weekday')}>
            <select className={select} value={form.weekday} onChange={(e) => set('weekday', e.target.value)}>
              {(t('saber.weekdays', { returnObjects: true }) as string[]).map((d, i) => <option key={i} value={i}>{d}</option>)}
            </select>
          </Field>
          <Field label={t('saber.plan.every')}>
            <select className={select} value={form.every} onChange={(e) => set('every', e.target.value)}>
              <option value="1">{t('saber.plan.weekly')}</option><option value="2">{t('saber.plan.biweekly')}</option>
            </select>
          </Field>
          <Field label={t('saber.plan.count', { n: max })}><input type="number" min={1} max={max * 4} className={select} value={form.count} onChange={(e) => set('count', e.target.value)} /></Field>
          <Field label={t('saber.plan.from')}><input type="date" dir="ltr" className={select} value={form.from} onChange={(e) => set('from', e.target.value)} /></Field>
          <Field label={t('saber.plan.room')}>
            <select className={select} value={form.room_id} onChange={(e) => set('room_id', e.target.value)}>
              <option value="">{t('saber.day.none')}</option>{overview.rooms.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
          </Field>
          <Field label={t('saber.plan.trainer')}>
            <select className={select} value={form.trainer_id} onChange={(e) => set('trainer_id', e.target.value)}>
              <option value="">{t('saber.day.none')}</option>{overview.trainers.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
          </Field>
        </div>
      </div>

      <div className="mt-4 flex justify-end"><Button variant="outline" loading={busy && !rows} disabled={!groupId} onClick={preview}>{t('saber.plan.preview')}</Button></div>

      {!rows ? (
        <p className="mt-4 rounded-xl border border-dashed border-navy-100 py-6 text-center text-sm text-slate-400">{t('saber.plan.empty')}</p>
      ) : (
        <div className="mt-4 space-y-3">
          <div className="flex flex-wrap items-center gap-2 text-xs font-bold">
            <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700">{t('saber.plan.free')} {stats!.free}</span>
            <span className="rounded-full bg-rose-50 px-2.5 py-1 text-rose-700">{t('saber.plan.busy')} {stats!.busy}</span>
            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">{t('saber.plan.off')} {stats!.off}</span>
            <span className="rounded-full bg-gold-50 px-2.5 py-1 text-navy-900 ring-1 ring-inset ring-gold-300">{t('saber.plan.chosen', { n: chosen.size })}</span>
            <span className="ms-auto flex gap-1">
              <Button size="sm" variant="ghost" onClick={() => setChosen(new Set(rows.filter((r) => r.status === 'free').map((r) => r.date)))}>{t('saber.plan.selectAll')}</Button>
              <Button size="sm" variant="ghost" onClick={() => setChosen(new Set())}>{t('saber.plan.clearAll')}</Button>
            </span>
          </div>
          <ul className="grid max-h-80 gap-1.5 overflow-y-auto sm:grid-cols-2">
            {rows.map((r) => {
              const on = chosen.has(r.date)
              return (
                <li key={r.date}>
                  <label className={clsx('flex items-center gap-3 rounded-xl border px-3 py-2 text-sm transition', r.status === 'off' ? 'cursor-not-allowed border-slate-100 bg-slate-50 text-slate-400' : on ? 'cursor-pointer border-gold-300 bg-gold-50/60' : 'cursor-pointer border-navy-100 hover:border-gold-300')}>
                    <input type="checkbox" disabled={r.status === 'off'} checked={on} onChange={() => setChosen((s) => { const n = new Set(s); if (n.has(r.date)) n.delete(r.date); else n.add(r.date); return n })} />
                    <span className="min-w-0 flex-1">
                      <span className="block font-semibold text-navy-900">{fmt.date(r.date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</span>
                      {(r.label || r.busy.length > 0) && <span className="block truncate text-[11px] text-slate-500">{[r.label, ...r.busy].filter(Boolean).join(' · ')}{r.status === 'off' && r.allow_events ? ` · ${t('saber.plan.eventsOk')}` : ''}</span>}
                    </span>
                    {r.status === 'free' ? <CheckCircle2 className="size-4 text-emerald-500" /> : r.status === 'busy' ? <Users className="size-4 text-rose-500" /> : <CalendarOff className="size-4 text-slate-400" />}
                  </label>
                </li>
              )
            })}
          </ul>
          {canApply && <div className="flex justify-end"><Button variant="gold" loading={busy} disabled={chosen.size === 0} onClick={apply}>{t('saber.plan.apply')}</Button></div>}
        </div>
      )}
    </Modal>
  )
}
