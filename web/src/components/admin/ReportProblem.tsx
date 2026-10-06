import { LifeBuoy } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router-dom'
import { Button, Field, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** A floating "report a problem" button: the page and the browser are captured, a screenshot can be added, and the ticket goes to Saaed. */
export default function ReportProblem() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const [open, setOpen] = useState(false)
  const [f, setF] = useState({ category: 'bug', priority: 'normal', subject: '', description: '' })
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const send = async () => {
    setBusy(true); setError(null)
    try {
      const body = new FormData()
      Object.entries(f).forEach(([k, v]) => body.append(k, v))
      body.append('page_url', window.location.href)
      body.append('context[platform]', 'web')
      body.append('context[viewport]', `${window.innerWidth}x${window.innerHeight}`)
      if (file) body.append('screenshot', file)
      const { data } = await api.post('/me/tickets', body)
      toast(String(data.data.ticket_no ? t('idn.ticket.sent', { no: data.data.ticket_no }) : t('idn.ticket.queued')))
      setOpen(false); setF({ ...f, subject: '', description: '' }); setFile(null)
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  return (
    <>
      <button type="button" onClick={() => setOpen(true)} aria-label={String(t('idn.ticket.title'))} title={String(t('idn.ticket.title'))} className="fixed bottom-5 end-5 z-40 grid size-12 place-items-center rounded-full bg-navy-900 text-gold-300 shadow-glass transition hover:scale-105 print:hidden"><LifeBuoy className="size-6" /></button>
      <Modal open={open} onClose={() => setOpen(false)} title={t('idn.ticket.title')}>
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('idn.ticket.category')}><select className={cls} value={f.category} onChange={(e) => setF({ ...f, category: e.target.value })}>{['bug', 'access', 'data', 'request', 'other'].map((c) => <option key={c} value={c}>{t(`idn.ticket.cats.${c}`)}</option>)}</select></Field>
            <Field label={t('idn.ticket.priority')}><select className={cls} value={f.priority} onChange={(e) => setF({ ...f, priority: e.target.value })}>{['low', 'normal', 'high', 'urgent'].map((c) => <option key={c} value={c}>{t(`idn.ticket.prios.${c}`)}</option>)}</select></Field>
          </div>
          <Field label={t('idn.ticket.subject')}><input className={cls} maxLength={200} value={f.subject} onChange={(e) => setF({ ...f, subject: e.target.value })} /></Field>
          <Field label={t('idn.ticket.description')}><textarea className={`${cls} min-h-28`} maxLength={5000} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} /></Field>
          <Field label={t('idn.ticket.screenshot')}><input type="file" accept="image/png,image/jpeg,image/webp" className="text-sm" onChange={(e) => setFile(e.target.files?.[0] ?? null)} /></Field>
          <p className="text-xs text-slate-400" dir="ltr">{t('idn.ticket.page', { url: pathname })}</p>
          {error && <p className="text-sm text-danger">{error}</p>}
          <div className="flex justify-end"><Button variant="gold" loading={busy} disabled={!f.subject.trim() || !f.description.trim()} onClick={send}>{t('idn.ticket.send')}</Button></div>
        </div>
      </Modal>
    </>
  )
}
