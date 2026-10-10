import {
  Award, Bug, LifeBuoy, KanbanSquare, CalendarRange, School2, Monitor, Palette, Video, Bot, CalendarDays, ClipboardList, DoorOpen, FileSearch, GraduationCap, LayoutDashboard, LineChart, Map, FilePenLine, LayoutTemplate, Megaphone, MessagesSquare, PackageOpen, Radio, School, Settings2, Shield, Target, UserCog, Users, Wallet, ShieldAlert, BookOpen,
} from 'lucide-react'
import type { ComponentType } from 'react'
import type { TFunction } from 'i18next'

export type Item = { to: string; label: string; icon: ComponentType<{ className?: string }>; permission?: string; end?: boolean; badge?: number; feature?: string }
export type Group = { id: string; title: string; items: Item[] }
export type MenuBadges = { errors?: number; chats?: number; requests?: number }

/** Every button of the dashboard menu with its default section, before the administrator's arrangement is laid over it. */
export function adminCatalog(t: TFunction, badges: MenuBadges): Group[] {
  const m = (k: string) => t(`admin.menu.${k}`)
  return [
    { id: 'overview', title: m('groups.overview'), items: [
      { to: '/admin', label: m('dashboard'), icon: LayoutDashboard, permission: 'dashboard.view', end: true },
      { to: '/admin/analytics', label: m('analytics'), icon: LineChart, permission: 'analytics.executive' },
    ] },
    { id: 'lifecycle', title: m('groups.lifecycle'), items: [
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
      { to: '/admin/communities', label: t('soc.nav.communities'), icon: MessagesSquare, feature: 'plc|forums' },
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
