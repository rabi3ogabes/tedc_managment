import { Link2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Button, Card } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { embedded } from '@/lib/embedded'
import { safePath } from '@/lib/paths'

/** A launch from another LMS matched this person's e-mail: they confirm, signed in, that the account is theirs to link. */
export default function LtiLink() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const ticket = params.get('ticket') ?? ''
  const link = async () => {
    setBusy(true); setError(null)
    try {
      const { data } = await api.post('/me/lti/tool/link', { ticket })
      embedded.set(true)
      navigate(safePath(data.data.to) ?? '/portal', { replace: true })
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  return (
    <div className="mx-auto max-w-lg px-4 py-10">
      <Card className="space-y-4">
        <div className="flex items-center gap-3"><span className="grid size-10 place-items-center rounded-xl bg-gold-50 text-gold-700"><Link2 className="size-5" /></span><h1 className="text-lg font-bold text-navy-900">{t('lti.link.title')}</h1></div>
        <p className="text-sm text-slate-600">{t('lti.link.body', { name: user?.name ?? '' })}</p>
        <p className="rounded-xl bg-ivory p-3 text-xs text-slate-500">{t('lti.link.note')}</p>
        {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
        <div className="flex flex-wrap gap-2"><Button variant="gold" loading={busy} disabled={!ticket} onClick={() => void link()}>{t('lti.link.confirm')}</Button><Button variant="outline" onClick={() => navigate('/portal', { replace: true })}>{t('lti.link.cancel')}</Button></div>
      </Card>
    </div>
  )
}
