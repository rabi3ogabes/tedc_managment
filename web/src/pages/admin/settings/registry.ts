import { BellRing, CalendarDays, MapPinCheck, DoorOpen, FileSearch, GraduationCap, Palette, UserCog } from 'lucide-react'
import { lazy, type ComponentType, type LazyExoticComponent } from 'react'

export type SettingsGroup = 'identity' | 'communication' | 'security' | 'training'

export type SettingsSection = {
  id: string
  group: SettingsGroup
  /** Permission slug(s) - any one is enough. */
  permission: string[]
  icon: ComponentType<{ className?: string }>
  component: LazyExoticComponent<ComponentType>
  /** Words that also find this section in the search. */
  keywords: string[]
}

/** Every page that can be opened as a settings tab. Components load on first use. */
export const SETTINGS_SECTIONS: SettingsSection[] = [
  { id: 'appearance', group: 'identity', permission: ['settings.manage'], icon: Palette, component: lazy(() => import('@/pages/admin/BrandStudio')), keywords: ['brand', 'appearance', 'identity', 'theme', 'logo', 'color', 'font', 'name', 'هوية', 'شعار', 'ألوان', 'اسم'] },
  { id: 'notifications', group: 'communication', permission: ['settings.manage'], icon: BellRing, component: lazy(() => import('@/pages/admin/PushSettings')), keywords: ['notifications', 'notification', 'push', 'firebase', 'fcm', 'إشعارات'] },
  { id: 'attendance', group: 'training', permission: ['settings.manage'], icon: MapPinCheck, component: lazy(() => import('@/pages/admin/AttendanceSettings')), keywords: ['attendance', 'location', 'gps', 'geofence', 'presence', 'حضور', 'موقع', 'تحقق'] },
  { id: 'users', group: 'security', permission: ['users.manage'], icon: UserCog, component: lazy(() => import('@/pages/admin/Users')), keywords: ['users', 'user', 'roles', 'permissions', 'accounts', 'مستخدمين', 'صلاحيات', 'أدوار'] },
  { id: 'audit', group: 'security', permission: ['audit.view'], icon: FileSearch, component: lazy(() => import('@/pages/admin/AuditLog')), keywords: ['audit', 'log', 'history', 'سجل', 'تدقيق'] },
  { id: 'calendar', group: 'training', permission: ['calendar.view', 'calendar.manage'], icon: CalendarDays, component: lazy(() => import('@/pages/admin/TrainingCalendar')), keywords: ['calendar', 'vacation', 'holiday', 'exam', 'إجازة', 'اختبارات', 'تقويم'] },
  { id: 'rooms', group: 'training', permission: ['programs.view', 'rooms.manage'], icon: DoorOpen, component: lazy(() => import('@/pages/admin/Rooms')), keywords: ['rooms', 'room', 'hall', 'equipment', 'قاعة', 'قاعات'] },
  { id: 'trainers', group: 'training', permission: ['programs.view', 'trainers.manage'], icon: GraduationCap, component: lazy(() => import('@/pages/admin/Trainers')), keywords: ['trainers', 'trainer', 'partner', 'مدرب', 'شريك'] },
]

export const GROUP_ORDER: SettingsGroup[] = ['identity', 'communication', 'security', 'training']

export const HOME = 'home'
