import { Check } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { Button, Card, Empty, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'

type F = { key: string; type: string; label_ar: string; label_en: string; required?: boolean; options?: string[] }
type Form = { slug: string; title_ar: string; title_en: string; intro_ar: string | null; intro_en: string | null; fields: F[]; allowed_email_domains: string[] }

/** The public registration form for people outside the Ministry: details → verify email → submitted. */
export default function JoinForm() {
  const { slug } = useParams()
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const form = useGet<{ data: Form }>(`/public/forms/${slug}`, undefined, { retry: false })
  const [step, setStep] = useState(1)
  const [email, setEmail] = useState('')
  const [code, setCode] = useState('')
  const [data, setData] = useState<Record<string, string | string[]>>({})
  const [number, setNumber] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (form.isLoading) return <Spinner className="min-h-[50vh]" />
  if (form.error || !form.data) return <div className="mx-auto max-w-xl px-4 py-16"><Card><Empty text={t('admission.join.closed')} /></Card></div>
  const f = form.data.data
  const missing = f.fields.some((x) => x.required && !(data[x.key] && String(data[x.key]).length)) || !email

  const sendCode = async () => {
    setBusy(true); setError(null)
    try { await api.post(`/public/forms/${slug}/verify-email`, { email }); setStep(2) } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const submit = async () => {
    setBusy(true); setError(null)
    try { const { data: r } = await api.post(`/public/forms/${slug}/submit`, { email, code, data }); setNumber(r.data.number); setStep(3) } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const input = (x: F) => {
    const label = ar ? x.label_ar : x.label_en
    if (x.type === 'textarea') return <textarea className="input min-h-24" value={String(data[x.key] ?? '')} onChange={(e) => setData({ ...data, [x.key]: e.target.value })} aria-label={label} />
    if (x.type === 'select') return <select className="input" value={String(data[x.key] ?? '')} onChange={(e) => setData({ ...data, [x.key]: e.target.value })} aria-label={label}><option value="" />{x.options?.map((o) => <option key={o} value={o}>{o}</option>)}</select>
    if (x.type === 'multiselect') return <div className="flex flex-wrap gap-2">{x.options?.map((o) => { const cur = (data[x.key] as string[]) ?? []; const on = cur.includes(o); return <button key={o} type="button" aria-pressed={on} onClick={() => setData({ ...data, [x.key]: on ? cur.filter((v) => v !== o) : [...cur, o] })} className={on ? 'rounded-full bg-navy-900 px-3 py-1.5 text-sm font-bold text-white' : 'rounded-full border border-navy-100 px-3 py-1.5 text-sm text-slate-600'}>{o}</button> })}</div>
    const type = { email: 'email', phone: 'tel', number: 'number', date: 'date' }[x.type] ?? 'text'
    return <input type={type} dir={['email', 'phone', 'national_id', 'number'].includes(x.type) ? 'ltr' : undefined} className="input" value={String(data[x.key] ?? '')} onChange={(e) => setData({ ...data, [x.key]: e.target.value })} aria-label={label} />
  }

  return (
    <div className="mx-auto max-w-2xl px-4 py-10">
      <h1 className="font-display text-3xl font-extrabold text-navy-900">{ar ? f.title_ar : f.title_en}</h1>
      {(ar ? f.intro_ar : f.intro_en) && <p className="mt-2 text-slate-600">{ar ? f.intro_ar : f.intro_en}</p>}
      <ol className="my-6 flex items-center gap-3 text-sm" aria-label="steps">
        {[1, 2, 3].map((n) => <li key={n} className="flex items-center gap-2"><span className={step > n ? 'grid size-6 place-items-center rounded-full bg-success text-white' : step === n ? 'grid size-6 place-items-center rounded-full bg-gold-500 font-bold text-navy-950' : 'grid size-6 place-items-center rounded-full bg-navy-100 text-slate-500'}>{step > n ? <Check className="size-3.5" /> : n}</span><span className={step === n ? 'font-bold text-navy-900' : 'text-slate-500'}>{t(`admission.join.step${n}`)}</span>{n < 3 && <span className="h-px w-8 bg-navy-200" aria-hidden />}</li>)}
      </ol>
      <Card className="space-y-5">
        {step === 1 && (
          <>
            <Field label={t('admission.join.email')} hint={f.allowed_email_domains.length ? t('admission.join.domain', { domains: f.allowed_email_domains.join(', ') }) : undefined}><input type="email" dir="ltr" className="input" value={email} onChange={(e) => setEmail(e.target.value)} /></Field>
            {f.fields.map((x) => <Field key={x.key} label={`${ar ? x.label_ar : x.label_en}${x.required ? ' *' : ''}`}>{input(x)}</Field>)}
            {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
            <Button variant="gold" loading={busy} disabled={missing} onClick={() => void sendCode()}>{t('admission.join.sendCode')}</Button>
          </>
        )}
        {step === 2 && (
          <>
            <p className="text-sm text-slate-600">{t('admission.join.codeSent')} <b dir="ltr">{email}</b></p>
            <Field label={t('admission.join.code')}><input inputMode="numeric" maxLength={6} dir="ltr" className="input text-center text-2xl tracking-[.5em]" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} /></Field>
            {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
            <div className="flex gap-2"><Button variant="outline" onClick={() => { setStep(1); setError(null) }}>{t('admission.join.back')}</Button><Button variant="gold" loading={busy} disabled={code.length !== 6} onClick={() => void submit()}>{t('admission.join.submit')}</Button></div>
          </>
        )}
        {step === 3 && (
          <div className="space-y-3 py-4 text-center"><span className="mx-auto grid size-14 place-items-center rounded-full bg-success text-white"><Check className="size-7" /></span><h2 className="text-xl font-bold text-navy-900">{t('admission.join.done')}</h2>
            <p className="text-slate-600">{t('admission.join.number')}: <b className="font-mono" dir="ltr">{number}</b></p><p className="text-sm text-slate-500">{t('admission.join.next')}</p></div>
        )}
      </Card>
    </div>
  )
}

/** Sets the first password from the activation link in the approval email. */
export function Activate() {
  const { t } = useTranslation()
  const q = new URLSearchParams(window.location.search)
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [done, setDone] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const go = async () => {
    setError(null)
    try { await api.post('/auth/activate', { email: q.get('email'), token: q.get('token'), password, password_confirmation: confirm }); setDone(true) } catch (e) { setError(errorMessage(e)) }
  }
  return (
    <div className="mx-auto max-w-md px-4 py-14">
      <Card className="space-y-4">
        <h1 className="font-display text-2xl font-extrabold text-navy-900">{t('admission.join.activateTitle')}</h1>
        {done ? <><p className="text-slate-600">{t('admission.join.activated')}</p><Link to="/login" className="font-bold text-link">{t('admission.join.login')}</Link></> : (
          <>
            <p className="text-sm text-slate-500">{t('admission.join.activateHint')}</p>
            <Field label={t('admission.join.password')}><input type="password" className="input" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)} /></Field>
            <Field label={t('admission.join.confirm')}><input type="password" className="input" autoComplete="new-password" value={confirm} onChange={(e) => setConfirm(e.target.value)} /></Field>
            {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
            <Button variant="gold" disabled={password.length < 10 || password !== confirm} onClick={() => void go()}>{t('admission.join.activate')}</Button>
          </>
        )}
      </Card>
    </div>
  )
}
