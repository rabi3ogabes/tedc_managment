import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Spinner } from '@/components/ui'
import { api } from '@/lib/api'
import { homeFor, useAuth } from '@/lib/auth'

/** Where the Ministry sign-in sends the browser back: the one-time code is swapped for the session. */
export default function SsoCallback() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { finish } = useAuth()
  const [failed, setFailed] = useState(false)
  useEffect(() => {
    const code = params.get('code')
    if (!code) { navigate('/login?sso_error=1', { replace: true }); return }
    api.post('/auth/sso/exchange', { code }).then(({ data }) => navigate(homeFor(finish(data)), { replace: true })).catch(() => { setFailed(true); navigate('/login?sso_error=1', { replace: true }) })
  }, [params, navigate, finish])
  return <div className="grid min-h-screen place-items-center">{failed ? null : <div className="text-center"><Spinner /><p className="mt-3 text-sm text-slate-500">{t('idn.login.completing')}</p></div>}</div>
}
