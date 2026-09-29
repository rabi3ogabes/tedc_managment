import { lazy, Suspense, type ReactNode } from 'react'
import { TopProgress } from '@/components/ui/TopProgress'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import PublicLayout from '@/components/public/PublicLayout'
import { Spinner } from '@/components/ui'
import { useAuth } from '@/lib/auth'
import Home from '@/pages/public/Home'

const About = lazy(() => import('@/pages/public/About'))
const Programs = lazy(() => import('@/pages/public/Programs'))
const ProgramDetail = lazy(() => import('@/pages/public/ProgramDetail'))
const Trainers = lazy(() => import('@/pages/public/Trainers'))
const CalendarPage = lazy(() => import('@/pages/public/CalendarPage'))
const Verify = lazy(() => import('@/pages/public/Verify'))
const News = lazy(() => import('@/pages/public/News'))
const NewsDetail = lazy(() => import('@/pages/public/News').then((m) => ({ default: m.NewsDetail })))
const Contact = lazy(() => import('@/pages/public/Contact'))
const Login = lazy(() => import('@/pages/public/Login'))

const AdminLayout = lazy(() => import('@/components/admin/AdminLayout'))
const Dashboard = lazy(() => import('@/pages/admin/Dashboard'))
const ProgramsAdmin = lazy(() => import('@/pages/admin/ProgramsAdmin'))
const ProgramEditor = lazy(() => import('@/pages/admin/ProgramEditor'))
const ProgramManage = lazy(() => import('@/pages/admin/ProgramManage'))
const SessionQr = lazy(() => import('@/pages/admin/SessionQr'))
const SettingsWorkspace = lazy(() => import('@/pages/admin/settings/SettingsWorkspace'))
const ProgramWizard = lazy(() => import('@/pages/admin/smart/ProgramWizard'))
const RoomsAdmin = lazy(() => import('@/pages/admin/Rooms'))
const TrainersAdmin = lazy(() => import('@/pages/admin/Trainers'))
const TrainingCalendar = lazy(() => import('@/pages/admin/TrainingCalendar'))
const Registrations = lazy(() => import('@/pages/admin/Registrations'))
const TrainingNeeds = lazy(() => import('@/pages/admin/TrainingNeeds'))
const SurveyStudio = lazy(() => import('@/pages/admin/needs/SurveyStudio'))
const Executive = lazy(() => import('@/pages/admin/Executive'))
const Geographic = lazy(() => import('@/pages/admin/Geographic'))
const AiAssistant = lazy(() => import('@/pages/admin/AiAssistant'))
const Communication = lazy(() => import('@/pages/admin/Communication'))
const Certificates = lazy(() => import('@/pages/admin/Certificates'))
const Schools = lazy(() => import('@/pages/admin/Schools'))
const Employees = lazy(() => import('@/pages/admin/Employees'))
const EmployeeProfile = lazy(() => import('@/pages/admin/EmployeeProfile'))
const Users = lazy(() => import('@/pages/admin/Users'))
const AuditLog = lazy(() => import('@/pages/admin/AuditLog'))

const PortalHome = lazy(() => import('@/pages/portal/PortalHome'))
const MyTraining = lazy(() => import('@/pages/portal/MyTraining'))
const Passport = lazy(() => import('@/pages/portal/Passport'))
const Wallet = lazy(() => import('@/pages/portal/Wallet'))
const MyTasks = lazy(() => import('@/pages/portal/MyTasks'))
const Surveys = lazy(() => import('@/pages/portal/Surveys'))
const Notifications = lazy(() => import('@/pages/portal/Notifications'))

function RequireAuth({ children, permission }: { children: ReactNode; permission?: string }) {
  const { user, loading, can } = useAuth()
  const location = useLocation()
  if (loading) return <Spinner className="min-h-screen" />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (permission && !can(permission)) return <Navigate to="/portal" replace />
  return <>{children}</>
}

// After the first page is shown, quietly download the code of the pages visitors open next.
const idle = (cb: () => void) => ('requestIdleCallback' in window ? window.requestIdleCallback(cb, { timeout: 4000 }) : setTimeout(cb, 2500))
idle(() => {
  void import('@/pages/public/Programs')
  void import('@/pages/public/ProgramDetail')
  void import('@/pages/public/Login')
})

export default function App() {
  return (
    <>
    <TopProgress />
    <Suspense fallback={<Spinner className="min-h-screen" />}>
      <Routes>
        <Route element={<PublicLayout />}>
          <Route index element={<Home />} />
          <Route path="about" element={<About />} />
          <Route path="programs" element={<Programs />} />
          <Route path="programs/:code" element={<ProgramDetail />} />
          <Route path="trainers" element={<Trainers />} />
          <Route path="calendar" element={<CalendarPage />} />
          <Route path="verify" element={<Verify />} />
          <Route path="verify/:code" element={<Verify />} />
          <Route path="news" element={<News />} />
          <Route path="news/:id" element={<NewsDetail />} />
          <Route path="contact" element={<Contact />} />
        </Route>
        <Route path="login" element={<Login />} />

        <Route path="admin/sessions/:id/qr" element={<RequireAuth permission="attendance.manage"><SessionQr /></RequireAuth>} />
        <Route path="admin" element={<RequireAuth><AdminLayout /></RequireAuth>}>
          <Route index element={<Dashboard />} />
          <Route path="programs" element={<ProgramsAdmin />} />
          <Route path="programs/new" element={<ProgramEditor />} />
          <Route path="programs/smart" element={<RequireAuth permission="programs.manage"><ProgramWizard /></RequireAuth>} />
          <Route path="rooms" element={<RequireAuth permission="programs.view"><RoomsAdmin /></RequireAuth>} />
          <Route path="trainers" element={<RequireAuth permission="programs.view"><TrainersAdmin /></RequireAuth>} />
          <Route path="calendar" element={<RequireAuth permission="calendar.view"><TrainingCalendar /></RequireAuth>} />
          <Route path="programs/:id/edit" element={<ProgramEditor />} />
          <Route path="programs/:id" element={<ProgramManage />} />
          <Route path="registrations" element={<Registrations />} />
          <Route path="needs" element={<TrainingNeeds />} />
          <Route path="needs/surveys/:id" element={<RequireAuth permission="needs.manage"><SurveyStudio /></RequireAuth>} />
          <Route path="analytics" element={<Executive />} />
          <Route path="geo" element={<Geographic />} />
          <Route path="ai" element={<AiAssistant />} />
          <Route path="communication" element={<Communication />} />
          <Route path="certificates" element={<Certificates />} />
          <Route path="schools" element={<Schools />} />
          <Route path="employees" element={<Employees />} />
          <Route path="employees/:id" element={<EmployeeProfile />} />
          <Route path="users" element={<Users />} />
          <Route path="audit" element={<AuditLog />} />
          <Route path="settings" element={<SettingsWorkspace />} />
          <Route path="appearance" element={<Navigate to="/admin/settings?tab=appearance" replace />} />
          <Route path="settings/notifications" element={<Navigate to="/admin/settings?tab=notifications" replace />} />
        </Route>

        <Route path="portal" element={<RequireAuth><AdminLayout portal /></RequireAuth>}>
          <Route index element={<PortalHome />} />
          <Route path="training" element={<MyTraining />} />
          <Route path="passport" element={<Passport />} />
          <Route path="certificates" element={<Wallet />} />
          <Route path="tasks" element={<MyTasks />} />
          <Route path="surveys" element={<Surveys />} />
          <Route path="notifications" element={<Notifications />} />
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
    </>
  )
}
