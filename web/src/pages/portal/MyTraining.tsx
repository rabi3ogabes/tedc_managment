import { CalendarPlus, Download, FileText, Star } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card, Empty, Field, Modal, PageHeader, Progress, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Registration } from '@/lib/types'

type Material = { id: string; title: string; type: string; url?: string; has_file: boolean }

export default function MyTraining() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Registration[] }>('/me/registrations')
  const [evaluating, setEvaluating] = useState<Registration | null>(null)
  const [materialsFor, setMaterialsFor] = useState<Registration | null>(null)

  const cancel = async (r: Registration) => {
    if (!window.confirm(t('portal.cancelRegistration') + '?')) return
    await api.post(`/me/registrations/${r.id}/cancel`)
    refetch()
  }

  return (
    <>
      <PageHeader title={t('portal.myTraining')} actions={<Button variant="outline" icon={<CalendarPlus className="size-4" />} onClick={() => downloadFile('/me/calendar.ics', 'tedc-training.ics')}>{t('portal.calendarFeed')}</Button>} />
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
              <div className="mt-4 grid grid-cols-3 gap-3 text-center text-xs">
                <div><div className="mb-1 text-slate-500">{t('admin.registrations.attendance')}</div><Progress value={r.attendance_percent} tone={r.attendance_percent >= (r.program?.min_attendance_percent ?? 80) ? 'green' : 'gold'} /><div className="mt-1 font-bold">{fmt.percent(r.attendance_percent)}</div></div>
                <div><div className="mb-1 text-slate-500">{t('admin.programs.tasks')}</div><StatusBadge status={r.tasks_completed ? 'approved' : 'pending'} /></div>
                <div><div className="mb-1 text-slate-500">{t('admin.registrations.certificate')}</div><StatusBadge status={r.certificate_status} /></div>
              </div>
              <div className="mt-4 flex flex-wrap gap-2">
                {['approved', 'completed'].includes(r.status) && <Button size="sm" variant="outline" icon={<FileText className="size-4" />} onClick={() => setMaterialsFor(r)}>{t('admin.programs.materials')}</Button>}
                {['approved', 'completed'].includes(r.status) && !r.evaluation_completed && <Button size="sm" variant="gold" icon={<Star className="size-4" />} onClick={() => setEvaluating(r)}>{t('portal.evaluate')}</Button>}
                {r.certificate && <Button size="sm" variant="primary" icon={<Download className="size-4" />} onClick={() => downloadFile(`/certificates/${r.certificate!.id}/download`, 'certificate.pdf', true)}>PDF</Button>}
                {['pending', 'approved', 'waitlisted'].includes(r.status) && <Button size="sm" variant="ghost" onClick={() => cancel(r)}>{t('portal.cancelRegistration')}</Button>}
              </div>
            </Card>
          ))}
        </div>
      )}
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
