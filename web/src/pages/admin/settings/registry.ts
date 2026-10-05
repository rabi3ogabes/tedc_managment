import { ClipboardCheck, ToggleRight, Sparkles, BellRing, BellPlus, Mail, CalendarDays, Clock, MonitorPlay, FlaskConical, Languages, LockKeyhole, MapPinCheck, DoorOpen, FileSearch, GraduationCap, Palette, UserCog } from 'lucide-react'
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
  { id: 'templates', group: 'communication', permission: ['announcements.manage'], icon: BellPlus, component: lazy(() => import('@/pages/admin/notifications/TemplatesManager')), keywords: ['templates', 'notification', 'message', 'wording', 'قوالب', 'إشعار', 'رسالة', 'نص'] },
  { id: 'labels', group: 'identity', permission: ['settings.manage'], icon: Languages, component: lazy(() => import('@/pages/admin/LabelManager')), keywords: ['labels', 'names', 'menu', 'button', 'rename', 'text', 'translation', 'أسماء', 'قائمة', 'زر', 'تسمية', 'نصوص'] },
  { id: 'notifications', group: 'communication', permission: ['settings.manage'], icon: BellRing, component: lazy(() => import('@/pages/admin/PushSettings')), keywords: ['notifications', 'notification', 'push', 'firebase', 'fcm', 'إشعارات'] },
  { id: 'channels', group: 'communication', permission: ['settings.manage'], icon: Mail, component: lazy(() => import('@/pages/admin/ChannelsSettings')), keywords: ['channels', 'email', 'sms', 'mail', 'smtp', 'قنوات', 'بريد', 'رسائل', 'نصية'] },
  { id: 'screens', group: 'training', permission: ['settings.manage'], icon: MonitorPlay, component: lazy(() => import('@/pages/admin/ScreensSettings')), keywords: ['lobby', 'portrait', 'today', 'programs', 'slides', 'الردهة', 'برامج اليوم', 'شرائح', 'room', 'screen', 'tv', 'display', 'template', 'brand', 'logo', 'شاشة', 'قاعة', 'قالب', 'عرض', 'هوية'] },
  { id: 'features', group: 'security', permission: ['settings.manage'], icon: ToggleRight, component: lazy(() => import('@/pages/admin/FeaturesSettings')), keywords: ['features', 'flags', 'switch', 'enable', 'impersonation', 'demo', 'payments', 'ميزات', 'تفعيل', 'إيقاف', 'أدوات', 'تجريبي'] },
  { id: 'rfp', group: 'security', permission: ['settings.manage'], icon: ClipboardCheck, component: lazy(() => import('@/pages/admin/RfpCompliance')), keywords: ['rfp', 'compliance', 'requirements', 'coverage', 'tender', 'gap', 'امتثال', 'كراسة', 'الشروط', 'متطلبات', 'تغطية'] },
  { id: 'aimodels', group: 'identity', permission: ['settings.manage'], icon: Sparkles, component: lazy(() => import('@/pages/admin/AiModelsSettings')), keywords: ['ai', 'model', 'models', 'openrouter', 'openai', 'anthropic', 'api', 'key', 'image', 'video', 'sound', 'content', 'ذكاء', 'اصطناعي', 'نموذج', 'نماذج', 'مفتاح', 'صور', 'صوت', 'فيديو', 'محتوى'] },
  { id: 'trainingday', group: 'training', permission: ['settings.manage'], icon: Clock, component: lazy(() => import('@/pages/admin/TrainingDaySettings')), keywords: ['day', 'hours', 'time', 'start', 'end', 'room', 'session', 'يوم', 'ساعات', 'وقت', 'بداية', 'نهاية', 'قاعة', 'جلسة'] },
  { id: 'attendance', group: 'training', permission: ['settings.manage'], icon: MapPinCheck, component: lazy(() => import('@/pages/admin/AttendanceSettings')), keywords: ['attendance', 'location', 'gps', 'geofence', 'presence', 'حضور', 'موقع', 'تحقق'] },
  { id: 'security', group: 'security', permission: ['settings.manage'], icon: LockKeyhole, component: lazy(() => import('@/pages/admin/SessionLockSettings')), keywords: ['security', 'lock', 'idle', 'session', 'password', 'timeout', 'أمان', 'قفل', 'جلسة', 'كلمة المرور'] },
  { id: 'testaccounts', group: 'training', permission: ['users.manage'], icon: FlaskConical, component: lazy(() => import('@/pages/admin/TestAccounts')), keywords: ['test', 'demo', 'trainee', 'trainer', 'accounts', 'app', 'اختبار', 'تجريبي', 'متدرب', 'مدرب', 'حسابات'] },
  { id: 'users', group: 'security', permission: ['users.manage'], icon: UserCog, component: lazy(() => import('@/pages/admin/Users')), keywords: ['users', 'user', 'roles', 'permissions', 'accounts', 'مستخدمين', 'صلاحيات', 'أدوار'] },
  { id: 'audit', group: 'security', permission: ['audit.view'], icon: FileSearch, component: lazy(() => import('@/pages/admin/AuditLog')), keywords: ['audit', 'log', 'history', 'سجل', 'تدقيق'] },
  { id: 'calendar', group: 'training', permission: ['calendar.view', 'calendar.manage'], icon: CalendarDays, component: lazy(() => import('@/pages/admin/TrainingCalendar')), keywords: ['calendar', 'vacation', 'holiday', 'exam', 'إجازة', 'اختبارات', 'تقويم'] },
  { id: 'rooms', group: 'training', permission: ['programs.view', 'rooms.manage'], icon: DoorOpen, component: lazy(() => import('@/pages/admin/Rooms')), keywords: ['rooms', 'room', 'hall', 'equipment', 'قاعة', 'قاعات'] },
  { id: 'trainers', group: 'training', permission: ['programs.view', 'trainers.manage'], icon: GraduationCap, component: lazy(() => import('@/pages/admin/Trainers')), keywords: ['trainers', 'trainer', 'partner', 'مدرب', 'شريك'] },
]

export const GROUP_ORDER: SettingsGroup[] = ['identity', 'communication', 'security', 'training']

export const HOME = 'home'
