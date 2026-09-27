import clsx from 'clsx'
import { Loader2, Inbox, AlertTriangle, X } from 'lucide-react'
import { useEffect, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

type Variant = 'primary' | 'gold' | 'outline' | 'ghost' | 'danger' | 'light'

const variants: Record<Variant, string> = {
  primary: 'bg-navy-900 text-white hover:bg-navy-800 shadow-glass',
  gold: 'bg-gradient-to-l from-gold-400 via-gold-500 to-gold-600 text-navy-950 hover:brightness-105 shadow-gold',
  outline: 'border border-navy-100 bg-white text-navy-900 hover:border-gold-400 hover:text-navy-800',
  ghost: 'text-navy-800 hover:bg-navy-100/60',
  danger: 'bg-danger text-white hover:brightness-110',
  light: 'bg-white/10 text-white border border-white/25 hover:bg-white/20 backdrop-blur',
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; size?: 'sm' | 'md' | 'lg'; loading?: boolean; to?: string; icon?: ReactNode }

export function Button({ variant = 'primary', size = 'md', loading, to, icon, className, children, disabled, ...rest }: ButtonProps) {
  const classes = clsx(
    'inline-flex items-center justify-center gap-2 rounded-xl font-semibold transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap',
    { sm: 'px-3 py-1.5 text-xs', md: 'px-4.5 py-2.5 text-sm', lg: 'px-7 py-3.5 text-base' }[size],
    variants[variant],
    className,
  )
  const content = (
    <>
      {loading ? <Loader2 className="size-4 animate-spin" /> : icon}
      {children}
    </>
  )
  if (to) return <Link to={to} className={classes}>{content}</Link>
  return <button className={classes} disabled={disabled || loading} {...rest}>{content}</button>
}

export function Card({ className, children, padded = true }: { className?: string; children: ReactNode; padded?: boolean }) {
  return <div className={clsx('card', padded && 'p-5 sm:p-6', className)}>{children}</div>
}

export function CardTitle({ children, action, subtitle }: { children: ReactNode; action?: ReactNode; subtitle?: ReactNode }) {
  return (
    <div className="mb-5 flex items-start justify-between gap-4">
      <div>
        <h3 className="text-lg font-bold text-navy-900">{children}</h3>
        {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {action}
    </div>
  )
}

const tone: Record<string, string> = {
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15',
  red: 'bg-red-50 text-red-700 ring-red-600/15',
  amber: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  blue: 'bg-sky-50 text-sky-700 ring-sky-600/15',
  navy: 'bg-navy-100 text-navy-800 ring-navy-600/15',
  gold: 'bg-gold-100 text-gold-700 ring-gold-600/20',
  gray: 'bg-slate-100 text-slate-600 ring-slate-500/15',
}

const statusTone: Record<string, keyof typeof tone> = {
  approved: 'green', completed: 'green', issued: 'green', valid: 'green', present: 'green', eligible: 'green', fulfilled: 'green', registration_open: 'green',
  pending: 'amber', submitted: 'amber', under_review: 'amber', waitlisted: 'blue', late: 'amber', changes_requested: 'amber', scheduled: 'blue', sent: 'blue',
  rejected: 'red', cancelled: 'red', blocked: 'red', revoked: 'red', absent: 'red', expired: 'gray', not_eligible: 'red',
  published: 'navy', in_progress: 'gold', planned: 'navy', live: 'gold', draft: 'gray', archived: 'gray',
  critical: 'red', high: 'amber', medium: 'blue', low: 'gray',
}

export function Badge({ children, color = 'navy', className }: { children: ReactNode; color?: keyof typeof tone; className?: string }) {
  return <span className={clsx('inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset', tone[color], className)}>{children}</span>
}

export function StatusBadge({ status, label }: { status: string; label?: string }) {
  const { t } = useTranslation()
  const text = label ?? (t(`status.${status}`, { defaultValue: '' }) || t(`priority.${status}`, { defaultValue: status }))
  return <Badge color={statusTone[status] ?? 'gray'}>{text}</Badge>
}

export function StatCard({ label, value, icon, hint, accent = false }: { label: string; value: ReactNode; icon?: ReactNode; hint?: ReactNode; accent?: boolean }) {
  return (
    <div className={clsx('relative overflow-hidden rounded-2xl p-5', accent ? 'bg-navy-900 text-white shadow-glass' : 'card')}>
      {accent && <div className="pointer-events-none absolute -end-10 -top-10 size-36 rounded-full bg-gold-500/20 blur-2xl" />}
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className={clsx('text-sm', accent ? 'text-white/70' : 'text-slate-500')}>{label}</p>
          <p className={clsx('mt-2 font-display text-3xl font-bold', accent ? 'text-gold-300' : 'text-navy-900')}>{value}</p>
          {hint && <p className={clsx('mt-1 text-xs', accent ? 'text-white/60' : 'text-slate-400')}>{hint}</p>}
        </div>
        {icon && <div className={clsx('grid size-11 place-items-center rounded-xl', accent ? 'bg-white/10 text-gold-300' : 'bg-gold-100 text-gold-700')}>{icon}</div>}
      </div>
    </div>
  )
}

export function PageHeader({ title, subtitle, actions }: { title: ReactNode; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 className="text-2xl font-bold text-navy-900 sm:text-3xl">{title}</h1>
        {subtitle && <p className="mt-1.5 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  )
}

export function Spinner({ className }: { className?: string }) {
  const { t } = useTranslation()
  return (
    <div className={clsx('flex items-center justify-center gap-3 py-16 text-slate-500', className)}>
      <Loader2 className="size-5 animate-spin text-gold-500" />
      <span className="text-sm">{t('common.loading')}</span>
    </div>
  )
}

export function Empty({ text, icon }: { text?: string; icon?: ReactNode }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col items-center justify-center gap-3 py-14 text-center text-slate-400">
      <div className="grid size-14 place-items-center rounded-2xl bg-ivory-dark text-slate-400">{icon ?? <Inbox className="size-6" />}</div>
      <p className="text-sm">{text ?? t('common.noData')}</p>
    </div>
  )
}

export function ErrorState({ message, onRetry }: { message?: string; onRetry?: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col items-center gap-3 py-14 text-center">
      <AlertTriangle className="size-8 text-danger" />
      <p className="text-sm text-slate-600">{message ?? t('common.error')}</p>
      {onRetry && <Button variant="outline" size="sm" onClick={onRetry}>{t('common.retry')}</Button>}
    </div>
  )
}

export function Modal({ open, onClose, title, children, wide }: { open: boolean; onClose: () => void; title: ReactNode; children: ReactNode; wide?: boolean }) {
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open, onClose])

  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-navy-950/50 p-4 backdrop-blur-sm sm:items-center" onMouseDown={onClose}>
      <div className={clsx('card my-8 w-full animate-fade-up p-6', wide ? 'max-w-3xl' : 'max-w-lg')} onMouseDown={(e) => e.stopPropagation()}>
        <div className="mb-5 flex items-center justify-between">
          <h3 className="text-lg font-bold text-navy-900">{title}</h3>
          <button onClick={onClose} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="close"><X className="size-5" /></button>
        </div>
        {children}
      </div>
    </div>
  )
}

export function Field({ label, children, hint, className }: { label: ReactNode; children: ReactNode; hint?: ReactNode; className?: string }) {
  return (
    <label className={clsx('block', className)}>
      <span className="label">{label}</span>
      {children}
      {hint && <span className="mt-1 block text-xs text-slate-400">{hint}</span>}
    </label>
  )
}

export function Tabs<T extends string>({ tabs, value, onChange }: { tabs: { id: T; label: ReactNode }[]; value: T; onChange: (id: T) => void }) {
  return (
    <div className="mb-6 flex gap-1 overflow-x-auto rounded-2xl border border-navy-100 bg-white p-1">
      {tabs.map((tab) => (
        <button
          key={tab.id}
          onClick={() => onChange(tab.id)}
          className={clsx('whitespace-nowrap rounded-xl px-4 py-2 text-sm font-semibold transition', value === tab.id ? 'bg-navy-900 text-white shadow' : 'text-slate-500 hover:text-navy-900')}
        >
          {tab.label}
        </button>
      ))}
    </div>
  )
}

export function Progress({ value, className, tone: t = 'gold' }: { value: number; className?: string; tone?: 'gold' | 'green' | 'red' | 'navy' }) {
  const color = { gold: 'bg-gradient-to-l from-gold-400 to-gold-600', green: 'bg-emerald-500', red: 'bg-red-500', navy: 'bg-navy-700' }[t]
  return (
    <div className={clsx('h-2 w-full overflow-hidden rounded-full bg-navy-100/70', className)}>
      <div className={clsx('h-full rounded-full transition-all duration-700', color)} style={{ width: `${Math.max(0, Math.min(100, value))}%` }} />
    </div>
  )
}

export function Avatar({ name, src, size = 40 }: { name: string; src?: string | null; size?: number }) {
  const initials = name.replace(/^(د\.|أ\.|م\.|Dr\.|Ms\.|Mr\.|Eng\.)\s*/, '').split(' ').slice(0, 2).map((s) => s[0]).join('')
  return src ? (
    <img src={src} alt={name} className="rounded-full object-cover ring-2 ring-gold-300/60" style={{ width: size, height: size }} />
  ) : (
    <div className="grid place-items-center rounded-full bg-gradient-to-br from-navy-800 to-navy-600 font-bold text-gold-300 ring-2 ring-gold-300/40" style={{ width: size, height: size, fontSize: size / 2.8 }}>
      {initials}
    </div>
  )
}

export function Table({ head, children, className }: { head: ReactNode[]; children: ReactNode; className?: string }) {
  return (
    <div className={clsx('overflow-x-auto', className)}>
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-navy-100 text-start text-xs font-semibold uppercase tracking-wide text-slate-500">
            {head.map((h, i) => <th key={i} className="whitespace-nowrap px-3 py-3 text-start">{h}</th>)}
          </tr>
        </thead>
        <tbody className="divide-y divide-navy-100/70">{children}</tbody>
      </table>
    </div>
  )
}

export function Td({ children, className }: { children: ReactNode; className?: string }) {
  return <td className={clsx('px-3 py-3 align-middle', className)}>{children}</td>
}

export function EligibilityPanel({ result }: { result: { eligible: boolean; label: string; summary: string; checks: { key: string; passed: boolean; mandatory: boolean; message: string }[] } }) {
  return (
    <div className={clsx('rounded-2xl border p-4', result.eligible ? 'border-emerald-200 bg-emerald-50/70' : 'border-red-200 bg-red-50/70')}>
      <div className="flex items-center gap-2">
        <span className={clsx('size-3 rounded-full', result.eligible ? 'bg-emerald-500' : 'bg-red-500')} />
        <span className={clsx('font-bold', result.eligible ? 'text-emerald-700' : 'text-red-700')}>{result.label}</span>
      </div>
      <p className="mt-1.5 text-sm text-slate-600">{result.summary}</p>
      {result.checks.length > 0 && (
        <ul className="mt-3 space-y-1.5">
          {result.checks.map((c) => (
            <li key={c.key} className="flex items-start gap-2 text-sm">
              <span className={clsx('mt-1 size-2 shrink-0 rounded-full', c.passed ? 'bg-emerald-500' : c.mandatory ? 'bg-red-500' : 'bg-amber-500')} />
              <span className={c.passed ? 'text-slate-600' : 'text-slate-800'}>{c.message}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
