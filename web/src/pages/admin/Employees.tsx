import clsx from 'clsx'
import { FileText, PanelRight, Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Avatar, Card, PageHeader } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Employee, Paginated } from '@/lib/types'
import { Pager } from './shared'
import PassportPanel from './employees/PassportPanel'

type OpenMode = 'panel' | 'page'
const MODE_KEY = 'tedc.passport.open'
const savedMode = (): OpenMode => { try { return localStorage.getItem(MODE_KEY) === 'page' ? 'page' : 'panel' } catch { return 'panel' } }

export default function Employees() {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<Paginated<Employee>>('/admin/employees', { q: q || undefined, page })
  const navigate = useNavigate()
  // How «جواز التدريب» opens — in a side panel over the list or as a full page — is the administrator's choice, remembered on this browser.
  const [mode, setModeState] = useState<OpenMode>(savedMode)
  const setMode = (m: OpenMode) => { setModeState(m); try { localStorage.setItem(MODE_KEY, m) } catch { /* private window */ } }
  const [viewing, setViewing] = useState<Employee | null>(null)
  const open = (e: Employee, ev: React.MouseEvent) => {
    if (ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.button === 1) return   // a new tab or window still opens the full page
    ev.preventDefault()
    if (mode === 'panel') setViewing(e)
    else navigate(`/admin/employees/${e.id}`)
  }

  return (
    <>
      <PageHeader title={t('admin.employees.title')} actions={
        <div className="flex items-center gap-2">
          <span className="hidden text-xs font-semibold text-slate-500 sm:inline">{t('admin.employees.openMode')}</span>
          <div role="radiogroup" aria-label={t('admin.employees.openMode')} className="inline-flex rounded-xl border border-navy-100 bg-white p-1">
            {([['panel', PanelRight, 'admin.employees.openPanel'], ['page', FileText, 'admin.employees.openPage']] as const).map(([id, Icon, label]) => (
              <button key={id} type="button" role="radio" aria-checked={mode === id} onClick={() => setMode(id)} className={clsx('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition', mode === id ? 'bg-navy-900 text-white shadow-sm' : 'text-slate-500 hover:text-navy-900')}><Icon className="size-3.5" />{t(label)}</button>
            ))}
          </div>
        </div>
      } />
      <Card padded={false}>
        <div className="border-b border-navy-100 p-4"><div className="relative max-w-md"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} /></div></div>
        <DataView id="admin.employees" rows={data?.data} total={data?.meta?.total} loading={isLoading} rowKey={(e) => e.id} columns={[
          { key: 'avatar', header: '', role: 'media', hideInTable: true, cell: (e) => <Avatar name={e.name ?? ''} size={44} /> },
          { key: 'name', header: t('common.name'), role: 'title', cell: (e) => <><div className="font-semibold text-navy-900">{e.name}</div><div className="text-xs font-normal text-slate-400" dir="ltr">{e.email}</div></> },
          { key: 'no', header: t('admin.employees.number'), role: 'badge', cell: (e) => <span className="font-mono text-xs text-slate-500" dir="ltr">{e.employee_no}</span> },
          { key: 'job', header: t('admin.employees.jobTitle'), cell: (e) => e.job_title?.name ?? '—' },
          { key: 'school', header: t('admin.employees.school'), cell: (e) => <span className="text-sm">{e.school?.name ?? '—'}</span> },
          { key: 'exp', header: t('admin.employees.experience'), cell: (e) => fmt.number(e.experience_years, 1) },
          { key: 'passport', header: '', role: 'actions', cell: (e) => <Link to={`/admin/employees/${e.id}`} onClick={(ev) => open(e, ev)} aria-haspopup={mode === 'panel' ? 'dialog' : undefined} className="inline-flex items-center gap-1.5 text-sm font-bold text-link">{mode === 'panel' ? <PanelRight className="size-3.5" /> : <FileText className="size-3.5" />}{t('admin.employees.passport')}</Link> },
        ]} />
        <Pager page={page} last={data?.meta?.last_page} onChange={setPage} />
      </Card>
      {viewing && <PassportPanel employee={viewing} onClose={() => setViewing(null)} />}
    </>
  )
}
