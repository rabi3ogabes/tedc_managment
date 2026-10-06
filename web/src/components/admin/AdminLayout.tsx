import clsx from 'clsx'
import GlobalSearch, { useSearchShortcut, type FunctionTarget } from '@/components/search/GlobalSearch'
import FeatureBanner from './FeatureBanner'
import ImpersonationBanner from './ImpersonationBanner'
import {
  Award, Bug, KanbanSquare, CalendarRange, School2, Monitor, Palette, Video, Bell, BookOpen, Bot, CalendarDays, ChevronDown, ClipboardList, DoorOpen, FileSearch, GraduationCap, Home, LayoutDashboard, LineChart, LogOut, Map, FilePenLine, LayoutTemplate, Megaphone, MessagesSquare, PanelLeftClose, PanelLeftOpen, Pin, PinOff, Menu, Notebook, PackageOpen, Radio, School, Settings2, Shield, Target, UserCog, Users, Wallet, X, ShieldAlert, Search } from 'lucide-react'
import { useEffect, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink, Outlet, useLocation } from 'react-router-dom'
import AppErrorBoundary from '@/components/AppErrorBoundary'
import ReportProblem from './ReportProblem'
import UserMenu from './UserMenu'
import { SETTINGS_SECTIONS } from '@/pages/admin/settings/registry'
import { BrandMark, LogoMark } from '@/components/public/Logo'
import { LanguageToggle } from '@/components/public/PublicLayout'
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
  // Menu size: pinned = full size; unpinned = small rail that (optionally) grows while the pointer is on it.
  const flag = (key: string, fallback: boolean) => { try { const v = localStorage.getItem(key); return v === null ? fallback : v === '1' } catch { return fallback } }
  const [pinned, setPinned] = useState(() => flag('tedc.nav.pinned', true))
  const [hoverOpen, setHoverOpen] = useState(() => flag('tedc.nav.hover', true))
  const [hovering, setHovering] = useState(false)
  const save = (key: string, v: boolean) => { try { localStorage.setItem(key, v ? '1' : '0') } catch { /* storage unavailable */ } }
  const togglePin = () => setPinned((v) => { save('tedc.nav.pinned', !v); if (v) setHovering(false); return !v })
  const toggleHover = () => setHoverOpen((v) => { save('tedc.nav.hover', !v); return !v })
  const expanded = pinned || (hoverOpen && hovering)
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if ((e.ctrlKey || e.metaKey) && !e.altKey && e.key.toLowerCase() === 'b') { e.preventDefault(); togglePin() } }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])
  const unread = useGet<{ meta?: { total: number } }>('/me/notifications', { unread: 1, per_page: 1 }, { refetchInterval: 60_000 })
  const requests = useGet<{ data: { pending: number } }>(!portal && can('employees.manage') ? '/admin/profile-requests/summary' : null, undefined, { refetchInterval: 60_000 })
  const errorBadge = useGet<{ data: { open: number; critical: number } }>(!portal && can('logs.manage') ? '/admin/error-logs/badge' : null, undefined, { refetchInterval: 60_000 })
  const chats = useGet<{ data: { unread: number; needs_human: number } }>(!portal && can('announcements.manage') ? '/admin/chats/badge' : null, undefined, { refetchInterval: 20_000 })
  useRealtimeNotifications()
  const [pulse, setPulse] = useState(false)
  useEffect(() => {
    const on = () => { setPulse(true); window.setTimeout(() => setPulse(false), 2500) }
    window.addEventListener('tedc:notification', on)
    return () => window.removeEventListener('tedc:notification', on)
  }, [])

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
          { to: '/portal/needs', label: t('needsHub.my.title'), icon: GraduationCap },
          { to: '/portal/assessments', label: t('assess.runner.title'), icon: ClipboardList },
          { to: '/portal/evaluations', label: t('evalc.my.title'), icon: ClipboardList },
          { to: '/portal/growth', label: t('career.myNav'), icon: GraduationCap },
          { to: '/portal/library', label: t('content.navLibrary'), icon: BookOpen },
          { to: '/portal/reports', label: t('rep.navReports'), icon: FilePenLine },
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
          { to: '/admin/programs/remote', label: t('studio.nav.remote'), icon: Video, permission: 'programs.manage' },
          { to: '/admin/kits', label: m('kits'), icon: PackageOpen, permission: 'kits.view' },
          { to: '/admin/my-assignments', label: t('assignments.title'), icon: GraduationCap, permission: 'trainers.respond' },
          { to: '/admin/evaluation-forms', label: t('evalc.nav'), icon: ClipboardList, permission: 'evaluations.manage' },
          { to: '/admin/evaluation-settings', label: t('evalc.settingsNav'), icon: Settings2, permission: 'evaluations.manage' },
          { to: '/admin/career-paths', label: t('career.nav'), icon: Map, permission: 'paths.manage|licences.manage' },
          { to: '/admin/pd-centre', label: t('career.pdNav'), icon: GraduationCap, permission: 'pd.recognise|pd.approve|pd.types.manage|knowledge_transfer.review' },
          { to: '/admin/standards', label: t('content.navStandards'), icon: Settings2, permission: 'standards.manage|lti.manage|providers.manage|library.manage' },
          { to: '/admin/library-admin', label: t('content.navLibraryAdmin'), icon: BookOpen, permission: 'library.manage' },
          { to: '/admin/sharing', label: t('content.navSharing'), icon: Users, permission: 'sharing.manage|job_groups.manage' },
          { to: '/admin/passing-policy', label: t('passing.global'), icon: Award, permission: 'passing.manage' },
          { to: '/admin/question-banks', label: t('assess.studio.title'), icon: ClipboardList, permission: 'banks.manage|assessments.manage' },
          { to: '/admin/needs-hub', label: t('needsHub.nav'), icon: Target, permission: 'needs.cycles|needs.propose|needs.request|needs.approve_individual|performance.import|gaps.view|competencies.manage' },
          { to: '/admin/plans', label: t('plans.nav'), icon: CalendarRange, permission: 'plans.view' },
          { to: '/admin/groups', label: t('groups.board.title'), icon: KanbanSquare, permission: 'programs.view' },
          { to: '/admin/internal-workshops', label: t('workshops.nav'), icon: School2, permission: 'workshops.internal|workshops.approve' },
          { to: '/admin/calendar', label: m('calendar'), icon: CalendarDays, permission: 'calendar.view' },
          { to: '/admin/approvals', label: t('admission.nav'), icon: ClipboardList, permission: 'registrations.approve_manager|registrations.manage|registrations.approve_center|withdrawals.decide|external_requests.review' },
          { to: '/admin/admission-rules', label: t('admission.rulesNav'), icon: Settings2, permission: 'priority.manage|withdrawals.policy|external_forms.manage' },
          { to: '/admin/registrations', label: m('registrations'), icon: ClipboardList, permission: 'registrations.view' },
          { to: '/admin/certificates', label: m('certificates'), icon: Award, permission: 'certificates.view' },
          { to: '/admin/room-screens', label: t('studio.wall.nav'), icon: Monitor, permission: 'programs.view' },
          { to: '/admin/certificate-templates', label: t('studio.nav.templates'), icon: Palette, permission: 'certificates.view' },
        ] },
        { title: m('groups.insights'), items: [
          { to: '/admin/live', label: m('live'), icon: Radio, permission: 'analytics.view' },
          { to: '/admin/absence', label: t('ops.absence.title'), icon: ShieldAlert, permission: 'attendance.manage|attendance.devices' },
          { to: '/admin/attendance-attempts', label: t('attempts.nav'), icon: ShieldAlert, permission: 'attendance.manage' },
          { to: '/admin/geo', label: m('geo'), icon: Map, permission: 'analytics.view' },
          { to: '/admin/ai', label: m('ai'), icon: Bot, permission: 'ai.assistant' },
          { to: '/admin/communication', label: m('communication'), icon: Megaphone, permission: 'announcements.manage|announcements.publish|notifications.schedule|notifications.reports|notifications.rules' },
          { to: '/admin/reports', label: t('rep.navReports'), icon: FilePenLine },
          { to: '/admin/integrations', label: t('idn.navIntegrations'), icon: Radio, permission: 'integrations.manage|integrations.logs|sso.manage|webhooks.manage' },
          { to: '/admin/migration', label: t('idn.navMigration'), icon: PackageOpen, permission: 'migration.run' },
          { to: '/admin/security-policy', label: t('idn.navSecurityAdmin'), icon: Shield, permission: 'security.policy|sessions.manage' },
          { to: '/admin/kpi', label: t('rep.navKpi'), icon: LineChart, permission: 'kpi.view' },
          { to: '/admin/dashboard-presets', label: t('rep.dash.presets'), icon: LayoutDashboard, permission: 'dashboards.manage' },
          { to: '/admin/appearance/home', label: t('comm.navHome'), icon: LayoutTemplate, permission: 'cms.manage' },
          { to: '/admin/error-log', label: t('logs.nav'), icon: Bug, permission: 'logs.manage', badge: (errorBadge.data?.data.critical || errorBadge.data?.data.open) || undefined },
          { to: '/admin/chats', label: m('chats'), icon: MessagesSquare, permission: 'announcements.manage', badge: (chats.data?.data.unread ?? 0) + (chats.data?.data.needs_human ?? 0) || undefined },
        ] },
        { title: m('groups.organization'), items: [
          { to: '/admin/schools', label: m('schools'), icon: School, permission: 'schools.view' },
          { to: '/admin/employees', label: m('employees'), icon: Users, permission: 'employees.view' },
          { to: '/admin/profile-requests', label: m('profileRequests'), icon: FilePenLine, permission: 'employees.manage', badge: requests.data?.data.pending },
          { to: '/admin/trainers', label: m('trainers'), icon: GraduationCap, permission: 'programs.view' },
          { to: '/admin/rooms', label: m('rooms'), icon: DoorOpen, permission: 'programs.view' },
          { to: '/admin/room-ops', label: t('ops.rooms.title'), icon: CalendarRange, permission: 'programs.view|rooms.book' },
          { to: '/admin/logistics', label: t('ops.logistics.title'), icon: PackageOpen, permission: 'programs.view|logistics.manage' },
        ] },
        { title: m('groups.security'), items: [
          { to: '/admin/users', label: m('users'), icon: UserCog, permission: 'users.manage' },
          { to: '/admin/audit', label: m('audit'), icon: FileSearch, permission: 'audit.view' },
        ] },
        { title: m('groups.settings'), items: [
          { to: '/admin/settings', label: m('settings'), icon: Settings2, permission: 'settings.manage' },
        ] },
      ]

  const visible = groups.map((g) => ({ ...g, items: g.items.filter((i) => !i.permission || i.permission.split('|').some((x) => can(x))) })).filter((g) => g.items.length)
  const unreadCount = unread.data?.meta?.total ?? 0
  const [searchOpen, setSearchOpen] = useState(false)
  useSearchShortcut(() => setSearchOpen((v) => !v))
  // The "functions" the search can open: the menu entries and the settings sections this role may use.
  const functions: FunctionTarget[] = [
    ...visible.flatMap((g) => g.items.map((i) => ({ label: i.label, to: i.to, icon: i.icon, keywords: [g.title] }))),
    ...(portal ? [] : SETTINGS_SECTIONS.filter((s) => s.permission.some((p) => can(p))).map((s) => ({ label: t(`mgmt.settings.sections.${s.id}.title`), to: `/admin/settings?tab=${s.id}`, icon: s.icon, keywords: s.keywords }))),
  ]

  const renderSidebar = (compact: boolean, drawer: boolean) => (
    <aside className={clsx('flex h-full flex-col bg-navy-950 text-white print:hidden transition-[width,box-shadow] duration-300 ease-out', compact ? 'w-[4.75rem]' : 'w-72', !drawer && !pinned && expanded && 'shadow-[12px_0_40px_-8px_rgba(0,0,0,.45)]')}>
      <div className={clsx('relative flex items-center gap-3 py-6', compact ? 'justify-center px-2' : 'px-6')}>
        {compact ? <LogoMark className="size-10" /> : (
          <>
            <BrandMark onDark className="h-11 max-w-[120px]" />
            <div className="min-w-0 flex-1 leading-tight">
              <div className="truncate font-display text-sm font-bold">{centerName}</div>
              <div className="text-[11px] text-gold-300">{portal ? t('nav.portal') : t('nav.dashboard')}</div>
            </div>
            {!drawer && (
              <button type="button" onClick={togglePin} aria-pressed={pinned} title={`${pinned ? t('nav.unpin') : t('nav.pin')} (Ctrl+B)`} aria-label={pinned ? t('nav.unpin') : t('nav.pin')}
                className={clsx('grid size-9 shrink-0 place-items-center rounded-xl transition', pinned ? 'bg-gold-500/20 text-gold-300 hover:bg-gold-500/30' : 'text-white/60 hover:bg-white/10 hover:text-white')}>
                {pinned ? <Pin className="size-4 -rotate-45" /> : <PinOff className="size-4" />}
              </button>
            )}
          </>
        )}
      </div>
      <nav className={clsx('flex-1 overflow-y-auto pb-6', compact ? 'space-y-4 px-3 [scrollbar-width:none]' : 'space-y-6 px-4')}>
        {visible.map((group) => (
          <div key={group.title}>
            {compact ? <div className="mx-2 mb-2 h-px bg-white/10" aria-hidden /> : <div className="px-3 pb-2 text-[11px] font-bold uppercase tracking-wider text-white/35">{group.title}</div>}
            <div className="space-y-1">
              {group.items.map((item) => item.to === '/admin/settings' && settingsChildren.length > 0 && !compact ? (
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
                <NavLink key={item.to} to={item.to} end={item.end} title={compact ? item.label : undefined} aria-label={compact ? item.label : undefined}
                  className={({ isActive }) => clsx('relative flex items-center gap-3 rounded-xl py-2.5 text-sm font-medium transition', compact ? 'justify-center px-0' : 'px-3',
                    isActive ? 'bg-gradient-to-l from-gold-500/25 to-gold-500/5 text-gold-300 ring-1 ring-gold-500/30' : 'text-white/70 hover:bg-white/5 hover:text-white')}>
                  <item.icon className="size-5 shrink-0" />
                  {!compact && <span className="truncate">{item.label}</span>}
                  {!!item.badge && (compact
                    ? <span className="absolute end-2 top-1.5 size-2.5 rounded-full bg-gold-500 ring-2 ring-navy-950" aria-hidden />
                    : <span className="ms-auto rounded-full bg-gold-500 px-2 py-0.5 text-[11px] font-bold text-navy-950">{item.badge}</span>)}
                </NavLink>
              ))}
            </div>
          </div>
        ))}
      </nav>
      <div className={clsx('border-t border-white/10', compact ? 'p-3' : 'p-4')}>
        {portal ? (can('dashboard.view') || can('programs.view')) && <SideLink to="/admin" icon={Shield} compact={compact}>{t('nav.dashboard')}</SideLink>
          : user?.employee && <SideLink to="/portal" icon={GraduationCap} compact={compact}>{t('nav.portal')}</SideLink>}
        <SideLink to="/" icon={Home} compact={compact}>{t('nav.home')}</SideLink>
        <button onClick={logout} title={compact ? t('nav.logout') : undefined} aria-label={t('nav.logout')} className={clsx('flex w-full items-center gap-3 rounded-xl py-2.5 text-sm text-white/70 hover:bg-white/5 hover:text-white', compact ? 'justify-center px-0' : 'px-3')}><LogOut className="size-5 shrink-0" />{!compact && t('nav.logout')}</button>
        {!compact && !drawer && !pinned && (
          <label className="mt-3 flex cursor-pointer items-center justify-between gap-3 rounded-xl bg-white/5 px-3 py-2 text-xs text-white/70">
            <span>{t('nav.autoExpand')}</span>
            <button type="button" role="switch" aria-checked={hoverOpen} onClick={toggleHover} className={clsx('relative inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition-colors', hoverOpen ? 'justify-end bg-gold-500' : 'justify-start bg-white/20')}><span className="inline-block size-4 rounded-full bg-white shadow" /></button>
          </label>
        )}
      </div>
    </aside>
  )

  return (
    <div className="flex min-h-screen bg-ivory">
      <ImpersonationBanner />
      <FeatureBanner />
      <GlobalSearch functions={functions} open={searchOpen} onClose={() => setSearchOpen(false)} />
      <div className="fixed inset-y-0 start-0 z-30 hidden lg:block" onMouseEnter={() => setHovering(true)} onMouseLeave={() => setHovering(false)} onFocus={() => setHovering(true)} onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setHovering(false) }}>{renderSidebar(!expanded, false)}</div>
      {open && (
        <div className="fixed inset-0 z-40 lg:hidden" onClick={() => setOpen(false)}>
          <div className="absolute inset-0 bg-navy-950/50" />
          <div className="absolute inset-y-0 start-0" onClick={(e) => e.stopPropagation()}>{renderSidebar(false, true)}</div>
        </div>
      )}
      <div className={clsx('flex min-w-0 flex-1 flex-col transition-[margin] duration-300 ease-out', pinned ? 'lg:ms-72' : 'lg:ms-[4.75rem]')}>
        <header className="glass sticky top-0 z-20 border-x-0 border-t-0 print:hidden">
          <div className="flex h-16 items-center justify-between gap-4 px-4 sm:px-8">
            <button className="rounded-lg p-2 text-navy-900 lg:hidden" onClick={() => setOpen(true)} aria-label="menu">{open ? <X /> : <Menu />}</button>
            <button type="button" onClick={togglePin} aria-pressed={pinned} title={`${pinned ? t('nav.collapse') : t('nav.expand')} (Ctrl+B)`} aria-label={pinned ? t('nav.collapse') : t('nav.expand')} className="hidden rounded-xl p-2 text-navy-800 transition hover:bg-navy-100/60 lg:block">
              {pinned ? <PanelLeftClose className="size-5 rtl:-scale-x-100" /> : <PanelLeftOpen className="size-5 rtl:-scale-x-100" />}
            </button>
            <div className="hidden text-sm text-slate-500 lg:block">{t('brand.tagline')}</div>
            <div className="flex items-center gap-2">
              <button type="button" onClick={() => setSearchOpen(true)} aria-label={t('search.title')} className="inline-flex items-center gap-2 rounded-xl p-2 text-navy-800 hover:bg-navy-100/60 sm:border sm:border-navy-100 sm:bg-white sm:px-3 sm:py-1.5"><Search className="size-4" /><span className="hidden text-xs text-slate-400 sm:inline">{t('search.button')}</span><kbd className="hidden rounded bg-navy-100/70 px-1.5 font-mono text-[10px] text-slate-500 sm:inline" dir="ltr">Ctrl K</kbd></button>
              <LanguageToggle />
              <Link to="/portal/notifications" className="relative rounded-xl p-2 text-navy-800 hover:bg-navy-100/60" aria-label="notifications">
                <Bell className="size-5" />
                {unreadCount > 0 && <span className={clsx('absolute -end-0.5 -top-0.5 grid min-w-5 place-items-center rounded-full bg-gold-500 px-1 text-[10px] font-bold text-navy-950', pulse && 'animate-bounce')}>{unreadCount}</span>}
              </Link>
              <UserMenu portal={portal} />
            </div>
          </div>
        </header>
        <main className="flex-1 px-4 py-8 sm:px-8">
          <AppErrorBoundary resetKey={pathname}><Outlet /></AppErrorBoundary>
          <ReportProblem />
        </main>
      </div>
    </div>
  )
}

function SideLink({ to, icon: Icon, children, compact }: { to: string; icon: ComponentType<{ className?: string }>; children: React.ReactNode; compact?: boolean }) {
  return <Link to={to} title={compact ? String(children) : undefined} aria-label={compact ? String(children) : undefined} className={clsx('flex items-center gap-3 rounded-xl py-2.5 text-sm text-white/70 hover:bg-white/5 hover:text-white', compact ? 'justify-center px-0' : 'px-3')}><Icon className="size-5 shrink-0" />{!compact && children}</Link>
}
