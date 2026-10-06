/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** The person's own sign-in security: change the password, two-step verification and the devices they are signed in on. */
export default function AccountSecurity() {
  const { t } = useTranslation()
  const opts = useGet<{ data: { password_policy: { min_length: number; upper: boolean; lower: boolean; digit: boolean; symbol: boolean } } }>('/auth/options', undefined, { staleTime: 60_000 })
  const mfa = useGet<{ data: { enabled: boolean; required: boolean; recovery_left: number } }>('/me/mfa', undefined, { staleTime: 0 })
  const sessions = useGet<{ data: any[] }>('/me/sessions', undefined, { staleTime: 0 })
  const [pw, setPw] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [setup, setSetup] = useState<{ secret: string; otpauth_url: string } | null>(null)
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const expired = (() => { try { return sessionStorage.getItem('tedc.pwexpired') === '1' } catch { return false } })()
  const p = opts.data?.data.password_policy

  const change = async (e: React.FormEvent) => {
    e.preventDefault(); setBusy(true); setError(null)
    try { await api.post('/me/password', pw); toast(String(t('idn.acct.changed'))); setPw({ current_password: '', password: '', password_confirmation: '' }); try { sessionStorage.removeItem('tedc.pwexpired') } catch { /* storage */ } sessions.refetch() } catch (er) { setError(errorMessage(er)) } finally { setBusy(false) }
  }
  const act = async (fn: () => Promise<void>) => { setBusy(true); setError(null); try { await fn() } catch (er) { setError(errorMessage(er)) } finally { setBusy(false) } }
  const startSetup = () => act(async () => { const { data } = await api.post('/me/mfa/totp/setup'); setSetup(data.data); setCode('') })
  const confirm = () => act(async () => { const { data } = await api.post('/me/mfa/totp/confirm', { code }); setCodes(data.data.recovery_codes); setSetup(null); mfa.refetch() })
  const disable = () => act(async () => { await api.post('/me/mfa/disable', { code }); setCode(''); mfa.refetch() })
  const regen = () => act(async () => { const { data } = await api.post('/me/mfa/recovery-codes', { code }); setCodes(data.data.recovery_codes); setCode(''); mfa.refetch() })
  const end = async (id: string) => { await api.delete(`/me/sessions/${id}`); toast(String(t('idn.acct.ended'))); sessions.refetch() }
  const hints = p ? [t('idn.acct.policy', { min: p.min_length }), p.upper && t('idn.acct.upper'), p.lower && t('idn.acct.lower'), p.digit && t('idn.acct.digit'), p.symbol && t('idn.acct.symbol')].filter(Boolean).join(' · ') : ''
  const m = mfa.data?.data

  return (
    <>
      <PageHeader title={t('idn.acct.title')} />
      {expired && <div className="mb-4 rounded-2xl bg-amber-50 p-3 text-sm font-semibold text-amber-800">{t('idn.acct.expired')}</div>}
      <div className="grid gap-5 lg:grid-cols-2">
        <Card>
          <h3 className="mb-3 font-bold text-navy-900">{t('idn.acct.password')}</h3>
          <form onSubmit={change} className="space-y-3">
            <Field label={t('idn.acct.current')}><input className={cls} type="password" autoComplete="current-password" dir="ltr" value={pw.current_password} onChange={(e) => setPw({ ...pw, current_password: e.target.value })} required /></Field>
            <Field label={t('idn.acct.next')} hint={hints}><input className={cls} type="password" autoComplete="new-password" dir="ltr" value={pw.password} onChange={(e) => setPw({ ...pw, password: e.target.value })} required /></Field>
            <Field label={t('idn.acct.confirm')}><input className={cls} type="password" autoComplete="new-password" dir="ltr" value={pw.password_confirmation} onChange={(e) => setPw({ ...pw, password_confirmation: e.target.value })} required /></Field>
            {error && <p className="text-sm text-danger">{error}</p>}
            <Button variant="gold" loading={busy}>{t('idn.acct.change')}</Button>
          </form>
        </Card>
        <Card>
          <div className="mb-3 flex items-center gap-2"><h3 className="font-bold text-navy-900">{t('idn.acct.mfa')}</h3>{m && <Badge color={m.enabled ? 'green' : 'gray'}>{m.enabled ? t('idn.acct.mfaOn') : t('idn.acct.mfaOff')}</Badge>}{m?.required && <Badge color="gold">{t('idn.acct.mfaRequired')}</Badge>}</div>
          {!m ? <Spinner /> : codes ? (
            <div className="space-y-3"><p className="text-sm text-slate-500">{t('idn.login.recoveryHint')}</p><div className="grid grid-cols-2 gap-2 rounded-2xl bg-ivory p-4 font-mono text-sm" dir="ltr">{codes.map((c) => <span key={c}>{c}</span>)}</div><Button variant="outline" onClick={() => setCodes(null)}>{t('idn.login.continue')}</Button></div>
          ) : setup ? (
            <div className="space-y-3">
              <p className="text-sm text-slate-500">{t('idn.login.setupHint')}</p>
              <div className="rounded-2xl bg-ivory p-3 text-sm"><code className="break-all font-mono font-bold" dir="ltr">{setup.secret}</code><a href={setup.otpauth_url} className="mt-1 block text-link">{t('idn.login.openApp')}</a></div>
              <input className={`${cls} text-center tracking-[0.3em]`} inputMode="numeric" maxLength={6} dir="ltr" value={code} onChange={(e) => setCode(e.target.value)} placeholder="000000" />
              <Button variant="gold" loading={busy} onClick={confirm}>{t('idn.login.confirm')}</Button>
            </div>
          ) : m.enabled ? (
            <div className="space-y-3">
              <p className="text-sm text-slate-500">{t('idn.acct.codesLeft', { n: m.recovery_left })}</p>
              <input className={`${cls} text-center tracking-[0.3em]`} inputMode="numeric" maxLength={6} dir="ltr" value={code} onChange={(e) => setCode(e.target.value)} placeholder="000000" />
              <div className="flex gap-2"><Button variant="outline" loading={busy} onClick={regen}>{t('idn.acct.newCodes')}</Button>{!m.required && <Button variant="outline" loading={busy} onClick={disable}>{t('idn.acct.disable')}</Button>}</div>
              {error && <p className="text-sm text-danger">{error}</p>}
            </div>
          ) : <Button variant="gold" loading={busy} onClick={startSetup}>{t('idn.acct.enable')}</Button>}
        </Card>
        <Card className="lg:col-span-2">
          <h3 className="mb-3 font-bold text-navy-900">{t('idn.acct.sessions')}</h3>
          <ul className="divide-y divide-navy-50 text-sm">
            {sessions.data?.data.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-3 py-2">
                <div className="min-w-0"><div className="truncate font-semibold">{s.device || s.method}{s.current && <Badge color="green" className="ms-2">{t('idn.acct.thisOne')}</Badge>}</div><div className="text-xs text-slate-500">{s.ip} · {t('idn.acct.since')} {fmt.date(s.started_at, { dateStyle: 'medium', timeStyle: 'short' } as any)} · {t('idn.acct.lastSeen')} {fmt.date(s.last_seen_at, { timeStyle: 'short' } as any)}</div></div>
                {!s.current && <Button size="sm" variant="outline" onClick={() => end(s.id)}>{t('idn.acct.end')}</Button>}
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </>
  )
}
