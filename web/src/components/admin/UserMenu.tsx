import clsx from 'clsx'
import { BadgeCheck, ChevronDown, LockKeyhole, LogOut, UserRound } from 'lucide-react'
import { useEffect, useRef, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Avatar } from '@/components/ui'
import { useAuth } from '@/lib/auth'

/** The account menu in the header: profile, lock the screen, sign out. */
export default function UserMenu({ portal }: { portal: boolean }) {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const [open, setOpen] = useState(false)
  const root = useRef<HTMLDivElement>(null)
  const first = useRef<HTMLAnchorElement>(null)

  useEffect(() => {
    if (!open) return
    const onDown = (e: MouseEvent) => { if (!root.current?.contains(e.target as Node)) setOpen(false) }
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', onDown)
    window.addEventListener('keydown', onKey)
    first.current?.focus()
    return () => { document.removeEventListener('mousedown', onDown); window.removeEventListener('keydown', onKey) }
  }, [open])

  const lock = () => { setOpen(false); window.dispatchEvent(new Event('tedc:lock-now')) }
  const roles = user?.roles.map((r) => r.name).join('، ')
  const row = 'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-navy-900 transition hover:bg-ivory focus:bg-ivory focus:outline-none'

  return (
    <div ref={root} className="relative border-s border-navy-100 ps-3">
      <button type="button" onClick={() => setOpen((o) => !o)} aria-haspopup="menu" aria-expanded={open} aria-label={t('userMenu.account')}
        className="group flex items-center gap-3 rounded-xl py-1 pe-1.5 ps-1 text-start transition hover:bg-navy-100/50">
        <Avatar name={user?.name ?? ''} size={36} />
        <span className="hidden leading-tight sm:block">
          <span className="block text-sm font-bold text-navy-900">{user?.name}</span>
          <span className="block max-w-40 truncate text-xs text-slate-400">{roles}</span>
        </span>
        <ChevronDown className={clsx('hidden size-4 text-slate-400 transition-transform duration-200 sm:block', open && 'rotate-180')} />
      </button>

      {open && (
        <div role="menu" aria-label={t('userMenu.account')} className="absolute end-0 top-[calc(100%+0.5rem)] z-50 w-72 animate-fade-up overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-[0_24px_60px_-18px_rgba(90,14,36,.45)]">
          <div className="relative overflow-hidden bg-gradient-to-l from-navy-950 via-navy-900 to-navy-800 p-4 text-white">
            <div className="pattern-bg absolute inset-0 opacity-20" />
            <div className="relative flex items-center gap-3">
              <Avatar name={user?.name ?? ''} size={46} />
              <div className="min-w-0"><div className="truncate font-bold">{user?.name}</div><div className="truncate text-xs text-white/70" dir="ltr">{user?.email}</div>
                <div className="mt-1 inline-flex items-center gap-1 rounded-full bg-white/15 px-2 py-0.5 text-[11px] font-semibold text-gold-300"><BadgeCheck className="size-3" />{roles}</div></div>
            </div>
          </div>
          <div className="p-2">
            <Item as="link" to={portal ? '/portal/profile' : '/admin/profile'} icon={UserRound} refEl={first} onClick={() => setOpen(false)} className={row}>{t('userMenu.profile')}</Item>
            <button type="button" role="menuitem" onClick={lock} className={row}><LockKeyhole className="size-4 text-gold-600" /><span className="flex-1 text-start">{t('userMenu.lock')}</span><kbd className="rounded bg-navy-100/70 px-1.5 font-mono text-[10px] text-slate-500" dir="ltr">Ctrl ⇧ L</kbd></button>
            <div className="my-1 h-px bg-navy-100" />
            <button type="button" role="menuitem" onClick={logout} className={clsx(row, '!text-danger hover:!bg-red-50')}><LogOut className="size-4" />{t('nav.logout')}</button>
          </div>
        </div>
      )}
    </div>
  )
}

function Item({ to, icon: Icon, children, onClick, className, refEl }: { as: 'link'; to: string; icon: ComponentType<{ className?: string }>; children: React.ReactNode; onClick: () => void; className: string; refEl: React.RefObject<HTMLAnchorElement | null> }) {
  return <Link ref={refEl} to={to} role="menuitem" onClick={onClick} className={className}><Icon className="size-4 text-gold-600" />{children}</Link>
}
