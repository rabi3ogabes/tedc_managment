import clsx from 'clsx'
import { Maximize2, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Avatar, Badge } from '@/components/ui'
import type { Employee } from '@/lib/types'
import { EmployeeProfileBody } from '../EmployeeProfile'

/**
 * The training passport in a side panel over the employees list: the list keeps its search and page underneath, Escape or the
 * backdrop closes it, and «فتح كصفحة كاملة» moves to the full page. No transform stays on the panel after it slides in, so windows
 * opened from inside it sit above it.
 */
export default function PassportPanel({ employee, onClose }: { employee: Employee; onClose: () => void }) {
  const { t } = useTranslation()
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

  return (
    <div className="fixed inset-0 z-50" onMouseDown={onClose}>
      <div className={clsx('absolute inset-0 bg-navy-950/45 transition-opacity duration-300', shown ? 'opacity-100' : 'opacity-0')} aria-hidden />
      <div ref={box} role="dialog" aria-modal="true" aria-label={`${t('admin.employees.passport')} — ${employee.name ?? ''}`} tabIndex={-1} onMouseDown={(e) => e.stopPropagation()}
        className={clsx('absolute inset-y-0 end-0 flex w-full max-w-5xl flex-col bg-ivory shadow-2xl outline-none transition-[translate,opacity] duration-300 ease-out', shown ? 'opacity-100' : 'opacity-0 ltr:translate-x-8 rtl:-translate-x-8')}>
        <header className="flex shrink-0 items-center gap-3 bg-gradient-to-l from-navy-900 to-navy-800 px-5 py-4 text-white">
          <Avatar name={employee.name ?? ''} size={44} />
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2"><h2 className="truncate text-lg font-bold">{employee.name}</h2>{employee.status === 'inactive' && <Badge color="gray">{t('status.inactive', { defaultValue: 'inactive' })}</Badge>}</div>
            <p className="truncate text-xs text-white/70">{[employee.job_title?.name, employee.school?.name, employee.employee_no].filter(Boolean).join(' · ')}</p>
          </div>
          <Link to={`/admin/employees/${employee.id}`} className="hidden items-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold hover:bg-white/20 sm:inline-flex"><Maximize2 className="size-3.5" />{t('admin.employees.openFull')}</Link>
          <button type="button" onClick={onClose} aria-label={t('common.close', { defaultValue: 'Close' }) as string} className="grid size-9 place-items-center rounded-xl bg-white/10 hover:bg-white/20"><X className="size-5" /></button>
        </header>
        <div className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
          <EmployeeProfileBody id={employee.id} embedded />
        </div>
      </div>
    </div>
  )
}
