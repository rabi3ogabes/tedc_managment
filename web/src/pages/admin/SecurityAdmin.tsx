/* eslint-disable @typescript-eslint/no-explicit-any */
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/dashboard/Widget'
import { Button, Card, Field, Modal, PageHeader, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** Runs an action that may ask for the second factor again (step-up) and retries it once the code is confirmed. */
export function useStepUp() {
  const { t } = useTranslation()
  const [pending, setPending] = useState<null | (() => Promise<void>)>(null)
  const [code, setCode] = useState('')
  const [error, setError] = useState<string | null>(null)
  const guard = async (fn: () => Promise<void>) => {
    try { await fn() } catch (e: any) { if (e?.response?.data?.code === 'step_up_required') { setPending(() => fn); setCode(''); setError(null) } else toast(errorMessage(e), 'error') }
  }
  const dialog = (
    <Modal open={!!pending} onClose={() => setPending(null)} title={t('idn.sec.stepUp')}>
      <Field label={t('idn.sec.stepCode')}><input className={`${cls} text-center tracking-[0.3em]`} inputMode="numeric" maxLength={6} dir="ltr" value={code} onChange={(e) => setCode(e.target.value)} /></Field>
      {error && <p className="mt-2 text-sm text-danger">{error}</p>}
      <div className="mt-4 flex justify-end"><Button variant="gold" onClick={async () => { try { await api.post('/auth/step-up', { code }); const fn = pending; setPending(null); if (fn) await guard(fn) } catch (e) { setError(errorMessage(e)) } }}>{t('idn.login.verify')}</Button></div>
    </Modal>
  )
  return { guard, dialog }
}

/** Settings → Security: the password policy, lockout, two-step verification and session limits; who is signed in, and unlocking people. */
export default function SecurityAdmin() {
  const { t } = useTranslation()
  const L = useLang()
  const { can } = useAuth()
  const res = useGet<{ data: any; meta: { roles: any[] } }>('/admin/security-policy', undefined, { staleTime: 0 })
  const [q, setQ] = useState('')
  const sessions = useGet<{ data: any[] }>(can('sessions.manage') ? '/admin/auth-sessions' : null, { q: q || undefined, per_page: 30 }, { staleTime: 0 })
  const [p, setP] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  const step = useStepUp()
  useEffect(() => { if (res.data) setP(res.data.data) }, [res.data])
  if (!p) return <Spinner />
  const set = (sec: string, k: string, v: any) => setP({ ...p, [sec]: { ...p[sec], [k]: v } })
  const num = (sec: string, k: string, label: string) => <Field label={label}><input type="number" className={cls} value={p[sec][k]} onChange={(e) => set(sec, k, Number(e.target.value))} /></Field>
  const chk = (sec: string, k: string, label: string) => <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={!!p[sec][k]} onChange={(e) => set(sec, k, e.target.checked)} />{label}</label>
  const toggle = (sec: string, k: string, v: string) => set(sec, k, p[sec][k].includes(v) ? p[sec][k].filter((x: string) => x !== v) : [...p[sec][k], v])
  const save = () => step.guard(async () => { setBusy(true); try { const { data } = await api.put('/admin/security-policy', p); setP(data.data); toast(String(t('idn.sec.saved'))) } finally { setBusy(false) } })
  const act = (fn: () => Promise<unknown>) => step.guard(async () => { await fn(); toast(String(t('idn.sec.done'))); sessions.refetch() })

  return (
    <>
      <PageHeader title={t('idn.sec.title')} actions={can('security.policy') ? <Button variant="gold" loading={busy} onClick={save}>{t('idn.sec.save')}</Button> : undefined} />
      {can('security.policy') && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card><h3 className="mb-3 font-bold text-navy-900">{t('idn.sec.password')}</h3><div className="grid gap-3 sm:grid-cols-2">{num('password', 'min_length', String(t('idn.sec.minLength')))}{num('password', 'history', String(t('idn.sec.history')))}{num('password', 'expiry_days', String(t('idn.sec.expiry')))}<div className="space-y-1.5 pt-6">{chk('password', 'upper', String(t('idn.sec.upper')))}{chk('password', 'lower', String(t('idn.sec.lower')))}{chk('password', 'digit', String(t('idn.sec.digit')))}{chk('password', 'symbol', String(t('idn.sec.symbol')))}{chk('password', 'breached_check', String(t('idn.sec.breached')))}</div></div></Card>
          <Card><h3 className="mb-3 font-bold text-navy-900">{t('idn.sec.lockout')}</h3><div className="grid gap-3 sm:grid-cols-2">{num('lockout', 'max_attempts', String(t('idn.sec.attempts')))}{num('lockout', 'minutes', String(t('idn.sec.lockMinutes')))}</div>
            <h3 className="mb-3 mt-5 font-bold text-navy-900">{t('idn.sec.sessions')}</h3><div className="grid gap-3 sm:grid-cols-2">{num('sessions', 'idle_minutes', String(t('idn.sec.idle')))}{num('sessions', 'absolute_hours', String(t('idn.sec.absolute')))}</div></Card>
          <Card className="lg:col-span-2"><h3 className="mb-3 font-bold text-navy-900">{t('idn.sec.mfa')}</h3>
            <div className="grid gap-4 md:grid-cols-2">
              <Field label={t('idn.sec.enforce')}><div className="flex max-h-32 flex-wrap gap-1.5 overflow-y-auto">{res.data?.meta.roles.map((r) => { const on = p.mfa.enforce_roles.includes(r.slug); return <button key={r.slug} type="button" onClick={() => toggle('mfa', 'enforce_roles', r.slug)} className={`rounded-full px-3 py-1 text-xs font-semibold ${on ? 'bg-navy-900 text-gold-300' : 'bg-navy-50 text-navy-700'}`}>{L({ ar: r.name_ar, en: r.name_en })}</button> })}</div></Field>
              <div className="space-y-3"><Field label={t('idn.sec.methods')}><div className="flex gap-4 text-sm">{['totp', 'email', 'sms'].map((m) => <label key={m} className="flex items-center gap-1.5"><input type="checkbox" className="accent-gold-600" checked={p.mfa.methods.includes(m)} onChange={() => toggle('mfa', 'methods', m)} />{t(`idn.login.methods.${m}`)}</label>)}</div></Field>{num('mfa', 'remember_days', String(t('idn.sec.remember')))}</div>
            </div>
          </Card>
        </div>
      )}
      {can('sessions.manage') && (
        <Card padded={false} className="mt-5">
          <div className="flex items-center justify-between gap-3 p-4"><h3 className="font-bold text-navy-900">{t('idn.sec.active')}</h3><input className={`${cls} w-64`} placeholder={String(t('idn.sec.search'))} value={q} onChange={(e) => setQ(e.target.value)} /></div>
          <Table head={[t('idn.sec.user'), t('idn.sec.device'), 'IP', t('idn.acct.lastSeen'), '']}>
            {sessions.data?.data.map((s: any) => (
              <tr key={s.id}>
                <Td><div className="font-semibold">{s.user?.name_ar || s.user?.name}</div><div className="text-xs text-slate-500">{s.user?.email}</div></Td>
                <Td className="max-w-56 truncate text-xs">{s.user_agent}</Td><Td className="text-xs" >{s.ip}</Td><Td className="text-xs">{fmt.date(s.last_seen_at, { dateStyle: 'short', timeStyle: 'short' } as any)}</Td>
                <Td><div className="flex flex-wrap gap-1">
                  <Button size="sm" variant="outline" onClick={() => act(() => api.delete(`/admin/auth-sessions/${s.id}`))}>{t('idn.sec.terminate')}</Button>
                  <Button size="sm" variant="outline" onClick={() => act(() => api.post(`/admin/users/${s.user_id}/sessions/terminate`))}>{t('idn.sec.terminateAll')}</Button>
                  <Button size="sm" variant="outline" onClick={() => act(() => api.post(`/admin/users/${s.user_id}/unlock`))}>{t('idn.sec.unlock')}</Button>
                  <Button size="sm" variant="outline" onClick={() => act(() => api.post(`/admin/users/${s.user_id}/mfa/reset`))}>{t('idn.sec.resetMfa')}</Button>
                </div></Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
      {step.dialog}
    </>
  )
}
