/* eslint-disable @typescript-eslint/no-explicit-any */
import { Paperclip } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

const tone = (s: string) => (s === 'passed' || s === 'exempted' ? 'green' : s === 'failed' ? 'red' : 'gray')

/** How a trainee stands against the passing policy: each criterion with its evidence, the hours, exceptions and certificates. */
export default function PassStatusModal({ registrationId, name, onClose, onChanged }: { registrationId: string; name?: string; onClose: () => void; onChanged: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const res = useGet<{ data: any }>(`/admin/registrations/${registrationId}/pass-status`, undefined, { staleTime: 0 })
  const [form, setForm] = useState({ criterion: 'attendance', reason: '' })
  const file = useRef<HTMLInputElement>(null)
  const [busy, setBusy] = useState(false)
  const d = res.data?.data
  const grant = async () => {
    const f = file.current?.files?.[0]
    if (!f) { toast(t('passing.attachment'), 'error'); return }
    const fd = new FormData(); fd.append('criterion', form.criterion); fd.append('reason', form.reason); fd.append('attachment', f)
    setBusy(true)
    try { await api.post(`/admin/registrations/${registrationId}/exceptions`, fd); toast(t('passing.granted')); setForm({ ...form, reason: '' }); await res.refetch(); onChanged() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const revoke = async (id: string) => { try { await api.delete(`/admin/pass-exceptions/${id}`); toast(t('passing.revoked')); await res.refetch(); onChanged() } catch (e) { toast(errorMessage(e), 'error') } }
  const evidence = (c: any) => {
    if (c.key === 'tasks') return `${c.evidence.done}/${c.evidence.total}`
    if (c.key === 'evaluation') return c.evidence.done ? '✓' : '—'
    return `${Math.round(c.value)}% / ${Math.round(c.min)}%`
  }
  return (
    <Modal open onClose={onClose} title={`${t('passing.details')}${name ? ` — ${name}` : ''}`} wide>
      {!d ? <Spinner /> : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2"><Badge color={tone(d.pass_status)}>{t(`passing.status.${d.pass_status}`)}</Badge>{d.passed_via && <span className="text-xs text-slate-500">{t(`passing.via.${d.passed_via}`)}</span>}{d.weighted_score !== null && d.policy.mode === 'weighted' && <span className="ms-auto text-sm font-bold">{t('passing.score')}: {fmt.number(d.weighted_score, 1)}% / {Math.round(d.policy.pass_threshold)}%</span>}</div>
          <div className="space-y-2">{d.criteria.map((c: any) => (
            <div key={c.key} className="flex flex-wrap items-center gap-3 rounded-xl border border-navy-100 p-3 text-sm">
              <b className="min-w-32 text-navy-900">{t(`passing.keys.${c.key}`)}</b>
              <span className="tabular-nums">{c.applicable ? evidence(c) : t('passing.na')}</span>
              {d.policy.mode === 'weighted' && c.weight > 0 && <span className="text-xs text-slate-400">{c.weight}%</span>}
              <span className="ms-auto">{!c.applicable ? <Badge color="gray">{t('passing.na')}</Badge> : c.exempted ? <Badge color="gold">{t('passing.exempt')}</Badge> : <Badge color={c.met ? 'green' : 'red'}>{c.met ? t('passing.met') : t('passing.notMet')}</Badge>}</span>
            </div>))}</div>
          <p className="text-xs text-slate-500">{t('passing.hoursLine', { used: d.hours.used, total: d.hours.total, actual: d.hours.actual })}</p>
          <div className="space-y-2"><h4 className="font-bold text-navy-900">{t('passing.exceptions')}</h4>
            {d.exceptions.map((e: any) => (
              <div key={e.id} className="flex flex-wrap items-center gap-2 rounded-xl bg-ivory p-3 text-sm"><Badge color={e.revoked_at ? 'gray' : 'gold'}>{t(`passing.keys.${e.criterion}`)}</Badge><span className="flex-1">{e.reason}</span><span className="text-xs text-slate-400">{fmt.date(e.granted_at)}{e.revoked_at ? ` · ${t('passing.revokedAt')}` : ''}</span>
                {can('pass_exceptions.grant') && !e.revoked_at && <Button size="sm" variant="outline" onClick={() => void revoke(e.id)}>{t('passing.revoke')}</Button>}</div>))}
            {can('pass_exceptions.grant') && (
              <div className="grid gap-2 rounded-xl border border-dashed border-navy-200 p-3 sm:grid-cols-3">
                <Field label={t('passing.criterion')}><select className="input" value={form.criterion} onChange={(e) => setForm({ ...form, criterion: e.target.value })}>{['attendance', 'participation', 'tasks', 'assessments', 'course', 'evaluation'].map((k) => <option key={k} value={k}>{t(`passing.keys.${k}`)}</option>)}</select></Field>
                <Field label={t('passing.reason')} className="sm:col-span-2"><input className="input" value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} /></Field>
                <label className="flex items-center gap-2 text-sm sm:col-span-2"><Paperclip className="size-4" /><input ref={file} type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" aria-label={t('passing.attachment')} /></label>
                <Button variant="gold" loading={busy} disabled={form.reason.length < 5} onClick={() => void grant()}>{t('passing.grant')}</Button>
              </div>)}
          </div>
        </div>
      )}
    </Modal>
  )
}
