import ExternalCourseModal from './ExternalCourseModal'
import MyProgress from './MyProgress'
import TraineeEvaluation from './TraineeEvaluation'
import clsx from 'clsx'
import { CalendarPlus, Check, Download, FileText, PlayCircle, QrCode, Star } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { Button, Card, Empty, Field, Modal, PageHeader, Progress, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Registration } from '@/lib/types'
import MaterialSocial from '@/components/social/MaterialSocial'

type Material = { id: string; title: string; type: string; url?: string; has_file: boolean }

export default function MyTraining() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Registration[] }>('/me/registrations')
  const [evaluating, setEvaluating] = useState<Registration | null>(null)
  // Arriving from a programme's form link (/f/t/{code}): that registration's evaluation opens by itself.
  const [search, setSearch] = useSearchParams()
  const wanted = search.get('evaluate')
  const arrived = wanted ? data?.data.find((r) => r.id === wanted && ['approved', 'completed'].includes(r.status) && !r.evaluation_completed) ?? null : null
  const current = evaluating ?? arrived
  const [materialsFor, setMaterialsFor] = useState<Registration | null>(null)

  const [withdrawing, setWithdrawing] = useState<Registration | null>(null)
  const [externalFor, setExternalFor] = useState<Registration | null>(null)
  const [progressFor, setProgressFor] = useState<string | null>(null)
  const [excusing, setExcusing] = useState<Registration | null>(null)
  const [showQr, setShowQr] = useState(false)

  return (
    <>
      <PageHeader title={t('portal.myTraining')} actions={<div className="flex gap-2"><Button variant="outline" icon={<QrCode className="size-4" />} onClick={() => setShowQr(true)}>{t('ops.my.qr')}</Button><Button variant="outline" icon={<CalendarPlus className="size-4" />} onClick={() => downloadFile('/me/calendar.ics', 'tedc-training.ics')}>{t('portal.calendarFeed')}</Button></div>} />
      {isLoading ? <Spinner /> : !data?.data.length ? <Card><Empty /></Card> : (
        <div className="grid gap-4 lg:grid-cols-2">
          {data.data.map((r) => (
            <Card key={r.id}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <Link to={`/programs/${r.program?.code}`} className="text-lg font-bold text-navy-900 hover:text-link">{r.program?.title}</Link>
                  <div className="text-xs text-slate-400">{fmt.date(r.program?.start_date)} – {fmt.date(r.program?.end_date)} · {t(`sources.${r.source}`)}</div>
                </div>
                <StatusBadge status={r.status} />
              </div>
              {['pending_manager', 'pending'].includes(r.status) && <ApprovalPath status={r.status} />}
              <div className="mt-4 grid grid-cols-3 gap-3 text-center text-xs">
                <div><div className="mb-1 text-slate-500">{t('admin.registrations.attendance')}</div><Progress value={r.attendance_percent} tone={r.attendance_percent >= (r.program?.min_attendance_percent ?? 80) ? 'green' : 'gold'} /><div className="mt-1 font-bold">{fmt.percent(r.attendance_percent)}</div></div>
                <div><div className="mb-1 text-slate-500">{t('admin.programs.tasks')}</div><StatusBadge status={r.tasks_completed ? 'approved' : 'pending'} /></div>
                <div><div className="mb-1 text-slate-500">{t('admin.registrations.certificate')}</div><StatusBadge status={r.certificate_status} /></div>
              </div>
              <div className="mt-4 flex flex-wrap gap-2">
                {['approved', 'completed'].includes(r.status) && r.has_course && <Button size="sm" variant="gold" icon={<PlayCircle className="size-4" />} to={`/portal/learn/${r.id}`}>{t('learn.title')} · {fmt.percent(r.course_percent ?? 0)}</Button>}
                {['approved', 'completed'].includes(r.status) && <Button size="sm" variant="outline" onClick={() => setProgressFor(r.id)}>{t('passing.my.open')}</Button>}
                {['approved', 'completed'].includes(r.status) && <Button size="sm" variant="outline" icon={<FileText className="size-4" />} onClick={() => setMaterialsFor(r)}>{t('admin.programs.materials')}</Button>}
                {['approved', 'completed'].includes(r.status) && !r.evaluation_completed && <Button size="sm" variant="gold" icon={<Star className="size-4" />} onClick={() => setEvaluating(r)}>{t('portal.evaluate')}</Button>}
                {r.program?.external_platform && ['approved', 'completed'].includes(r.status) && !r.certificate && <Button size="sm" variant="gold" onClick={() => setExternalFor(r)}>{r.program.external_platform.name}</Button>}
                {r.certificate && <Button size="sm" variant="primary" icon={<Download className="size-4" />} onClick={() => downloadFile(`/certificates/${r.certificate!.id}/download`, 'certificate.pdf', true)}>PDF</Button>}
                {r.status === 'approved' && <Button size="sm" variant="ghost" onClick={() => setExcusing(r)}>{t('ops.my.excuse')}</Button>}
                {['pending_manager', 'pending', 'approved', 'waitlisted'].includes(r.status) && <Button size="sm" variant="ghost" onClick={() => setWithdrawing(r)}>{t('admission.withdraw.button')}</Button>}
              </div>
            </Card>
          ))}
        </div>
      )}
      {externalFor && <ExternalCourseModal registration={externalFor} onClose={() => setExternalFor(null)} />}
      {progressFor && <MyProgress registrationId={progressFor} onClose={() => setProgressFor(null)} />}
      {excusing && <ExcuseModal registration={excusing} onClose={() => setExcusing(null)} />}
      {showQr && <MyQrModal onClose={() => setShowQr(false)} />}
      {withdrawing && <WithdrawModal registration={withdrawing} onClose={(changed) => { setWithdrawing(null); if (changed) refetch() }} />}
      {current && <TraineeEvaluation registration={current} onClose={() => { setEvaluating(null); if (wanted) { search.delete('evaluate'); setSearch(search, { replace: true }) } refetch() }} />}
      {materialsFor && <MaterialsModal registration={materialsFor} onClose={() => setMaterialsFor(null)} />}
    </>
  )
}

function MaterialsModal({ registration, onClose }: { registration: Registration; onClose: () => void }) {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: Material[] }>(`/me/registrations/${registration.id}/materials`)
  const [picked, setPicked] = useState<Material | null>(null)
  const open = async (m: Material) => {
    try {
      if (m.url) { window.open(m.url, '_blank', 'noopener,noreferrer'); return }
      const { data: res } = await api.get(`/me/materials/${m.id}/download`)
      window.open(res.data.url, '_blank', 'noopener,noreferrer')
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal open onClose={onClose} title={t('admin.programs.materials')} wide>
      {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
        <div className="grid gap-4 md:grid-cols-[minmax(0,15rem)_1fr]">
          <ul className={clsx('space-y-2', picked && 'hidden md:block')}>{data.data.map((m) => (
            <li key={m.id}>
              <button type="button" onClick={() => setPicked(m)} aria-current={picked?.id === m.id}
                className={clsx('flex w-full items-center gap-3 rounded-xl p-3 text-start text-sm font-semibold text-navy-800 transition-colors', picked?.id === m.id ? 'bg-gold-100 ring-1 ring-gold-300' : 'bg-ivory hover:bg-gold-50')}>
                <FileText className="size-5 shrink-0 text-gold-600" /><bdi className="min-w-0 flex-1 truncate">{m.title}</bdi>
              </button>
            </li>
          ))}</ul>
          {picked ? (
            <div className="min-w-0 space-y-4">
              <div className="flex flex-wrap items-center gap-2">
                <button type="button" onClick={() => setPicked(null)} className="rounded-lg px-2 py-1 text-xs font-semibold text-slate-500 hover:bg-navy-50 md:hidden">{t('soc.space.back')}</button>
                <h4 className="min-w-0 flex-1 truncate font-display text-lg font-bold text-navy-900"><bdi>{picked.title}</bdi></h4>
                <Button size="sm" variant="outline" icon={<Download className="size-4" />} onClick={() => void open(picked)}>{t('soc.material.open')}</Button>
              </div>
              <MaterialSocial key={picked.id} materialId={picked.id} />
            </div>
          ) : <p className="hidden self-center text-center text-sm text-slate-500 md:block">{t('soc.material.pick')}</p>}
        </div>
      )}
    </Modal>
  )
}

/** Where the registration is on its way: direct manager, then the training centre. */
function ApprovalPath({ status }: { status: string }) {
  const { t } = useTranslation()
  const steps = [{ id: 'manager', label: t('admission.path.manager'), done: status === 'pending' }, { id: 'center', label: t('admission.path.center'), done: false }]
  return (
    <ol className="mt-3 flex items-center gap-2 text-xs" aria-label={t('admission.path.title')}>
      {steps.map((s, i) => (
        <li key={s.id} className="flex items-center gap-2">
          <span className={s.done ? 'grid size-5 place-items-center rounded-full bg-success text-white' : (i === (status === 'pending' ? 1 : 0) ? 'grid size-5 place-items-center rounded-full bg-gold-500 font-bold text-navy-950' : 'grid size-5 place-items-center rounded-full bg-navy-100 text-slate-500')}>{s.done ? <Check className="size-3" /> : i + 1}</span>
          <span className={i === (status === 'pending' ? 1 : 0) ? 'font-bold text-navy-900' : 'text-slate-500'}>{s.label}</span>
          {i < steps.length - 1 && <span className="h-px w-6 bg-navy-200" aria-hidden />}
        </li>
      ))}
    </ol>
  )
}

type Reason = { code: string; label_ar: string; label_en: string; requires_attachment: boolean }

/** Withdrawing: free before the manager approved, otherwise a request with a reason (and a document when the reason needs one). */
function WithdrawModal({ registration, onClose }: { registration: Registration; onClose: (changed: boolean) => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const reasons = useGet<{ data: Reason[] }>('/me/withdrawal-reasons', undefined, { staleTime: 60_000 })
  const free = ['pending_manager', 'waitlisted'].includes(registration.status)
  const [code, setCode] = useState('')
  const [text, setText] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const reason = reasons.data?.data.find((r) => r.code === code)
  const send = async () => {
    setBusy(true)
    try {
      const body = new FormData()
      if (code) body.append('reason_code', code)
      if (text) body.append('reason_text', text)
      if (file) body.append('attachments[]', file)
      const { data } = await api.post(`/me/registrations/${registration.id}/withdraw`, body)
      toast(data.data.mode === 'direct' ? t('admission.withdraw.done') : t('admission.withdraw.requested')); onClose(true)
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open onClose={() => onClose(false)} title={`${t('admission.withdraw.title')} — ${registration.program?.title}`}>
      <div className="space-y-4">
        <p className="rounded-xl bg-ivory p-3 text-sm text-slate-600">{free ? t('admission.withdraw.free') : t('admission.withdraw.needs', { supervisor: registration.status === 'approved' ? t('admission.withdraw.supervisor') : '' })}</p>
        {!free && (
          <>
            <Field label={t('admission.withdraw.reason')}><select className="input" value={code} onChange={(e) => setCode(e.target.value)}><option value="" />{reasons.data?.data.map((r) => <option key={r.code} value={r.code}>{ar ? r.label_ar : r.label_en}</option>)}</select></Field>
            <Field label={t('admission.withdraw.details')}><textarea className="input min-h-20" value={text} onChange={(e) => setText(e.target.value)} /></Field>
            <Field label={t('admission.withdraw.attach')} hint={reason?.requires_attachment ? t('admission.withdraw.attachRequired') : undefined}><input type="file" accept=".pdf,.jpg,.jpeg,.png" className="input" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></Field>
          </>
        )}
        <Button variant="gold" loading={busy} disabled={!free && (!code || (!!reason?.requires_attachment && !file))} onClick={() => void send()}>{free ? t('admission.withdraw.button') : t('admission.withdraw.send')}</Button>
      </div>
    </Modal>
  )
}

/** My personal attendance QR: authorised staff scan it to record attendance. */
function MyQrModal({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: { payload: string } }>('/me/attendance-qr', undefined, { staleTime: 0 })
  return (
    <Modal open onClose={onClose} title={t('ops.my.qr')}>
      {isLoading || !data ? <Spinner /> : <div className="space-y-3 text-center"><div className="mx-auto w-fit rounded-2xl bg-white p-4 shadow"><QRCodeSVG value={data.data.payload} size={220} /></div><p className="text-sm text-slate-500">{t('ops.my.qrHint')}</p></div>}
    </Modal>
  )
}

/** An absence excuse for the manager to decide: reason, the day(s) and an optional document. */
function ExcuseModal({ registration, onClose }: { registration: Registration; onClose: () => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState({ reason_code: 'sick_leave', reason_text: '', from_date: '', to_date: '' })
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const send = async () => {
    setBusy(true)
    try {
      const body = new FormData()
      Object.entries(f).forEach(([k, v]) => v && body.append(k, v))
      if (file) body.append('attachments[]', file)
      await api.post(`/me/registrations/${registration.id}/excuses`, body); toast(t('ops.my.excuseSent')); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open onClose={onClose} title={`${t('ops.my.excuse')} — ${registration.program?.title}`}>
      <div className="space-y-4">
        <Field label={t('admission.withdraw.reason')}><select className="input" value={f.reason_code} onChange={(e) => setF({ ...f, reason_code: e.target.value })}>{['sick_leave', 'bereavement', 'work_assignment', 'other'].map((c) => <option key={c} value={c}>{t(`ops.absence.excuseReason.${c}`)}</option>)}</select></Field>
        <div className="grid gap-4 sm:grid-cols-2"><Field label={t('ops.rooms.from')}><input type="date" className="input" value={f.from_date} onChange={(e) => setF({ ...f, from_date: e.target.value })} /></Field><Field label={t('ops.rooms.to')}><input type="date" className="input" value={f.to_date} onChange={(e) => setF({ ...f, to_date: e.target.value })} /></Field></div>
        <Field label={t('admission.withdraw.details')}><textarea className="input min-h-20" value={f.reason_text} onChange={(e) => setF({ ...f, reason_text: e.target.value })} /></Field>
        <Field label={t('admission.withdraw.attach')}><input type="file" accept=".pdf,.jpg,.jpeg,.png" className="input" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></Field>
        <Button variant="gold" loading={busy} disabled={!f.from_date || !f.to_date} onClick={() => void send()}>{t('admission.withdraw.send')}</Button>
      </div>
    </Modal>
  )
}
