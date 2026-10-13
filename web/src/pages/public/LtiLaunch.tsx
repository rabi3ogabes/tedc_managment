import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Spinner } from '@/components/ui'
import { api } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { embedded } from '@/lib/embedded'
import { safePath } from '@/lib/paths'

/**
 * Where an LTI launch from another LMS arrives: the one-time code becomes the session (as with single sign-on) and the
 * learner lands on the lesson or programme, in the focused layout without the portal's menus.
 */
export default function LtiLaunch() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const { finish } = useAuth()
  const [failed, setFailed] = useState(false)
  const once = useRef(false)
  useEffect(() => {
    if (once.current) return
    once.current = true
    const code = params.get('code')
    const to = safePath(params.get('to')) ?? '/portal'
    if (!code) return
    api.post('/auth/sso/exchange', { code })
      .then(({ data }) => { finish(data); embedded.set(true); navigate(to, { replace: true }) })
      .catch(() => setFailed(true))
  }, [params, navigate, finish])
  return (
    <div className="grid min-h-screen place-items-center bg-ivory p-4">
      {failed || !params.get('code') ? (
        <div className="max-w-md space-y-3 rounded-2xl border border-navy-100 bg-white p-6 text-center shadow-sm">
          <h1 className="text-lg font-bold text-navy-900">{t('lti.launch.failedTitle')}</h1>
          <p className="text-sm text-slate-600">{t('lti.launch.failed')}</p>
          <Link to="/login" className="inline-block font-bold text-link">{t('lti.launch.signIn')}</Link>
        </div>
      ) : <div className="text-center"><Spinner /><p className="mt-3 text-sm text-slate-500">{t('lti.launch.opening')}</p></div>}
    </div>
  )
}
