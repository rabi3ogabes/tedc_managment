import { Building2, Lock, Mail } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import MfaStep from '@/components/auth/MfaStep'
import Logo from '@/components/public/Logo'
import { LanguageToggle } from '@/components/public/PublicLayout'
import { MinistryOfEducation } from '@/components/public/QatarArt'
import { Button, Field } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { homeFor, MfaRequired, useAuth, type MfaChallenge } from '@/lib/auth'
import { useCenterName } from '@/lib/ThemeProvider'

const DEMO = [
  ['admin@tedc.qa', 'Super Admin'], ['center@tedc.qa', 'Center Admin'], ['coordinator@tedc.qa', 'Coordinator'], ['trainer@tedc.qa', 'Trainer'],
  ['school@tedc.qa', 'School Admin'], ['teacher@tedc.qa', 'Employee'], ['supervisor@tedc.qa', 'Supervisor'], ['executive@tedc.qa', 'Executive'],
]

const TEST_ACCOUNTS: [string, string][] = [['trainee1@tedc.qa', 'متدرب 1'], ['trainee2@tedc.qa', 'متدرب 2'], ['trainee3@tedc.qa', 'متدرب 3'], ['trainee4@tedc.qa', 'متدرب 4'], ['trainer1@tedc.qa', 'مدرب 1'], ['trainer2@tedc.qa', 'مدرب 2']]

// The demo accounts panel appears only where the server is in demo mode (never on a live system by default; see DemoGuard).
// A build can still hide it with VITE_SHOW_DEMO_ACCOUNTS=false.
const DEMO_BUILD = import.meta.env.VITE_SHOW_DEMO_ACCOUNTS !== 'false'
const DEMO_PASSWORD = 'Tedc@2026!'

/** After signing in: the change-password page when the password has expired, else where the person was heading. */
function landing(me: Parameters<typeof homeFor>[0], from?: string) {
  let expired = false
  try { expired = sessionStorage.getItem('tedc.pwexpired') === '1' } catch { /* storage unavailable */ }
  return expired ? (homeFor(me).startsWith('/admin') ? '/admin/security' : '/portal/security') : (from ?? homeFor(me))
}

export default function Login() {
  const { t } = useTranslation()
  const centerName = useCenterName()
  const { login, finish, user } = useAuth()
  const [params] = useSearchParams()
  const [challenge, setChallenge] = useState<MfaChallenge | null>(null)
  const [directory, setDirectory] = useState(false)
  const options = useGet<{ data: { sso: boolean; ldap: boolean } }>('/auth/options', undefined, { staleTime: 60_000, retry: false })
  const navigate = useNavigate()
  const location = useLocation() as { state?: { from?: string } }
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(params.get('sso_error') ? String(t('idn.login.ssoError')) : null)
  const [loading, setLoading] = useState(false)
  const [opening, setOpening] = useState<string | null>(null)
  const config = useGet<{ data: { demo_accounts?: boolean } }>('/public/mobile-config', undefined, { staleTime: 5 * 60_000, retry: false })
  const SHOW_DEMO = DEMO_BUILD && config.data?.data.demo_accounts === true

  if (user) return <Navigate to={homeFor(user)} replace />

  const sso = async () => {
    try { const { data } = await api.get('/auth/sso/start', { params: { redirect: `${window.location.origin}/sso/callback` } }); window.location.href = data.data.url } catch (err) { setError(errorMessage(err)) }
  }
  const done = (data: unknown) => { const me = finish(data); navigate(landing(me, location.state?.from), { replace: true }) }

  const signIn = async (mail: string, pass: string, viaDirectory = false) => {
    setLoading(true)
    setError(null)
    try {
      if (viaDirectory) {
        const { data } = await api.post('/auth/ldap/login', { username: mail, password: pass })
        const me = finish(data)
        navigate(landing(me, location.state?.from), { replace: true })
        return
      }
      const me = await login(mail, pass)
      navigate(landing(me, location.state?.from), { replace: true })
    } catch (err) {
      if (err instanceof MfaRequired) { setChallenge(err.challenge); return }
      setError(errorMessage(err))
    } finally {
      setLoading(false)
      setOpening(null)
    }
  }
  const submit = (e: React.FormEvent) => { e.preventDefault(); void signIn(email, password, directory) }
  // One tap on a demo account signs straight in with the shared demo password.
  const openDemo = (mail: string) => {
    if (loading) return
    setEmail(mail); setPassword(DEMO_PASSWORD); setOpening(mail)
    void signIn(mail, DEMO_PASSWORD)
  }

  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      <div className="flex flex-col px-6 py-8 sm:px-12">
        <div className="flex items-center justify-between"><Logo /><LanguageToggle /></div>
        <div className="m-auto w-full max-w-md py-12">
          <h1 className="text-3xl font-bold text-navy-900">{t('auth.title')}</h1>
          <p className="mt-2 text-slate-500">{t('auth.subtitle')}</p>
          {challenge ? <MfaStep challenge={challenge} onDone={done} onBack={() => setChallenge(null)} /> : (<>
          {options.data?.data.sso && (
            <div className="mt-8">
              <Button type="button" variant="gold" size="lg" className="w-full" icon={<Building2 className="size-5" />} onClick={sso}>{t('idn.login.sso')}</Button>
              <div className="my-5 flex items-center gap-3 text-xs text-slate-400"><span className="h-px flex-1 bg-navy-100" />{t('idn.login.or')}<span className="h-px flex-1 bg-navy-100" /></div>
            </div>
          )}
          <form onSubmit={submit} className="mt-8 space-y-5">
            <Field label={t('common.email')}>
              <div className="relative"><Mail className="absolute start-3 top-3 size-5 text-slate-400" /><input className="input ps-11" type={directory ? 'text' : 'email'} dir="ltr" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} /></div>
            </Field>
            <Field label={t('auth.password')}>
              <div className="relative"><Lock className="absolute start-3 top-3 size-5 text-slate-400" /><input className="input ps-11" type="password" dir="ltr" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} /></div>
            </Field>
            {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
            <Button variant="primary" size="lg" className="w-full" loading={loading}>{t('auth.signIn')}</Button>
            {options.data?.data.ldap && <button type="button" className="text-sm text-link" onClick={() => setDirectory(!directory)}>{directory ? t('idn.login.local') : t('idn.login.directory')}</button>}
          </form></>)}
          {SHOW_DEMO && (
            <div className="mt-8 rounded-2xl border border-dashed border-gold-300 bg-gold-100/40 p-4">
              <p className="text-sm font-bold text-navy-900">{t('auth.demo')}</p>
              <p className="mb-3 text-xs text-slate-500">{t('auth.demoHint')} <bdi dir="ltr" className="font-mono font-bold text-navy-900">{DEMO_PASSWORD}</bdi></p>
              <div className="flex flex-wrap gap-2">
                {DEMO.map(([mail, role]) => (
                  <button key={mail} type="button" disabled={loading} aria-busy={opening === mail} onClick={() => openDemo(mail)} className="rounded-lg bg-white px-3 py-1.5 text-xs font-semibold text-navy-800 ring-1 ring-navy-100 transition hover:ring-gold-400 focus-visible:outline-2 focus-visible:outline-gold-400 disabled:cursor-wait disabled:opacity-60">{opening === mail ? '…' : role}</button>
                ))}
              </div>
              <p className="mb-2 mt-4 text-xs font-bold text-navy-900">{t('auth.testAccounts')}</p>
              <div className="flex flex-wrap gap-2">
                {TEST_ACCOUNTS.map(([mail, label]) => (
                  <button key={mail} type="button" disabled={loading} aria-busy={opening === mail} onClick={() => openDemo(mail)} className="rounded-lg bg-navy-900 px-3 py-1.5 text-xs font-semibold text-gold-300 transition hover:bg-navy-800 focus-visible:outline-2 focus-visible:outline-gold-400 disabled:cursor-wait disabled:opacity-60">{opening === mail ? '…' : label}</button>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
      <div className="relative hidden overflow-hidden lg:block">
        <MinistryOfEducation />
        <div className="absolute inset-0 bg-gradient-to-t from-navy-950 via-navy-950/30 to-transparent" />
        <div className="absolute inset-x-12 bottom-14 text-white">
          <h2 className="text-4xl font-bold">{centerName}</h2>
          <div className="gold-line mt-4" />
          <p className="mt-4 max-w-md text-white/75">{t('home.introText')}</p>
        </div>
      </div>
    </div>
  )
}
