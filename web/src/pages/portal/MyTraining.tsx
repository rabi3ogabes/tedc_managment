import { CalendarPlus, Check, Download, FileText, PlayCircle, QrCode, Star } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card, Empty, Field, Modal, PageHeader, Progress, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Registration } from '@/lib/types'

type Material = { id: string; title: string; type: string; url?: string; has_file: boolean }

export default function MyTraining() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Registration[] }>('/me/registrations')
  const [evaluating, setEvaluating] = useState<Registration | null>(null)
  const [materialsFor, setMaterialsFor] = useState<Registration | null>(null)

  const [withdrawing, setWithdrawing] = useState<Registration | null>(null)
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
                {['approved', 'completed'].includes(r.status) && <Button size="sm" variant="outline" icon={<FileText className="size-4" />} onClick={() => setMaterialsFor(r)}>{t('admin.programs.materials')}</Button>}
                {['approved', 'completed'].includes(r.status) && !r.evaluation_completed && <Button size="sm" variant="gold" icon={<Star className="size-4" />} onClick={() => setEvaluating(r)}>{t('portal.evaluate')}</Button>}
                {r.certificate && <Button size="sm" variant="primary" icon={<Download className="size-4" />} onClick={() => downloadFile(`/certificates/${r.certificate!.id}/download`, 'certificate.pdf', true)}>PDF</Button>}
                {r.status === 'approved' && <Button size="sm" variant="ghost" onClick={() => setExcusing(r)}>{t('ops.my.excuse')}</Button>}
                {['pending_manager', 'pending', 'approved', 'waitlisted'].includes(r.status) && <Button size="sm" variant="ghost" onClick={() => setWithdrawing(r)}>{t('admission.withdraw.button')}</Button>}
              </div>
            </Card>
          ))}
        </div>
      )}
      {excusing && <ExcuseModal registration={excusing} onClose={() => setExcusing(null)} />}
      {showQr && <MyQrModal onClose={() => setShowQr(false)} />}
      {withdrawing && <WithdrawModal registration={withdrawing} onClose={(changed) => { setWithdrawing(null); if (changed) refetch() }} />}
      {evaluating && <EvaluationModal registration={evaluating} onClose={() => { setEvaluating(null); refetch() }} />}
      {materialsFor && <MaterialsModal registration={materialsFor} onClose={() => setMaterialsFor(null)} />}
    </>
  )
}

function EvaluationModal({ registration, onClose }: { registration: Registration; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const criteria = i18n.language === 'ar'
    ? { content: 'المحتوى', trainer: 'المدرب', organization: 'التنظيم', relevance: 'ملاءمة البرنامج لعملي' }
    : { content: 'Content', trainer: 'Trainer', organization: 'Organization', relevance: 'Relevance to my work' }
  const [ratings, setRatings] = useState<Record<string, number>>({ content: 5, trainer: 5, organization: 5, relevance: 5 })
  const [comments, setComments] = useState('')
  const [post, setPost] = useState('')
  const [allow, setAllow] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async () => {
    try {
      await api.post(`/me/registrations/${registration.id}/evaluation`, { ratings, comments, post_test_score: post ? Number(post) : null, allow_testimonial: allow })
      onClose()
    } catch (e) { setError(errorMessage(e)) }
  }

  return (
    <Modal open onClose={onClose} title={`${t('portal.evaluate')} — ${registration.program?.title}`}>
      <div className="space-y-4">
        {Object.entries(criteria).map(([k, label]) => (
          <div key={k} className="flex items-center justify-between">
            <span className="text-sm font-medium text-navy-800">{label}</span>
            <div className="flex gap-1">{[1, 2, 3, 4, 5].map((n) => (
              <button key={n} onClick={() => setRatings({ ...ratings, [k]: n })}><Star className={`size-6 ${n <= ratings[k] ? 'fill-gold-500 text-gold-500' : 'text-slate-300'}`} /></button>
            ))}</div>
          </div>
        ))}
        <Field label="Post-test %"><input className="input" type="number" min={0} max={100} value={post} onChange={(e) => setPost(e.target.value)} /></Field>
        <Field label={t('contact.message')}><textarea className="input" value={comments} onChange={(e) => setComments(e.target.value)} /></Field>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={allow} onChange={(e) => setAllow(e.target.checked)} />{t('home.testimonials')}</label>
        {error && <p className="text-sm text-danger">{error}</p>}
        <Button variant="gold" className="w-full" onClick={submit}>{t('common.submit')}</Button>
      </div>
    </Modal>
  )
}

function MaterialsModal({ registration, onClose }: { registration: Registration; onClose: () => void }) {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: Material[] }>(`/me/registrations/${registration.id}/materials`)
  const open = async (m: Material) => {
    if (m.url) return window.open(m.url, '_blank', 'noopener')
    const { data: res } = await api.get(`/me/materials/${m.id}/download`)
    window.open(res.data.url, '_blank', 'noopener')
  }
  return (
    <Modal open onClose={onClose} title={t('admin.programs.materials')}>
      {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
        <ul className="space-y-2">{data.data.map((m) => (
          <li key={m.id}><button onClick={() => open(m)} className="flex w-full items-center gap-3 rounded-xl bg-ivory p-3 text-start text-sm font-semibold text-navy-800 hover:bg-gold-100"><FileText className="size-5 text-gold-600" />{m.title}<span className="ms-auto text-xs text-slate-400">{m.type}</span></button></li>
        ))}</ul>
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
