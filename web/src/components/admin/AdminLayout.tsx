import clsx from 'clsx'
import {
  Award, Bell, BookOpen, Bot, CalendarDays, ChevronDown, ClipboardList, DoorOpen, FileSearch, GraduationCap, Home, LayoutDashboard, LineChart, LogOut, Map, FilePenLine, Megaphone, MessagesSquare, Menu, Notebook, PackageOpen, Radio, School, Settings2, Shield, Target, UserCog, Users, Wallet, X,
} from 'lucide-react'
import { useEffect, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { SETTINGS_SECTIONS } from '@/pages/admin/settings/registry'
import { BrandMark } from '@/components/public/Logo'
import { LanguageToggle } from '@/components/public/PublicLayout'
import { Avatar } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { useRealtimeNotifications } from '@/lib/realtime'
import { useCenterName } from '@/lib/ThemeProvider'

type Item = { to: string; label: string; icon: ComponentType<{ className?: string }>; permission?: string; end?: boolean; badge?: number }

export default function AdminLayout({ portal = false }: { portal?: boolean }) {
  const { t } = useTranslation()
  const centerName = useCenterName()
  const { user, logout, can } = useAuth()
  const { pathname, search } = useLocation()
  const activeTab = new URLSearchParams(search).get('tab')
  const onSettings = pathname.startsWith('/admin/settings')
  const [settingsOpen, setSettingsOpen] = useState(() => {
    try { return localStorage.getItem('tedc.nav.settings') !== 'closed' } catch { return true }
  })
  const toggleSettings = (next: boolean) => {
    setSettingsOpen(next)
    try { localStorage.setItem('tedc.nav.settings', next ? 'open' : 'closed') } catch { /* storage unavailable */ }
  }
  const settingsChildren = SETTINGS_SECTIONS.filter((s) => s.permission.some((p) => can(p)))
  const [open, setOpen] = useState(false)
  const unread = useGet<{ meta?: { total: number } }>('/me/notifications', { unread: 1, per_page: 1 }, { refetchInterval: 60_000 })
  const requests = useGet<{ data: { pending: number } }>(!portal && can('employees.manage') ? '/admin/profile-requests/summary' : null, undefined, { refetchInterval: 60_000 })
  const chats = useGet<{ data: { unread: number; needs_human: number } }>(!portal && can('announcements.manage') ? '/admin/chats/badge' : null, undefined, { refetchInterval: 20_000 })
  useRealtimeNotifications()

  useEffect(() => setOpen(false), [pathname, search])

  const m = (k: string) => t(`admin.menu.${k}`)
  const groups: { title: string; items: Item[] }[] = portal
    ? [{
        title: t('nav.portal'),
        items: [
          { to: '/portal', label: t('nav.home'), icon: Home, end: true },
          { to: '/portal/training', label: t('portal.myTraining'), icon: BookOpen },
          { to: '/portal/passport', label: t('portal.passport'), icon: GraduationCap },
          { to: '/portal/certificates', label: t('portal.certificates'), icon: Wallet },
          { to: '/portal/tasks', label: t('portal.tasks'), icon: ClipboardList },
          { to: '/portal/surveys', label: t('portal.surveys'), icon: Target },
          { to: '/portal/notifications', label: t('portal.notifications'), icon: Bell },
        ],
      }]
    : [
        { title: m('groups.overview'), items: [
          { to: '/admin', label: m('dashboard'), icon: LayoutDashboard, permission: 'dashboard.view', end: true },
          { to: '/admin/analytics', label: m('analytics'), icon: LineChart, permission: 'analytics.executive' },
        ] },
        { title: m('groups.lifecycle'), items: [
          { to: '/admin/needs', label: m('needs'), icon: Notebook, permission: 'needs.view' },
          { to: '/admin/programs', label: m('programs'), icon: BookOpen, permission: 'programs.view' },
          { to: '/admin/kits', label: m('kits'), icon: PackageOpen, permission: 'kits.view' },
          { to: '/admin/calendar', label: m('calendar'), icon: CalendarDays, permission: 'calendar.view' },
          { to: '/admin/registrations', label: m('registrations'), icon: ClipboardList, permission: 'registrations.view' },
          { to: '/admin/certificates', label: m('certificates'), icon: Award, permission: 'certificates.view' },
        ] },
        { title: m('groups.insights'), items: [
          { to: '/admin/live', label: m('live'), icon: Radio, permission: 'analytics.view' },
          { to: '/admin/geo', label: m('geo'), icon: Map, permission: 'analytics.view' },
          { to: '/admin/ai', label: m('ai'), icon: Bot, permission: 'ai.assistant' },
          { to: '/admin/communication', label: m('communication'), icon: Megaphone, permission: 'announcements.manage' },
          { to: '/admin/chats', label: m('chats'), icon: MessagesSquare, permission: 'announcements.manage', badge: (chats.data?.data.unread ?? 0) + (chats.data?.data.needs_human ?? 0) || undefined },
        ] },
        { title: m('groups.organization'), items: [
          { to: '/admin/schools', label: m('schools'), icon: School, permission: 'schools.view' },
          { to: '/admin/employees', label: m('employees'), icon: Users, permission: 'employees.view' },
          { to: '/admin/profile-requests', label: m('profileRequests'), icon: FilePenLine, permission: 'employees.manage', badge: requests.data?.data.pending },
          { to: '/admin/trainers', label: m('trainers'), icon: GraduationCap, permission: 'programs.view' },
          { to: '/admin/rooms', label: m('rooms'), icon: DoorOpen, permission: 'programs.view' },
        ] },
        { title: m('groups.security'), items: [
          { to: '/admin/users', label: m('users'), icon: UserCog, permission: 'users.manage' },
          { to: '/admin/audit', label: m('audit'), icon: FileSearch, permission: 'audit.view' },
        ] },
        { title: m('groups.settings'), items: [
          { to: '/admin/settings', label: m('settings'), icon: Settings2, permission: 'settings.manage' },
        ] },
      ]

  const visible = groups.map((g) => ({ ...g, items: g.items.filter((i) => !i.permission || can(i.permission)) })).filter((g) => g.items.length)
  const unreadCount = unread.data?.meta?.total ?? 0

  const sidebar = (
    <aside className="flex h-full w-72 flex-col bg-navy-950 text-white">
      <div className="relative flex items-center gap-3 px-6 py-6">
        <BrandMark onDark className="h-11 max-w-[120px]" />
        <div className="leading-tight">
          <div className="font-display text-sm font-bold">{centerName}</div>
          <div className="text-[11px] text-gold-300">{portal ? t('nav.portal') : t('nav.dashboard')}</div>
        </div>
      </div>
      <nav className="flex-1 space-y-6 overflow-y-auto px-4 pb-6">
        {visible.map((group) => (
          <div key={group.title}>
            <div className="px-3 pb-2 text-[11px] font-bold uppercase tracking-wider text-white/35">{group.title}</div>
            <div className="space-y-1">
              {group.items.map((item) => item.to === '/admin/settings' && settingsChildren.length > 0 ? (
                <div key={item.to}>
                  <div className={clsx('flex items-stretch rounded-xl transition', onSettings ? 'bg-gradient-to-l from-gold-500/25 to-gold-500/5 ring-1 ring-gold-500/30' : 'hover:bg-white/5')}>
                    <Link to="/admin/settings?tab=home" onClick={() => !settingsOpen && toggleSettings(true)}
                      className={clsx('flex flex-1 items-center gap-3 px-3 py-2.5 text-sm font-medium', onSettings ? 'text-gold-300' : 'text-white/70 hover:text-white')}>
                      <item.icon className="size-5" />
                      {item.label}
                    </Link>
                    <button type="button" aria-expanded={settingsOpen} aria-controls="settings-submenu" aria-label={item.label}
                      onClick={() => toggleSettings(!settingsOpen)}
                      className={clsx('grid w-10 place-items-center rounded-e-xl transition', onSettings ? 'text-gold-300 hover:bg-white/10' : 'text-white/50 hover:bg-white/10 hover:text-white')}>
                      <ChevronDown className={clsx('size-4 transition-transform duration-300', settingsOpen && 'rotate-180')} />
                    </button>
                  </div>
                  <div id="settings-submenu" className={clsx('grid transition-[grid-template-rows,opacity] duration-300 ease-out', settingsOpen ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0')} aria-hidden={!settingsOpen}>
                    <div className="overflow-hidden">
                      <ul className="relative mt-1 space-y-0.5 ms-5 border-s border-white/10 ps-2">
                        {settingsChildren.map((s) => {
                          const active = onSettings && activeTab === s.id
                          return (
                            <li key={s.id}>
                              <Link to={`/admin/settings?tab=${s.id}`} tabIndex={settingsOpen ? 0 : -1}
                                className={clsx('flex items-center gap-2.5 rounded-lg px-3 py-2 text-[13px] font-medium transition', active ? 'bg-white/10 text-gold-300' : 'text-white/60 hover:bg-white/5 hover:text-white')}>
                                <s.icon className={clsx('size-4 shrink-0', active ? 'text-gold-300' : 'text-white/40')} />
                                <span className="truncate">{t(`mgmt.settings.sections.${s.id}.title`)}</span>
                                {active && <span className="ms-auto size-1.5 shrink-0 rounded-full bg-gold-400" />}
                              </Link>
                            </li>
                          )
                        })}
                      </ul>
                    </div>
                  </div>
                </div>
              ) : (
                <NavLink key={item.to} to={item.to} end={item.end}
                  className={({ isActive }) => clsx('flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                    isActive ? 'bg-gradient-to-l from-gold-500/25 to-gold-500/5 text-gold-300 ring-1 ring-gold-500/30' : 'text-white/70 hover:bg-white/5 hover:text-white')}>
                  <item.icon className="size-5" />
                  {item.label}
                  {!!item.badge && <span className="ms-auto rounded-full bg-gold-500 px-2 py-0.5 text-[11px] font-bold text-navy-950">{item.badge}</span>}
                </NavLink>
              ))}
            </div>
          </div>
        ))}
      </nav>
      <div className="border-t border-white/10 p-4">
        {portal ? (can('dashboard.view') || can('programs.view')) && <SideLink to="/admin" icon={Shield}>{t('nav.dashboard')}</SideLink>
          : user?.employee && <SideLink to="/portal" icon={GraduationCap}>{t('nav.portal')}</SideLink>}
        <SideLink to="/" icon={Home}>{t('nav.home')}</SideLink>
        <button onClick={logout} className="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-white/70 hover:bg-white/5 hover:text-white"><LogOut className="size-5" />{t('nav.logout')}</button>
      </div>
    </aside>
  )

  return (
    <div className="flex min-h-screen bg-ivory">
      <div className="fixed inset-y-0 start-0 z-30 hidden lg:block">{sidebar}</div>
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden" onClick={() => setOpen(false)}>
          <div className="absolute inset-0 bg-navy-950/50" />
          <div className="absolute inset-y-0 start-0" onClick={(e) => e.stopPropagation()}>{sidebar}</div>
        </div>
      )}
      <div className="flex min-w-0 flex-1 flex-col lg:ms-72">
        <header className="glass sticky top-0 z-20 border-x-0 border-t-0">
          <div className="flex h-16 items-center justify-between gap-4 px-4 sm:px-8">
            <button className="rounded-lg p-2 text-navy-900 lg:hidden" onClick={() => setOpen(true)} aria-label="menu">{open ? <X /> : <Menu />}</button>
            <div className="hidden text-sm text-slate-500 lg:block">{t('brand.tagline')}</div>
            <div className="flex items-center gap-2">
              <LanguageToggle />
              <Link to="/portal/notifications" className="relative rounded-xl p-2 text-navy-800 hover:bg-navy-100/60" aria-label="notifications">
                <Bell className="size-5" />
                {unreadCount > 0 && <span className="absolute -end-0.5 -top-0.5 grid min-w-5 place-items-center rounded-full bg-gold-500 px-1 text-[10px] font-bold text-navy-950">{unreadCount}</span>}
              </Link>
              <div className="flex items-center gap-3 border-s border-navy-100 ps-3">
                <Avatar name={user?.name ?? ''} size={36} />
                <div className="hidden leading-tight sm:block">
                  <div className="text-sm font-bold text-navy-900">{user?.name}</div>
                  <div className="text-xs text-slate-400">{user?.roles.map((r) => r.name).join('، ')}</div>
                </div>
              </div>
            </div>
          </div>
        </header>
        <main className="flex-1 px-4 py-8 sm:px-8">
          <Outlet />
        </main>
      </div>
    </div>
  )
}

function SideLink({ to, icon: Icon, children }: { to: string; icon: ComponentType<{ className?: string }>; children: React.ReactNode }) {
  return <Link to={to} className="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm text-white/70 hover:bg-white/5 hover:text-white"><Icon className="size-5" />{children}</Link>
}
