/* eslint-disable @typescript-eslint/no-explicit-any */
import { KeyRound } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import type { MfaChallenge } from '@/lib/auth'

/** The second step of signing in: the code from the app, e-mail or SMS, a recovery code, or setting up the authenticator app. */
export default function MfaStep({ challenge, onDone, onBack }: { challenge: MfaChallenge; onDone: (data: any) => void; onBack: () => void }) {
  const { t } = useTranslation()
  const methods = [...(challenge.enrolled ? ['totp'] : []), ...challenge.methods.filter((m) => m !== 'totp'), 'recovery']
  const [method, setMethod] = useState(methods[0])
  const [code, setCode] = useState('')
  const [remember, setRemember] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [info, setInfo] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null)
  const [codes, setCodes] = useState<string[] | null>(null)
  const [pending, setPending] = useState<any | null>(null)

  const run = async (fn: () => Promise<void>) => { setBusy(true); setError(null); try { await fn() } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) } }
  const verify = (e: React.FormEvent) => { e.preventDefault(); void run(async () => {
    const { data } = await api.post('/auth/mfa/verify', { mfa_token: challenge.mfa_token, method, code, remember_device: remember })
    if (data.device_token) { try { localStorage.setItem('tedc.device', data.device_token) } catch { /* storage unavailable */ } }
    onDone(data)
  }) }
  const send = () => void run(async () => { await api.post('/auth/mfa/send', { mfa_token: challenge.mfa_token, method }); setInfo(String(t('idn.login.sent'))) })
  const startSetup = () => void run(async () => { const { data } = await api.post('/auth/mfa/totp/setup', { mfa_token: challenge.mfa_token }); setSetup(data.data) })
  const confirm = (e: React.FormEvent) => { e.preventDefault(); void run(async () => {
    const { data } = await api.post('/auth/mfa/totp/confirm', { mfa_token: challenge.mfa_token, code })
    setCodes(data.recovery_codes); setPending(data)
  }) }

  if (codes && pending) {
    return (
      <div className="mt-8 space-y-4">
        <h2 className="text-xl font-bold text-navy-900">{t('idn.login.recoveryTitle')}</h2>
        <p className="text-sm text-slate-500">{t('idn.login.recoveryHint')}</p>
        <div className="grid grid-cols-2 gap-2 rounded-2xl bg-ivory p-4 font-mono text-sm" dir="ltr">{codes.map((c) => <span key={c}>{c}</span>)}</div>
        <Button variant="primary" size="lg" className="w-full" onClick={() => onDone(pending)}>{t('idn.login.continue')}</Button>
      </div>
    )
  }
  if (setup) {
    return (
      <form onSubmit={confirm} className="mt-8 space-y-4">
        <h2 className="text-xl font-bold text-navy-900">{t('idn.login.setup')}</h2>
        <p className="text-sm text-slate-500">{t('idn.login.setupHint')}</p>
        <div className="rounded-2xl bg-ivory p-4 text-sm"><div className="text-xs text-slate-500">{t('idn.login.secret')}</div><code className="break-all font-mono text-base font-bold" dir="ltr">{setup.secret}</code><a href={setup.otpauth_url} className="mt-2 block text-link">{t('idn.login.openApp')}</a></div>
        <Field label={t('idn.login.code')}><input className="input text-center text-xl tracking-[0.4em]" inputMode="numeric" maxLength={6} autoComplete="one-time-code" dir="ltr" value={code} onChange={(e) => setCode(e.target.value)} /></Field>
        {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
        <Button variant="primary" size="lg" className="w-full" loading={busy}>{t('idn.login.confirm')}</Button>
      </form>
    )
  }
  return (
    <form onSubmit={verify} className="mt-8 space-y-4">
      <div className="flex items-center gap-2"><KeyRound className="size-6 text-gold-600" /><h2 className="text-xl font-bold text-navy-900">{t('idn.login.mfaTitle')}</h2></div>
      <p className="text-sm text-slate-500">{t('idn.login.mfaHint')}</p>
      <div className="flex flex-wrap gap-1.5">{methods.map((m) => <button key={m} type="button" onClick={() => { setMethod(m); setCode(''); setInfo(null); setError(null) }} className={`rounded-full px-3 py-1 text-xs font-semibold ${method === m ? 'bg-navy-900 text-gold-300' : 'bg-navy-50 text-navy-700'}`}>{t(`idn.login.methods.${m}`)}</button>)}</div>
      {(method === 'email' || method === 'sms') && <Button type="button" variant="outline" loading={busy} onClick={send}>{t('idn.login.send')}</Button>}
      {info && <p className="text-sm text-emerald-700">{info}</p>}
      <Field label={t('idn.login.code')}><input className="input text-center text-xl tracking-[0.3em]" inputMode={method === 'recovery' ? 'text' : 'numeric'} autoComplete="one-time-code" dir="ltr" value={code} onChange={(e) => setCode(e.target.value)} required /></Field>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={remember} onChange={(e) => setRemember(e.target.checked)} />{t('idn.login.remember')}</label>
      {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
      <Button variant="primary" size="lg" className="w-full" loading={busy}>{t('idn.login.verify')}</Button>
      <div className="flex justify-between text-sm"><button type="button" className="text-link" onClick={onBack}>{t('idn.login.back')}</button>{!challenge.enrolled && <button type="button" className="text-link" onClick={startSetup}>{t('idn.login.setup')}</button>}</div>
    </form>
  )
}
