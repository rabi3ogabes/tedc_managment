import clsx from 'clsx'
import { Eye, GraduationCap, Maximize2, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Avatar, Badge, ErrorState, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import type { Employee } from '@/lib/types'
import { EmployeeProfileBody } from '../EmployeeProfile'
import { UserAccessBody } from '../UserAccess'
import { type PanelView } from './panelState'

/**
 * An employee in a side panel over the employees list. Two views: «جواز التدريب» (the training passport and profile) and,
 * for whoever manages users, «ما يراه» (the person's roles, the pages of each role's menu, its rights, and the way into
 * the account in that role).
 *
 * The list keeps its search and page underneath; Escape or the backdrop closes the panel. The panel is addressed by the
 * employee's id (the page keeps it in the address), so it opens again after a reload and when the administrator returns
 * from the person's account; `initial` is the row that was pressed, shown at once while the rest loads. No transform
 * stays on the panel after it slides in, so windows opened from inside it sit above it.
 */
export default function EmployeePanel({ id, initial, view, onView, onClose }: { id: string; initial?: Employee | null; view: PanelView; onView: (v: PanelView) => void; onClose: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  // The same request the passport view makes, so opening the panel costs one request, not two.
  const profile = useGet<{ data: { employee: Employee } }>(`/admin/employees/${id}`, undefined, { retry: false })
  const loaded = profile.data?.data.employee
  const employee = loaded ?? (initial?.id === id ? initial : null)
  const mayAccess = can('users.manage')
  const shownView: PanelView = view === 'access' && mayAccess ? 'access' : 'passport'
  const [shown, setShown] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const close = useRef(onClose)
  useEffect(() => { close.current = onClose })
  useEffect(() => {
    const raf = requestAnimationFrame(() => setShown(true))
    const before = document.activeElement as HTMLElement | null
    box.current?.focus()
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && document.querySelectorAll('[role="dialog"]').length <= 1) close.current() }
    window.addEventListener('keydown', onKey)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { cancelAnimationFrame(raf); window.removeEventListener('keydown', onKey); document.body.style.overflow = overflow; before?.focus?.() }
  }, [])
  // A view starts at its top, not where the other one was left.
  const body = useRef<HTMLDivElement>(null)
  useEffect(() => { body.current?.scrollTo({ top: 0 }) }, [shownView, id])

  // An employee that cannot be opened (outside the viewer's scope, or gone) has no views and no page to go to.
  const missing = profile.isError
  const tabs: { id: PanelView; label: string; icon: typeof Eye }[] = missing ? [] : [
    { id: 'passport', label: t('admin.employees.passport'), icon: GraduationCap },
    ...(mayAccess ? [{ id: 'access' as const, label: t('userAccess.open'), icon: Eye }] : []),
  ]
  const name = employee?.name ?? ''

  return (
    <div className="fixed inset-0 z-50" onMouseDown={onClose}>
      <div className={clsx('absolute inset-0 bg-navy-950/45 transition-opacity duration-300 motion-reduce:transition-none', shown ? 'opacity-100' : 'opacity-0')} aria-hidden />
      <div ref={box} role="dialog" aria-modal="true" aria-label={`${t(shownView === 'access' ? 'userAccess.title' : 'admin.employees.passport')} — ${name}`} tabIndex={-1} onMouseDown={(e) => e.stopPropagation()}
        className={clsx('absolute inset-y-0 end-0 flex w-full max-w-5xl flex-col bg-ivory shadow-2xl outline-none transition-[translate,opacity] duration-300 ease-out motion-reduce:transition-none', shown ? 'opacity-100' : 'opacity-0 ltr:translate-x-8 rtl:-translate-x-8')}>
        <header className="shrink-0 bg-gradient-to-l from-navy-900 to-navy-800 text-white">
          <div className="flex items-center gap-3 px-5 pt-4">
            <Avatar name={name || (missing ? '?' : '…')} size={44} />
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2"><h2 className="truncate text-lg font-bold">{name || (missing ? t('admin.employees.title') : <span className="inline-block h-5 w-40 animate-pulse rounded bg-white/15 align-middle" aria-hidden />)}</h2>{employee?.status === 'inactive' && <Badge color="gray">{t('status.inactive', { defaultValue: 'inactive' })}</Badge>}</div>
              <p className="truncate text-xs text-white/70">{employee ? [employee.job_title?.name, employee.school?.name, employee.employee_no].filter(Boolean).join(' · ') : ' '}</p>
            </div>
            {!missing && <Link to={`/admin/employees/${id}`} className="hidden items-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold transition-colors hover:bg-white/20 sm:inline-flex"><Maximize2 className="size-3.5" />{t('admin.employees.openFull')}</Link>}
            <button type="button" onClick={onClose} aria-label={t('common.close', { defaultValue: 'Close' }) as string} className="grid size-9 place-items-center rounded-xl bg-white/10 transition-colors hover:bg-white/20"><X className="size-5" /></button>
          </div>
          {tabs.length > 1 ? (
            <div role="tablist" aria-label={name} className="mt-3 flex gap-1 px-4">
              {tabs.map((tab) => (
                <button key={tab.id} type="button" role="tab" id={`employee-tab-${tab.id}`} aria-selected={shownView === tab.id} aria-controls="employee-panel-body" onClick={() => onView(tab.id)}
                  className={clsx('inline-flex items-center gap-2 rounded-t-xl px-4 py-2.5 text-sm font-bold transition-colors', shownView === tab.id ? 'bg-ivory text-navy-900' : 'text-white/70 hover:bg-white/10 hover:text-white')}>
                  <tab.icon className={clsx('size-4', shownView === tab.id ? 'text-gold-700' : 'text-gold-300')} aria-hidden />{tab.label}
                </button>
              ))}
            </div>
          ) : <div className="h-4" />}
        </header>
        <div ref={body} id="employee-panel-body" role={tabs.length > 1 ? 'tabpanel' : undefined} aria-labelledby={tabs.length > 1 ? `employee-tab-${shownView}` : undefined} className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
          {missing ? <ErrorState message={t('admin.employees.missing')} error={profile.error} onRetry={() => void profile.refetch()} />
            : shownView === 'passport' ? <EmployeeProfileBody id={id} embedded />
            : !employee ? <Spinner />
            : employee.user_id ? <UserAccessBody key={employee.user_id} userId={employee.user_id} embedded onLeave={onClose} />
            : <p className="rounded-2xl bg-white p-6 text-sm leading-7 text-slate-600 ring-1 ring-navy-100">{t('admin.employees.noAccount')}</p>}
        </div>
      </div>
    </div>
  )
}
