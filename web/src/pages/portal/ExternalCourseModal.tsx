import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import EvidenceInput, { noEvidence, type Evidence } from '@/components/EvidenceInput'
import { Button, Field, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import type { Registration } from '@/lib/types'
import { toast } from '@/lib/toast'

/** A course on another platform: open it from here, and send evidence of completion for the centre to review. */
export default function ExternalCourseModal({ registration, onClose }: { registration: Registration; onClose: () => void }) {
  const { t } = useTranslation()
  const [ev, setEv] = useState<Evidence>(noEvidence())
  const [note, setNote] = useState('')
  const open = async () => { try { const { data } = await api.post(`/me/registrations/${registration.id}/external-launch`); window.open(data.data.url, '_blank', 'noopener') } catch (e) { toast(errorMessage(e), 'error') } }
  const send = async () => {
    const fd = new FormData()
    if (note) fd.append('note', note)
    ev.files.forEach((f, i) => fd.append(`evidence[${i}]`, f)); ev.links.forEach((l, i) => fd.append(`evidence[${ev.files.length + i}]`, l))
    try { await api.post(`/me/registrations/${registration.id}/external-completion`, fd); toast(t('content.ext.sent')); onClose() } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal open onClose={onClose} title={registration.program?.external_platform?.name ?? ''}>
      <div className="space-y-3"><Button variant="gold" onClick={() => void open()}>{t('content.ext.open')}</Button>
        <div className="space-y-2 border-t border-navy-50 pt-3"><span className="label">{t('content.ext.evidence')}</span><EvidenceInput max={5} value={ev} onChange={setEv} />
          <Field label={t('content.ext.note')}><textarea className="input" rows={2} value={note} onChange={(e) => setNote(e.target.value)} /></Field><Button variant="outline" onClick={() => void send()}>{t('content.ext.send')}</Button></div></div>
    </Modal>
  )
}
