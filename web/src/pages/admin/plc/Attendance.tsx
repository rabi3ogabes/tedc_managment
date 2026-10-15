import { AlertTriangle, Download, Lock, LockOpen, MapPin, QrCode, RefreshCw, ShieldOff, Users, Video } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Empty, ErrorState, Field, Modal, PageHeader, Spinner, StatCard } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { STATE_TONE, type PlcState } from './plcApi'

type Status = 'expected' | 'present' | 'late' | 'left_early' | 'absent' | 'excused'
type Row = { registration_id: string; employee_id: string; name?: string | null; school?: string | null; status: Status; channel?: 'in_person' | 'remote' | null; method?: string | null; check_in_at?: string | null; check_out_at?: string | null; minutes: number; late_by?: number | null; corrected: boolean; correction_reason?: string | null }
type Roster = {
  session: { id: string; sequence: number; title_ar: string; title_en?: string | null; starts_at: string; ends_at: string; mode: 'in_person' | 'online' | 'hybrid'; status: string; room?: string | null; location_text?: string | null; has_link: boolean; ended: boolean; started: boolean; locked_at?: string | null; locked_by?: string | null }
  workshop: { id: string; state: PlcState; title_ar: string; title_en?: string | null }
  policy: { late_minutes: number; early_leave_minutes: number; rotation_seconds: number }
  counts: { expected: number; present: number; late: number; left_early: number; absent: number; excused: number; pending: number; in_person: number; remote: number }
  rows: Row[]; qr: { payload: string; expires_at: string; rotation_seconds: number } | null
  attempts: { at: string; name?: string | null; code: string; message: string }[]
  can: { manage: boolean; correct: boolean; lock: boolean; unlock: boolean; code: boolean }
}
const TONE: Record<Status, 'gray' | 'green' | 'amber' | 'red' | 'navy' | 'gold'> = { expected: 'gray', present: 'green', late: 'amber', left_early: 'gold', absent: 'red', excused: 'navy' }

/** The live roster of one session: the rotating code, who is in and through which channel, corrections with a reason, the lock, and rejected attempts. */
export function PlcSessionAttendance() {
  const { t, i18n } = useTranslation()
  const { id, sid } = useParams()
  const en = i18n.language === 'en'
  const res = useGet<{ data: Roster }>(`/admin/plc/workshops/${id}/sessions/${sid}/attendance`, undefined, { staleTime: 0, refetchInterval: 10_000 })
  const [code, setCode] = useState<{ payload: string; expires_at: string; rotation_seconds: number } | null>(null)
  const [showCode, setShowCode] = useState(false)
  const [fix, setFix] = useState<{ row: Row; status: Status; minutes: string; reason: string } | null>(null)
  const [revoking, setRevoking] = useState(false)
  const [unlocking, setUnlocking] = useState(false)
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const d = res.data?.data
  const rotation = (code?.rotation_seconds ?? d?.policy.rotation_seconds ?? 30) * 1000
  useEffect(() => {
    if (!showCode) return
    let alive = true
    const pull = async () => { try { const { data } = await api.post(`/admin/plc/workshops/${id}/sessions/${sid}/attendance/code`); if (alive) setCode(data.data) } catch (e) { toast(errorMessage(e), 'error'); setShowCode(false) } }
    void pull()
    const h = window.setInterval(pull, rotation)
    return () => { alive = false; window.clearInterval(h) }
  }, [showCode, id, sid, rotation])
  if (res.isLoading && !d) return <Spinner />
  if (res.error || !d) return <ErrorState error={res.error} onRetry={() => res.refetch()} home={`/admin/communities/workshops/${id}/schedule`} />
  const s = d.session
  const act = async (path: string, body?: Record<string, unknown>, msg?: string) => { setBusy(true); try { await api.post(`/admin/plc/workshops/${id}/sessions/${sid}/attendance/${path}`, body); if (msg) toast(msg); await res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) } }
  const time = (iso?: string | null) => (iso ? new Date(iso).toLocaleTimeString(en ? 'en-GB' : 'ar-QA', { hour: '2-digit', minute: '2-digit' }) : '—')

  return (
    <>
      <PageHeader title={<span className="flex flex-wrap items-center gap-3">{t('plc.attendance.title')} · {t('plc.schedule.sessionN', { n: s.sequence })}<Badge color={STATE_TONE[d.workshop.state]}>{t(`plc.state.${d.workshop.state}`)}</Badge>{s.locked_at && <Badge color="navy"><Lock className="me-1 inline size-3" />{t('plc.attendance.locked', { at: fmt.dateTime(s.locked_at) })}</Badge>}</span>}
        subtitle={<span>{en && d.workshop.title_en ? d.workshop.title_en : d.workshop.title_ar} · {fmt.dateTime(s.starts_at)} → {time(s.ends_at)} · {t(`plc.mode.${s.mode}`)}{s.room ? ` · ${s.room}` : s.location_text ? ` · ${s.location_text}` : ''}</span>}
        actions={<div className="flex flex-wrap gap-2">
          <Button variant="outline" to={`/admin/communities/workshops/${id}/schedule`}>{t('plc.detail.back')}</Button>
          <Button variant="outline" to={`/admin/communities/workshops/${id}/attendance`}>{t('plc.attendance.report')}</Button>
          {d.can.code && <Button variant="gold" icon={<QrCode className="size-4" />} onClick={() => setShowCode(!showCode)}>{t('plc.attendance.issue')}</Button>}
          {d.can.lock && <Button variant="outline" icon={<Lock className="size-4" />} loading={busy} onClick={() => { if (window.confirm(String(t('plc.attendance.lockHint')))) void act('lock', undefined, String(t('plc.attendance.lockDone', { n: d.counts.pending }))) }}>{t('plc.attendance.lock')}</Button>}
          {d.can.unlock && <Button variant="ghost" icon={<LockOpen className="size-4" />} onClick={() => { setReason(''); setUnlocking(true) }}>{t('plc.attendance.unlock')}</Button>}
        </div>} />
      <div className="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <StatCard label={t('plc.attendance.expected')} value={d.counts.expected} />
        <StatCard label={t('plc.attendance.present')} value={d.counts.present} accent />
        <StatCard label={t('plc.attendance.late')} value={d.counts.late} />
        <StatCard label={t('plc.attendance.leftEarly')} value={d.counts.left_early} />
        <StatCard label={t('plc.attendance.absent')} value={s.ended ? d.counts.absent : d.counts.pending} hint={s.ended ? undefined : t('plc.attendance.pending')} />
        <StatCard label={t('plc.attendance.excused')} value={d.counts.excused} />
      </div>
      <div className="grid gap-4 lg:grid-cols-[1fr_320px]">
        <Card padded={false}>
          <div className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-xs text-slate-500"><span className="inline-flex items-center gap-1"><Users className="size-3.5" />{t('plc.attendance.roster')} · {t('plc.attendance.policy', { late: d.policy.late_minutes, early: d.policy.early_leave_minutes })}</span><span className="inline-flex items-center gap-1"><RefreshCw className="size-3" />{t('plc.attendance.refresh')}{s.mode === 'hybrid' ? ` · ${t('plc.attendance.inPerson')} ${d.counts.in_person} · ${t('plc.attendance.remote')} ${d.counts.remote}` : ''}</span></div>
          {!d.rows.length ? <Empty text={String(t('plc.attendance.noRows'))} /> : (
            <table className="w-full text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr><th className="px-4 py-2 text-start">{t('plc.attendance.name')}</th><th className="px-4 py-2 text-start">{t('plc.attendance.school')}</th><th className="px-4 py-2 text-start">{t('common.status')}</th><th className="px-4 py-2 text-start">{t('plc.attendance.in')}</th><th className="px-4 py-2 text-start">{t('plc.attendance.out')}</th><th className="px-4 py-2 text-start">{t('plc.attendance.minutes')}</th><th /></tr></thead>
              <tbody className="divide-y divide-slate-100">{d.rows.map((r) => (
                <tr key={r.registration_id}>
                  <td className="px-4 py-2 font-semibold text-navy-900">{r.name}{r.corrected && <span title={r.correction_reason ?? ''} className="ms-1 text-[10px] font-normal text-gold-700">({t('plc.attendance.corrected')})</span>}</td>
                  <td className="px-4 py-2 text-xs text-slate-500">{r.school}</td>
                  <td className="px-4 py-2"><Badge color={TONE[r.status]}>{t(`plc.attendance.status.${r.status}`)}</Badge>{r.channel && <span className="ms-1 inline-flex items-center gap-0.5 text-[10px] text-slate-500">{r.channel === 'remote' ? <Video className="size-3" /> : <MapPin className="size-3" />}{t(`plc.attendance.channel.${r.channel}`)}</span>}{r.late_by ? <span className="ms-1 text-[10px] text-amber-700">+{r.late_by}′</span> : null}</td>
                  <td className="px-4 py-2 text-xs tabular-nums">{time(r.check_in_at)}</td><td className="px-4 py-2 text-xs tabular-nums">{time(r.check_out_at)}</td><td className="px-4 py-2 text-xs tabular-nums">{r.minutes}</td>
                  <td className="px-2 py-2 text-end">{d.can.correct && <Button size="sm" variant="ghost" onClick={() => setFix({ row: r, status: r.status === 'expected' ? 'present' : r.status, minutes: String(r.minutes || ''), reason: '' })}>{t('plc.attendance.correct')}</Button>}</td>
                </tr>))}</tbody></table>
          )}
        </Card>
        <div className="space-y-4">
          {showCode && (
            <Card className="text-center">
              <CardTitle subtitle={t('plc.attendance.codeHint')}>{t('plc.attendance.code')}</CardTitle>
              {code ? <><div className="mx-auto inline-block rounded-2xl bg-white p-3 shadow-sm"><QRCodeSVG value={code.payload} size={220} level="M" /></div><div className="mt-2 text-xs text-slate-500">{t('plc.attendance.rotates', { s: code.rotation_seconds })}</div></> : <Spinner />}
              {d.can.manage && <Button className="mt-3" size="sm" variant="ghost" icon={<ShieldOff className="size-3.5" />} onClick={() => { setReason(''); setRevoking(true) }}>{t('plc.attendance.revoke')}</Button>}
            </Card>
          )}
          {!d.can.code && s.mode === 'online' && <Card><p className="text-sm text-slate-600">{t('plc.attendance.noCode')}</p></Card>}
          <Card>
            <CardTitle>{t('plc.attendance.attempts')}</CardTitle>
            {!d.attempts.length ? <p className="text-sm text-slate-400">{t('plc.attendance.noAttempts')}</p> : <ul className="space-y-1 text-xs">{d.attempts.map((a, i) => <li key={i} className="flex items-start gap-1.5"><AlertTriangle className="mt-0.5 size-3 shrink-0 text-amber-600" /><span><b>{a.name ?? '—'}</b> · {fmt.dateTime(a.at)} · {a.message}</span></li>)}</ul>}
          </Card>
        </div>
      </div>

      <Modal open={!!fix} onClose={() => setFix(null)} title={t('plc.attendance.correctTitle')}>
        {fix && (
          <div className="space-y-3">
            <div className="text-sm font-semibold text-navy-900">{fix.row.name}</div>
            <Field label={t('plc.attendance.newStatus')}><select className="input" value={fix.status} onChange={(e) => setFix({ ...fix, status: e.target.value as Status })}>{(['present', 'late', 'left_early', 'absent', 'excused'] as Status[]).map((x) => <option key={x} value={x}>{t(`plc.attendance.status.${x}`)}</option>)}</select></Field>
            {fix.status === 'left_early' && <Field label={t('plc.attendance.minutesLabel')}><input type="number" min={1} className="input" value={fix.minutes} onChange={(e) => setFix({ ...fix, minutes: e.target.value })} /></Field>}
            <Field label={t('plc.attendance.reason')} hint={t('plc.attendance.reasonHint')}><input className="input" value={fix.reason} onChange={(e) => setFix({ ...fix, reason: e.target.value })} /></Field>
            <div className="flex justify-end gap-2"><Button variant="outline" onClick={() => setFix(null)}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} disabled={!fix.reason.trim()} onClick={async () => { await act('correct', { registration_id: fix.row.registration_id, status: fix.status, minutes: fix.status === 'left_early' && fix.minutes ? Number(fix.minutes) : undefined, reason: fix.reason }, String(t('plc.attendance.saved'))); setFix(null) }}>{t('common.save')}</Button></div>
          </div>
        )}
      </Modal>
      <Modal open={revoking} onClose={() => setRevoking(false)} title={t('plc.attendance.revoke')}>
        <p className="text-sm text-slate-600">{t('plc.attendance.revokeHint')}</p>
        <Field label={t('plc.attendance.revokeReason')} className="mt-3"><input className="input" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setRevoking(false)}>{t('common.cancel')}</Button><Button variant="primary" loading={busy} onClick={async () => { await act('revoke', { reason: reason || undefined }, String(t('plc.attendance.revoked'))); setRevoking(false); setCode(null) }}>{t('plc.attendance.revoke')}</Button></div>
      </Modal>
      <Modal open={unlocking} onClose={() => setUnlocking(false)} title={t('plc.attendance.unlock')}>
        <Field label={t('plc.attendance.unlockReason')}><input className="input" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setUnlocking(false)}>{t('common.cancel')}</Button><Button variant="primary" loading={busy} disabled={!reason.trim()} onClick={async () => { await act('unlock', { reason }, String(t('plc.attendance.unlocked'))); setUnlocking(false) }}>{t('plc.attendance.unlock')}</Button></div>
      </Modal>
    </>
  )
}

type Report = {
  sessions: { id: string; sequence: number; title_ar: string; starts_at: string; mode: string; locked: boolean; expected: number; present: number; late: number; left_early: number; absent: number; excused: number; rate: number | null }[]
  rows: { registration_id: string; name?: string | null; school?: string | null; percent: number; cells: { session_id: string; status: Status; minutes: number; channel?: string | null }[] }[]
  by_school: { school?: string | null; people: number; percent: number }[]
  overall: { people: number; percent: number | null; min_attendance_percent?: number | null; meeting: number }
}

/** The workshop's attendance report: every trainee against every session, the rate per session and per school, CSV export. */
export function PlcAttendanceReport() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const res = useGet<{ data: Report }>(`/admin/plc/workshops/${id}/attendance`, undefined, { staleTime: 0 })
  if (res.isLoading && !res.data) return <Spinner />
  if (res.error || !res.data) return <ErrorState error={res.error} onRetry={() => res.refetch()} home={`/admin/communities/workshops/${id}`} />
  const d = res.data.data
  return (
    <>
      <PageHeader title={t('plc.attendance.report')} subtitle={t('plc.attendance.reportHint')} actions={<div className="flex gap-2"><Button variant="outline" to={`/admin/communities/workshops/${id}`}>{t('plc.detail.back')}</Button><Button variant="gold" icon={<Download className="size-4" />} onClick={() => void downloadFile(`/admin/plc/workshops/${id}/attendance/export`, 'attendance.csv')}>{t('plc.attendance.export')}</Button></div>} />
      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <StatCard label={t('plc.attendance.overall')} value={d.overall.percent != null ? `${d.overall.percent}%` : '—'} accent />
        <StatCard label={t('plc.attendance.people', { n: d.overall.people })} value={d.overall.people} />
        <StatCard label={t('plc.attendance.meeting', { n: d.overall.meeting, min: d.overall.min_attendance_percent ?? 0 })} value={d.overall.meeting} />
      </div>
      {!d.sessions.length ? <Card><Empty text={String(t('plc.attendance.notYet'))} /></Card> : (
        <div className="grid gap-4 lg:grid-cols-[1fr_280px]">
          <Card padded={false}>
            <div className="overflow-auto"><table className="w-full text-xs"><thead className="bg-slate-50 text-slate-500"><tr><th className="sticky start-0 bg-slate-50 px-3 py-2 text-start">{t('plc.attendance.name')}</th><th className="px-3 py-2 text-start">{t('plc.attendance.school')}</th><th className="px-3 py-2 text-start">{t('plc.attendance.rate')}</th>{d.sessions.map((s) => <th key={s.id} className="px-2 py-2 text-center"><Link to={`/admin/communities/workshops/${id}/sessions/${s.id}/attendance`} className="hover:text-gold-700">{t('plc.schedule.sessionN', { n: s.sequence })}</Link><div className="font-normal">{fmt.date(s.starts_at)}{s.locked && <Lock className="ms-0.5 inline size-2.5" />}</div></th>)}</tr></thead>
              <tbody className="divide-y divide-slate-100">{d.rows.map((r) => (
                <tr key={r.registration_id}><td className="sticky start-0 bg-white px-3 py-1.5 font-semibold text-navy-900">{r.name}</td><td className="px-3 py-1.5 text-slate-500">{r.school}</td><td className="px-3 py-1.5 tabular-nums font-semibold">{r.percent}%</td>{r.cells.map((c) => <td key={c.session_id} className="px-2 py-1.5 text-center"><Badge color={TONE[c.status]}>{t(`plc.attendance.status.${c.status}`)}</Badge></td>)}</tr>))}
                <tr className="bg-slate-50 font-semibold"><td className="sticky start-0 bg-slate-50 px-3 py-1.5">{t('plc.attendance.rate')}</td><td /><td className="px-3 py-1.5">{d.overall.percent != null ? `${d.overall.percent}%` : '—'}</td>{d.sessions.map((s) => <td key={s.id} className="px-2 py-1.5 text-center tabular-nums">{s.rate != null ? `${s.rate}%` : '—'}</td>)}</tr>
              </tbody></table></div>
          </Card>
          <Card>
            <CardTitle>{t('plc.attendance.bySchool')}</CardTitle>
            <ul className="space-y-2 text-sm">{d.by_school.map((b, i) => <li key={i}><div className="flex items-center justify-between"><span className="truncate">{b.school ?? '—'}</span><span className="tabular-nums font-semibold">{b.percent}%</span></div><div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-gradient-to-l from-gold-400 to-gold-600" style={{ width: `${b.percent}%` }} /></div><div className="text-[11px] text-slate-500">{t('plc.attendance.people', { n: b.people })}</div></li>)}</ul>
            <div className="mt-4 text-xs text-slate-500">{i18n.language === 'en' ? 'Sessions' : t('plc.attendance.sessionsHead')}: {d.sessions.map((s) => `${s.sequence}: ${s.present}/${s.expected}`).join(' · ')}</div>
          </Card>
        </div>
      )}
    </>
  )
}
