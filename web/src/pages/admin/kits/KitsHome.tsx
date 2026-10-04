import clsx from 'clsx'
import { AlarmClock, CalendarClock, FilePlus2, FolderKanban, LayoutGrid, MessageSquareWarning, PackageCheck, Rows3, Search, ShieldCheck, Table2, UserCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Avatar, Button, Card, Empty, PageHeader, Progress, Spinner } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated } from '@/lib/types'
import { relativeTime } from './comments/api'
import { DeliveryBadge, KitStatusBadge, deliveryMeta, statusTone } from './common'
import SampleKits from './SampleKits'
import KitForm from './KitForm'
import { KIT_STATUSES, type Kit, type KitDelivery, type KitStats, type KitStatus } from './types'
import { Pager } from '../shared'

type Column = { status: KitStatus; count: number; items: Kit[] }
type View = 'board' | 'cards' | 'table'

function People({ kit, max = 4 }: { kit: Kit; max?: number }) {
  const people = [...(kit.owner ? [{ id: kit.owner.id, name: kit.owner.name, role: 'developer' }] : []), ...(kit.members ?? []).map((m) => ({ id: m.user_id, name: m.name ?? '', role: m.role }))]
  return (
    <div className="flex -space-x-2 rtl:space-x-reverse">
      {people.slice(0, max).map((p) => <span key={p.id} title={p.name} className={clsx('rounded-full ring-2', p.role === 'qa' ? 'ring-gold-500' : 'ring-white')}><Avatar name={p.name} size={26} /></span>)}
      {people.length > max && <span className="grid size-[26px] place-items-center rounded-full bg-navy-100 text-[10px] font-bold text-navy-800 ring-2 ring-white">+{people.length - max}</span>}
    </div>
  )
}

function KitCard({ kit }: { kit: Kit }) {
  const { t } = useTranslation()
  const overdue = kit.due_at && new Date(kit.due_at) < new Date() && !['approved', 'published', 'archived'].includes(kit.status)
  return (
    <Link to={`/admin/kits/${kit.id}`} className="group relative block overflow-hidden rounded-2xl border border-navy-100 bg-white p-4 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-glass">
      <span className={clsx('absolute inset-x-0 top-0 h-1 bg-gradient-to-l to-transparent', statusTone[kit.status].column)} />
      <div className="flex items-start justify-between gap-2">
        <span className="rounded-md bg-navy-900 px-1.5 py-0.5 font-mono text-[10px] font-bold text-gold-300" dir="ltr">{kit.code}</span>
        <DeliveryBadge delivery={kit.delivery} className="!text-[10px]" />
      </div>
      {kit.category && <div className="mt-1.5 truncate text-[11px] font-semibold text-slate-400">{kit.category.name}</div>}
      <h3 className="mt-2 line-clamp-2 min-h-[2.5rem] font-bold leading-snug text-navy-900 group-hover:text-link" dir="auto">{kit.title}</h3>
      {kit.program && <p className="mt-1 truncate text-[11px] text-slate-400" dir="auto">{kit.program.title}</p>}
      <div className="mt-3 flex items-center gap-2"><Progress value={kit.progress ?? 0} tone={kit.progress && kit.progress >= 90 ? 'green' : 'gold'} /><span className="w-9 shrink-0 text-end text-[11px] font-bold text-navy-800">{fmt.percent(kit.progress ?? 0)}</span></div>
      <div className="mt-3 flex items-center justify-between gap-2">
        <People kit={kit} />
        <div className="flex items-center gap-2 text-[11px] font-semibold text-slate-500">
          {(kit.files_count ?? 0) > 0 && <span>{fmt.number(kit.files_count)} {t('kits.home.files')}</span>}
          {(kit.open_comments ?? 0) > 0 && <span className={clsx('inline-flex items-center gap-1 rounded-full px-1.5 py-0.5', (kit.blocking_comments ?? 0) > 0 ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700')}><MessageSquareWarning className="size-3" />{kit.open_comments}</span>}
        </div>
      </div>
      {(kit.due_at || kit.status === 'in_review') && (
        <div className={clsx('mt-2.5 flex items-center gap-1.5 border-t border-dashed border-navy-100 pt-2 text-[11px] font-semibold', overdue ? 'text-danger' : 'text-slate-400')}>
          {overdue ? <AlarmClock className="size-3.5" /> : <CalendarClock className="size-3.5" />}
          {kit.due_at ? fmt.date(kit.due_at, { day: 'numeric', month: 'short', year: 'numeric' }) : ''}
          {kit.status === 'in_review' && <span className="ms-auto rounded-full bg-gold-100 px-1.5 text-gold-700">{t('kits.home.round', { n: kit.review_round })}</span>}
        </div>
      )}
    </Link>
  )
}

function Stat({ icon: Icon, label, value, tone = 'navy', onClick }: { icon: typeof PackageCheck; label: string; value: number; tone?: 'navy' | 'gold' | 'red'; onClick?: () => void }) {
  return (
    <button type="button" onClick={onClick} className={clsx('flex items-center gap-3 rounded-2xl border p-4 text-start transition hover:-translate-y-0.5 hover:shadow-glass', tone === 'gold' ? 'border-gold-300 bg-gold-100/50' : tone === 'red' && value > 0 ? 'border-red-200 bg-red-50/60' : 'border-navy-100 bg-white')}>
      <span className={clsx('grid size-11 shrink-0 place-items-center rounded-xl', tone === 'gold' ? 'bg-gold-500 text-navy-950' : tone === 'red' && value > 0 ? 'bg-red-600 text-white' : 'bg-navy-900 text-gold-300')}><Icon className="size-5" /></span>
      <span><span className="block text-2xl font-bold leading-none text-navy-900">{fmt.number(value)}</span><span className="mt-1 block text-xs text-slate-500">{label}</span></span>
    </button>
  )
}

/** Studio home: what needs attention, and every kit on a board, as cards or as a table. */
export default function KitsHome() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const navigate = useNavigate()
  const [view, setView] = useState<View>(() => (localStorage.getItem('tedc.kits.view') as View) || 'board')
  const [q, setQ] = useState('')
  const [mine, setMine] = useState(false)
  const [status, setStatus] = useState<KitStatus | ''>('')
  const [overdue, setOverdue] = useState(false)
  const [page, setPage] = useState(1)
  const [creating, setCreating] = useState(false)
  const [delivery, setDelivery] = useState<KitDelivery | 'all'>(() => { try { const v = localStorage.getItem('tedc.kits.delivery'); return v === 'in_person' || v === 'online' || v === 'hybrid' ? v : 'all' } catch { return 'all' } })
  const type = delivery === 'all' ? undefined : delivery
  const filters = { q: q || undefined, mine: mine ? 1 : undefined, overdue: overdue ? 1 : undefined, delivery: type }
  const stats = useGet<{ data: KitStats }>('/admin/kits/stats', { delivery: type })
  const board = useGet<{ data: Column[] }>(view === 'board' ? '/admin/kits/board' : null, filters)
  const list = useGet<Paginated<Kit>>(view !== 'board' ? '/admin/kits' : null, { ...filters, status: status || undefined, page })
  const s = stats.data?.data
  const changeDelivery = (d: KitDelivery | 'all') => { setDelivery(d); setStatus(''); setPage(1); try { localStorage.setItem('tedc.kits.delivery', d) } catch { /* storage unavailable */ } }
  const changeView = (v: View) => { setView(v); setPage(1); try { localStorage.setItem('tedc.kits.view', v) } catch { /* storage unavailable */ } }

  return (
    <>
      <PageHeader title={t('kits.home.title')} subtitle={t('kits.home.subtitle')} actions={can('kits.manage') && <Button variant="gold" icon={<FilePlus2 className="size-4" />} onClick={() => setCreating(true)}>{t('kits.home.new')}</Button>} />

      {/* The kinds of programs the kits are for */}
      <div role="tablist" aria-label={t('kits.delivery.label')} className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        {(['all', 'in_person', 'online', 'hybrid'] as const).map((id) => {
          const active = delivery === id
          const Icon = id === 'all' ? PackageCheck : deliveryMeta[id].icon
          const count = id === 'all' ? Object.values(s?.by_delivery ?? {}).reduce((a, n) => a + n, 0) : s?.by_delivery?.[id] ?? 0
          return (
            <button key={id} type="button" role="tab" aria-selected={active} onClick={() => changeDelivery(id)} className={clsx('group flex items-center gap-3 rounded-2xl border p-3.5 text-start transition', active ? 'border-navy-900 bg-navy-900 text-white shadow-glass' : 'border-navy-100 bg-white hover:border-gold-400')}>
              <span className={clsx('grid size-11 shrink-0 place-items-center rounded-xl', active ? 'bg-gold-500 text-navy-950' : 'bg-navy-100/70 text-navy-800')}><Icon className="size-5" /></span>
              <span className="min-w-0 flex-1"><span className="block text-sm font-extrabold leading-tight">{t(`kits.delivery.${id}`)}</span><span className={clsx('mt-0.5 block truncate text-[11px]', active ? 'text-white/70' : 'text-slate-500')}>{t(`kits.delivery.${id}Hint`)}</span></span>
              <span className={clsx('rounded-full px-2.5 py-0.5 text-lg font-black tabular-nums', active ? 'bg-white/15 text-gold-300' : 'bg-navy-100/70 text-navy-900')}>{fmt.number(count)}</span>
            </button>
          )
        })}
      </div>

      {can('kits.manage') && <SampleKits />}

      <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-5">
        <Stat icon={PackageCheck} label={t('kits.home.stats.total')} value={s?.total ?? 0} onClick={() => { setStatus(''); setMine(false); setOverdue(false) }} />
        <Stat icon={ShieldCheck} label={s && s.awaiting_my_review > 0 ? t('kits.home.stats.awaitingMine') : t('kits.home.stats.awaiting')} value={s && s.awaiting_my_review > 0 ? s.awaiting_my_review : s?.awaiting_review ?? 0} tone="gold" onClick={() => { changeView('cards'); setStatus('in_review') }} />
        <Stat icon={UserCheck} label={t('kits.home.stats.changes')} value={s?.needs_my_changes ?? 0} tone="red" onClick={() => { changeView('cards'); setStatus('changes_requested') }} />
        <Stat icon={MessageSquareWarning} label={t('kits.home.stats.myComments')} value={s?.my_open_comments ?? 0} onClick={() => changeView('cards')} />
        <Stat icon={AlarmClock} label={t('kits.home.stats.overdue')} value={s?.overdue ?? 0} tone="red" onClick={() => { changeView('cards'); setOverdue(true) }} />
      </div>

      <div className="mb-5 flex flex-wrap items-center gap-3">
        <div className="relative min-w-60 flex-1"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('kits.home.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} /></div>
        <button type="button" aria-pressed={mine} onClick={() => { setMine(!mine); setPage(1) }} className={clsx('rounded-xl border px-4 py-2.5 text-sm font-semibold transition', mine ? 'border-gold-500 bg-gold-100 text-gold-700' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>{t('kits.home.mine')}</button>
        {overdue && <button type="button" onClick={() => setOverdue(false)} className="rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-semibold text-red-700">{t('kits.home.stats.overdue')} ×</button>}
        <div className="inline-flex rounded-xl border border-navy-100 bg-white p-1 shadow-sm" role="radiogroup" aria-label={t('common.viewMode')}>
          {([['board', FolderKanban], ['cards', LayoutGrid], ['table', Table2]] as const).map(([id, Icon]) => (
            <button key={id} type="button" role="radio" aria-checked={view === id} onClick={() => changeView(id)} className={clsx('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition', view === id ? 'bg-navy-900 text-white shadow' : 'text-slate-500 hover:text-navy-900')}><Icon className="size-4" /><span className="hidden sm:inline">{t(`kits.home.views.${id}`)}</span></button>
          ))}
        </div>
      </div>

      {view !== 'board' && (
        <div className="mb-4 flex flex-wrap gap-1.5">
          <button type="button" aria-pressed={status === ''} onClick={() => { setStatus(''); setPage(1) }} className={clsx('rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset transition', status === '' ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-slate-500 ring-navy-100')}>{t('kits.common.all')}</button>
          {KIT_STATUSES.map((st) => <button key={st} type="button" aria-pressed={status === st} onClick={() => { setStatus(st); setPage(1) }} className={clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset transition', status === st ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-slate-500 ring-navy-100')}><span className={clsx('size-1.5 rounded-full', statusTone[st].dot)} />{t(`kits.status.${st}`)}{s?.by_status[st] ? <span className="opacity-60">{s.by_status[st]}</span> : null}</button>)}
        </div>
      )}

      {view === 'board' ? (
        board.isLoading ? <Spinner /> : (
          <div className="-mx-2 flex gap-4 overflow-x-auto px-2 pb-4">
            {board.data?.data.map((col) => (
              <section key={col.status} className="w-[19.5rem] shrink-0" aria-label={t(`kits.status.${col.status}`)}>
                <header className="mb-3 flex items-center gap-2 px-1"><span className={clsx('size-2.5 rounded-full', statusTone[col.status].dot)} /><h3 className="text-sm font-bold text-navy-900">{t(`kits.status.${col.status}`)}</h3><span className="rounded-full bg-navy-100 px-2 text-xs font-bold text-navy-800">{col.count}</span></header>
                <div className="space-y-3 rounded-2xl bg-navy-100/40 p-2.5">
                  {col.items.length === 0 ? <p className="px-3 py-8 text-center text-xs text-slate-400">{t('kits.home.columnEmpty')}</p> : col.items.map((k) => <KitCard key={k.id} kit={k} />)}
                </div>
              </section>
            ))}
          </div>
        )
      ) : (
        <Card padded={false}>
          <DataView id="admin.kits" rows={list.data?.data} total={list.data?.meta?.total} loading={list.isLoading} rowKey={(k) => k.id} defaultMode={view === 'table' ? 'table' : 'cards'} emptyText={t('kits.home.empty')} cardsClassName="2xl:grid-cols-3" columns={[
            { key: 'code', header: t('kits.home.code'), role: 'media', cell: (k) => <span className="rounded-md bg-navy-900 px-1.5 py-0.5 font-mono text-[10px] font-bold text-gold-300" dir="ltr">{k.code}</span> },
            { key: 'title', header: t('kits.home.kit'), role: 'title', cell: (k) => <Link to={`/admin/kits/${k.id}`} className="hover:text-link" dir="auto">{k.title}</Link> },
            { key: 'delivery', header: t('kits.delivery.label'), cell: (k) => <DeliveryBadge delivery={k.delivery} /> },
            { key: 'program', header: t('kits.home.program'), role: 'subtitle', cell: (k) => k.program?.title ?? '—' },
            { key: 'status', header: t('kits.home.status'), role: 'badge', cell: (k) => <KitStatusBadge status={k.status} /> },
            { key: 'team', header: t('kits.home.team'), cell: (k) => <People kit={k} /> },
            { key: 'progress', header: t('kits.home.progress'), cell: (k) => <div className="flex w-32 items-center gap-2"><Progress value={k.progress ?? 0} /><span className="text-xs font-bold">{fmt.percent(k.progress ?? 0)}</span></div> },
            { key: 'comments', header: t('kits.home.comments'), cell: (k) => (k.open_comments ? <span className={clsx('rounded-full px-2 py-0.5 text-xs font-bold', k.blocking_comments ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700')}>{k.open_comments}</span> : '—') },
            { key: 'due', header: t('kits.home.due'), cell: (k) => (k.due_at ? fmt.date(k.due_at, { day: 'numeric', month: 'short', year: 'numeric' }) : '—') },
            { key: 'updated', header: t('kits.home.updated'), hideInCards: true, cell: (k) => <span className="text-xs text-slate-400">{k.updated_at ? relativeTime(k.updated_at, i18n.language) : ''}</span> },
            { key: 'open', header: '', role: 'actions', hideInTable: true, cell: (k) => <Button size="sm" variant="outline" to={`/admin/kits/${k.id}`}>{t('kits.home.open')}</Button> },
          ]} />
          <Pager page={page} last={list.data?.meta?.last_page} onChange={setPage} />
        </Card>
      )}

      {!board.isLoading && !list.isLoading && s && s.total === 0 && (
        <div className="mt-6"><Empty text={t('kits.home.firstKit')} icon={<Rows3 className="size-8" />} /></div>
      )}

      {s && s.recent.length > 0 && (
        <section className="mt-8">
          <h3 className="mb-3 text-sm font-bold text-navy-900">{t('kits.home.recent')}</h3>
          <ul className="divide-y divide-navy-100 rounded-2xl border border-navy-100 bg-white">
            {s.recent.slice(0, 6).map((a) => (
              <li key={a.id}><Link to={`/admin/kits/${a.kit_id}`} className="flex items-center gap-3 px-4 py-2.5 text-sm transition hover:bg-ivory">
                <Avatar name={a.user?.name ?? '—'} size={28} />
                <span className="min-w-0 flex-1 truncate"><b className="text-navy-900">{a.user?.name ?? '—'}</b> <span className="text-slate-500">{t(`kits.activity.${a.action}`, { defaultValue: a.action })}</span>{a.kit && <span className="text-slate-400" dir="auto"> · {i18n.language === 'en' ? a.kit.title_en : a.kit.title_ar}</span>}</span>
                <span className="shrink-0 text-xs text-slate-400">{relativeTime(a.created_at, i18n.language)}</span>
              </Link></li>
            ))}
          </ul>
        </section>
      )}

      {creating && <KitForm defaultDelivery={type ?? 'in_person'} onClose={() => setCreating(false)} onSaved={(id) => navigate(`/admin/kits/${id}`)} />}
    </>
  )
}
