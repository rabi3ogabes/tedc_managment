import clsx from 'clsx'
import { LayoutGrid, Table2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { STATUS_TONE, StatusDialog, type Group } from './program/GroupsTab'

const COLUMNS = ['planned', 'registration_open', 'ongoing', 'incomplete', 'postponed', 'cancelled', 'completed']
type Board = { data: { columns: Record<string, { count: number; groups: Group[] }>; total: number } }

/** Every training group by status: a board (drag a card to change its status, with the reason dialog) and a table. */
export default function GroupBoard() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [year, setYear] = useState(String(new Date().getFullYear()))
  const [emergency, setEmergency] = useState(false)
  const [view, setView] = useState<'kanban' | 'table'>('kanban')
  const [drag, setDrag] = useState<Group | null>(null)
  const [pending, setPending] = useState<{ group: Group; to: string } | null>(null)
  const { data, isLoading, refetch } = useGet<Board>('/admin/groups/board', { year: year || undefined, emergency: emergency || undefined }, { staleTime: 0 })
  const canMove = can('groups.status')

  const drop = (to: string) => {
    if (!drag || drag.status === to) { setDrag(null); return }
    if (!drag.allowed_transitions.includes(to)) { toast(t('groups.board.notAllowed'), 'error'); setDrag(null); return }
    setPending({ group: drag, to }); setDrag(null)
  }

  const card = (g: Group) => (
    <div key={g.id} draggable={canMove && g.allowed_transitions.length > 0} onDragStart={() => setDrag(g)} onDragEnd={() => setDrag(null)}
      className="cursor-grab space-y-1.5 rounded-xl border border-navy-100 bg-white p-3 shadow-sm active:cursor-grabbing">
      <div className="flex items-center justify-between gap-2"><span className="font-mono text-[11px] text-slate-400" dir="ltr">{g.code}</span>{g.is_emergency && <Badge color="red">{t('groups.emergency')}</Badge>}</div>
      <div className="line-clamp-2 text-sm font-bold text-navy-900">{g.title}</div>
      <div className="text-xs text-slate-500">{fmt.date(g.start_date, { day: 'numeric', month: 'short' })} – {fmt.date(g.end_date, { day: 'numeric', month: 'short' })}</div>
      <div className="text-xs text-slate-500">{fmt.number(g.seats_taken)} / {fmt.number(g.capacity)} {t('groups.seats')}</div>
    </div>
  )

  return (
    <div className="space-y-5 pb-6">
      <PageHeader title={t('groups.board.title')} subtitle={t('groups.board.subtitle')} actions={
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex items-center gap-1.5 text-sm font-semibold text-slate-600"><input type="checkbox" checked={emergency} onChange={(e) => setEmergency(e.target.checked)} />{t('groups.board.emergencyOnly')}</label>
          <input type="number" className="input w-24" aria-label={t('groups.board.year')} value={year} onChange={(e) => setYear(e.target.value)} />
          <div className="flex rounded-xl border border-navy-100 bg-white p-0.5">
            {([['kanban', LayoutGrid, t('groups.board.kanban')], ['table', Table2, t('groups.board.table')]] as const).map(([id, Icon, label]) => <button key={id} type="button" aria-pressed={view === id} onClick={() => setView(id)} className={clsx('flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-bold', view === id ? 'bg-navy-900 text-white' : 'text-slate-500')}><Icon className="size-4" />{label}</button>)}
          </div>
        </div>} />
      {isLoading || !data ? <Spinner /> : view === 'kanban' ? (
        <div className="flex gap-3 overflow-x-auto pb-3">
          {COLUMNS.map((s) => {
            const col = data.data.columns[s]
            return (
              <section key={s} onDragOver={(e) => e.preventDefault()} onDrop={() => drop(s)} aria-label={t(`groups.status.${s}`)}
                className={clsx('flex min-h-64 w-64 shrink-0 flex-col gap-2 rounded-2xl bg-ivory p-3 transition', drag && drag.allowed_transitions.includes(s) && 'ring-2 ring-gold-400')}>
                <header className="flex items-center justify-between"><Badge color={STATUS_TONE[s] ?? 'navy'}>{t(`groups.status.${s}`)}</Badge><span className="text-xs font-bold text-slate-400">{fmt.number(col?.count ?? 0)}</span></header>
                {(col?.groups ?? []).map(card)}
                {(col?.count ?? 0) === 0 && <p className="py-6 text-center text-xs text-slate-400">{drag ? t('groups.board.dropHere') : t('groups.board.none')}</p>}
              </section>
            )
          })}
        </div>
      ) : (
        <Card padded={false}>
          {data.data.total === 0 ? <Empty text={t('groups.board.none')} /> : (
            <div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-ivory text-xs text-slate-500"><tr>{['code', 'title', 'start', 'seats', 'status'].map((h) => <th key={h} className="px-4 py-3 text-start font-bold">{h === 'code' ? '#' : h === 'title' ? t('plans.itemTitleEn') : h === 'seats' ? t('groups.seats') : h === 'start' ? t('groups.start') : t('groups.changeStatus')}</th>)}</tr></thead>
              <tbody>{COLUMNS.flatMap((s) => data.data.columns[s]?.groups ?? []).map((g) => <tr key={g.id} className="border-t border-navy-50"><td className="px-4 py-3 font-mono text-xs" dir="ltr">{g.code}</td><td className="px-4 py-3 font-semibold text-navy-900">{g.title}</td><td className="px-4 py-3">{fmt.date(g.start_date)}</td><td className="px-4 py-3 tabular-nums">{g.seats_taken}/{g.capacity}</td><td className="px-4 py-3"><Badge color={STATUS_TONE[g.status] ?? 'navy'}>{t(`groups.status.${g.status}`)}</Badge></td></tr>)}</tbody></table></div>
          )}
        </Card>
      )}
      <StatusDialog group={pending?.group ?? null} to={pending?.to} onClose={() => setPending(null)} onDone={() => void refetch()} />
    </div>
  )
}
