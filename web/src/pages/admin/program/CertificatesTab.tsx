import { Save } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import type { Program } from '@/lib/types'
import { CertificatesStep } from '../smart/RemoteParts'

/** Which certificate designs this program issues to its trainees and trainers. */
export default function CertificatesTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [trainee, setTrainee] = useState(program.certificate_template_id ?? '')
  const [trainer, setTrainer] = useState(program.trainer_certificate_template_id ?? '')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)
  const manage = can('programs.manage')

  const save = async () => {
    setBusy(true)
    setMessage(null)
    try {
      await api.put(`/admin/programs/${program.id}`, { certificate_template_id: trainee || null, trainer_certificate_template_id: trainer || null })
      setMessage({ ok: true, text: t('studio.certs.saved') })
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-4">
      {message && <div className={`rounded-xl p-3 text-sm ${message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger'}`}>{message.text}</div>}
      <CertificatesStep trainee={trainee} trainer={trainer} setTrainee={manage ? setTrainee : () => undefined} setTrainer={manage ? setTrainer : () => undefined} />
      {manage && <div className="flex items-center justify-between gap-3"><p className="text-xs text-slate-500">{t('studio.certs.appliesNote')}</p><Button variant="gold" loading={busy} icon={<Save className="size-4" />} onClick={save}>{t('studio.certs.save')}</Button></div>}
    </div>
  )
}
