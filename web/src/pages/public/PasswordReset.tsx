import { ArrowLeft, KeyRound, Mail, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { Button, Card, Field } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'

type Policy = { min_length: number; upper: boolean; lower: boolean; digit: boolean; symbol: boolean }

/** Step one of the self-service reset: the person gives their e-mail; the answer is the same whether or not an account exists. */
export function ForgotPassword() {
  const { t } = useTranslation()
  const [email, setEmail] = useState('')
  const [sent, setSent] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null); setLoading(true)
    try { await api.post('/auth/forgot', { email }); setSent(true) } catch (err) { setError(errorMessage(err)) } finally { setLoading(false) }
  }
  return (
    <div className="mx-auto max-w-md px-4 pb-16 pt-28">
      <Card className="space-y-5">
        <div className="flex size-12 items-center justify-center rounded-2xl bg-gold-100 text-navy-900"><KeyRound className="size-6" /></div>
        <div>
          <h1 className="font-display text-2xl font-extrabold text-navy-900">{t('idn.pw.forgotTitle')}</h1>
          <p className="mt-1 text-sm text-slate-500">{sent ? t('idn.pw.sentHint') : t('idn.pw.forgotHint')}</p>
        </div>
        {sent ? (
          <div className="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-800" role="status">
            <p className="font-bold">{t('idn.pw.sentTitle')}</p>
            <p className="mt-1">{t('idn.pw.sentBody', { email })}</p>
          </div>
        ) : (
          <form onSubmit={submit} className="space-y-4">
            <Field label={t('common.email')}>
              <div className="relative"><Mail className="absolute start-3 top-3 size-5 text-slate-400" /><input id="forgot-email" className="input ps-11" type="email" dir="ltr" autoComplete="username" required autoFocus value={email} onChange={(e) => setEmail(e.target.value)} /></div>
            </Field>
            {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger" role="alert">{error}</p>}
            <Button variant="primary" size="lg" className="w-full" loading={loading}>{t('idn.pw.send')}</Button>
          </form>
        )}
        <Link to="/login" className="inline-flex items-center gap-1.5 text-sm font-bold text-link"><ArrowLeft className="size-4 rtl:rotate-180" />{t('idn.pw.backToLogin')}</Link>
      </Card>
    </div>
  )
}

/** Step two: the link from the e-mail opens this page; a new password under the policy ends every other session. */
export function ResetPassword() {
  const { t } = useTranslation()
  const [q] = useSearchParams()
  const email = q.get('email') ?? ''
  const token = q.get('token') ?? ''
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [done, setDone] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const invalidLink = !email || !token
  // The rule the server will apply, so the hint and the button agree with it.
  const options = useGet<{ data: { password_policy?: Policy } }>('/auth/options', undefined, { staleTime: 60_000, retry: false })
  const policy = options.data?.data.password_policy
  const min = policy?.min_length ?? 10
  const classes = policy ? (['upper', 'lower', 'digit', 'symbol'] as const).filter((k) => policy[k]).map((k) => t(`idn.acct.${k}`)) : []
  const hint = policy ? `${t('idn.acct.policy', { min })}${classes.length ? ` · ${classes.join('، ')}` : ''}` : t('idn.pw.policyHint')
  const submit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null); setLoading(true)
    try { await api.post('/auth/reset', { email, token, password, password_confirmation: confirm }); setDone(true) } catch (err) { setError(errorMessage(err)) } finally { setLoading(false) }
  }
  return (
    <div className="mx-auto max-w-md px-4 pb-16 pt-28">
      <Card className="space-y-5">
        <div className="flex size-12 items-center justify-center rounded-2xl bg-gold-100 text-navy-900"><ShieldCheck className="size-6" /></div>
        <div>
          <h1 className="font-display text-2xl font-extrabold text-navy-900">{t('idn.pw.resetTitle')}</h1>
          <p className="mt-1 text-sm text-slate-500">{done ? t('idn.pw.doneHint') : t('idn.pw.resetHint')}</p>
        </div>
        {done ? (
          <>
            <div className="rounded-2xl bg-emerald-50 p-4 text-sm font-bold text-emerald-800" role="status">{t('idn.pw.doneTitle')}</div>
            <Link to="/login" className="btn-primary inline-flex"><Button variant="primary" size="lg" className="w-full">{t('idn.pw.signInNow')}</Button></Link>
          </>
        ) : invalidLink ? (
          <>
            <p className="rounded-xl bg-red-50 p-3 text-sm text-danger" role="alert">{t('idn.pw.linkInvalid')}</p>
            <Link to="/forgot" className="text-sm font-bold text-link">{t('idn.pw.requestAgain')}</Link>
          </>
        ) : (
          <form onSubmit={submit} className="space-y-4">
            <p className="text-xs text-slate-500" dir="ltr">{email}</p>
            <Field label={t('idn.acct.next')} hint={hint}><input id="reset-password" className="input" type="password" dir="ltr" autoComplete="new-password" required autoFocus value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
            <Field label={t('idn.acct.confirm')}><input id="reset-confirm" className="input" type="password" dir="ltr" autoComplete="new-password" required value={confirm} onChange={(e) => setConfirm(e.target.value)} /></Field>
            {confirm && confirm !== password && <p className="text-xs text-danger">{t('idn.pw.mismatch')}</p>}
            {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger" role="alert">{error}</p>}
            <Button variant="primary" size="lg" className="w-full" loading={loading} disabled={password.length < min || password !== confirm}>{t('idn.pw.save')}</Button>
            <Link to="/forgot" className="block text-center text-sm text-link">{t('idn.pw.requestAgain')}</Link>
          </form>
        )}
      </Card>
    </div>
  )
}
