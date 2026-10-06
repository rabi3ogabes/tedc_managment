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
const PassingPolicies = lazy(() => import('@/pages/admin/PassingPolicies'))
const EvaluationForms = lazy(() => import('@/pages/admin/EvaluationForms'))
const EvaluationSettings = lazy(() => import('@/pages/admin/EvaluationSettings'))
const CareerPaths = lazy(() => import('@/pages/admin/CareerPaths'))
const PdCentre = lazy(() => import('@/pages/admin/PdCentre'))
const StandardsSettings = lazy(() => import('@/pages/admin/StandardsSettings'))
const LibraryAdmin = lazy(() => import('@/pages/admin/LibraryAdmin'))
const SharingAdmin = lazy(() => import('@/pages/admin/SharingAdmin'))
const QuestionBanks = lazy(() => import('@/pages/admin/QuestionBanks'))
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
const PortalAssessments = lazy(() => import('@/pages/portal/Assessments'))
const ExamRunner = lazy(() => import('@/pages/portal/ExamRunner'))
const AttemptResult = lazy(() => import('@/pages/portal/ExamRunner').then((m) => ({ default: m.AttemptResult })))
const LiveNow = lazy(() => import('@/pages/admin/LiveNow'))
const AttendanceAttempts = lazy(() => import('@/pages/admin/AttendanceAttempts'))
const ProfileRequests = lazy(() => import('@/pages/admin/ProfileRequests'))
const ChatInbox = lazy(() => import('@/pages/admin/ChatInbox'))
const Profile = lazy(() => import('@/pages/Profile'))
const Executive = lazy(() => import('@/pages/admin/Executive'))
const Geographic = lazy(() => import('@/pages/admin/Geographic'))
const AiAssistant = lazy(() => import('@/pages/admin/AiAssistant'))
const Communication = lazy(() => import('@/pages/admin/Communication'))
const Reports = lazy(() => import('@/pages/admin/Reports'))
const ReportBuilder = lazy(() => import('@/pages/admin/ReportBuilder'))
const KpiDashboard = lazy(() => import('@/pages/admin/KpiDashboard'))
const DashboardPresets = lazy(() => import('@/pages/admin/DashboardPresets'))
const SsoCallback = lazy(() => import('@/pages/public/SsoCallback'))
const AccountSecurity = lazy(() => import('@/pages/AccountSecurity'))
const SecurityAdmin = lazy(() => import('@/pages/admin/SecurityAdmin'))
const IntegrationsHub = lazy(() => import('@/pages/admin/IntegrationsHub'))
const MigrationTool = lazy(() => import('@/pages/admin/MigrationTool'))
const HomeEditor = lazy(() => import('@/pages/admin/HomeEditor'))
const EventsPage = lazy(() => import('@/pages/public/Events'))
const EventDetail = lazy(() => import('@/pages/public/Events').then((m) => ({ default: m.EventDetail })))
const NotificationPrefs = lazy(() => import('@/pages/NotificationPrefs'))
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
const MyGrowth = lazy(() => import('@/pages/portal/MyGrowth'))
const Library = lazy(() => import('@/pages/portal/Library'))
const MyEvaluations = lazy(() => import('@/pages/portal/MyEvaluations'))
const MyEvaluationForm = lazy(() => import('@/pages/portal/MyEvaluations').then((m) => ({ default: m.MyEvaluationForm })))
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
          <Route path="evaluation-forms" element={<RequireAuth permission="evaluations.manage"><EvaluationForms /></RequireAuth>} />
          <Route path="evaluation-settings" element={<RequireAuth permission="evaluations.manage"><EvaluationSettings /></RequireAuth>} />
          <Route path="career-paths" element={<RequireAuth permission="paths.manage|licences.manage"><CareerPaths /></RequireAuth>} />
          <Route path="pd-centre" element={<RequireAuth permission="pd.recognise|pd.approve|pd.types.manage|knowledge_transfer.review"><PdCentre /></RequireAuth>} />
          <Route path="standards" element={<RequireAuth permission="standards.manage|lti.manage|providers.manage|library.manage"><StandardsSettings /></RequireAuth>} />
          <Route path="library-admin" element={<RequireAuth permission="library.manage"><LibraryAdmin /></RequireAuth>} />
          <Route path="sharing" element={<RequireAuth permission="sharing.manage|job_groups.manage"><SharingAdmin /></RequireAuth>} />
          <Route path="passing-policy" element={<RequireAuth permission="passing.manage"><PassingPolicies /></RequireAuth>} />
          <Route path="question-banks" element={<RequireAuth permission="banks.manage|assessments.manage"><QuestionBanks /></RequireAuth>} />
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
          <Route path="events" element={<EventsPage />} />
          <Route path="events/:id" element={<EventDetail />} />
          <Route path="news/:id" element={<NewsDetail />} />
          <Route path="contact" element={<Contact />} />
        </Route>
        <Route path="login" element={<Login />} />
        <Route path="sso/callback" element={<SsoCallback />} />

        <Route path="admin/kits/:kitId/files/:fileId" element={<RequireAuth permission="kits.view"><FileStudio /></RequireAuth>} />
        <Route path="room-screen/:token" element={<RoomScreen />} />
        <Route path="lobby-screen/:token" element={<LobbyScreen />} />
        <Route path="admin/rooms/:id/screen" element={<RequireAuth permission="programs.view"><RoomScreen admin /></RequireAuth>} />
        <Route path="portal/assessments/:id/take" element={<RequireAuth><ExamRunner /></RequireAuth>} />
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
          <Route path="notification-preferences" element={<NotificationPrefs />} />
          <Route path="security" element={<AccountSecurity />} />
          <Route path="profile-requests" element={<RequireAuth permission="employees.manage"><ProfileRequests /></RequireAuth>} />
          <Route path="users" element={<Users />} />
          <Route path="audit" element={<AuditLog />} />
          <Route path="settings" element={<SettingsWorkspace />} />
          <Route path="reports" element={<Reports />} />
          <Route path="security-policy" element={<RequireAuth permission="security.policy|sessions.manage"><SecurityAdmin /></RequireAuth>} />
          <Route path="integrations" element={<RequireAuth permission="integrations.manage|integrations.logs|sso.manage|webhooks.manage"><IntegrationsHub /></RequireAuth>} />
          <Route path="migration" element={<RequireAuth permission="migration.run"><MigrationTool /></RequireAuth>} />
          <Route path="reports/new" element={<RequireAuth permission="reports.builder"><ReportBuilder /></RequireAuth>} />
          <Route path="reports/:id/edit" element={<RequireAuth permission="reports.builder"><ReportBuilder /></RequireAuth>} />
          <Route path="kpi" element={<RequireAuth permission="kpi.view"><KpiDashboard /></RequireAuth>} />
          <Route path="dashboard-presets" element={<RequireAuth permission="dashboards.manage"><DashboardPresets /></RequireAuth>} />
          <Route path="appearance/home" element={<RequireAuth permission="cms.manage"><HomeEditor /></RequireAuth>} />
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
          <Route path="evaluations" element={<MyEvaluations />} />
          <Route path="library" element={<Library />} />
          <Route path="growth" element={<MyGrowth />} />
          <Route path="evaluations/:id" element={<MyEvaluationForm />} />
          <Route path="needs" element={<MyNeeds />} />
          <Route path="assessments" element={<PortalAssessments />} />
          <Route path="attempts/:id" element={<AttemptResult />} />
          <Route path="notifications" element={<Notifications />} />
          <Route path="reports" element={<Reports />} />
          <Route path="profile" element={<Profile />} />
          <Route path="notification-preferences" element={<NotificationPrefs />} />
          <Route path="security" element={<AccountSecurity />} />
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </Suspense>
    </>
  )
}
