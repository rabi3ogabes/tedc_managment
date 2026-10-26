import clsx from 'clsx'
import { Ban, BadgeCheck, CalendarRange, CalendarX2, Eraser, Lock, RotateCcw, ShieldCheck, Trash2, Undo2 } from 'lucide-react'
import { Fragment, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { useSaberActions } from './actions'
import { Picker } from './Picker'
import type { Day, Group, Notice, Overview, Program } from './types'

type Props = { program: Program; group: Group; overview: Overview; view: 'rows' | 'cards'; onGroup: (g: Group) => void; onRemoved: (id: string) => void }

const tell = (notices: Notice[] | undefined) => notices?.forEach((n) => toast(n.text, n.type === 'warning' ? 'error' : n.type === 'info' ? 'info' : 'success'))

/** One group: its identity (colour, number, name, term, approval) and its training days, each saved the moment it changes. */
export default function GroupPanel({ program, group, overview, view, onGroup, onRemoved }: Props) {
  const { t } = useTranslation()
  const actions = useSaberActions()
  const can = (p: string) => !!overview.can[p]
  const [busyDay, setBusyDay] = useState<number | null>(null)
  const online = program.method === 'online'
  const editable = !group.cancelled && !program.cancelled
  const name = group.title || t('saber.group.n', { n: group.number })
  // Planning edits go to the programme's draft; approval and cancellation act on the live group.
  const base = `/admin/saber/programs/${program.id}/groups/${group.id}`
  const live = group.live !== false

  const patchGroup = async (body: Record<string, unknown>) => {
    try {
      const { data } = await api.patch(`${base}`, body)
      onGroup(data.data)
      toast(t('saber.group.renamed'))
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  const setDay = async (day: Day, patch: Partial<Record<'date' | 'start_time' | 'end_time' | 'room_id' | 'trainer_id', string | null>>) => {
    setBusyDay(day.idx)
    try {
      const { data } = await api.put(`${base}/days/${day.idx}`, patch)
      onGroup(data.data)
      if (data.notices?.length) tell(data.notices)
      else toast(t('saber.day.saved', { n: day.idx }))
    } catch (e) {
      // A closed day is not a dead end: offer the request to train on it (or the instant approval for whoever holds the right).
      const code = (e as { response?: { data?: { code?: string } } }).response?.data?.code
      const date = patch.date ?? day.date
      if (code === 'calendar_closed' && date) actions.requestOffday({ date, groupId: group.id, idx: day.idx, programTitle: program.title, groupLabel: name })
      else toast(errorMessage(e), 'error')
    } finally { setBusyDay(null) }
  }

  const reactivate = async () => {
    try {
      const { data } = await api.post(`/admin/saber/groups/${group.id}/reactivate`)
      tell(data.notices); toast(t('saber.cancel.reactivated')); actions.refresh()
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  const resetDay = async (day: Day) => {
    setBusyDay(day.idx)
    try { onGroup((await api.delete(`${base}/days/${day.idx}`)).data.data) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusyDay(null) }
  }

  const resetGroup = async () => {
    if (!(await dialogs.confirm(t('saber.group.resetConfirm', { name })))) return
    try {
      const { data } = await api.post(`${base}/reset`)
      onGroup(data.data); toast(t('saber.group.resetDone', { n: data.reset }))
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  const remove = async () => {
    if (!(await dialogs.confirm(t('saber.group.deleteConfirm', { name })))) return
    try { await api.delete(base); onRemoved(group.id); actions.refresh(); toast(t('saber.group.deleted')) } catch (e) { toast(errorMessage(e), 'error') }
  }

  const validate = async () => {
    try {
      const { data } = await api.post(`/admin/saber/groups/${group.id}/validate`)
      onGroup(data.data); toast(t(data.data.validated_at ? 'saber.group.validatedDone' : 'saber.group.unvalidatedDone'))
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  const canManage = can('saber.group.manage')
  const canRename = canManage || can('saber.group.rename')
  const colour = group.color ?? overview.palette[0]

  return (
    <section className="overflow-visible rounded-2xl border border-navy-100 bg-white" style={{ borderInlineStartWidth: 4, borderInlineStartColor: colour }}>
      <header className="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-navy-50 px-4 py-3">
        <ColourDot value={colour} palette={overview.palette} disabled={!canRename || !editable} onPick={(c) => patchGroup({ color: c })} label={t('saber.group.colour')} />
        <input type="number" min={1} defaultValue={group.number} key={`n-${group.number}`} disabled={!canManage || !editable} aria-label={t('saber.group.number')}
          onBlur={(e) => { const n = Number(e.target.value); if (n && n !== group.number) patchGroup({ sequence: n }); else e.target.value = String(group.number) }}
          className="h-8 w-14 rounded-lg border border-transparent bg-ivory text-center text-sm font-bold text-navy-900 hover:border-navy-100 focus:border-gold-400 focus:outline-none disabled:bg-transparent" />
        <input defaultValue={group.title ?? ''} key={`t-${group.title}`} disabled={!canRename || !editable} aria-label={t('saber.group.name')} placeholder={name}
          onBlur={(e) => { const v = e.target.value.trim(); if (v !== (group.title ?? '')) patchGroup({ title_ar: v || null }) }}
          className="h-8 min-w-40 flex-1 rounded-lg border border-transparent bg-transparent px-2 text-sm font-bold text-navy-900 hover:border-navy-100 focus:border-gold-400 focus:bg-white focus:outline-none" />
        <select value={group.term ?? ''} disabled={!canRename || !editable} onChange={(e) => patchGroup({ term: e.target.value || null })} aria-label={t('saber.group.term')}
          className="h-8 rounded-lg border border-navy-100 bg-white px-2 text-xs font-semibold text-slate-600">
          <option value="">{t('saber.group.termAuto')}{group.term_effective && !group.term ? ` · ${t(`saber.group.terms.${group.term_effective}`)}` : ''}</option>
          {(['first', 'second', 'year'] as const).map((x) => <option key={x} value={x}>{t(`saber.group.terms.${x}`)}</option>)}
        </select>
        {group.cancelled && <Badge color="red">{t('saber.group.cancelled')}</Badge>}
        {!live && <Badge color="blue">{t('saber.draft.newGroup')}</Badge>}
        {live ? <ValidationChip group={group} canValidate={can('saber.group.validate')} onToggle={validate} /> : <span className="text-[11px] text-slate-400">{t('saber.draft.liveOnly')}</span>}
        <div className="ms-auto flex items-center gap-1">
          {live && group.cancelled && can('saber.program.reactivate') && <IconButton label={t('saber.cancel.reactivate')} onClick={reactivate}><Undo2 className="size-4" /></IconButton>}
          {can('saber.plan.view') && editable && <IconButton label={t('saber.plan.fromGroup')} onClick={() => actions.plan(program, group)}><CalendarRange className="size-4" /></IconButton>}
          {can('saber.day.edit') && editable && <IconButton label={t('saber.group.reset')} onClick={resetGroup}><Eraser className="size-4" /></IconButton>}
          {live && can('saber.program.cancel') && editable && <IconButton label={t('saber.cancel.cancelGroup')} onClick={() => actions.cancelGroup(program, group)} danger><Ban className="size-4" /></IconButton>}
          {canManage && overview.settings.group_delete_enabled && <IconButton label={t('saber.group.delete')} onClick={remove} danger><Trash2 className="size-4" /></IconButton>}
        </div>
      </header>

      {view === 'rows' ? (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[760px] text-sm">
            <thead className="bg-ivory/60 text-[11px] font-bold uppercase tracking-wide text-slate-500">
              <tr>{['', t('saber.day.date'), t('saber.day.start'), t('saber.day.end'), t('saber.day.room'), t('saber.day.trainer'), ''].map((h, i) => <th key={i} className="px-3 py-2 text-start font-bold">{h}</th>)}</tr>
            </thead>
            <tbody className="divide-y divide-navy-50">
              {group.days.map((d) => (
                <tr key={d.idx} className={clsx('transition', busyDay === d.idx && 'opacity-60', d.extra && 'bg-amber-50/40')}>
                  <td className="w-24 px-3 py-2"><DayLabel day={d} /></td>
                  <DayCells day={d} scope={{ programId: program.id, groupId: group.id }} {...{ overview, online, editable, can, setDay, resetDay }} wrap={(c) => <td className="px-2 py-2">{c}</td>} />
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="grid gap-3 p-3 sm:grid-cols-2 xl:grid-cols-3">
          {group.days.map((d) => (
            <div key={d.idx} className={clsx('space-y-2 rounded-xl border border-navy-50 bg-ivory/40 p-3 transition', busyDay === d.idx && 'opacity-60', d.extra && 'border-amber-200')}>
              <div className="flex items-center justify-between"><DayLabel day={d} /></div>
              <DayCells day={d} scope={{ programId: program.id, groupId: group.id }} {...{ overview, online, editable, can, setDay, resetDay }} wrap={(c) => <div>{c}</div>} cards />
            </div>
          ))}
        </div>
      )}
    </section>
  )
}

function DayLabel({ day }: { day: Day }) {
  const { t } = useTranslation()
  return (
    <span className="flex items-center gap-1.5 whitespace-nowrap font-bold text-navy-900">
      <span className={clsx('size-2 rounded-full', day.date && (day.room_id || day.trainer_id) ? 'bg-emerald-500' : day.date ? 'bg-amber-400' : 'bg-slate-200')} />
      {day.extra ? t('saber.day.extra') : t('saber.day.n', { n: day.idx })}
      {day.locked && <Lock className="size-3.5 text-slate-400" aria-label={t('saber.day.locked')} />}
      {day.changed && <span className="rounded-full bg-gold-100 px-1.5 text-[10px] font-bold text-gold-800" title={t('saber.draft.changedDay')}>{t('saber.draft.changedDay')}</span>}
    </span>
  )
}

type CellProps = {
  day: Day; scope: { programId: string; groupId: string }; overview: Overview; online: boolean; editable: boolean; can: (p: string) => boolean; cards?: boolean
  setDay: (d: Day, patch: Record<string, string | null>) => void; resetDay: (d: Day) => void; wrap: (c: React.ReactNode) => React.ReactNode
}

/** Date, times, room, trainer and the clear button of one day; the same cells serve the rows and the cards. */
function DayCells({ day, scope, overview, online, editable, can, setDay, resetDay, wrap, cards }: CellProps) {
  const { t } = useTranslation()
  const canDay = editable && can('saber.day.edit') && !day.locked
  const time = (field: 'start_time' | 'end_time', value: string | null, fallback: string) => (
    <input type="time" step={900} dir="ltr" value={value ?? ''} placeholder={fallback} disabled={!canDay || !day.date} aria-label={t(field === 'start_time' ? 'saber.day.start' : 'saber.day.end')}
      onChange={(e) => e.target.value && e.target.value !== value && setDay(day, { [field]: e.target.value })}
      className="h-9 w-full rounded-xl border border-navy-100 bg-white px-2 text-sm tabular-nums disabled:bg-slate-50 disabled:text-slate-300" />
  )
  const cells = [
    <div key="date" className="relative">
      <input type="date" dir="ltr" value={day.date ?? ''} disabled={!canDay} aria-label={t('saber.day.date')}
        onChange={(e) => e.target.value !== (day.date ?? '') && setDay(day, { date: e.target.value || null })}
        className="h-9 w-full rounded-xl border border-navy-100 bg-white px-2 text-sm tabular-nums disabled:bg-slate-50" />
      {day.date && <span className="pointer-events-none mt-0.5 block text-[11px] text-slate-400">{fmt.date(day.date, { weekday: 'long', day: 'numeric', month: 'long' })}</span>}
    </div>,
    cards ? <div key="times" className="grid grid-cols-2 gap-2">{time('start_time', day.start_time, overview.day.day_start)}{time('end_time', day.end_time, overview.day.day_end)}</div> : time('start_time', day.start_time, overview.day.day_start),
    cards ? null : time('end_time', day.end_time, overview.day.day_end),
    online
      ? <span key="room" className="text-xs text-slate-400">{t('saber.day.online')}</span>
      : <Picker key="room" kind="room" day={day} scope={scope} value={day.room_id} options={overview.rooms} placeholder={t('saber.day.pickRoom')} disabled={!editable || !can('saber.room.assign') || !day.date} onPick={(id) => setDay(day, { room_id: id })} />,
    <Picker key="trainer" kind="trainer" day={day} scope={scope} value={day.trainer_id} options={overview.trainers} placeholder={t('saber.day.pickTrainer')} disabled={!editable || !can('saber.trainer.assign') || !day.date} onPick={(id) => setDay(day, { trainer_id: id })} />,
    <div key="reset" className={clsx('flex', cards ? 'justify-end' : 'justify-center')}>
      {day.date && canDay ? <IconButton label={t('saber.day.reset')} onClick={() => resetDay(day)}><RotateCcw className="size-4" /></IconButton> : day.date ? null : <CalendarX2 className="size-4 text-slate-200" />}
    </div>,
  ].filter(Boolean)
  return <>{cells.map((c, i) => <Fragment key={i}>{wrap(c)}</Fragment>)}</>
}

function ValidationChip({ group, canValidate, onToggle }: { group: Group; canValidate: boolean; onToggle: () => void }) {
  const { t } = useTranslation()
  const done = !!group.validated_at
  const title = done ? t('saber.group.validatedBy', { name: group.validated_by_name ?? '—', at: fmt.date(group.validated_at) }) + (canValidate ? ` — ${t('saber.group.unvalidate')}` : '') : undefined
  const chip = (
    <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold ring-1 ring-inset transition',
      done ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-slate-50 text-slate-500 ring-slate-500/15', canValidate && 'hover:ring-gold-400')}>
      {done ? <BadgeCheck className="size-3.5" /> : <ShieldCheck className="size-3.5" />}
      {done ? `${t('saber.group.validated')} · ${group.validated_by_name ?? ''}` : canValidate ? t('saber.group.validate') : t('saber.group.notValidated')}
    </span>
  )
  return canValidate ? <button type="button" onClick={onToggle} title={title} aria-pressed={done}>{chip}</button> : <span title={title}>{chip}</span>
}

function ColourDot({ value, palette, onPick, disabled, label }: { value: string; palette: string[]; onPick: (c: string) => void; disabled?: boolean; label: string }) {
  const [open, setOpen] = useState(false)
  return (
    <div className="relative">
      <button type="button" disabled={disabled} onClick={() => setOpen((o) => !o)} aria-label={label} title={label}
        className="size-6 rounded-full ring-2 ring-white ring-offset-1 ring-offset-navy-100 transition hover:scale-110 disabled:hover:scale-100" style={{ background: value }} />
      {open && (
        <div className="absolute top-full z-30 mt-2 grid grid-cols-5 gap-1.5 rounded-xl border border-navy-100 bg-white p-2 shadow-xl" onMouseLeave={() => setOpen(false)}>
          {palette.map((c) => <button key={c} type="button" onClick={() => { onPick(c); setOpen(false) }} aria-label={c} className={clsx('size-6 rounded-full transition hover:scale-110', c.toUpperCase() === value.toUpperCase() && 'ring-2 ring-navy-900 ring-offset-1')} style={{ background: c }} />)}
        </div>
      )}
    </div>
  )
}

export function IconButton({ label, onClick, children, danger }: { label: string; onClick: () => void; children: React.ReactNode; danger?: boolean }) {
  return <button type="button" onClick={onClick} title={label} aria-label={label} className={clsx('grid size-8 place-items-center rounded-lg text-slate-400 transition', danger ? 'hover:bg-rose-50 hover:text-rose-600' : 'hover:bg-ivory hover:text-navy-900')}>{children}</button>
}
