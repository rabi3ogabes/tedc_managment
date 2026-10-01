import { Plus, Search, Video, Wand2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card, PageHeader, Progress, StatusBadge } from '@/components/ui'
import { DataView, type Column } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Program } from '@/lib/types'
import { Pager } from './shared'

export default function ProgramsAdmin() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<Paginated<Program>>('/admin/programs', { q: q || undefined, status: status || undefined, page })

  const columns: Column<Program>[] = [
    { key: 'code', header: t('admin.programs.code'), role: 'media', cell: (p) => <span className="inline-block rounded-lg bg-navy-900 px-2 py-1 font-mono text-[11px] font-bold text-gold-300" dir="ltr">{p.code}</span> },
    { key: 'title', header: t('programs.title'), role: 'title', cell: (p) => <Link to={`/admin/programs/${p.id}`} className="font-bold text-navy-900 hover:text-link">{p.title}</Link> },
    { key: 'meta', header: t('programs.mode'), role: 'subtitle', cell: (p) => <span className="text-xs text-slate-400">{t(`modes.${p.delivery_mode}`)} · {fmt.number(p.total_hours)} {t('common.hours')}</span> },
    { key: 'category', header: t('programs.category'), cell: (p) => <span className="text-slate-600">{p.category?.name ?? '—'}</span> },
    { key: 'start', header: t('programs.startsOn'), className: 'whitespace-nowrap', cell: (p) => fmt.date(p.start_date, { day: 'numeric', month: 'short', year: 'numeric' }) },
    { key: 'capacity', header: t('programs.capacity'), cell: (p) => <div className="w-32"><div className="mb-1 text-xs text-slate-500">{fmt.number(p.seats_taken ?? 0)} / {fmt.number(p.capacity)}</div><Progress value={((p.seats_taken ?? 0) / p.capacity) * 100} /></div> },
    { key: 'status', header: t('common.status'), role: 'badge', cell: (p) => <StatusBadge status={p.status} /> },
    { key: 'open', header: '', role: 'actions', hideInTable: true, cell: (p) => <Button size="sm" variant="outline" to={`/admin/programs/${p.id}`}>{t('common.details')}</Button> },
  ]

  return (
    <>
      <PageHeader title={t('admin.menu.programs')} actions={can('programs.manage') && <div className="flex flex-wrap gap-2"><Button to="/admin/programs/smart" variant="gold" icon={<Wand2 className="size-4" />}>{t('mgmt.wizard.smartCreate')}</Button><Button to="/admin/programs/remote" variant="primary" icon={<Video className="size-4" />}>{t('studio.nav.remote')}</Button><Button to="/admin/programs/new" variant="outline" icon={<Plus className="size-4" />}>{t('admin.programs.new')}</Button></div>} />
      <Card padded={false}>
        <div className="flex flex-wrap gap-3 border-b border-navy-100 p-4">
          <div className="relative min-w-60 flex-1"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} /></div>
          <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t('common.all')}</option>
            {['draft', 'published', 'registration_open', 'in_progress', 'completed', 'archived'].map((s) => <option key={s} value={s}>{t(`status.${s}`)}</option>)}
          </select>
        </div>
        <DataView id="admin.programs" rows={data?.data} total={data?.meta?.total} loading={isLoading} columns={columns} rowKey={(p) => p.id} defaultMode="cards" />
        <Pager page={page} last={data?.meta?.last_page} onChange={setPage} />
      </Card>
    </>
  )
}
