import clsx from 'clsx'
import {
  Award, Bell, Bot, BookOpen, ClipboardList, FileSearch, GraduationCap, Home, LayoutDashboard, LineChart, LogOut, Map, Megaphone,
  BellRing, Menu, Notebook, Palette, School, Shield, Target, UserCog, Users, Wallet, X,
} from 'lucide-react'
import { useEffect, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import { BrandMark } from '@/components/public/Logo'
import { LanguageToggle } from '@/components/public/PublicLayout'
import { Avatar } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { useRealtimeNotifications } from '@/lib/realtime'

type Item = { to: string; label: string; icon: ComponentType<{ className?: string }>; permission?: string; end?: boolean }

export default function AdminLayout({ portal = false }: { portal?: boolean }) {
  const { t } = useTranslation()
  const { user, logout, can } = useAuth()
  const { pathname } = useLocation()
  const [open, setOpen] = useState(false)
  const unread = useGet<{ meta?: { total: number } }>('/me/notifications', { unread: 1, per_page: 1 }, { refetchInterval: 60_000 })
  useRealtimeNotifications()

  useEffect(() => setOpen(false), [pathname])

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
          { to: '/admin/registrations', label: m('registrations'), icon: ClipboardList, permission: 'registrations.view' },
          { to: '/admin/certificates', label: m('certificates'), icon: Award, permission: 'certificates.view' },
        ] },
        { title: m('groups.insights'), items: [
          { to: '/admin/geo', label: m('geo'), icon: Map, permission: 'analytics.view' },
          { to: '/admin/ai', label: m('ai'), icon: Bot, permission: 'ai.assistant' },
          { to: '/admin/communication', label: m('communication'), icon: Megaphone, permission: 'announcements.manage' },
        ] },
        { title: m('groups.organization'), items: [
          { to: '/admin/schools', label: m('schools'), icon: School, permission: 'schools.view' },
          { to: '/admin/employees', label: m('employees'), icon: Users, permission: 'employees.view' },
        ] },
        { title: m('groups.security'), items: [
          { to: '/admin/users', label: m('users'), icon: UserCog, permission: 'users.manage' },
          { to: '/admin/audit', label: m('audit'), icon: FileSearch, permission: 'audit.view' },
        ] },
        { title: m('groups.settings'), items: [
          { to: '/admin/appearance', label: m('appearance'), icon: Palette, permission: 'settings.manage' },
          { to: '/admin/settings/notifications', label: m('pushSettings'), icon: BellRing, permission: 'settings.manage' },
        ] },
      ]

  const visible = groups.map((g) => ({ ...g, items: g.items.filter((i) => !i.permission || can(i.permission)) })).filter((g) => g.items.length)
  const unreadCount = unread.data?.meta?.total ?? 0

  const sidebar = (
    <aside className="flex h-full w-72 flex-col bg-navy-950 text-white">
      <div className="relative flex items-center gap-3 px-6 py-6">
        <BrandMark onDark className="h-11 max-w-[120px]" />
        <div className="leading-tight">
          <div className="font-display text-sm font-bold">{t('brand.name')}</div>
          <div className="text-[11px] text-gold-300">{portal ? t('nav.portal') : t('nav.dashboard')}</div>
        </div>
      </div>
      <nav className="flex-1 space-y-6 overflow-y-auto px-4 pb-6">
        {visible.map((group) => (
          <div key={group.title}>
            <div className="px-3 pb-2 text-[11px] font-bold uppercase tracking-wider text-white/35">{group.title}</div>
            <div className="space-y-1">
              {group.items.map((item) => (
                <NavLink key={item.to} to={item.to} end={item.end}
                  className={({ isActive }) => clsx('flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition',
                    isActive ? 'bg-gradient-to-l from-gold-500/25 to-gold-500/5 text-gold-300 ring-1 ring-gold-500/30' : 'text-white/70 hover:bg-white/5 hover:text-white')}>
                  <item.icon className="size-5" />
                  {item.label}
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
