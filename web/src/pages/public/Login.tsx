import { Lock, Mail } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useLocation, useNavigate } from 'react-router-dom'
import Logo from '@/components/public/Logo'
import { LanguageToggle } from '@/components/public/PublicLayout'
import { MinistryOfEducation } from '@/components/public/QatarArt'
import { Button, Field } from '@/components/ui'
import { errorMessage } from '@/lib/api'
import { homeFor, useAuth } from '@/lib/auth'

const DEMO = [
  ['admin@tedc.qa', 'Super Admin'], ['center@tedc.qa', 'Center Admin'], ['coordinator@tedc.qa', 'Coordinator'], ['trainer@tedc.qa', 'Trainer'],
  ['school@tedc.qa', 'School Admin'], ['teacher@tedc.qa', 'Employee'], ['supervisor@tedc.qa', 'Supervisor'], ['executive@tedc.qa', 'Executive'],
]

// Demo accounts panel: shown by default (also on the live site); build with VITE_SHOW_DEMO_ACCOUNTS=false to hide it.
const SHOW_DEMO = import.meta.env.VITE_SHOW_DEMO_ACCOUNTS !== 'false'
const DEMO_PASSWORD = 'Tedc@2026!'

export default function Login() {
  const { t } = useTranslation()
  const { login, user } = useAuth()
  const navigate = useNavigate()
  const location = useLocation() as { state?: { from?: string } }
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  if (user) return <Navigate to={homeFor(user)} replace />

  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setLoading(true)
    setError(null)
    try {
      const me = await login(email, password)
      navigate(location.state?.from ?? homeFor(me), { replace: true })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      <div className="flex flex-col px-6 py-8 sm:px-12">
        <div className="flex items-center justify-between"><Logo /><LanguageToggle /></div>
        <div className="m-auto w-full max-w-md py-12">
          <h1 className="text-3xl font-bold text-navy-900">{t('auth.title')}</h1>
          <p className="mt-2 text-slate-500">{t('auth.subtitle')}</p>
          <form onSubmit={submit} className="mt-8 space-y-5">
            <Field label={t('common.email')}>
              <div className="relative"><Mail className="absolute start-3 top-3 size-5 text-slate-400" /><input className="input ps-11" type="email" dir="ltr" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} /></div>
            </Field>
            <Field label={t('auth.password')}>
              <div className="relative"><Lock className="absolute start-3 top-3 size-5 text-slate-400" /><input className="input ps-11" type="password" dir="ltr" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} /></div>
            </Field>
            {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
            <Button variant="primary" size="lg" className="w-full" loading={loading}>{t('auth.signIn')}</Button>
          </form>
          {SHOW_DEMO && (
            <div className="mt-8 rounded-2xl border border-dashed border-gold-300 bg-gold-100/40 p-4">
              <p className="text-sm font-bold text-navy-900">{t('auth.demo')}</p>
              <p className="mb-3 text-xs text-slate-500">{t('auth.demoHint')} <bdi dir="ltr" className="font-mono font-bold text-navy-900">{DEMO_PASSWORD}</bdi></p>
              <div className="flex flex-wrap gap-2">
                {DEMO.map(([mail, role]) => (
                  <button key={mail} type="button" onClick={() => { setEmail(mail); setPassword(DEMO_PASSWORD) }} className="rounded-lg bg-white px-2.5 py-1 text-xs font-semibold text-navy-800 ring-1 ring-navy-100 hover:ring-gold-400">{role}</button>
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
          <h2 className="text-4xl font-bold">{t('brand.name')}</h2>
          <div className="gold-line mt-4" />
          <p className="mt-4 max-w-md text-white/75">{t('home.introText')}</p>
        </div>
      </div>
    </div>
  )
}
