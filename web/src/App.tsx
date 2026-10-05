import { lazy, Suspense, type ReactNode } from 'react'
import SessionGuard from '@/components/SessionGuard'
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
const KitsWorkbench = lazy(() => import('@/pages/admin/kits/KitsWorkbench'))
const FileStudio = lazy(() => import('@/pages/admin/kits/FileStudio'))
const ProgramWizard = lazy(() => import('@/pages/admin/smart/ProgramWizard'))
const RoomsAdmin = lazy(() => import('@/pages/admin/Rooms'))
const TrainersAdmin = lazy(() => import('@/pages/admin/Trainers'))
const TrainingCalendar = lazy(() => import('@/pages/admin/TrainingCalendar'))
const Registrations = lazy(() => import('@/pages/admin/Registrations'))
const TrainingNeeds = lazy(() => import('@/pages/admin/TrainingNeeds'))
const SurveyStudio = lazy(() => import('@/pages/admin/needs/SurveyStudio'))
const GroupBoard = lazy(() => import('@/pages/admin/GroupBoard'))
const PlanStudio = lazy(() => import('@/pages/admin/PlanStudio'))
const InternalWorkshops = lazy(() => import('@/pages/admin/InternalWorkshops'))
const MyAssignments = lazy(() => import('@/pages/admin/MyAssignments'))
const NeedsHub = lazy(() => import('@/pages/admin/needshub/NeedsHub'))
const MyNeeds = lazy(() => import('@/pages/portal/MyNeeds'))
const ApprovalsInbox = lazy(() => import('@/pages/admin/ApprovalsInbox'))
const AdmissionSettings = lazy(() => import('@/pages/admin/AdmissionSettings'))
const JoinForm = lazy(() => import('@/pages/public/JoinForm'))
const Activate = lazy(() => import('@/pages/public/JoinForm').then((m) => ({ default: m.Activate })))
const Kiosk = lazy(() => import('@/pages/admin/Kiosk'))
const AttendanceOps = lazy(() => import('@/pages/admin/AttendanceOps'))
const RoomsOps = lazy(() => import('@/pages/admin/RoomsOps'))
const Logistics = lazy(() => import('@/pages/admin/Logistics'))
const LiveNow = lazy(() => import('@/pages/admin/LiveNow'))
const AttendanceAttempts = lazy(() => import('@/pages/admin/AttendanceAttempts'))
const ProfileRequests = lazy(() => import('@/pages/admin/ProfileRequests'))
const ChatInbox = lazy(() => import('@/pages/admin/ChatInbox'))
const Profile = lazy(() => import('@/pages/Profile'))
const Executive = lazy(() => import('@/pages/admin/Executive'))
const Geographic = lazy(() => import('@/pages/admin/Geographic'))
const AiAssistant = lazy(() => import('@/pages/admin/AiAssistant'))
const Communication = lazy(() => import('@/pages/admin/Communication'))
const Certificates = lazy(() => import('@/pages/admin/Certificates'))
const CertificateTemplates = lazy(() => import('@/pages/admin/certificates/Templates'))
const CertificateDesigner = lazy(() => import('@/pages/admin/certificates/Designer'))
const Schools = lazy(() => import('@/pages/admin/Schools'))
const Employees = lazy(() => import('@/pages/admin/Employees'))
const EmployeeProfile = lazy(() => import('@/pages/admin/EmployeeProfile'))
const Users = lazy(() => import('@/pages/admin/Users'))
const AuditLog = lazy(() => import('@/pages/admin/AuditLog'))

const PortalHome = lazy(() => import('@/pages/portal/PortalHome'))
const MyTraining = lazy(() => import('@/pages/portal/MyTraining'))
const Passport = lazy(() => import('@/pages/portal/Passport'))
const RoomWall = lazy(() => import('@/pages/admin/RoomWall'))
const ErrorLog = lazy(() => import('@/pages/admin/ErrorLog'))
const RoomScreen = lazy(() => import('@/pages/RoomScreen'))
const LobbyScreen = lazy(() => import('@/pages/LobbyScreen'))
const Learn = lazy(() => import('@/pages/portal/Learn'))
const Wallet = lazy(() => import('@/pages/portal/Wallet'))
const MyTasks = lazy(() => import('@/pages/portal/MyTasks'))
const Surveys = lazy(() => import('@/pages/portal/Surveys'))
const Notifications = lazy(() => import('@/pages/portal/Notifications'))

function RequireAuth({ children, permission }: { children: ReactNode; permission?: string }) {
  const { user, loading, can } = useAuth()
  const location = useLocation()
  if (loading) return <Spinner className="min-h-screen" />
  if (!user) return <Navigate to="/login" replace state={{ from: location.pathname }} />
  if (permission && !permission.split('|').some((x) => can(x))) return <Navigate to="/portal" replace />
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
    <SessionGuard />
    <Suspense fallback={<Spinner className="min-h-screen" />}>
      <Routes>
        <Route element={<PublicLayout />}>
          <Route index element={<Home />} />
          <Route path="about" element={<About />} />
          <Route path="programs" element={<Programs />} />
          <Route path="programs/:code" element={<ProgramDetail />} />
          <Route path="trainers" element={<Trainers />} />
          <Route path="join/:slug" element={<JoinForm />} />
          <Route path="activate" element={<Activate />} />
          <Route path="my-assignments" element={<RequireAuth permission="trainers.respond"><MyAssignments /></RequireAuth>} />
          <Route path="needs-hub" element={<RequireAuth permission="needs.cycles|needs.propose|needs.request|needs.approve_individual|performance.import|gaps.view|competencies.manage"><NeedsHub /></RequireAuth>} />
          <Route path="approvals" element={<RequireAuth permission="registrations.approve_manager|registrations.manage|registrations.approve_center|withdrawals.decide|external_requests.review"><ApprovalsInbox /></RequireAuth>} />
          <Route path="admission-rules" element={<RequireAuth permission="priority.manage|withdrawals.policy|external_forms.manage"><AdmissionSettings /></RequireAuth>} />
          <Route path="absence" element={<RequireAuth permission="attendance.manage|attendance.devices"><AttendanceOps /></RequireAuth>} />
          <Route path="room-ops" element={<RequireAuth permission="programs.view|rooms.book"><RoomsOps /></RequireAuth>} />
          <Route path="logistics" element={<RequireAuth permission="programs.view|logistics.manage"><Logistics /></RequireAuth>} />
          <Route path="groups" element={<RequireAuth permission="programs.view"><GroupBoard /></RequireAuth>} />
          <Route path="plans" element={<RequireAuth permission="plans.view"><PlanStudio /></RequireAuth>} />
          <Route path="internal-workshops" element={<RequireAuth permission="workshops.internal|workshops.approve"><InternalWorkshops /></RequireAuth>} />
          <Route path="calendar" element={<CalendarPage />} />
          <Route path="verify" element={<Verify />} />
          <Route path="verify/:code" element={<Verify />} />
          <Route path="news" element={<News />} />
          <Route path="news/:id" element={<NewsDetail />} />
          <Route path="contact" element={<Contact />} />
        </Route>
        <Route path="login" element={<Login />} />

        <Route path="admin/kits/:kitId/files/:fileId" element={<RequireAuth permission="kits.view"><FileStudio /></RequireAuth>} />
        <Route path="room-screen/:token" element={<RoomScreen />} />
        <Route path="lobby-screen/:token" element={<LobbyScreen />} />
        <Route path="admin/rooms/:id/screen" element={<RequireAuth permission="programs.view"><RoomScreen admin /></RequireAuth>} />
        <Route path="kiosk/sessions/:id" element={<RequireAuth permission="attendance.manage"><Kiosk /></RequireAuth>} />
        <Route path="admin/sessions/:id/qr" element={<RequireAuth permission="attendance.manage"><SessionQr /></RequireAuth>} />
        <Route path="admin" element={<RequireAuth><AdminLayout /></RequireAuth>}>
          <Route index element={<Dashboard />} />
          <Route path="programs" element={<ProgramsAdmin />} />
          <Route path="programs/new" element={<ProgramEditor />} />
          <Route path="kits/*" element={<RequireAuth permission="kits.view"><KitsWorkbench /></RequireAuth>} />
          <Route path="programs/remote" element={<RequireAuth permission="programs.manage"><ProgramWizard remote /></RequireAuth>} />
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
          <Route path="live" element={<RequireAuth permission="analytics.view"><LiveNow /></RequireAuth>} />
          <Route path="attendance-attempts" element={<RequireAuth permission="attendance.manage"><AttendanceAttempts /></RequireAuth>} />
          <Route path="geo" element={<Geographic />} />
          <Route path="ai" element={<AiAssistant />} />
          <Route path="communication" element={<Communication />} />
          <Route path="chats" element={<RequireAuth permission="announcements.manage"><ChatInbox /></RequireAuth>} />
          <Route path="certificates" element={<Certificates />} />
          <Route path="room-screens" element={<RequireAuth permission="programs.view"><RoomWall /></RequireAuth>} />
          <Route path="error-log" element={<RequireAuth permission="logs.manage"><ErrorLog /></RequireAuth>} />
          <Route path="certificate-templates" element={<RequireAuth permission="certificates.view"><CertificateTemplates /></RequireAuth>} />
          <Route path="certificate-templates/:id" element={<RequireAuth permission="certificates.issue"><CertificateDesigner /></RequireAuth>} />
          <Route path="schools" element={<Schools />} />
          <Route path="employees" element={<Employees />} />
          <Route path="employees/:id" element={<EmployeeProfile />} />
          <Route path="profile" element={<Profile />} />
          <Route path="profile-requests" element={<RequireAuth permission="employees.manage"><ProfileRequests /></RequireAuth>} />
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
          <Route path="learn/:registrationId/:lessonId?" element={<Learn />} />
          <Route path="tasks" element={<MyTasks />} />
          <Route path="surveys" element={<Surveys />} />
          <Route path="needs" element={<MyNeeds />} />
          <Route path="notifications" element={<Notifications />} />
          <Route path="profile" element={<Profile />} />
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
    </>
  )
}
