import clsx from 'clsx'
import { Eye, KeyRound, ShieldCheck, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Avatar, Badge, ErrorState, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { UserAccessBody } from '../UserAccess'
import { UserRolesBody } from '../UserRolesDialog'
import UserRights from './UserRights'
import { type UserView } from './panelAddress'

type Head = { user: { id: string; name: string; name_ar: string | null; name_en: string; email: string; status: string }; roles: { id: string; name_ar: string; name_en: string }[] }
export type PressedUser = { id: string; name: string; name_ar?: string; email: string; status: string; roles: { slug: string; name_ar: string; name_en: string }[] }

/**
 * A user in a side panel over the Users list. Three views: «الصلاحيات» (the rights of this person in each role: what the
 * role gives, what was added for them, what was taken from them), «الأدوار» (the roles they hold, granted and removed
 * here) and «ما يراه» (the pages and rights they end up with, and the way into the account).
 *
 * The list keeps its search underneath; Escape or the backdrop closes the panel. The panel is addressed by the user's id
 * (the page keeps it in the address), so it opens again after a reload and when the administrator returns from the
 * person's account; `initial` is the row that was pressed, shown at once while the rest loads.
 */
export default function UserPanel({ id, initial, view, onView, onClose, onChanged }: { id: string; initial?: PressedUser | null; view: UserView; onView: (v: UserView) => void; onClose: () => void; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  // The same request «ما يراه» makes: it also tells whether the person exists and may be read.
  const access = useGet<{ data: Head }>(`/admin/users/${id}/access`, undefined, { staleTime: 0, retry: false })
  const loaded = access.data?.data
  const pressed = initial?.id === id ? initial : null
  const name = loaded ? (ar ? loaded.user.name_ar ?? loaded.user.name_en : loaded.user.name_en) : pressed ? (ar ? pressed.name_ar ?? pressed.name : pressed.name) : ''
  const email = loaded?.user.email ?? pressed?.email ?? ''
  const status = loaded?.user.status ?? pressed?.status
  const roles = loaded?.roles ?? pressed?.roles ?? []
  const missing = access.isError && !loaded
  const [shown, setShown] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const close = useRef(onClose)
  useEffect(() => { close.current = onClose })
  useEffect(() => {
    const raf = requestAnimationFrame(() => setShown(true))
    const before = document.activeElement as HTMLElement | null
    box.current?.focus()
    // A window opened from inside the panel (a confirmation) takes Escape first.
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && document.querySelectorAll('[role="dialog"], [role="alertdialog"]').length <= 1) close.current() }
    window.addEventListener('keydown', onKey)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { cancelAnimationFrame(raf); window.removeEventListener('keydown', onKey); document.body.style.overflow = overflow; before?.focus?.() }
  }, [])
  const body = useRef<HTMLDivElement>(null)
  useEffect(() => { body.current?.scrollTo({ top: 0 }) }, [view, id])
  // A change of rights or roles is seen at once in the other views and in the list underneath.
  const changed = () => { void access.refetch(); onChanged() }

  const tabs: { id: UserView; label: string; icon: typeof Eye }[] = missing ? [] : [
    { id: 'rights', label: t('userRights.tab'), icon: KeyRound },
    { id: 'roles', label: t('userRoles.manage'), icon: ShieldCheck },
    { id: 'access', label: t('userAccess.open'), icon: Eye },
  ]

  return (
    <div className="fixed inset-0 z-50" onMouseDown={onClose}>
      <div className={clsx('absolute inset-0 bg-navy-950/45 transition-opacity duration-300 motion-reduce:transition-none', shown ? 'opacity-100' : 'opacity-0')} aria-hidden />
      <div ref={box} role="dialog" aria-modal="true" aria-label={`${t(`userPanel.label.${view}`)} — ${name}`} tabIndex={-1} onMouseDown={(e) => e.stopPropagation()}
        className={clsx('absolute inset-y-0 end-0 flex w-full max-w-5xl flex-col bg-ivory shadow-2xl outline-none transition-[translate,opacity] duration-300 ease-out motion-reduce:transition-none', shown ? 'opacity-100' : 'opacity-0 ltr:translate-x-8 rtl:-translate-x-8')}>
        <header className="shrink-0 bg-gradient-to-l from-navy-900 to-navy-800 text-white">
          <div className="flex items-center gap-3 px-5 pt-4">
            <Avatar name={name || (missing ? '?' : '…')} size={44} />
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="truncate text-lg font-bold">{name || (missing ? t('admin.users.title') : <span className="inline-block h-5 w-40 animate-pulse rounded bg-white/15 align-middle" aria-hidden />)}</h2>
                {status && <StatusBadge status={status === 'active' ? 'approved' : 'blocked'} label={t(`hlp.userStatus.${status}`, { defaultValue: status })} />}
              </div>
              <p className="truncate text-xs text-white/70"><span dir="ltr">{email || ' '}</span></p>
            </div>
            <button type="button" onClick={onClose} aria-label={t('common.close', { defaultValue: 'Close' }) as string} className="grid size-9 place-items-center rounded-xl bg-white/10 transition-colors hover:bg-white/20"><X className="size-5" /></button>
          </div>
          {roles.length > 0 && <div className="flex flex-wrap gap-1.5 px-5 pt-2.5">{roles.map((r, i) => <Badge key={i} color="gold">{ar ? r.name_ar : r.name_en}</Badge>)}</div>}
          {tabs.length > 0 ? (
            <div role="tablist" aria-label={name} className="mt-3 flex gap-1 overflow-x-auto px-4">
              {tabs.map((tab) => (
                <button key={tab.id} type="button" role="tab" id={`user-tab-${tab.id}`} aria-selected={view === tab.id} aria-controls="user-panel-body" onClick={() => onView(tab.id)}
                  className={clsx('inline-flex shrink-0 items-center gap-2 rounded-t-xl px-4 py-2.5 text-sm font-bold transition-colors', view === tab.id ? 'bg-ivory text-navy-900' : 'text-white/70 hover:bg-white/10 hover:text-white')}>
                  <tab.icon className={clsx('size-4', view === tab.id ? 'text-gold-700' : 'text-gold-300')} aria-hidden />{tab.label}
                </button>
              ))}
            </div>
          ) : <div className="h-4" />}
        </header>
        <div ref={body} id="user-panel-body" role={tabs.length ? 'tabpanel' : undefined} aria-labelledby={tabs.length ? `user-tab-${view}` : undefined} className="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
          {missing ? <ErrorState message={t('userPanel.missing')} error={access.error} onRetry={() => void access.refetch()} />
            : !loaded && !pressed ? <Spinner />
            : view === 'rights' ? <UserRights key={id} userId={id} onChanged={changed} />
            : view === 'roles' ? <UserRolesBody key={id} userId={id} onChanged={changed} />
            : <UserAccessBody key={id} userId={id} embedded onLeave={onClose} />}
        </div>
      </div>
    </div>
  )
}
