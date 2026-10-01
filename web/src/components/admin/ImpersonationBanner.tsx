import { LogOut, UserCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { impersonation } from '@/lib/impersonation'

/** Shown for as long as the administrator is inside another user's account. */
export default function ImpersonationBanner() {
  const { t } = useTranslation()
  const [state, setState] = useState(impersonation.get())
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    // The borrowed session ended by itself: return to the administrator's account.
    const onLogout = () => { if (impersonation.restoreSilently()) window.location.href = '/admin/users' }
    window.addEventListener('tedc:logout', onLogout)
    const id = window.setInterval(() => { const s = impersonation.get(); setState(s); if (s && Date.now() > s.until) onLogout() }, 15_000)
    return () => { window.removeEventListener('tedc:logout', onLogout); window.clearInterval(id) }
  }, [])

  // Room for the fixed bar.
  useEffect(() => { document.body.style.paddingTop = state ? '44px' : ''; return () => { document.body.style.paddingTop = '' } }, [state])

  if (!state) return null
  const minutes = Math.max(0, Math.ceil((state.until - Date.now()) / 60000))
  return (
    <div className="fixed inset-x-0 top-0 z-[60] flex flex-wrap items-center justify-center gap-3 bg-gradient-to-r from-gold-600 via-gold-500 to-gold-600 px-4 py-2 text-sm font-semibold text-navy-950 shadow">
      <UserCheck className="size-4" />
      <span>{t('impersonation.banner', { name: state.target.name, email: state.target.email })}</span>
      <span className="rounded-full bg-navy-950/15 px-2 py-0.5 text-xs">{t('impersonation.left', { minutes })}</span>
      <button type="button" disabled={busy} onClick={async () => { setBusy(true); await impersonation.stop(); window.location.href = '/admin/users' }} className="inline-flex items-center gap-1.5 rounded-lg bg-navy-950 px-3 py-1 text-xs font-bold text-white hover:bg-navy-900"><LogOut className="size-3.5" />{t('impersonation.stop')}</button>
    </div>
  )
}
