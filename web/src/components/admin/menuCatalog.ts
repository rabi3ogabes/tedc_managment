import {
  Award, Bell, Boxes, Bug, Home, Layers, LifeBuoy, KanbanSquare, CalendarRange, School2, Monitor, Palette, Bot, CalendarDays, ClipboardCheck, ClipboardList, DoorOpen, FileSearch, GraduationCap, LayoutDashboard, LineChart, Map, FilePenLine, LayoutTemplate, Megaphone, MessagesSquare, PackageOpen, Radio, School, Settings2, Shield, Target, UserCog, Users, Wallet, ShieldAlert, BookOpen, Presentation, ShieldCheck,
} from 'lucide-react'
import type { ComponentType } from 'react'
import type { TFunction } from 'i18next'
import { holds, landsOnPortal, onlyDoors, visibleGroups, type Gated } from '@/lib/menuRules'
import { showsOwnTraining } from '@/lib/portalHome'

export { landsOnPortal, pageAt, pageRightOf, visibleGroups } from '@/lib/menuRules'

export type Item = { to: string; label: string; icon: ComponentType<{ className?: string }>; permission?: string; end?: boolean; badge?: number; feature?: string
  /** Hidden from whoever is shown the button with this gate (menuRules.allowed). */
  without?: Gated
  /** A page that only shows things: it has nothing to add, edit or delete (lib/pageAccess). */
  plain?: boolean
  /** A page of the person's own training (portal): listed in the trainee role, see showsOwnTraining. */
  ownTraining?: boolean
}
export type Group = { id: string; title: string; items: Item[] }
/** A section as the menu draws it (the portal's own section has no id). */
export type MenuGroup = { id?: string; title: string; items: Item[] }
export type MenuBadges = { errors?: number; chats?: number; requests?: number }

/** Every button of the dashboard menu with its default section, before the administrator's arrangement is laid over it. */
export function adminCatalog(t: TFunction, badges: MenuBadges): Group[] {
  const m = (k: string) => t(`admin.menu.${k}`)
  return [
    { id: 'overview', title: m('groups.overview'), items: [
      { to: '/admin', label: m('dashboard'), icon: LayoutDashboard, end: true },
      { to: '/admin/live-training', label: t('liveT.nav'), icon: Boxes, permission: 'live_training.view' },
      { to: '/admin/analytics', label: m('analytics'), icon: LineChart, permission: 'analytics.executive' },
    ] },
    { id: 'lifecycle', title: m('groups.lifecycle'), items: [
      { to: '/admin/programs', label: m('programs'), icon: BookOpen, permission: 'programs.view' },
      { to: '/admin/saber', label: t('saber.nav'), icon: Layers, permission: 'saber.view' },
      // «البرامج عن بُعد» is hidden for now (its page stays at /admin/programs/remote); it will be merged into another button.
      { to: '/admin/kits', label: m('kits'), icon: PackageOpen, permission: 'kits.view' },
      // For whoever delivers training or handles attendance; the page itself lists only the person's own groups, and what
      // is done on it is done by assignment (the group's trainer or supervisor), not by a right: no add / edit / delete to set.
      { to: '/admin/my-programs', label: t('duties.nav'), icon: ClipboardCheck, plain: true, permission: 'trainers.respond|attendance.manage' },
      { to: '/admin/teaching', label: t('teach.nav'), icon: Presentation, permission: 'trainers.respond' },
      { to: '/admin/my-assignments', label: t('assignments.title'), icon: GraduationCap, permission: 'trainers.respond' },
      { to: '/admin/kit-protection', label: t('teach.protection.nav'), icon: ShieldCheck, permission: 'kits.manage' },
      { to: '/admin/evaluation-forms', label: t('evalc.nav'), icon: ClipboardList, permission: 'evaluations.manage' },
      { to: '/admin/evaluation-settings', label: t('evalc.settingsNav'), icon: Settings2, permission: 'evaluations.manage' },
      { to: '/admin/career-paths', label: t('career.nav'), icon: Map, permission: 'paths.manage|licences.manage' },
      { to: '/admin/pd-centre', label: t('career.pdNav'), icon: GraduationCap, permission: 'pd.recognise|pd.approve|pd.types.manage|knowledge_transfer.review' },
      { to: '/admin/standards', label: t('content.navStandards'), icon: Settings2, permission: 'standards.manage|lti.manage|providers.manage|library.manage' },
      { to: '/admin/library-admin', label: t('content.navLibraryAdmin'), icon: BookOpen, permission: 'library.manage' },
      { to: '/admin/sharing', label: t('content.navSharing'), icon: Users, permission: 'sharing.manage|job_groups.manage' },
      { to: '/admin/passing-policy', label: t('passing.global'), icon: Award, permission: 'passing.manage' },
      { to: '/admin/question-banks', label: t('assess.studio.title'), icon: ClipboardList, permission: 'banks.manage|assessments.manage' },
      { to: '/admin/surveys', label: t('ss.nav'), icon: ClipboardList, permission: 'surveys.view', feature: 'survey_studio_v2' },
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
    { id: 'insights', title: m('groups.insights'), items: [
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
      { to: '/admin/privacy', label: t('prv.adminNav'), icon: Shield, permission: 'privacy.manage' },
      { to: '/admin/help', label: t('hlp.nav'), icon: LifeBuoy },
      { to: '/admin/help-articles', label: t('hlp.navAdmin'), icon: BookOpen, permission: 'help.manage' },
      { to: '/admin/satisfaction', label: t('hlp.navSat'), icon: LineChart, permission: 'evaluations.manage|evaluation_reports.prepare' },
      { to: '/admin/tickets', label: t('hlp.navTickets'), icon: Bug, permission: 'integrations.manage|integrations.logs' },
      { to: '/admin/ekits', label: t('hlp.navKits'), icon: BookOpen, permission: 'packages.manage' },
      { to: '/admin/finance', label: t('pay.nav.finance'), icon: Wallet, permission: 'orders.view|pricing.manage|finance.reports|entity_accounts.manage', feature: 'payments' },
      { to: '/admin/forecasts', label: t('aix.nav.forecasts'), icon: LineChart, permission: 'ai.forecasts.view', feature: 'ai' },
      { to: '/admin/dashboard-presets', label: t('rep.dash.presets'), icon: LayoutDashboard, permission: 'dashboards.manage' },
      { to: '/admin/appearance/home', label: t('comm.navHome'), icon: LayoutTemplate, permission: 'cms.manage' },
      { to: '/admin/error-log', label: t('logs.nav'), icon: Bug, permission: 'logs.manage', badge: badges.errors },
      // «مجتمعات التعلم المهنية» is the management hub: its data needs `plc.view` on the server, so the button asks for it too
      // (it used to show for everybody and open on «هذه الصفحة خارج صلاحياتك»). The discussions, open to all, were reachable
      // only as the hub's last tab: whoever is not shown the hub gets them as a button of their own.
      { to: '/admin/communities', label: t('plc.nav'), icon: MessagesSquare, permission: 'plc.view', feature: 'plc' },
      { to: '/admin/discussions', label: t('soc.hub.title'), icon: MessagesSquare, feature: 'plc|forums', without: { permission: 'plc.view', feature: 'plc' } },
      { to: '/admin/trainer-inbox', label: t('soc.nav.inbox'), icon: ClipboardList, permission: 'trainers.respond|forums.moderate|ratings.moderate', feature: 'forums' },
      { to: '/admin/gamification', label: t('soc.nav.studio'), icon: Award, permission: 'gamification.manage|rewards.manage', feature: 'gamification' },
      { to: '/admin/achievements', label: t('soc.nav.achievements'), icon: Award, feature: 'gamification' },
      { to: '/admin/chats', label: m('chats'), icon: MessagesSquare, permission: 'announcements.manage', badge: badges.chats },
    ] },
    { id: 'organization', title: m('groups.organization'), items: [
      { to: '/admin/schools', label: m('schools'), icon: School, permission: 'schools.view' },
      { to: '/admin/employees', label: m('employees'), icon: Users, permission: 'employees.view' },
      { to: '/admin/profile-requests', label: m('profileRequests'), icon: FilePenLine, permission: 'employees.manage', badge: badges.requests },
      { to: '/admin/trainers', label: m('trainers'), icon: GraduationCap, permission: 'programs.view' },
      { to: '/admin/rooms', label: m('rooms'), icon: DoorOpen, permission: 'programs.view' },
      { to: '/admin/room-ops', label: t('ops.rooms.title'), icon: CalendarRange, permission: 'programs.view|rooms.book' },
      { to: '/admin/logistics', label: t('ops.logistics.title'), icon: PackageOpen, permission: 'programs.view|logistics.manage' },
    ] },
    { id: 'security', title: m('groups.security'), items: [
      { to: '/admin/users', label: m('users'), icon: UserCog, permission: 'users.manage' },
      { to: '/admin/audit', label: m('audit'), icon: FileSearch, permission: 'audit.view' },
    ] },
    { id: 'settings', title: m('groups.settings'), items: [
      { to: '/admin/settings', label: m('settings'), icon: Settings2, permission: 'settings.manage' },
    ] },
  ]
}

/** The pages of the trainee portal, in the order of its menu. */
export function portalCatalog(t: TFunction): Item[] {
  return [
    { to: '/portal', label: t('nav.home'), icon: Home, end: true },
    // The pages of the person's own training (ownTraining) are listed in the trainee role. Somebody who is a supervisor and
    // a trainee finds them as «موظف», not under «مشرف»; a person with no trainee role keeps them (showsOwnTraining).
    { to: '/portal/training', label: t('portal.myTraining'), icon: BookOpen, ownTraining: true },
    { to: '/portal/passport', label: t('portal.passport'), icon: GraduationCap, ownTraining: true },
    { to: '/portal/certificates', label: t('portal.certificates'), icon: Wallet, ownTraining: true },
    { to: '/portal/tasks', label: t('portal.tasks'), icon: ClipboardList, ownTraining: true },
    // Impact surveys stay: a supervisor answers them about the people they supervise.
    { to: '/portal/surveys', label: t('portal.surveys'), icon: Target },
    { to: '/portal/needs', label: t('needsHub.my.title'), icon: GraduationCap, ownTraining: true },
    { to: '/portal/assessments', label: t('assess.runner.title'), icon: ClipboardList, ownTraining: true },
    { to: '/portal/evaluations', label: t('evalc.my.title'), icon: ClipboardList, ownTraining: true },
    { to: '/portal/growth', label: t('career.myNav'), icon: GraduationCap, ownTraining: true },
    { to: '/portal/library', label: t('content.navLibrary'), icon: BookOpen },
    { to: '/portal/communities', label: t('soc.nav.communities'), icon: MessagesSquare, feature: 'plc|forums' },
    { to: '/portal/questions', label: t('soc.nav.questions'), icon: GraduationCap, feature: 'forums' },
    { to: '/portal/achievements', label: t('soc.nav.achievements'), icon: Award, feature: 'gamification', ownTraining: true },
    { to: '/portal/my-data', label: t('prv.nav'), icon: Shield },
    { to: '/portal/help', label: t('hlp.nav'), icon: LifeBuoy },
    { to: '/portal/orders', label: t('pay.nav.orders'), icon: Wallet, feature: 'payments', ownTraining: true },
    { to: '/portal/entity', label: t('pay.nav.entity'), icon: School, feature: 'payments' },
    { to: '/portal/reports', label: t('rep.navReports'), icon: FilePenLine },
    { to: '/portal/notifications', label: t('portal.notifications'), icon: Bell },
  ]
}

/** Working as a supervisor: his people's requests, needs and staff, named the way he reads them. */
function superviseItems(t: TFunction): Item[] {
  return [
    // What he was given to supervise: it asks for no right, since it only ever lists his own groups.
    { to: '/admin/my-programs', label: t('duties.nav'), icon: ClipboardCheck, plain: true },
    { to: '/admin/approvals', label: t('admission.nav'), icon: ClipboardList, permission: 'registrations.approve_manager' },
    { to: '/admin/needs-hub', label: t('needsHub.nav'), icon: Target, permission: 'needs.request|needs.approve_individual' },
    { to: '/admin/employees', label: t('nav.myStaff'), icon: School, permission: 'employees.view' },
  ]
}

/**
 * Every dashboard page that is opened by a right of its own, for the menu of a role whose home is the portal.
 *
 * The portal menu used to be a fixed list: a right given to «مشرف» or «موظف» in Settings → Roles & permissions opened the
 * page, but no button led to it, so the change looked as if it had done nothing. Now the page a right opens is listed for
 * whoever holds that right, in every role and for every account. A page that asks for no right (the dashboard, help) is
 * left out: it says nothing about what the role was given. `taken` are pages the menu already lists under another name.
 */
export function workPages(t: TFunction, taken: Iterable<string> = []): Item[] {
  const skip = new Set(taken)
  return adminCatalog(t, {}).flatMap((g) => g.items).filter((i) => !!i.permission && !skip.has(i.to))
}

/**
 * The menu of the portal for the role the person is working in, before rights and feature switches are applied
 * (visibleGroups). `landsOnPortal`: the portal is this role's home, so the pages its rights open are listed here; a role
 * whose home is the dashboard finds them in the dashboard's own menu.
 */
export function portalMenu(t: TFunction, o: { ownTraining: boolean; supervisor: boolean; landsOnPortal: boolean }): MenuGroup[] {
  const supervise = o.supervisor ? superviseItems(t) : []
  // «لوحة القيادة» used to be a link at the foot of the menu; it is a page like the others, so it can be given or not.
  const dashboard: Item = { to: '/admin', label: t('admin.menu.dashboard'), icon: LayoutDashboard, end: true, plain: true, permission: 'dashboard.view|programs.view' }
  const work = o.landsOnPortal ? [...supervise, dashboard, ...workPages(t, supervise.map((i) => i.to))] : supervise
  return [
    { title: t('nav.portal'), items: portalCatalog(t).filter((i) => o.ownTraining || !i.ownTraining) },
    ...(work.length ? [{ id: o.supervisor ? 'supervise' : 'work', title: t(o.supervisor ? 'nav.supervise' : 'nav.workPages'), items: work }] : []),
  ]
}

/**
 * The menu a role sees, worked out from its rights alone. The side menu and the administrator's «ما يراه» window both
 * read it, so what the administrator is shown is what the person gets. (The dashboard menu is shown in its default
 * arrangement; an administrator's own arrangement of sections changes the order, never what is listed.)
 */
export function menuOfRole(t: TFunction, role: { slug: string; landing: string; permissions: readonly string[] }, heldSlugs: readonly string[], featureOn: (feature?: string) => boolean, hidden?: ReadonlySet<string>): MenuGroup[] {
  return visibleGroups(pagesOfRole(t, role, heldSlugs), holds(role.permissions), featureOn, hidden)
}

/**
 * Every page the menu of a role can list, before rights, feature switches and the person's hidden pages are applied.
 * A role has one menu, on every page: the portal's for a role whose home is the portal (its own pages, then the dashboard
 * pages its rights open), the dashboard's for the others. The menu used to change with the page the person stood on, so
 * a supervisor saw one list on the portal and another on a dashboard page, and «ما يراه» could only show one of them.
 */
export function pagesOfRole(t: TFunction, role: { slug: string; landing: string }, heldSlugs: readonly string[]): MenuGroup[] {
  return landsOnPortal(role.landing)
    ? portalMenu(t, { ownTraining: showsOwnTraining(role.slug, heldSlugs), supervisor: role.slug === 'supervisor', landsOnPortal: true })
    : adminCatalog(t, {})
}

/** Every page of both menus, to tell which page an address belongs to (pageAt). */
export function everyPage(t: TFunction): Item[] {
  return [...adminCatalog(t, {}).flatMap((g) => g.items), ...portalCatalog(t)]
}

/** The rights that open a page by themselves, from the pages of the dashboard menu (onlyDoors, pageRightOf). */
export function pageRights(t: TFunction): Set<string> {
  return onlyDoors(adminCatalog(t, {}).flatMap((g) => g.items).map((i) => i.permission))
}
